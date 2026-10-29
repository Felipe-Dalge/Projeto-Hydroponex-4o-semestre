<?php
declare(strict_types=1);
require_once __DIR__.'/../services/monitoring.php';
auth_api(['GET'],function():void {
    $user=require_authenticated_user();$pdo=database_connection();$h=garden_id($_GET['horta']??null);garden_access($pdo,$h,(int)$user['id_usuario']);
    $s=$pdo->prepare('SELECT status_planta,COUNT(*) AS quantidade FROM planta WHERE id_horta=? AND arquivada_em IS NULL GROUP BY status_planta');$s->execute([$h]);
    $plants=['Normal'=>0,'Atenção'=>0,'Alerta'=>0];foreach($s->fetchAll() as $r)$plants[$r['status_planta']]=(int)$r['quantidade'];
    // Não mistura simulação/importação com registros manuais/de dispositivo.
    $sql="SELECT x.* FROM (SELECT ms.*,m.origem,m.data_hora,m.registrado_em,ROW_NUMBER() OVER(PARTITION BY ms.id_sensor,CASE WHEN m.origem='simulacao' THEN 1 ELSE 0 END ORDER BY m.data_hora DESC,ms.id_leitura DESC) AS pos FROM monitoramento_sensor ms JOIN monitoramento m ON m.id_monitoramento=ms.id_monitoramento AND m.id_horta=ms.id_horta WHERE ms.id_horta=? AND m.origem IN ('manual','sensor','simulacao')) x WHERE x.pos=1";
    $s=$pdo->prepare($sql);$s->execute([$h]);$latest=[];foreach($s->fetchAll() as $r){unset($r['pos']);$latest[$r['id_sensor']][$r['origem']==='simulacao'?'simulacao':'medicao']=monitoring_reading($r);}
    $s=$pdo->prepare('SELECT id_sensor,nome,tipo,unidade,status,limite_min,limite_max,id_planta,id_estrutura,id_reservatorio FROM sensor WHERE id_horta=? ORDER BY id_sensor');$s->execute([$h]);$sensors=[];$alerts=0;
    foreach($s->fetchAll() as $r){$r['ultima_medicao']=$latest[$r['id_sensor']]['medicao']??null;$r['ultima_simulacao']=$latest[$r['id_sensor']]['simulacao']??null;if($r['status']==='ativo'&&($r['ultima_medicao']['estado']??'')==='alerta')$alerts++;$sensors[]=$r;}
    $day=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $s=$pdo->prepare("SELECT data_consumo,volume_l,referencia_solo_l,origem FROM consumo_agua WHERE id_horta=? AND origem<>'simulacao' AND data_consumo>=DATE(?)-INTERVAL 6 DAY AND data_consumo<=DATE(?) ORDER BY data_consumo");$s->execute([$h,$day,$day]);$water=$s->fetchAll();
    $s=$pdo->prepare("SELECT volume_l FROM consumo_agua WHERE id_horta=? AND origem<>'simulacao' AND data_consumo=?");$s->execute([$h,$day]);$today=$s->fetchColumn();
    $s=$pdo->prepare("SELECT DATE_FORMAT(data_consumo,'%Y-%m') AS mes,SUM(volume_l) AS volume_l,SUM(CASE WHEN referencia_solo_l IS NOT NULL THEN referencia_solo_l-volume_l END) AS economia_l,COUNT(*) AS dias_registrados FROM consumo_agua WHERE id_horta=? AND origem<>'simulacao' AND data_consumo>=DATE_FORMAT(DATE(?)-INTERVAL 5 MONTH,'%Y-%m-01') AND data_consumo<=DATE(?) GROUP BY DATE_FORMAT(data_consumo,'%Y-%m') ORDER BY mes");$s->execute([$h,$day,$day]);
    send_json(['success'=>true,'data'=>['plantas'=>$plants,'total_plantas'=>array_sum($plants),'sensores'=>$sensors,'alertas_medicoes'=>$alerts,'consumo_hoje_l'=>$today===false?null:$today,'consumo_diario'=>$water,'consumo_mensal'=>$s->fetchAll(),'gerado_em'=>gmdate('c'),'data_consumo_hoje'=>$day,'fuso_consumo'=>'America/Sao_Paulo','nota_consumo'=>'Simulações excluídas. Legado/importação tem origem não verificada; economia é estimativa, não volume medido.']]);
});
