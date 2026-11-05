<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
try {
    if(version_compare(PHP_VERSION,'8.1.0','<'))throw new RuntimeException();
    foreach(['pdo_mysql','session','json'] as $ext)if(!extension_loaded($ext))throw new RuntimeException();
    $pdo=database_connection();$profile=database_server_profile((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    if($profile['driver']!=='mariadb')throw new RuntimeException();
    $r=$pdo->query('SELECT @@check_constraint_checks AS checks_on,@@foreign_key_checks AS fk_on,@@character_set_connection AS charset,@@collation_connection AS collation,@@session.time_zone AS timezone')->fetch();
    if(!(int)$r['checks_on']||!(int)$r['fk_on']||$r['charset']!=='utf8mb4'||$r['collation']!=='utf8mb4_unicode_ci'||$r['timezone']!=='+00:00')throw new RuntimeException();
    echo 'OK PHP '.PHP_VERSION.'; MariaDB '.$profile['version'].'; CHECK/FK ativos; UTF-8; UTC; PDO nativo.'.PHP_EOL;
    require __DIR__.'/test_connection.php';
} catch(Throwable){fwrite(STDERR,"FALHA: requisitos não atendidos. Confira versão testada, extensões, ambiente e serviço; detalhes internos omitidos.\n");exit(1);}
