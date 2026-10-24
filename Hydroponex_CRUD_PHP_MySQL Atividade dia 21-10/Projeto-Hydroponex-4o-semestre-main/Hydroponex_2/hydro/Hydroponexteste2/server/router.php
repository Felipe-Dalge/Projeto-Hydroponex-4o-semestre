<?php
/** Router do servidor embutido: bloqueia arquivos internos. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = realpath(dirname(__DIR__));
if (preg_match('~^/(?:php/(?:config|src|storage)/|database/|server/|\.)~', $path) || str_contains($path, "\0")) {
    http_response_code(404); exit('Not Found');
}
$file = realpath($root . $path);
if ($path === '/') { require $root . '/index.html'; return true; }
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
http_response_code(404); echo 'Not Found';
