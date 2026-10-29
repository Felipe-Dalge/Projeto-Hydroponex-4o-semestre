<?php
declare(strict_types=1);
require_once __DIR__.'/gardens.php';
require_once __DIR__.'/plant_fields.php';

const INFRA_RESOURCES = [
    'culturas'=>['table'=>'cultura','pk'=>'id_cultura','catalog'=>true,'fields'=>['nome','tipo','tempo_crescimento_dias','temperatura_ideal_c','ph_ideal']],
    'nutrientes'=>['table'=>'nutriente','pk'=>'id_nutriente','catalog'=>true,'fields'=>['nome','classificacao','descricao','tipo']],
    'reservatorios'=>['table'=>'reservatorio','pk'=>'id_reservatorio','catalog'=>false,'fields'=>['nome','capacidade_l','nivel_atual_l','temperatura_c','ph_atual']],
    'estruturas'=>['table'=>'estrutura','pk'=>'id_estrutura','catalog'=>false,'fields'=>['nome','tipo','qnt_unidade','id_reservatorio']]
];

function infra_decimal(mixed $value,string $label,float $min,float $max,int $precision=2): mixed
{
    if($value===null)return null;
    if((!is_string($value)&&!is_int($value)&&!is_float($value)) || strlen((string)$value)>30 || !preg_match('/\A-?[0-9]+(?:\.[0-9]{1,'.$precision.'})?\z/D',(string)$value)
        || !is_finite((float)$value) || (float)$value<$min || (float)$value>$max)auth_error(422,'Valor ou precisão inválida: '.$label.'.');
    return $value;
}

function infra_integer(mixed $value,int $min,int $max): int
{
    if((!is_int($value)&&!is_string($value)) || !preg_match('/\A[0-9]{1,5}\z/D',(string)$value) || (int)$value<$min || (int)$value>$max)auth_error(422,'Quantidade/dias inválidos.');
    return (int)$value;
}

