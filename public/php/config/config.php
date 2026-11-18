<?php
declare(strict_types=1);

// Disponibiliza PDO para futuras APIs; a inclusão não abre conexão.
require_once __DIR__ . '/database.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Sessão de autenticação e token CSRF. Credenciais permanecem somente no servidor.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_lifetime', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($secure) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('HYDROPONEXSESSID');
    session_start();
}

function send_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Usar somente nas APIs que já tiverem sido migradas para MySQL.
function database_for_api(): PDO
{
    try {
        return database_connection();
    } catch (DatabaseConnectionException) {
        send_json(['success' => false, 'message' => 'Serviço de dados temporariamente indisponível.'], 503);
    }
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        send_json(['success' => false, 'message' => 'Token de segurança inválido ou ausente.'], 403);
    }
}

function validate_id(mixed $id): int
{
    if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        send_json(['success' => false, 'message' => 'ID inválido.'], 400);
    }
    return (int) $id;
}

