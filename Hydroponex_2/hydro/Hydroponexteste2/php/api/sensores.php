<?php
declare(strict_types=1);
require_once __DIR__.'/../services/monitoring.php';
auth_api(['GET','POST','PATCH','PUT','DELETE'],function():void {
    $user=require_authenticated_user();$uid=(int)$user['id_usuario'];$pdo=database_connection();$h=garden_id($_GET['horta']??null);$access=garden_access($pdo,$h,$uid);
    $id=isset($_GET['id'])?garden_id($_GET['id']):null;$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET') {
        if($id!==null)send_json(['success'=>true,'data'=>monitoring_sensor($pdo,$h,$id)]);
        $s=$pdo->prepare('SELECT s.*,EXISTS(SELECT 1 FROM monitoramento_sensor ms WHERE ms.id_sensor=s.id_sensor) AS possui_leituras FROM sensor s WHERE s.id_horta=? ORDER BY s.id_sensor');$s->execute([$h]);
        send_json(['success'=>true,'data'=>$s->fetchAll(),'tipos'=>MEASUREMENT_TYPES,'pode_editar'=>in_array($access['papel'],['proprietario','gestor'],true)]);
    }
    require_csrf();$data=auth_body($method==='DELETE'?['confirmacao']:['nome','tipo','unidade','status','id_reservatorio','id_estrutura','id_planta','id_nutriente','limite_min','limite_max']);
    if($method==='POST'&&$id!==null)auth_error(422,'Identificador é gerado pelo servidor.');
    if($method!=='POST'&&$id===null)auth_error(422,'Informe o sensor.');
    if($method==='DELETE'&&($data['confirmacao']??null)!=='EXCLUIR')auth_error(422,'Confirme a exclusão.');
    $pdo->beginTransaction();
    try {
        garden_lock_user($pdo);garden_access($pdo,$h,$uid,true,false,true);$old=$id!==null?monitoring_sensor($pdo,$h,$id,true):null;
        if($method==='DELETE') {
            if($old['possui_leituras'])auth_error(409,'Sensor possui histórico: inative-o para preservar suas leituras.');
            $s=$pdo->prepare('DELETE FROM sensor WHERE id_sensor=? AND id_horta=?');$s->execute([$id,$h]);$pdo->commit();send_json(['success'=>true,'message'=>'Sensor excluído.']);
        }
        $v=sensor_values($pdo,$h,$uid,$data,$old);
        if($method==='POST') {
            $columns=implode(',',array_keys($v));$marks=implode(',',array_fill(0,count($v),'?'));$s=$pdo->prepare("INSERT INTO sensor (id_horta,$columns) VALUES (?,$marks)");$s->execute([$h,...array_values($v)]);$id=(int)$pdo->lastInsertId();
        }else {
            $set=implode(',',array_map(static fn($f)=>$f.'=?',array_keys($v)));$s=$pdo->prepare("UPDATE sensor SET $set WHERE id_sensor=? AND id_horta=?");$s->execute([...array_values($v),$id,$h]);
        }
        $row=monitoring_sensor($pdo,$h,$id);$pdo->commit();send_json(['success'=>true,'data'=>$row],$method==='POST'?201:200);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&in_array((int)($e->errorInfo[1]??0),[1451,1217],true))auth_error(409,'Sensor possui dependências.');throw $e;}
});
