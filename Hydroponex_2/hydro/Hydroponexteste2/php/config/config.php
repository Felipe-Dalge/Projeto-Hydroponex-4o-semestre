<?php
declare(strict_types=1);

// Sessão usada pelo CRUD e pelo token CSRF. Não contém autenticação neste momento.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($secure) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const STORAGE_DIR = __DIR__ . '/../storage';
const PLANTAS_FILE = STORAGE_DIR . '/plantas.json';

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

function ensure_storage(): void
{
    if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0700, true) && !is_dir(STORAGE_DIR)) {
        send_json(['success' => false, 'message' => 'Não foi possível preparar o armazenamento local.'], 500);
    }

    if (!file_exists(PLANTAS_FILE)) {
        $initial = [
            ['id' => 1, 'nome' => 'Alface Americana', 'bancada' => 'Bancada A1', 'temp' => 21.5, 'umidade' => 68, 'nutrientes' => 750, 'agua' => 1.2, 'status' => 'Normal', 'lastUpdate' => 'Agora'],
            ['id' => 2, 'nome' => 'Rúcula Hidropônica', 'bancada' => 'Bancada A2', 'temp' => 24.8, 'umidade' => 52, 'nutrientes' => 620, 'agua' => 1.8, 'status' => 'Atenção', 'lastUpdate' => 'Agora'],
            ['id' => 3, 'nome' => 'Manjericão Italiano', 'bancada' => 'Bancada B1', 'temp' => 28.2, 'umidade' => 42, 'nutrientes' => 450, 'agua' => 2.5, 'status' => 'Alerta', 'lastUpdate' => 'Agora'],
            ['id' => 4, 'nome' => 'Morango Vertical', 'bancada' => 'Bancada C1', 'temp' => 20.1, 'umidade' => 70, 'nutrientes' => 850, 'agua' => 1.1, 'status' => 'Normal', 'lastUpdate' => 'Agora'],
            ['id' => 5, 'nome' => 'Espinafre Nobre', 'bancada' => 'Bancada B2', 'temp' => 22.0, 'umidade' => 65, 'nutrientes' => 780, 'agua' => 1.3, 'status' => 'Normal', 'lastUpdate' => 'Agora'],
        ];
        write_json_file($initial);
    }
}

function read_json_file(): array
{
    ensure_storage();
    $raw = file_get_contents(PLANTAS_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        send_json(['success' => false, 'message' => 'O arquivo local de dados está inválido.'], 500);
    }
    if (!is_array($data)) {
        send_json(['success' => false, 'message' => 'O formato do armazenamento local é inválido.'], 500);
    }
    return array_values($data);
}

function acquire_storage_lock()
{
    ensure_storage_dir_only();
    $handle = fopen(STORAGE_DIR . '/.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        send_json(['success' => false, 'message' => 'Não foi possível bloquear o armazenamento local.'], 500);
    }
    return $handle;
}

function release_storage_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function write_json_file(array $data): void
{
    ensure_storage_dir_only();
    $json = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = tempnam(STORAGE_DIR, 'plantas_');
    if ($tmp === false) {
        send_json(['success' => false, 'message' => 'Não foi possível preparar a gravação.'], 500);
    }
    try {
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, PLANTAS_FILE)) {
            @unlink($tmp);
            send_json(['success' => false, 'message' => 'Não foi possível salvar os dados.'], 500);
        }
        @chmod(PLANTAS_FILE, 0600);
    } catch (Throwable) {
        @unlink($tmp);
        send_json(['success' => false, 'message' => 'Erro ao salvar os dados locais.'], 500);
    }
}

function ensure_storage_dir_only(): void
{
    if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0700, true) && !is_dir(STORAGE_DIR)) {
        send_json(['success' => false, 'message' => 'Não foi possível preparar o armazenamento local.'], 500);
    }
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
    return is_array($data) ? $data : [];
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

function validate_plant(array $input, bool $partial = false): array
{
    $errors = [];
    $out = [];

    if (!$partial || array_key_exists('nome', $input)) {
        $nome = trim((string)($input['nome'] ?? ''));
        if ($nome === '' || strlen($nome) > 100) {
            $errors['nome'] = 'Informe um nome entre 1 e 100 caracteres.';
        } else {
            $out['nome'] = $nome;
        }
    }

    if (!$partial || array_key_exists('bancada', $input)) {
        $bancada = trim((string)($input['bancada'] ?? ''));
        if ($bancada === '' || strlen($bancada) > 100) {
            $errors['bancada'] = 'Informe a bancada/local entre 1 e 100 caracteres.';
        } else {
            $out['bancada'] = $bancada;
        }
    }

    if (!$partial || array_key_exists('temp', $input)) {
        $temp = filter_var($input['temp'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($temp === false || $temp < 0 || $temp > 60) {
            $errors['temp'] = 'A temperatura deve estar entre 0 e 60 °C.';
        } else {
            $out['temp'] = round((float)$temp, 2);
        }
    }

    if (!$partial || array_key_exists('nutrientes', $input)) {
        $nutrientes = filter_var($input['nutrientes'] ?? null, FILTER_VALIDATE_INT);
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
