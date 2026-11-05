<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=sys_get_temp_dir().'/hx-local-test-'.bin2hex(random_bytes(8));
$files=[];$dirs=[$root,$root.'/app',$root.'/app/php',$root.'/app/php/config'];
try {
    foreach($dirs as $dir)if(!mkdir($dir))throw new RuntimeException('Diretório temporário indisponível.');
    $local=$root.'/app/php/config/local.php';copy(__DIR__.'/../../Hydroponex_2/hydro/Hydroponexteste2/php/config/local.php',$local);$files[]=$local;
    $private=$root.'/config.local.json';$files[]=$private;$marker=$root.'/app/php/config/installation.local.php';$files[]=$marker;
    $runner=$root.'/runner.php';$files[]=$runner;
    file_put_contents($runner,'<?php require '.var_export($local,true).'; echo json_encode([hydroponex_setting("HYDROPONEX_DB_PORT"),defined("HYDROPONEX_PRIVATE_INVALID")]);');
    $run=static function()use($runner):array{
        $proc=proc_open([PHP_BINARY,$runner],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['HYDROPONEX_DB_PORT'=>'9999']);
        if(!is_resource($proc))throw new RuntimeException('Processo PHP indisponível.');
        fclose($pipes[0]);$json=stream_get_contents($pipes[1]);$null=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('Carregador falhou.');return json_decode($json,true,16,JSON_THROW_ON_ERROR);
    };
    if($run()!==['9999',false])throw new RuntimeException('Fallback de ambiente alterado.');
    file_put_contents($private,json_encode(['HYDROPONEX_DB_HOST'=>'127.0.0.1','HYDROPONEX_DB_PORT'=>'3306','HYDROPONEX_DB_NAME'=>'hydroponex','HYDROPONEX_DB_USER'=>'fixture','HYDROPONEX_DB_PASSWORD'=>'fixture-only','HYDROPONEX_AUTH_KEY'=>str_repeat('a',64)]));
    file_put_contents($marker,'<?php return '.var_export($private,true).';');
    if($run()!==['3306',false])throw new RuntimeException('Configuração privada não prevalece.');
    file_put_contents($private,'invalid-json');if($run()!==[false,true])throw new RuntimeException('JSON inválido não falhou fechado.');
    file_put_contents($marker,'<?php return '.var_export($root.'/app/public.json',true).';');$public=$root.'/app/public.json';$files[]=$public;file_put_contents($public,'{}');
    if($run()!==[false,true])throw new RuntimeException('Arquivo público não foi rejeitado.');
    echo "4 verificações do carregador privado aprovadas; sem banco ou segredos reais.\n";
}catch(Throwable){fwrite(STDERR,"FALHA: carregador de configuração local.\n");exit(1);}
finally {foreach(array_reverse($files) as $file)if(is_file($file))unlink($file);foreach(array_reverse($dirs) as $dir)if(is_dir($dir))rmdir($dir);}
