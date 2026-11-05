<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';

function garden_id(mixed $value): int
{
    if (is_bool($value) || (!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$value) || (int)$value > 4294967295) auth_error(422,'Identificador de horta inválido.');
    return (int)$value;
}

/** Deve ser chamado dentro da transação de escrita; impede revogação concorrente. */
function garden_lock_user(PDO $pdo): array
{
    $s=$pdo->prepare('SELECT id_usuario,ativo,excluido_em,auth_versao FROM usuario WHERE id_usuario=? FOR UPDATE');
    $s->execute([$_SESSION['id_usuario']]); $user=$s->fetch();
    if (!$user || !(int)$user['ativo'] || $user['excluido_em'] !== null || (int)$user['auth_versao'] !== (int)$_SESSION['auth_versao']) auth_error(401,'Autenticação necessária.');
    return $user;
}

function garden_access(PDO $pdo, int $id, int $userId, bool $write=false, bool $owner=false, bool $lock=false): array
{
    $s=$pdo->prepare('SELECT h.*,uh.papel FROM horta h JOIN usuario_horta uh ON uh.id_horta=h.id_horta WHERE h.id_horta=? AND uh.id_usuario=? AND h.arquivada_em IS NULL' . ($lock?' FOR UPDATE':''));
    $s->execute([$id,$userId]); $row=$s->fetch();
    if (!$row) auth_error(404,'Horta não encontrada ou sem acesso.');
    if (($write && !in_array($row['papel'],['gestor','proprietario'],true)) || ($owner && $row['papel'] !== 'proprietario')) auth_error(403,'Você não tem permissão para esta operação.');
    return $row;
}

