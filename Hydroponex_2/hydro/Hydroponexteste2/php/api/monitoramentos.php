<?php
declare(strict_types=1);
require_once __DIR__.'/../services/monitoring.php';
auth_api(['GET','POST'],function():void {
    $user=require_authenticated_user();$uid=(int)$user['id_usuario'];$pdo=database_connection();$h=garden_id($_GET['horta']??null);garden_access($pdo,$h,$uid);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $where=['ms.id_horta=?'];$params=[$h];
        foreach(['sensor'=>'s.id_sensor','planta'=>'s.id_planta'] as $key=>$column)if(isset($_GET[$key])&&$_GET[$key]!=='') {
            $id=garden_id($_GET[$key]);
            if($key==='sensor')monitoring_sensor($pdo,$h,$id);
            else {$s=$pdo->prepare('SELECT 1 FROM planta WHERE id_planta=? AND id_horta=?');$s->execute([$id,$h]);if(!$s->fetchColumn())auth_error(404,'Planta não encontrada nesta horta.');}
            $where[]="$column=?";$params[]=$id;
        }
        $start=isset($_GET['inicio'])&&$_GET['inicio']!==''?monitoring_date($_GET['inicio']):null;
        $end=isset($_GET['fim'])&&$_GET['fim']!==''?monitoring_date($_GET['fim']):null;
        if($start&&$end&&$start>$end)auth_error(422,'Período invertido.');
        if($start){$where[]='m.data_hora>=?';$params[]=$start;}if($end){$where[]='m.data_hora<=?';$params[]=$end;}
        if(isset($_GET['origem'])&&$_GET['origem']!=='') {if(!in_array($_GET['origem'],['manual','sensor','importacao','simulacao'],true))auth_error(422,'Origem inválida.');$where[]='m.origem=?';$params[]=$_GET['origem'];}
        $limit=isset($_GET['limite'])?infra_integer($_GET['limite'],1,100):50;
        if(isset($_GET['cursor'])) {
            if(!is_string($_GET['cursor'])||strlen($_GET['cursor'])>512)auth_error(422,'Cursor inválido.');
            $cursor=json_decode(base64_decode((string)$_GET['cursor'],true)?:'',true);
            if(!is_array($cursor)||count($cursor)!==2||!is_string($cursor[1]??null)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$cursor[1]))auth_error(422,'Cursor inválido.');
            $date=monitoring_date($cursor[0]??null);$where[]='(m.data_hora<? OR (m.data_hora=? AND ms.id_leitura<?))';array_push($params,$date,$date,$cursor[1]);
        }
        $sql='SELECT ms.*,s.nome AS sensor,s.id_planta,m.data_hora,m.registrado_em,m.origem,m.medicao AS descricao FROM monitoramento_sensor ms JOIN monitoramento m ON m.id_monitoramento=ms.id_monitoramento AND m.id_horta=ms.id_horta JOIN sensor s ON s.id_sensor=ms.id_sensor AND s.id_horta=ms.id_horta WHERE '.implode(' AND ',$where).' ORDER BY m.data_hora DESC,ms.id_leitura DESC LIMIT '.($limit+1);
        $s=$pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll();$more=count($rows)>$limit;if($more)array_pop($rows);$last=$rows?end($rows):null;
        send_json(['success'=>true,'data'=>array_map('monitoring_reading',$rows),'proximo_cursor'=>$more?base64_encode(json_encode([$last['data_hora'],(string)$last['id_leitura']])):null]);
    }
    require_csrf();$data=auth_body(['origem','data_hora','descricao','leituras']);
    $origin=$data['origem']??'manual';if(!in_array($origin,['manual','simulacao'],true))auth_error(422,'Esta API registra somente medições manuais ou simulações explícitas.');
    $date=monitoring_date($data['data_hora']??null);
    if(new DateTimeImmutable($date,new DateTimeZone('UTC'))>new DateTimeImmutable('now +5 minutes',new DateTimeZone('UTC')))auth_error(422,'Medição não pode estar no futuro.');
    $description=auth_string($data,'descricao',100,false);$readings=$data['leituras']??null;
    if(!is_array($readings)||!array_is_list($readings)||count($readings)<1||count($readings)>25)auth_error(422,'Envie entre 1 e 25 leituras.');
    $pdo->beginTransaction();
    try {
        garden_lock_user($pdo);garden_access($pdo,$h,$uid,true,false,true);$validated=[];$ids=[];
        foreach($readings as $reading) {
            if(!$reading instanceof stdClass)auth_error(422,'Leitura inválida.');$r=(array)$reading;
            if(array_diff(array_keys($r),['id_sensor','valor','unidade','tipo_medicao']))auth_error(422,'Campo de leitura não permitido.');
            $id=garden_id($r['id_sensor']??null);if(in_array($id,$ids,true))auth_error(422,'Sensor repetido no mesmo evento.');$ids[]=$id;
            $sensor=monitoring_sensor($pdo,$h,$id,true);if($sensor['status']!=='ativo')auth_error(409,'Sensor precisa estar ativo para registrar leituras.');
            if(($r['unidade']??null)!==$sensor['unidade']||($r['tipo_medicao']??null)!==$sensor['tipo'])auth_error(422,'Tipo/unidade devem coincidir com o sensor.');
            $value=measurement_value($r['valor']??null,$sensor['tipo'],$sensor['unidade']);$validated[]=[$id,$value,$sensor];
        }
        $s=$pdo->prepare('INSERT INTO monitoramento (id_horta,id_usuario,origem,medicao,data_hora,registrado_em) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');$s->execute([$h,$uid,$origin,$description?:null,$date]);$mid=$pdo->lastInsertId();
        $s=$pdo->prepare('INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade,limite_min,limite_max) VALUES (?,?,?,?,?,?,?,?)');
        foreach($validated as [$id,$value,$sensor])$s->execute([$mid,$id,$h,$value,$sensor['tipo'],$sensor['unidade'],$sensor['limite_min'],$sensor['limite_max']]);
        $pdo->commit();send_json(['success'=>true,'data'=>['id_monitoramento'=>$mid,'origem'=>$origin,'leituras'=>count($validated)]],201);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
});
