<?php
declare(strict_types=1);
require_once __DIR__.'/gardens.php';
require_once __DIR__.'/plant_fields.php';

function garden_plant_public(array $row): array
{
    $out=['id'=>(int)$row['id_planta'],'id_planta'=>(int)$row['id_planta'],'id_horta'=>(int)$row['id_horta']];
    foreach(PLANT_FIELDS as $field) $out[$field]=$row[$field];
    foreach(['ph_atual','temperatura_agua_c','umidade_percentual','nivel_nutrientes_ppm','nivel_irrigacao','agua_l'] as $field) $out[$field]=$out[$field]===null?null:(float)$out[$field];
    foreach(['dias_crescimento','id_estrutura','id_cultura'] as $field) $out[$field]=$out[$field]===null?null:(int)$out[$field];
    foreach(PLANT_ALIASES as $alias=>$field) $out[$alias]=$out[$field];
    $out['criado_em']=$row['criado_em']; $out['atualizado_em']=$row['atualizado_em'];
    $out['lastUpdate']=$row['ultima_medicao_em']??$row['atualizado_em'];
    return $out;
}

function garden_plant_read(PDO $pdo,int $garden,int $id,bool $lock=false): array
{
    $s=$pdo->prepare('SELECT * FROM planta WHERE id_planta=? AND id_horta=? AND arquivada_em IS NULL'.($lock?' FOR UPDATE':''));
    $s->execute([$id,$garden]); $row=$s->fetch();
    if(!$row) auth_error(404,'Planta não encontrada nesta horta.');
    return $row;
}

function plant_associations(PDO $pdo,int $garden,array $values): void
{
    foreach(['id_estrutura','id_cultura'] as $field) {
        if($values[$field]===null) continue;
        $sql=$field==='id_estrutura'?'SELECT 1 FROM estrutura WHERE id_horta=? AND id_estrutura=?':'SELECT 1 FROM horta_cultura WHERE id_horta=? AND id_cultura=?';
        $s=$pdo->prepare($sql); $s->execute([$garden,$values[$field]]);
        if(!$s->fetchColumn()) auth_error(422,'Estrutura/cultura não pertence a esta horta.');
    }
}

function plant_summary(PDO $pdo,int $garden): array
{
    $s=$pdo->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(status_planta='Normal'),0) AS normal,COALESCE(SUM(status_planta='Atenção'),0) AS atencao,COALESCE(SUM(status_planta='Alerta'),0) AS alerta FROM planta WHERE id_horta=? AND arquivada_em IS NULL");
    $s->execute([$garden]);return array_map('intval',$s->fetch());
}

function plant_consumption(PDO $pdo,int $garden): array
{
    $day=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $s=$pdo->prepare('SELECT volume_l FROM consumo_agua WHERE id_horta=? AND data_consumo=?');$s->execute([$garden,$day]);$value=$s->fetchColumn();
    return ['data'=>$day,'consumo_hoje_l'=>$value===false?null:(float)$value];
}

