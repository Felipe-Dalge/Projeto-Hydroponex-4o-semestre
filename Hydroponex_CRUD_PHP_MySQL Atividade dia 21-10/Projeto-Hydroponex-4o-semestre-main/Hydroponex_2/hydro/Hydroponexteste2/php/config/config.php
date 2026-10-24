<?php
declare(strict_types=1);
/** Inicialização comum da API: sessão, headers, validação e CSRF. */
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$production = (getenv('APP_ENV') ?: 'local') === 'production';
if ($production && !$https) {
    http_response_code(403);
    exit('HTTPS obrigatório.');
}
if ($production) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('hydroponex_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

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

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        send_json(['success' => false, 'message' => 'JSON inválido.'], 400);
    }
    if (!is_array($data) || array_is_list($data)) {
        send_json(['success' => false, 'message' => 'Envie um objeto JSON válido.'], 400);
    }
    return $data;
}

function require_csrf(): void
{
    // CSRF de dupla camada: sessão e cabeçalho específico da mesma origem.
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided)) { $provided = ''; }
    $expected = $_SESSION['csrf_token'] ?? '';
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($fetchSite === 'cross-site') {
        send_json(['success' => false, 'message' => 'Origem não permitida.'], 403);
    }
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        send_json(['success' => false, 'message' => 'Token de segurança inválido ou ausente.'], 403);
    }
}

function validate_id(mixed $id): int
{
    if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        send_json(['success' => false, 'message' => 'ID inválido.'], 400);
    }
    return (int) $id;
}

function validate_plant(array $input, bool $partial = false): array
{
    $errors = [];
    $out = [];

    if (!$partial || array_key_exists('nome', $input)) {
        $nome = $input['nome'] ?? null;
        $nome = is_string($nome) ? trim($nome) : ''; 
        if ($nome === '' || mb_strlen($nome, 'UTF-8') > 100) {
            $errors['nome'] = 'Informe um nome entre 1 e 100 caracteres.';
        } else {
            $out['nome'] = $nome;
        }
    }

    if (!$partial || array_key_exists('bancada', $input)) {
        $bancada = $input['bancada'] ?? null;
        $bancada = is_string($bancada) ? trim($bancada) : ''; 
        if ($bancada === '' || mb_strlen($bancada, 'UTF-8') > 100) {
            $errors['bancada'] = 'Informe a bancada/local entre 1 e 100 caracteres.';
        } else {
            $out['bancada'] = $bancada;
        }
    }

    if (!$partial || array_key_exists('temp', $input)) {
        $temp = filter_var(is_scalar($input['temp'] ?? null) ? $input['temp'] : null, FILTER_VALIDATE_FLOAT);
        if ($temp === false || $temp < 0 || $temp > 60) {
            $errors['temp'] = 'A temperatura deve estar entre 0 e 60 °C.';
        } else {
            $out['temp'] = round((float)$temp, 2);
        }
    }

    if (!$partial || array_key_exists('nutrientes', $input)) {
        $nutrientes = filter_var(is_scalar($input['nutrientes'] ?? null) ? $input['nutrientes'] : null, FILTER_VALIDATE_INT);
        if ($nutrientes === false || $nutrientes < 0 || $nutrientes > 10000) {
            $errors['nutrientes'] = 'Os nutrientes devem estar entre 0 e 10000 ppm.';
        } else {
            $out['nutrientes'] = (int)$nutrientes;
        }
    }

    if ($errors !== []) {
        send_json(['success' => false, 'message' => 'Dados inválidos.', 'errors' => $errors], 400);
    }
    return $out;
}
