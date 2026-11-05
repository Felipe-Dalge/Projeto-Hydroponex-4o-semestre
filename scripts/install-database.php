<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require_once __DIR__.'/../Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
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
function install_validate(PDO $p,string $schema): void {
    preg_match_all('/CREATE TABLE ([a-z_]+)/',$schema,$tables);$actual=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($actual);$expected=$tables[1];sort($expected);
    if($actual!==$expected||!$p->query("SELECT 1 FROM schema_version WHERE versao='1.3.0'")->fetchColumn())throw new RuntimeException('Banco incompatível ou importação parcial. Preserve-o, faça backup e consulte MIGRACAO_MARIADB.md.');
    preg_match_all('/CONSTRAINT ([a-z_]+) /',$schema,$constraints);$names=$p->query('SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    foreach($constraints[1] as $name)if(!in_array($name,$names,true))throw new RuntimeException('Restrição obrigatória ausente. Faça backup e revise o esquema.');
    if((int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB' AND table_collation='utf8mb4_unicode_ci'")->fetchColumn()!==21)throw new RuntimeException('Engine/collation incompatível.');
    $refs=$p->query('SELECT table_name,column_name,referenced_table_name,referenced_column_name,constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL ORDER BY constraint_name,ordinal_position')->fetchAll();$groups=[];
    foreach($refs as $r)$groups[$r['constraint_name']][]=$r;
    $q=static fn($v)=>'`'.str_replace('`','``',$v).'`';
    foreach($groups as $rows){$join=[];$nonnull=[];foreach($rows as $r){$join[]='a.'.$q($r['column_name']).'=b.'.$q($r['referenced_column_name']);$nonnull[]='a.'.$q($r['column_name']).' IS NOT NULL';}$r=$rows[0];$sql='SELECT 1 FROM '.$q($r['table_name']).' a LEFT JOIN '.$q($r['referenced_table_name']).' b ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$nonnull).' AND b.'.$q($r['referenced_column_name']).' IS NULL LIMIT 1';if($p->query($sql)->fetchColumn())throw new RuntimeException('Existem registros órfãos. Preserve o banco e revise os vínculos.');}
}
try {
    if(version_compare(PHP_VERSION,'8.1.0','<'))throw new RuntimeException('PHP 8.1 ou superior necessário.');
    foreach(['pdo_mysql','session','json'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('Extensão PHP ausente: '.$ext);
    $in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$port=filter_var($in['port']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if(!$port)throw new RuntimeException('Porta inválida.');
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
    try{$p=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",$in['admin'],$in['password'],$opts);}catch(PDOException){throw new RuntimeException('Acesso administrativo falhou. Confira serviço, porta e credenciais. Nenhuma senha foi registrada.');}
    $profile=database_server_profile((string)$p->getAttribute(PDO::ATTR_SERVER_VERSION));if($profile['driver']!=='mariadb')throw new RuntimeException('Use o MariaDB homologado do XAMPP.');
    $p->exec('SET SESSION foreign_key_checks=ON');$p->exec('SET SESSION check_constraint_checks=ON');$p->exec("SET SESSION time_zone='+00:00'");
    $schema=(string)file_get_contents(__DIR__.'/../database/mariadb/hydroponex_mariadb.sql');
    $exists=(bool)$p->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='hydroponex'")->fetchColumn();
    if(!$exists)$p->exec('CREATE DATABASE hydroponex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$p->exec('USE hydroponex');
    $new=count($p->query('SHOW TABLES')->fetchAll())===0;
    if($new)foreach(install_sql($schema) as $stmt)$p->exec($stmt);
    install_validate($p,$schema);
    $s=$p->prepare("SELECT 1 FROM mysql.user WHERE User='hydroponex_app' AND Host='127.0.0.1'");$s->execute();$account=(bool)$s->fetchColumn();
    $prior=$in['prior']??null;$password=$prior['HYDROPONEX_DB_PASSWORD']??($in['appPassword']??'');
    if(!$account){$password=bin2hex(random_bytes(24));
        // Recuperação de falha parcial: salva o segredo ANTES de criar a conta.
        $recovery=['HYDROPONEX_DB_HOST'=>'127.0.0.1','HYDROPONEX_DB_PORT'=>(string)$port,'HYDROPONEX_DB_NAME'=>'hydroponex','HYDROPONEX_DB_USER'=>'hydroponex_app','HYDROPONEX_DB_PASSWORD'=>$password,'HYDROPONEX_AUTH_KEY'=>$prior['HYDROPONEX_AUTH_KEY']??bin2hex(random_bytes(32)),'demo'=>false];
        $privatePath=$in['privatePath']??'';
        if(!is_string($privatePath)||basename($privatePath)!=='config.local.json'||!is_dir(dirname($privatePath)))throw new RuntimeException('Pasta privada de recuperação ausente.');
        if(is_file($privatePath)&&!copy($privatePath,$privatePath.'.'.gmdate('YmdHis').'.'.bin2hex(random_bytes(3)).'.bak'))throw new RuntimeException('Backup privado falhou. Conta não foi criada.');
        if(file_put_contents($privatePath.'.tmp',json_encode($recovery,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($privatePath.'.tmp',$privatePath))throw new RuntimeException('Não foi possível preservar a configuração privada. Conta não foi criada.');
        $prior=$recovery;$p->exec("CREATE USER 'hydroponex_app'@'127.0.0.1' IDENTIFIED BY ".$p->quote($password));}
    if($password==='')throw new RuntimeException('A conta hydroponex_app já existe. Execute novamente e informe sua senha; ela não será alterada.');
    try{$app=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'hydroponex_app',$password,$opts);}catch(PDOException){throw new RuntimeException('Senha da conta existente não confere. Nenhuma credencial foi alterada.');}
    $p->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON hydroponex.* TO 'hydroponex_app'@'127.0.0.1'");
    $app->exec('USE hydroponex');
    foreach($app->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if(str_contains($grant,'GRANT USAGE ON *.*'))continue;
        if(!preg_match('/^GRANT (SELECT, INSERT, UPDATE, DELETE|SELECT, INSERT, DELETE, UPDATE) ON `?hydroponex`?\.\* TO /',$grant)||str_contains($grant,'WITH GRANT OPTION'))throw new RuntimeException('Conta existente possui privilégios adicionais. Revise os GRANTs manualmente ou use uma conta exclusiva antes de continuar.');
    }
    $key=$prior['HYDROPONEX_AUTH_KEY']??bin2hex(random_bytes(32));if(!is_string($key)||!preg_match('/\A[0-9a-f]{64}\z/i',$key))throw new RuntimeException('Chave existente inválida; não foi substituída.');
    $demo=(bool)($prior['demo']??false);
    if(($in['demo']??false)&&!$demo) {
        $populated=false;foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)if($table!=='schema_version'&&$p->query("SELECT 1 FROM `$table` LIMIT 1")->fetchColumn())$populated=true;
        if($populated)throw new RuntimeException('Demonstração recusada: banco já contém dados. Reexecute sem demonstração; seus dados foram preservados.');
        $pass=$in['demoPassword']??'';if(!is_string($pass)||strlen($pass)<12||strlen($pass)>72)throw new RuntimeException('Senha demonstrativa deve ter de 12 a 72 bytes.');
        $p->beginTransaction();try{foreach(install_sql((string)file_get_contents(__DIR__.'/../database/mariadb/seed_mariadb.sql')) as $stmt){if(in_array(strtoupper($stmt),['START TRANSACTION','COMMIT'],true))continue;$p->exec($stmt);}$s=$p->prepare('UPDATE usuario SET ativo=1,senha_hash=? WHERE id_usuario=1');$s->execute([password_hash($pass,PASSWORD_BCRYPT,['cost'=>12])]);$p->commit();$demo=true;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    $settings=['HYDROPONEX_DB_HOST'=>'127.0.0.1','HYDROPONEX_DB_PORT'=>(string)$port,'HYDROPONEX_DB_NAME'=>'hydroponex','HYDROPONEX_DB_USER'=>'hydroponex_app','HYDROPONEX_DB_PASSWORD'=>$password,'HYDROPONEX_AUTH_KEY'=>$key,'demo'=>$demo];
    echo json_encode(['success'=>true,'settings'=>$settings,'new'=>$new,'version'=>$profile['version']],JSON_THROW_ON_ERROR);
}catch(Throwable $e){$message=$e instanceof PDOException?'Operação administrativa falhou. Verifique privilégios, integridade do MariaDB e importações parciais. Não apague arquivos do banco.':($e instanceof DatabaseConnectionException?'Versão do MariaDB não homologada. Consulte MIGRACAO_MARIADB.md.':$e->getMessage());echo json_encode(['success'=>false,'message'=>$message]);exit(1);}
