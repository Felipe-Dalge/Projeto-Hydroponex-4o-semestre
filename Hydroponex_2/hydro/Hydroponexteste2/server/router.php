<?php
// Router seguro para o servidor embutido do PHP.
// Execute: php -S localhost:8000 server/router.php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = str_replace('\\', '/', $path);
$projectRoot = dirname(__DIR__);

// Bloqueio também pelo caminho resolvido abaixo, inclusive ../ e URL codificada.
if (preg_match('~^/php/(storage|config|services)(/|$)~i', $path)) {
    http_response_code(404);
    exit('Not Found');
}

$file = realpath($projectRoot . $path);
$root = realpath($projectRoot);
foreach (['storage','config','services'] as $private) {
    $privateRoot = realpath($projectRoot . '/php/' . $private);
    if ($file !== false && $privateRoot !== false && (strcasecmp($file, $privateRoot) === 0 || strncasecmp($file, $privateRoot . DIRECTORY_SEPARATOR, strlen($privateRoot) + 1) === 0)) {
        http_response_code(404); exit('Not Found');
    }
}
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

if ($path === '/') {
    require $projectRoot . '/index.html';
    return true;
}

http_response_code(404);
echo 'Not Found';