function garden_values(PDO $pdo, array $data, ?array $old=null): array
{
    $name=array_key_exists('nome',$data)?auth_string($data,'nome',200):($old['nome']??null);
    if ($name===null) auth_error(422,'Informe o nome da horta.');
    $size=$old['tamanho_m2']??null;
    if (array_key_exists('tamanho_m2',$data)) {
        $size=$data['tamanho_m2'];
        if ($size!==null && (!is_scalar($size) || is_bool($size) || !preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D',(string)$size) || (float)$size<=0)) auth_error(422,'Informe um tamanho positivo, com até duas casas decimais.');
    }
    $location=array_key_exists('localizacao',$data)?auth_string($data,'localizacao',200,false):($old['localizacao']??null);
    $cep=$old['id_cep']??null;
    if (array_key_exists('cep',$data)) {
        if ($data['cep']===null || $data['cep']==='') $cep=null;
        else {
            if (!is_string($data['cep']) || !preg_match('/\A[0-9]{5}-?[0-9]{3}\z/D',$data['cep'])) auth_error(422,'CEP deve ter oito dígitos.');
            $s=$pdo->prepare('SELECT id_cep FROM cep WHERE codigo=?'); $s->execute([str_replace('-','',$data['cep'])]); $cep=$s->fetchColumn();
            if (!$cep) auth_error(422,'CEP não cadastrado no catálogo de endereços. Deixe vazio ou solicite seu cadastro.');
        }
    }
    return [$name,$size,$location===''?null:$location,$cep];
}

function garden_structure(PDO $pdo, int $gardenId, mixed $input): void
{
    if ($input===null) return; // Ausência não apaga instalações existentes.
    if (!$input instanceof stdClass) auth_error(422,'Estrutura inválida.');
    $data=(array)$input;
    if (array_diff(array_keys($data),['id_estrutura','nome','tipo','qnt_unidade'])) auth_error(422,'Campos de estrutura não permitidos.');
    $name=auth_string($data,'nome',100);
    $type=auth_string($data,'tipo',100,false);
    $quantity=$data['qnt_unidade']??1;
    if (!is_int($quantity) || $quantity<1 || $quantity>65535) auth_error(422,'Quantidade de unidades deve estar entre 1 e 65535.');
    if (array_key_exists('id_estrutura',$data)) {
        $id=garden_id($data['id_estrutura']);
        $s=$pdo->prepare('SELECT id_estrutura FROM estrutura WHERE id_estrutura=? AND id_horta=? FOR UPDATE'); $s->execute([$id,$gardenId]);
        if (!$s->fetchColumn()) auth_error(422,'A estrutura deve pertencer a esta horta.');
        $s=$pdo->prepare('UPDATE estrutura SET nome=?,tipo=?,qnt_unidade=? WHERE id_estrutura=? AND id_horta=?');
        $s->execute([$name,$type===''?null:$type,$quantity,$id,$gardenId]);
    } else {
        $s=$pdo->prepare('INSERT INTO estrutura (id_horta,nome,tipo,qnt_unidade) VALUES (?,?,?,?)'); $s->execute([$gardenId,$name,$type===''?null:$type,$quantity]);
    }
}

/** Carrega relações em lote; não permite listar dados de hortas não autorizadas. */
function garden_hydrate(PDO $pdo, array $rows): array
{
    if (!$rows) return [];
    $ids=array_column($rows,'id_horta'); $marks=implode(',',array_fill(0,count($ids),'?'));
    $s=$pdo->prepare("SELECT uh.id_horta,u.id_usuario,u.nome FROM usuario_horta uh JOIN usuario u ON u.id_usuario=uh.id_usuario WHERE uh.id_horta IN ($marks) AND uh.papel='proprietario' AND u.ativo=1 AND u.excluido_em IS NULL ORDER BY u.id_usuario"); $s->execute($ids);
    $owners=[]; foreach($s->fetchAll() as $r) $owners[$r['id_horta']][]=['id_usuario'=>(int)$r['id_usuario'],'nome'=>$r['nome']];
    $s=$pdo->prepare("SELECT id_estrutura,id_horta,nome,tipo,qnt_unidade FROM estrutura WHERE id_horta IN ($marks) ORDER BY id_estrutura"); $s->execute($ids);
    $structures=[]; foreach($s->fetchAll() as $r) $structures[$r['id_horta']][]=$r;
    $s=$pdo->prepare("SELECT hc.id_horta,c.id_cultura,c.nome FROM horta_cultura hc JOIN cultura c ON c.id_cultura=hc.id_cultura WHERE hc.id_horta IN ($marks) ORDER BY c.nome");$s->execute($ids);
    $cultures=[];foreach($s->fetchAll() as $r)$cultures[$r['id_horta']][]=['id_cultura'=>(int)$r['id_cultura'],'nome'=>$r['nome']];
    foreach($rows as &$row) {
        $row['id_horta']=(int)$row['id_horta'];
        $row['total_plantas']=(int)$row['total_plantas'];
        $row['responsaveis']=$owners[$row['id_horta']]??[];
        $row['estruturas']=$structures[$row['id_horta']]??[];
        $row['culturas']=$cultures[$row['id_horta']]??[];
        $row['pode_editar']=in_array($row['papel'],['gestor','proprietario'],true);
        $row['pode_excluir']=$row['papel']==='proprietario';
    }
    unset($row);
    return $rows;
}

function garden_select_sql(): string
{
    return 'SELECT h.id_horta,h.nome,h.tamanho_m2,h.localizacao,h.criado_em,c.codigo AS cep,ci.nome AS cidade,e.sigla AS estado,uh.papel,
        (SELECT COUNT(*) FROM planta p WHERE p.id_horta=h.id_horta AND p.arquivada_em IS NULL) AS total_plantas
        FROM horta h JOIN usuario_horta uh ON uh.id_horta=h.id_horta
        LEFT JOIN cep c ON c.id_cep=h.id_cep LEFT JOIN cidade ci ON ci.id_cidade=c.id_cidade LEFT JOIN estado e ON e.id_estado=ci.id_estado
        WHERE uh.id_usuario=? AND h.arquivada_em IS NULL';
}

function garden_read(PDO $pdo,int $id,int $userId): array
{
    $s=$pdo->prepare(garden_select_sql().' AND h.id_horta=?'); $s->execute([$userId,$id]); $rows=garden_hydrate($pdo,$s->fetchAll());
    if (!$rows) auth_error(404,'Horta não encontrada ou sem acesso.');
    return $rows[0];
}

function garden_save(array $user,array $data,?int $id=null): array
{
    $pdo=database_connection(); $pdo->beginTransaction();
    try {
        garden_lock_user($pdo);
        $old=$id!==null?garden_access($pdo,$id,(int)$user['id_usuario'],true,false,true):null;
        [$name,$size,$location,$cep]=garden_values($pdo,$data,$old);
        if ($id===null) {
            if (isset($data['estrutura']) && $data['estrutura'] instanceof stdClass && property_exists($data['estrutura'],'id_estrutura')) auth_error(422,'Uma nova horta deve criar sua própria estrutura.');
            $s=$pdo->prepare('INSERT INTO horta (nome,tamanho_m2,localizacao,id_cep) VALUES (?,?,?,?)'); $s->execute([$name,$size,$location,$cep]); $id=(int)$pdo->lastInsertId();
            $s=$pdo->prepare("INSERT INTO usuario_horta (id_usuario,id_horta,papel) VALUES (?,?,'proprietario')"); $s->execute([$user['id_usuario'],$id]);
        } else {
            if (!$data) auth_error(422,'Informe os dados que deseja alterar.');
            $s=$pdo->prepare('UPDATE horta SET nome=?,tamanho_m2=?,localizacao=?,id_cep=? WHERE id_horta=?'); $s->execute([$name,$size,$location,$cep,$id]);
        }
        if (array_key_exists('estrutura',$data)) garden_structure($pdo,$id,$data['estrutura']);
        $result=garden_read($pdo,$id,(int)$user['id_usuario']);
        $pdo->commit(); return $result;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function garden_delete(array $user,int $id,array $data): void
{
    if (($data['confirmacao']??null)!=='EXCLUIR') auth_error(422,'Confirme a exclusão digitando EXCLUIR.');
    $pdo=database_connection(); $pdo->beginTransaction();
    try {
        garden_lock_user($pdo); garden_access($pdo,$id,(int)$user['id_usuario'],false,true,true);
        // Tabelas são uma lista interna fixa; inclui plantas arquivadas e todo o histórico.
        foreach(['planta','estrutura','reservatorio','sensor','monitoramento','consumo_agua','horta_cultura'] as $table) {
            $s=$pdo->prepare("SELECT 1 FROM $table WHERE id_horta=? LIMIT 1"); $s->execute([$id]);
            if($s->fetchColumn()) auth_error(409,'A horta possui plantas, equipamentos, culturas ou histórico relacionado. Remova ou trate esses vínculos antes de excluir.');
        }
        // Associações de acesso são removidas explicitamente; usuários não são excluídos.
        $s=$pdo->prepare('DELETE FROM usuario_horta WHERE id_horta=?'); $s->execute([$id]);
        $s=$pdo->prepare('DELETE FROM horta WHERE id_horta=?'); $s->execute([$id]); $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[1217,1451],true)) auth_error(409,'A horta possui registros relacionados e não pode ser excluída.');
        throw $e;
    }
}
