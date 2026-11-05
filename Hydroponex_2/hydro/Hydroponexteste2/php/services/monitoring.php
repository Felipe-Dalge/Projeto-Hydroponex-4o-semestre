<?php
declare(strict_types=1);
require_once __DIR__.'/infrastructure.php';

// Limites físicos/de armazenamento; faixas de cultivo são configuradas por sensor.
const MEASUREMENT_TYPES = [
 'temperatura'=>['unidades'=>['°C'],'min'=>-273.15,'max'=>999.99],
 'ph'=>['unidades'=>['pH'],'min'=>0,'max'=>14],
 'nutrientes'=>['unidades'=>['ppm','mg/L'],'min'=>0,'max'=>999999999.999],
 'umidade'=>['unidades'=>['%'],'min'=>0,'max'=>100],
 'nivel_agua'=>['unidades'=>['L','%'],'min'=>0,'max'=>999999999.999],
 'condutividade'=>['unidades'=>['mS/cm'],'min'=>0,'max'=>1000],
 'luminosidade'=>['unidades'=>['lux'],'min'=>0,'max'=>1000000]
];

function monitoring_date(mixed $value): string
{
    try {$date=plant_timestamp($value);}catch(InvalidArgumentException $e){auth_error(422,$e->getMessage());}
    if($date===null)auth_error(422,'Informe data e horário completos.');
    return $date;
}

function measurement_value(mixed $value,string $type,string $unit,bool $nullable=false): mixed
{
    if(!isset(MEASUREMENT_TYPES[$type])||!in_array($unit,MEASUREMENT_TYPES[$type]['unidades'],true))auth_error(422,'Tipo de leitura ou unidade não suportados.');
    if($value===null&&$nullable)return null;
    $max=$unit==='%'?100:MEASUREMENT_TYPES[$type]['max'];
    $v=infra_decimal($value,'medição/limite',MEASUREMENT_TYPES[$type]['min'],$max,6);
    if($v===null)auth_error(422,'Informe o valor da leitura.');
    return $v;
}

function monitoring_sensor(PDO $pdo,int $garden,int $id,bool $lock=false): array
{
    $s=$pdo->prepare('SELECT s.*,EXISTS(SELECT 1 FROM monitoramento_sensor ms WHERE ms.id_sensor=s.id_sensor) AS possui_leituras FROM sensor s WHERE s.id_horta=? AND s.id_sensor=?'.($lock?' FOR UPDATE':''));
    $s->execute([$garden,$id]);$row=$s->fetch();if(!$row)auth_error(404,'Sensor não encontrado nesta horta.');return $row;
}

function sensor_values(PDO $pdo,int $garden,int $uid,array $data,?array $old): array
{
    $fields=['nome','tipo','unidade','status','id_reservatorio','id_estrutura','id_planta','id_nutriente','limite_min','limite_max'];
    $v=array_fill_keys($fields,null);$v['status']='ativo';if($old)$v=array_replace($v,array_intersect_key($old,$v));$v=array_replace($v,$data);
    foreach(['nome','tipo','unidade','status'] as $f)$v[$f]=auth_string($v,$f,in_array($f,['nome','tipo'],true)?100:20);
    $supported=isset(MEASUREMENT_TYPES[$v['tipo']])&&in_array($v['unidade'],MEASUREMENT_TYPES[$v['tipo']]['unidades'],true);
    // Permite inativar/renomear o legado sem atribuir unidades ou semântica novas.
    if(!$supported&&(!$old||$old['tipo']!==$v['tipo']||$old['unidade']!==$v['unidade']||array_intersect(array_keys($data),['limite_min','limite_max'])))auth_error(422,'Tipo de leitura ou unidade não suportados.');
    if(!in_array($v['status'],['ativo','inativo','manutencao'],true))auth_error(422,'Status de sensor inválido.');
    $targets=0;
    foreach(['id_reservatorio'=>'reservatorio','id_estrutura'=>'estrutura','id_planta'=>'planta'] as $f=>$table) {
        if($v[$f]===null)continue;$targets++;$v[$f]=garden_id($v[$f]);
        $archivedAllowed=$old&&$v['status']!=='ativo'&&(string)$old[$f]===(string)$v[$f];
        $s=$pdo->prepare("SELECT $f FROM $table WHERE $f=? AND id_horta=?".($table==='planta'&&!$archivedAllowed?' AND arquivada_em IS NULL':''));
        $s->execute([$v[$f],$garden]);if(!$s->fetchColumn())auth_error(422,'O alvo deve existir e pertencer à mesma horta.');
    }
    if($targets>1)auth_error(422,'Escolha no máximo um alvo: reservatório, estrutura ou planta.');
    if($v['id_nutriente']!==null){$v['id_nutriente']=garden_id($v['id_nutriente']);infra_catalog_get($pdo,'nutrientes',$uid,$v['id_nutriente'],true);if($v['tipo']!=='nutrientes'&&(!$old||(string)$old['id_nutriente']!==(string)$v['id_nutriente']))auth_error(422,'Nutriente específico exige sensor do tipo nutrientes.');}
    if($supported)foreach(['limite_min','limite_max'] as $f)$v[$f]=measurement_value($v[$f],$v['tipo'],$v['unidade'],true);
    if($v['limite_min']!==null&&$v['limite_max']!==null&&(float)$v['limite_min']>(float)$v['limite_max'])auth_error(422,'Limite mínimo não pode superar o máximo.');
    if($old&&$old['possui_leituras'])foreach(['tipo','unidade','id_reservatorio','id_estrutura','id_planta','id_nutriente'] as $f) {
        if((string)($v[$f]??'')!==(string)($old[$f]??''))auth_error(409,'Sensor com histórico não pode mudar tipo, unidade ou alvo. Cadastre outro sensor e inative este.');
    }
    return $v;
}

function monitoring_status(array $row): string
{
    if($row['limite_min']===null&&$row['limite_max']===null)return 'sem_limites';
    return (($row['limite_min']!==null&&(float)$row['valor']<(float)$row['limite_min'])||($row['limite_max']!==null&&(float)$row['valor']>(float)$row['limite_max']))?'alerta':'normal';
}

function monitoring_reading(array $r): array
{
    $r['estado']=monitoring_status($r);
    $r['simulado']=$r['origem']==='simulacao';
    $r['antiga']=new DateTimeImmutable($r['data_hora'],new DateTimeZone('UTC'))<new DateTimeImmutable('-24 hours',new DateTimeZone('UTC'));
    return $r;
}
