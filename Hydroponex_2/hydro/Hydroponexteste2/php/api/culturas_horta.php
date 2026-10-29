<?php
declare(strict_types=1);
require_once __DIR__.'/../services/infrastructure.php';
auth_api(['GET','POST','DELETE'],function():void {
    $user=require_authenticated_user();$uid=(int)$user['id_usuario'];$pdo=database_connection();$garden=garden_id($_GET['horta']??null);
    garden_access($pdo,$garden,$uid);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $s=$pdo->prepare('SELECT c.*,1 AS em_uso FROM cultura c JOIN horta_cultura hc ON hc.id_cultura=c.id_cultura WHERE hc.id_horta=? ORDER BY c.nome');$s->execute([$garden]);
        send_json(['success'=>true,'data'=>array_map(static fn($r)=>infra_public('culturas',$r,$uid),$s->fetchAll())]);
    }
    require_csrf();$data=auth_body($_SERVER['REQUEST_METHOD']==='DELETE'?['id_cultura','confirmacao']:['id_cultura']);$id=garden_id($data['id_cultura']??null);
    if($_SERVER['REQUEST_METHOD']==='DELETE'&&($data['confirmacao']??null)!=='DESVINCULAR')auth_error(422,'Confirme a desvinculação da cultura.');
    $pdo->beginTransaction();
    try {
        garden_lock_user($pdo);garden_access($pdo,$garden,$uid,true,false,true);infra_catalog_get($pdo,'culturas',$uid,$id,true);
        $s=$pdo->prepare('SELECT 1 FROM horta_cultura WHERE id_horta=? AND id_cultura=? FOR UPDATE');$s->execute([$garden,$id]);$exists=(bool)$s->fetchColumn();
        if($_SERVER['REQUEST_METHOD']==='DELETE') {
            if(!$exists)auth_error(404,'Cultura não vinculada a esta horta.');
            $s=$pdo->prepare('DELETE FROM horta_cultura WHERE id_horta=? AND id_cultura=?');$s->execute([$garden,$id]);
        } elseif(!$exists) { $s=$pdo->prepare('INSERT INTO horta_cultura (id_horta,id_cultura) VALUES (?,?)');$s->execute([$garden,$id]); }
        $pdo->commit();send_json(['success'=>true,'message'=>$_SERVER['REQUEST_METHOD']==='DELETE'?'Cultura desvinculada.':'Cultura habilitada nesta horta.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&in_array((int)($e->errorInfo[1]??0),[1217,1451],true))auth_error(409,'A cultura ainda está associada a plantas, inclusive arquivadas. Preserve os vínculos antes de desvincular.');throw $e;}
});