/** Catálogo global, pessoal ou compartilhado por vínculo de horta autorizado. */
function infra_catalog_query(string $resource,int $uid): array
{
    if($resource==='culturas') {
        return ["SELECT c.*,EXISTS(SELECT 1 FROM horta_cultura x WHERE x.id_cultura=c.id_cultura) AS em_uso FROM cultura c WHERE
            (c.id_criador IS NULL OR c.id_criador=? OR EXISTS(SELECT 1 FROM horta_cultura hc JOIN usuario_horta uh ON uh.id_horta=hc.id_horta JOIN horta h ON h.id_horta=hc.id_horta WHERE hc.id_cultura=c.id_cultura AND uh.id_usuario=? AND h.arquivada_em IS NULL))",[$uid,$uid]];
    }
    return ["SELECT c.*,(EXISTS(SELECT 1 FROM sensor s WHERE s.id_nutriente=c.id_nutriente) OR EXISTS(SELECT 1 FROM reservatorio_nutriente rn WHERE rn.id_nutriente=c.id_nutriente)) AS em_uso FROM nutriente c WHERE
        (c.id_criador IS NULL OR c.id_criador=? OR EXISTS(SELECT 1 FROM sensor s JOIN usuario_horta uh ON uh.id_horta=s.id_horta JOIN horta h ON h.id_horta=s.id_horta WHERE s.id_nutriente=c.id_nutriente AND uh.id_usuario=? AND h.arquivada_em IS NULL)
        OR EXISTS(SELECT 1 FROM reservatorio_nutriente rn JOIN reservatorio r ON r.id_reservatorio=rn.id_reservatorio JOIN usuario_horta uh ON uh.id_horta=r.id_horta JOIN horta h ON h.id_horta=r.id_horta WHERE rn.id_nutriente=c.id_nutriente AND uh.id_usuario=? AND h.arquivada_em IS NULL))",[$uid,$uid,$uid]];
}

function infra_catalog_get(PDO $pdo,string $resource,int $uid,int $id,bool $lock=false): array
{
    [$sql,$params]=infra_catalog_query($resource,$uid);$pk=INFRA_RESOURCES[$resource]['pk'];
    $s=$pdo->prepare($sql." AND c.$pk=?".($lock?' FOR UPDATE':''));$s->execute([...$params,$id]);$row=$s->fetch();
    if(!$row)auth_error(404,'Item de catálogo não encontrado ou sem acesso.');
    return $row;
}

function infra_public(string $resource,array $row,int $uid,bool $write=false): array
{
    $meta=INFRA_RESOURCES[$resource];$row['id']=(int)$row[$meta['pk']];
    if($meta['catalog']) {
        $own=$row['id_criador']!==null&&(int)$row['id_criador']===$uid;
        $row['escopo']=$row['id_criador']===null?'global':($own?'pessoal':'compartilhado');
        // Catálogo global não revela utilização agregada em hortas privadas de terceiros.
        $row['em_uso']=$row['id_criador']===null?null:(bool)$row['em_uso'];$row['pode_editar']=$own&&!$row['em_uso'];unset($row['id_criador']);
    } else $row['pode_editar']=$write;
    $row['pode_excluir']=$row['pode_editar'];
    if($resource==='culturas')$row['categoria']=$row['tipo'];
    return $row;
}

function infra_values(string $resource,array $data,?array $old): array
{
    $fields=INFRA_RESOURCES[$resource]['fields'];$values=array_fill_keys($fields,null);
    if($resource==='estruturas')$values['qnt_unidade']=1;
    if($old)$values=array_replace($values,array_intersect_key($old,$values));
    if($resource==='culturas'&&array_key_exists('categoria',$data)) {
        if(array_key_exists('tipo',$data))auth_error(422,'Use categoria ou tipo, não ambos.');
        $data['tipo']=$data['categoria'];unset($data['categoria']);
    }
    $values=array_replace($values,$data);
    if($resource==='nutrientes'&&($values['nome']===null||$values['nome']==='')&&$values['classificacao'])$values['nome']=$values['classificacao'];
    $values['nome']=auth_string($values,'nome',100);
    foreach(['tipo','classificacao','descricao'] as $field)if(array_key_exists($field,$values)) {
        if($field==='descricao') {
            $v=$values[$field]??'';
            if(!is_string($v)||!preg_match('//u',$v)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$v)||preg_match_all('/./us',$v)>1000)auth_error(422,'Descrição inválida ou maior que 1000 caracteres.');
            $v=trim($v);
        }else $v=auth_string($values,$field,100,false);
        $values[$field]=$v===''?null:$v;
    }
    foreach(['ph_atual','ph_ideal'] as $f)if(array_key_exists($f,$values))$values[$f]=infra_decimal($values[$f],'pH',0,14);
    foreach(['temperatura_c','temperatura_ideal_c'] as $f)if(array_key_exists($f,$values))$values[$f]=infra_decimal($values[$f],'temperatura',-273.15,999.99);
    if($resource==='culturas'&&$values['tempo_crescimento_dias']!==null)$values['tempo_crescimento_dias']=infra_integer($values['tempo_crescimento_dias'],0,65535);
    if($resource==='reservatorios') {
        $values['capacidade_l']=infra_decimal($values['capacidade_l'],'capacidade',0,999999999.999,3);
        $values['nivel_atual_l']=infra_decimal($values['nivel_atual_l'],'nível',0,999999999.999,3);
        if($values['capacidade_l']===null || (float)$values['capacidade_l']<=0 || ($values['nivel_atual_l']!==null&&(float)$values['nivel_atual_l']>(float)$values['capacidade_l']))auth_error(422,'Capacidade deve ser positiva e nível não pode excedê-la.');
    }
    if($resource==='estruturas') {
        $values['qnt_unidade']=infra_integer($values['qnt_unidade'],1,65535);
        if($values['id_reservatorio']!==null)$values['id_reservatorio']=garden_id($values['id_reservatorio']);
    }
    return $values;
}

function infrastructure_api(string $resource): never
{
    auth_api(['GET','POST','PATCH','PUT','DELETE'],function()use($resource):void {
        $meta=INFRA_RESOURCES[$resource];$user=require_authenticated_user();$pdo=database_connection();$uid=(int)$user['id_usuario'];
        $garden=isset($_GET['horta'])?garden_id($_GET['horta']):null;$id=isset($_GET['id'])?garden_id($_GET['id']):null;$method=$_SERVER['REQUEST_METHOD'];$access=null;
        if(!$meta['catalog']&&$garden===null)auth_error(422,'Selecione uma horta.');
        if($garden!==null)$access=garden_access($pdo,$garden,$uid);
        $table=$meta['table'];$pk=$meta['pk'];
        if($method==='GET') {
            if($meta['catalog']) { [$sql,$params]=infra_catalog_query($resource,$uid);if($id!==null){$sql.=" AND c.$pk=?";$params[]=$id;} $sql.=" ORDER BY c.$pk DESC"; }
            else { $sql="SELECT * FROM $table WHERE id_horta=?";$params=[$garden];if($id!==null){$sql.=" AND $pk=?";$params[]=$id;} $sql.=" ORDER BY $pk DESC"; }
            $s=$pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll();if($id!==null&&!$rows)auth_error(404,'Registro não encontrado nesta horta ou catálogo.');
            $linked=[];
            if($resource==='culturas'&&$garden!==null){$s=$pdo->prepare('SELECT id_cultura FROM horta_cultura WHERE id_horta=?');$s->execute([$garden]);$linked=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));}
            $out=[];foreach($rows as $r){$v=infra_public($resource,$r,$uid,$access&&in_array($access['papel'],['gestor','proprietario'],true));if($resource==='culturas')$v['vinculada']=in_array($v['id'],$linked,true);$out[]=$v;}
            send_json(['success'=>true,'data'=>$id!==null?$out[0]:$out]);
        }
        require_csrf();$fields=$method==='DELETE'?['confirmacao']:$meta['fields'];if($resource==='culturas'&&$method!=='DELETE')$fields[]='categoria';
        $data=auth_body($fields);if($method!=='POST'&&$id===null)auth_error(422,'Informe o identificador.');if($method==='POST'&&$id!==null)auth_error(422,'O identificador é gerado pelo servidor.');
        if($method==='DELETE'&&($data['confirmacao']??null)!=='EXCLUIR')auth_error(422,'Confirme a exclusão digitando EXCLUIR.');
        if($method!=='DELETE'&&!$data)auth_error(422,'Informe os campos que deseja salvar.');
        $pdo->beginTransaction();
        try {
            garden_lock_user($pdo);if(!$meta['catalog'])$access=garden_access($pdo,$garden,$uid,true,false,true);
            $old=null;
            if($id!==null) {
                if($meta['catalog']) {
                    $old=infra_catalog_get($pdo,$resource,$uid,$id,true);
                    if($old['id_criador']===null||(int)$old['id_criador']!==$uid)auth_error(403,'Somente o criador pode alterar seu item pessoal. Catálogo global é somente consulta.');
                    if($old['em_uso'])auth_error(409,'Item em uso. Preserve o catálogo compartilhado: desvincule onde permitido ou cadastre uma nova versão.');
                } else {
                    $s=$pdo->prepare("SELECT * FROM $table WHERE $pk=? AND id_horta=? FOR UPDATE");$s->execute([$id,$garden]);$old=$s->fetch();if(!$old)auth_error(404,'Registro não encontrado nesta horta.');
                }
            }
            if($method==='DELETE') {
                $s=$pdo->prepare("DELETE FROM $table WHERE $pk=?");$s->execute([$id]);$pdo->commit();send_json(['success'=>true,'message'=>'Registro excluído.']);
            }
            $values=infra_values($resource,$data,$old);
            if($resource==='estruturas'&&$values['id_reservatorio']!==null) {
                $s=$pdo->prepare('SELECT 1 FROM reservatorio WHERE id_horta=? AND id_reservatorio=?');$s->execute([$garden,$values['id_reservatorio']]);if(!$s->fetchColumn())auth_error(422,'Reservatório deve pertencer à mesma horta.');
            }
            if($method==='POST') {
                $parent=$meta['catalog']?'id_criador':'id_horta';$cols=implode(',',$meta['fields']);$marks=implode(',',array_fill(0,count($values),'?'));
                $s=$pdo->prepare("INSERT INTO $table ($parent,$cols) VALUES (?,$marks)");$s->execute([$meta['catalog']?$uid:$garden,...array_values($values)]);$id=(int)$pdo->lastInsertId();
            } else {
                $set=implode(',',array_map(static fn($f)=>$f.'=?',$meta['fields']));if($resource==='reservatorios')$set.=',atualizado_em=UTC_TIMESTAMP(6)';
                $s=$pdo->prepare("UPDATE $table SET $set WHERE $pk=?");$s->execute([...array_values($values),$id]);
            }
            $row=$meta['catalog']?infra_catalog_get($pdo,$resource,$uid,$id):null;
            if(!$row){$s=$pdo->prepare("SELECT * FROM $table WHERE $pk=? AND id_horta=?");$s->execute([$id,$garden]);$row=$s->fetch();}
            $out=infra_public($resource,$row,$uid,true);$pdo->commit();send_json(['success'=>true,'data'=>$out,'message'=>'Registro salvo.'],$method==='POST'?201:200);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&in_array((int)($e->errorInfo[1]??0),[1217,1451],true))auth_error(409,'Registro possui plantas, sensores, aplicações ou outros vínculos e não pode ser excluído.');throw $e;}
    });
}
