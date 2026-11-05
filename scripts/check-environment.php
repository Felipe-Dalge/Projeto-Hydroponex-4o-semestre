<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(PHP_VERSION_ID<80100){echo 'FAIL';exit(1);}
foreach(['pdo_mysql','session','json'] as $ext)if(!extension_loaded($ext)){echo 'FAIL';exit(1);}
echo 'OK';
