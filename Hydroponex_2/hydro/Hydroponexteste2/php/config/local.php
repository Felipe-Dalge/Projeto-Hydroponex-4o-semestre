<?php
declare(strict_types=1);
// O marcador contém apenas o caminho; segredos ficam fora de htdocs.
$marker=__DIR__.'/installation.local.php';
try { if(is_file($marker)) {
    $path=@include $marker;
    if(!is_string($path)||!is_file($path))throw new RuntimeException('Configuração privada indisponível. Execute o instalador novamente.');
    $public=realpath(dirname(__DIR__,2));$real=realpath($path);
    if(!$real||str_contains(strtolower(str_replace(DIRECTORY_SEPARATOR,'/',$real)),'/htdocs/')||strncasecmp($real,$public.DIRECTORY_SEPARATOR,strlen($public)+1)===0)throw new RuntimeException('Configuração privada em local inválido.');
    $settings=json_decode((string)@file_get_contents($real),true,16,JSON_THROW_ON_ERROR);
    foreach(['HYDROPONEX_DB_HOST','HYDROPONEX_DB_PORT','HYDROPONEX_DB_NAME','HYDROPONEX_DB_USER','HYDROPONEX_DB_PASSWORD','HYDROPONEX_AUTH_KEY'] as $key) {
        if(!isset($settings[$key])||!is_string($settings[$key]))throw new RuntimeException('Configuração privada incompleta.');
    }
    $GLOBALS['hydroponex_private_settings']=$settings;
}

} catch (Throwable) { error_log('[Hydroponex] private_configuration_failed'); define('HYDROPONEX_PRIVATE_INVALID', true); }

function hydroponex_setting(string $name): string|false
{
    if(defined('HYDROPONEX_PRIVATE_INVALID'))return false;
    if(isset($GLOBALS['hydroponex_private_settings'])) {
        // O instalador é autoridade única; DSN legado não sobrepõe o destino privado.
        if($name==='HYDROPONEX_DSN')return false;
        return $GLOBALS['hydroponex_private_settings'][$name]??false;
    }
    return getenv($name);
}