function garden_plants_api(array $user,int $garden): never
{
    $pdo=database_connection(); $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET') {
        $access=garden_access($pdo,$garden,(int)$user['id_usuario']);
        if(isset($_GET['id'])) send_json(['success'=>true,'data'=>garden_plant_public(garden_plant_read($pdo,$garden,garden_id($_GET['id'])))]);
        $name=auth_string($_GET,'nome',100,false); $status=auth_string($_GET,'status',15,false);
        if($status!=='' && !in_array($status,['Normal','Atenção','Alerta'],true)) auth_error(422,'Status inválido.');
        $sql='SELECT * FROM planta WHERE id_horta=? AND arquivada_em IS NULL'; $params=[$garden];
        if($name!=='') { $sql.=" AND nome LIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$name).'%'; }
        if($status!=='') { $sql.=' AND status_planta=?'; $params[]=$status; }
        $s=$pdo->prepare($sql.' ORDER BY id_planta'); $s->execute($params);
        send_json(['success'=>true,'data'=>array_map('garden_plant_public',$s->fetchAll()),'resumo'=>plant_summary($pdo,$garden),'indicadores'=>plant_consumption($pdo,$garden),
            'permissoes'=>['pode_editar'=>in_array($access['papel'],['proprietario','gestor'],true)]]);
    }
    require_csrf();
    $allowed=$method==='DELETE'?['id','confirmacao']:array_merge(['id'],PLANT_FIELDS,array_keys(PLANT_ALIASES));
    $data=auth_body($allowed);
    $id=$method==='POST'?null:garden_id($data['id']??null);
    if($method==='POST' && array_key_exists('id',$data)) auth_error(422,'O identificador da planta é gerado pelo servidor.');
    if($method==='DELETE' && ($data['confirmacao']??null)!=='EXCLUIR') auth_error(422,'Confirme a exclusão da planta.');
    $changes=[];
    if($method!=='DELETE') {
        unset($data['id']);
        try { $changes=plant_validate($data,$method!=='POST'); }
        catch(InvalidArgumentException $e) { auth_error(422,$e->getMessage()); }
        if(!$changes) auth_error(422,'Informe os dados que deseja alterar.');
    }
    $pdo->beginTransaction();
    try {
        garden_lock_user($pdo); garden_access($pdo,$garden,(int)$user['id_usuario'],true,false,true);
        $old=$id!==null?garden_plant_read($pdo,$garden,$id,true):null;
        if($method==='DELETE') {
            $s=$pdo->prepare("SELECT id_sensor FROM sensor WHERE id_planta=? AND status<>'inativo' LIMIT 1 FOR UPDATE"); $s->execute([$id]);
            if($s->fetchColumn()) auth_error(409,'Desative os sensores da planta antes de excluí-la.');
            // Exclusão lógica preserva sensores históricos, FKs e auditoria da migração.
            $s=$pdo->prepare('UPDATE planta SET arquivada_em=UTC_TIMESTAMP(6) WHERE id_planta=? AND id_horta=?'); $s->execute([$id,$garden]);
            $pdo->commit();send_json(['success'=>true,'message'=>'Planta excluída da listagem; histórico preservado.']);
        }
        $base=array_fill_keys(PLANT_FIELDS,null); $base['status_planta']='Normal';
        if($old) $base=array_replace($base,array_intersect_key($old,$base));
        $values=array_replace($base,$changes); plant_associations($pdo,$garden,$values);
        if($method==='POST') {
            $columns=implode(',',PLANT_FIELDS);$marks=implode(',',array_fill(0,count(PLANT_FIELDS),'?'));
            $s=$pdo->prepare("INSERT INTO planta (id_horta,$columns) VALUES (?,$marks)"); $s->execute(array_merge([$garden],array_values($values)));$id=(int)$pdo->lastInsertId();
        } else {
            $set=implode(',',array_map(static fn($field)=>$field.'=?',PLANT_FIELDS));
            $s=$pdo->prepare("UPDATE planta SET $set WHERE id_planta=? AND id_horta=?");$s->execute(array_merge(array_values($values),[$id,$garden]));
        }
        $result=garden_plant_public(garden_plant_read($pdo,$garden,$id));$pdo->commit();
        send_json(['success'=>true,'data'=>$result,'message'=>$method==='POST'?'Planta cadastrada na horta.':'Planta atualizada.'],$method==='POST'?201:200);
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function plant_transfer(array $user,int $source,array $data): array
{
    $id=garden_id($data['id']??null);$target=garden_id($data['horta_destino']??null);
    if($target===$source)auth_error(422,'Selecione uma horta de destino diferente.');
    if(($data['confirmacao']??null)!=='TRANSFERIR')auth_error(422,'Confirme a transferência.');
    try { $links=plant_validate(array_intersect_key($data,array_flip(['id_estrutura','id_cultura'])),true); }
    catch(InvalidArgumentException $e){auth_error(422,$e->getMessage());}
    $pdo=database_connection();$pdo->beginTransaction();
    try {
        garden_lock_user($pdo);$ids=[$source,$target];sort($ids,SORT_NUMERIC);
        foreach($ids as $h) garden_access($pdo,$h,(int)$user['id_usuario'],true,false,true);
        $row=garden_plant_read($pdo,$source,$id,true);
        foreach(['SELECT id_sensor FROM sensor WHERE id_planta=? LIMIT 1 FOR UPDATE','SELECT id_planta FROM importacao_json WHERE id_planta=? LIMIT 1 FOR UPDATE'] as $sql) {
            $s=$pdo->prepare($sql);$s->execute([$id]);
            if($s->fetchColumn())auth_error(409,'A planta possui sensores ou auditoria de importação. Sua transferência exige um procedimento administrativo que preserve esses vínculos.');
        }
        foreach(['id_estrutura','id_cultura'] as $field) {
            if($row[$field]!==null && !array_key_exists($field,$links))auth_error(409,'Informe explicitamente os vínculos de estrutura/cultura no destino ou confirme sua remoção com valor nulo.');
            $links[$field]=$links[$field]??null;
        }
        plant_associations($pdo,$target,$links);
        $s=$pdo->prepare('UPDATE planta SET id_horta=?,id_estrutura=?,id_cultura=? WHERE id_planta=? AND id_horta=?');$s->execute([$target,$links['id_estrutura'],$links['id_cultura'],$id,$source]);
        $result=garden_plant_public(garden_plant_read($pdo,$target,$id));$pdo->commit();return $result;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
