<?php
declare(strict_types=1);

// Validação pura, compartilhada pela API e pelo importador CLI (sem sessão HTTP).
const PLANT_ALIASES = ['bancada'=>'localizacao','temp'=>'temperatura_agua_c','umidade'=>'umidade_percentual',
    'nutrientes'=>'nivel_nutrientes_ppm','agua'=>'agua_l','status'=>'status_planta'];
const PLANT_FIELDS = ['nome','tipo','localizacao','dias_crescimento','ph_atual','temperatura_agua_c',
    'umidade_percentual','nivel_nutrientes_ppm','nivel_irrigacao','agua_l','status_planta','ultima_medicao_em','id_estrutura','id_cultura'];

function plant_identifier(mixed $id): int
{
    if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id) || (int)$id>4294967295) throw new InvalidArgumentException('Identificador inválido.');
    return (int)$id;
}

function plant_timestamp(mixed $value): ?string
{
    if ($value===null || $value==='') return null;
    if (!is_string($value)) throw new InvalidArgumentException('Data de medição inválida.');
    if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D',$value)) {
        $date=DateTimeImmutable::createFromFormat(str_contains($value,'.')?'!Y-m-d H:i:s.u':'!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    } elseif (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',$value)) {
        if(!str_ends_with($value,'Z')) {
            $offset=substr($value,-6);$hours=(int)substr($offset,1,2);$minutes=(int)substr($offset,4,2);
            if($hours>14 || $minutes>59 || ($hours===14 && $minutes!==0)) throw new InvalidArgumentException('Fuso horário inválido.');
        }
        $date=DateTimeImmutable::createFromFormat(str_contains($value,'.')?'!Y-m-d\TH:i:s.uP':'!Y-m-d\TH:i:sP',$value);
    } else throw new InvalidArgumentException('Informe uma data completa, com hora e fuso, ou deixe a medição vazia.');
    $errors=DateTimeImmutable::getLastErrors();
    if (!$date || ($errors!==false && ($errors['warning_count'] || $errors['error_count'])) || (int)$date->format('Y')<1000 || (int)$date->format('Y')>9999) throw new InvalidArgumentException('Data de medição inválida.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function plant_validate(array $input,bool $partial=false): array
{
    $data=[];
    foreach($input as $field=>$value) {
        $canonical=PLANT_ALIASES[$field]??$field;
        if(!in_array($canonical,PLANT_FIELDS,true)) throw new InvalidArgumentException('Campo de planta não permitido.');
        if(array_key_exists($canonical,$data)) throw new InvalidArgumentException('Envie somente um nome por campo, sem misturar alias e nome do banco.');
        $data[$canonical]=$value;
    }
    foreach(['nome','localizacao'] as $field) if(!$partial && !array_key_exists($field,$data)) throw new InvalidArgumentException('Informe nome e localização da planta.');
    foreach(['nome','tipo','localizacao'] as $field) if(array_key_exists($field,$data)) {
        $value=$data[$field];
        if($field==='tipo' && ($value===null || $value==='')) { $data[$field]=null; continue; }
        if(!is_string($value) || !preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/u',$value) || trim($value)==='' || preg_match_all('/./us',trim($value))>100) throw new InvalidArgumentException('Nome, tipo e localização devem ter até 100 caracteres válidos.');
        $data[$field]=trim($value);
    }
    $ranges=['ph_atual'=>[0,14,2],'temperatura_agua_c'=>[-273.15,999.99,2],'umidade_percentual'=>[0,100,2],
        'nivel_nutrientes_ppm'=>[0,999999999.999,3],'nivel_irrigacao'=>[0,999999999.999,3],'agua_l'=>[0,999999999.999,3]];
    foreach($ranges as $field=>[$min,$max,$precision]) if(array_key_exists($field,$data)) {
        $value=$data[$field];
        if($value===null) continue;
        if((!is_int($value) && !is_float($value) && !is_string($value)) || strlen((string)$value)>30
            || !preg_match('/\A-?[0-9]+(?:\.[0-9]{1,'.$precision.'})?\z/D',(string)$value)
            || !is_finite((float)$value) || (float)$value<$min || (float)$value>$max) throw new InvalidArgumentException("Valor ou precisão inválida para $field.");
    }
    if(array_key_exists('dias_crescimento',$data) && $data['dias_crescimento']!==null) {
        $v=$data['dias_crescimento'];
        if((!is_int($v) && !is_string($v)) || !preg_match('/\A[0-9]{1,5}\z/D',(string)$v) || (int)$v>65535) throw new InvalidArgumentException('Dias de crescimento devem estar entre 0 e 65535.');
        $data['dias_crescimento']=(int)$v;
    }
    if(array_key_exists('status_planta',$data) && !in_array($data['status_planta'],['Normal','Atenção','Alerta'],true)) throw new InvalidArgumentException('Status inválido.');
    if(array_key_exists('ultima_medicao_em',$data)) $data['ultima_medicao_em']=plant_timestamp($data['ultima_medicao_em']);
    foreach(['id_estrutura','id_cultura'] as $field) if(isset($data[$field])) $data[$field]=plant_identifier($data[$field]);
    return $data;
}
