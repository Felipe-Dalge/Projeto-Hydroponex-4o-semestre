<?php
declare(strict_types=1);
require_once __DIR__.'/../services/infrastructure.php';
auth_api(['GET','PATCH','POST'],function():void {
    $user=require_authenticated_user();$uid=(int)$user['id_usuario'];$pdo=database_connection();$garden=garden_id($_GET['horta']??null);garden_access($pdo,$garden,$uid);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $s=$pdo->prepare('SELECT s.id_sensor,s.nome,s.tipo,s.unidade,s.id_nutriente,n.nome AS nutriente,EXISTS(SELECT 1 FROM monitoramento_sensor ms WHERE ms.id_sensor=s.id_sensor) AS possui_leituras FROM sensor s LEFT JOIN nutriente n ON n.id_nutriente=s.id_nutriente WHERE s.id_horta=? ORDER BY s.id_sensor');$s->execute([$garden]);$sensors=$s->fetchAll();
        $s=$pdo->prepare('SELECT rn.id_aplicacao,rn.id_reservatorio,r.nome AS reservatorio,rn.id_nutriente,n.nome AS nutriente,rn.quantidade,rn.unidade,rn.aplicado_em FROM reservatorio_nutriente rn JOIN reservatorio r ON r.id_reservatorio=rn.id_reservatorio JOIN nutriente n ON n.id_nutriente=rn.id_nutriente WHERE r.id_horta=? ORDER BY rn.aplicado_em DESC,rn.id_aplicacao DESC LIMIT 100');$s->execute([$garden]);$applications=$s->fetchAll();
        $s=$pdo->prepare('SELECT ms.id_leitura,s.id_sensor,s.nome AS sensor,n.nome AS nutriente,ms.valor,ms.unidade,m.data_hora,m.origem FROM monitoramento_sensor ms JOIN sensor s ON s.id_sensor=ms.id_sensor JOIN monitoramento m ON m.id_monitoramento=ms.id_monitoramento JOIN nutriente n ON n.id_nutriente=s.id_nutriente WHERE ms.id_horta=? ORDER BY m.data_hora DESC,ms.id_leitura DESC LIMIT 20');$s->execute([$garden]);
        send_json(['success'=>true,'data'=>['sensores'=>$sensors,'aplicacoes'=>$applications,'leituras'=>$s->fetchAll()]]);
    }
    require_csrf();$isSensor=$_SERVER['REQUEST_METHOD']==='PATCH';
    $data=auth_body($isSensor?['id_sensor','id_nutriente']:['id_reservatorio','id_nutriente','quantidade','unidade','aplicado_em']);
    $nutrient=isset($data['id_nutriente'])?garden_id($data['id_nutriente']):null;
    if($isSensor&&!array_key_exists('id_nutriente',$data))auth_error(422,'Informe o nutriente ou valor nulo.');
    if(!$isSensor&&$nutrient===null)auth_error(422,'Informe o nutriente da aplicação.');
    $pdo->beginTransaction();
    try {
        garden_lock_user($pdo);garden_access($pdo,$garden,$uid,true,false,true);
        if($nutrient!==null)infra_catalog_get($pdo,'nutrientes',$uid,$nutrient,true);
        if($isSensor) {
            $id=garden_id($data['id_sensor']??null);$s=$pdo->prepare('SELECT id_nutriente,tipo FROM sensor WHERE id_sensor=? AND id_horta=? FOR UPDATE');$s->execute([$id,$garden]);$sensor=$s->fetch();if(!$sensor)auth_error(404,'Sensor não encontrado nesta horta.');
            if($nutrient!==null&&$sensor['tipo']!=='nutrientes'&&(string)$sensor['id_nutriente']!==(string)$nutrient)auth_error(422,'Nutriente específico exige sensor do tipo nutrientes.');
            if(($sensor['id_nutriente']===null?null:(int)$sensor['id_nutriente'])!==$nutrient) {
                $s=$pdo->prepare('SELECT 1 FROM monitoramento_sensor WHERE id_sensor=? LIMIT 1 FOR UPDATE');$s->execute([$id]);if($s->fetchColumn())auth_error(409,'Sensor já possui leituras. Alterar seu nutriente reinterpretaria o histórico.');
            }
            $s=$pdo->prepare('UPDATE sensor SET id_nutriente=? WHERE id_sensor=? AND id_horta=?');$s->execute([$nutrient,$id,$garden]);
        } else {
            $id=garden_id($data['id_reservatorio']??null);$s=$pdo->prepare('SELECT 1 FROM reservatorio WHERE id_reservatorio=? AND id_horta=? FOR UPDATE');$s->execute([$id,$garden]);if(!$s->fetchColumn())auth_error(404,'Reservatório não encontrado nesta horta.');
            $amount=infra_decimal($data['quantidade']??null,'quantidade',0,999999999.999,3);if($amount===null||(float)$amount<=0)auth_error(422,'Quantidade aplicada deve ser positiva.');
            $unit=auth_string($data,'unidade',5);if(!in_array($unit,['g','mg','mL','L'],true))auth_error(422,'Unidade deve ser g, mg, mL ou L.');
            try{$date=isset($data['aplicado_em'])?plant_timestamp($data['aplicado_em']):null;}catch(InvalidArgumentException $e){auth_error(422,$e->getMessage());}
            $date??=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $s=$pdo->prepare('INSERT INTO reservatorio_nutriente (id_reservatorio,id_nutriente,quantidade,unidade,aplicado_em,id_usuario) VALUES (?,?,?,?,?,?)');$s->execute([$id,$nutrient,$amount,$unit,$date,$uid]);
        }
        $pdo->commit();send_json(['success'=>true,'message'=>$isSensor?'Vínculo do sensor salvo.':'Aplicação registrada no histórico.'],$isSensor?200:201);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
});
