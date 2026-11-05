<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
$cases=['10.4.32-MariaDB'=>true,'5.5.5-10.4.32-MariaDB'=>true,'10.4.31-MariaDB'=>false,'10.11.1-MariaDB'=>false,'11.4.2-MariaDB'=>false,'8.0.44'=>true,'5.7.44'=>false,'invalid'=>false];
foreach($cases as $version=>$expected){$accepted=true;try{database_server_profile($version);}catch(DatabaseConnectionException){$accepted=false;}if($accepted!==$expected){fwrite(STDERR,"FALHA: perfil de servidor.\n");exit(1);}}
echo "8 verificações de versões aprovadas.\n";
