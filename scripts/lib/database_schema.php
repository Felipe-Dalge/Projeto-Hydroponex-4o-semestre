<?php
declare(strict_types=1);
function install_sql(string $sql): array {
    $out=[];$part='';$quote=null;$n=strlen($sql);
    for($i=0;$i<$n;$i++) {
        $c=$sql[$i];
        if($quote!==null){$part.=$c;if($c==='\\'&&$i+1<$n){$part.=$sql[++$i];continue;}if($c===$quote){if(($sql[$i+1]??'')===$quote)$part.=$sql[++$i];else $quote=null;}continue;}
        if($c==='-'&&substr($sql,$i,2)==='--'){while($i<$n&&$sql[$i]!=="\n")$i++;$part.="\n";continue;}
        if($c==='/'&&substr($sql,$i,2)==='/*'){$end=strpos($sql,'*/',$i+2);if($end===false)throw new RuntimeException('SQL incompleto.');$i=$end+1;continue;}
        if(in_array($c,["'",'"','`'],true)){$quote=$c;$part.=$c;continue;}
        if($c===';'){if(trim($part)!=='')$out[]=trim($part);$part='';}else $part.=$c;
    }
    if($quote!==null)throw new RuntimeException('SQL incompleto.');if(trim($part)!=='')$out[]=trim($part);return $out;
}
function install_validate(PDO $p,string $schema,string $version = '1.7.0',bool $allowAdditional = false): void {
    preg_match_all('/CREATE TABLE ([a-z_]+)/',$schema,$tables);$actual=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($actual);$expected=$tables[1];sort($expected);
    $missing=array_diff($expected,$actual);
    $v=$p->prepare('SELECT 1 FROM schema_version WHERE versao=?');$v->execute([$version]);
    if($missing||(!$allowAdditional&&$actual!==$expected)||!$v->fetchColumn())throw new RuntimeException('Banco incompatível ou importação parcial. Preserve-o, faça backup e consulte MIGRACAO_MARIADB.md.');
    preg_match_all('/CONSTRAINT ([a-z_]+) /',$schema,$constraints);$names=$p->query('SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    foreach($constraints[1] as $name)if(!in_array($name,$names,true))throw new RuntimeException('Restrição obrigatória ausente. Faça backup e revise o esquema.');
    if((int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (".implode(',',array_map([$p,'quote'],$expected)).") AND engine='InnoDB' AND table_collation='utf8mb4_unicode_ci'")->fetchColumn()!==count($expected))throw new RuntimeException('Engine/collation incompatível.');
    $refs=$p->query('SELECT table_name,column_name,referenced_table_name,referenced_column_name,constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL ORDER BY constraint_name,ordinal_position')->fetchAll();$groups=[];
    foreach($refs as $r)$groups[$r['constraint_name']][]=$r;
    $q=static fn($v)=>'`'.str_replace('`','``',$v).'`';
    foreach($groups as $rows){$join=[];$nonnull=[];foreach($rows as $r){$join[]='a.'.$q($r['column_name']).'=b.'.$q($r['referenced_column_name']);$nonnull[]='a.'.$q($r['column_name']).' IS NOT NULL';}$r=$rows[0];$sql='SELECT 1 FROM '.$q($r['table_name']).' a LEFT JOIN '.$q($r['referenced_table_name']).' b ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$nonnull).' AND b.'.$q($r['referenced_column_name']).' IS NULL LIMIT 1';if($p->query($sql)->fetchColumn())throw new RuntimeException('Existem registros órfãos. Preserve o banco e revise os vínculos.');}
}
