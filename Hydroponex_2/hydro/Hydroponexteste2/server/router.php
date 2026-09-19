<?php
// Router seguro para o servidor embutido do PHP.
// Execute: php -S localhost:8000 server/router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$projectRoot = dirname(__DIR__);

if (str_starts_with($path, '/php/storage/')) {
    http_response_code(404);
    exit('Not Found');
}

$file = realpath($projectRoot . $path);
$root = realpath($projectRoot);
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

if ($path === '/') {
    require $projectRoot . '/index.html';
    return true;
}

http_response_code(404);
echo 'Not Found';
