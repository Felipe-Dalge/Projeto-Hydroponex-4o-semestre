<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

final class AuthApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

function auth_error(int $status, string $message): never { throw new AuthApiException($status, $message); }

function auth_api(array $methods, callable $handler): never
{
    try {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            auth_error(405, 'Método não permitido.');
        }
        $handler();
        throw new RuntimeException('Resposta ausente.');
    } catch (AuthApiException $e) {
        send_json(['success' => false, 'message' => $e->getMessage()], $e->status);
    } catch (DatabaseConnectionException) {
        send_json(['success' => false, 'message' => 'Serviço de dados temporariamente indisponível.'], 503);
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $pdo = database_connection();
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (in_array((int)($e->errorInfo[1] ?? 0),[1213,1205],true)) {
                send_json(['success'=>false,'message'=>'Houve outra operação simultânea. Tente novamente.'],409);
            }
        }
        error_log('[Hydroponex] auth_operation_failed');
        send_json(['success' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
    }
}

function auth_body(array $allowed, int $maxBytes = 8192): array
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) auth_error(415, 'Envie os dados em JSON.');
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) auth_error(413, 'Solicitação muito grande.');
    try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { auth_error(400, 'JSON inválido.'); }
    if (!$object instanceof stdClass) auth_error(400, 'Envie um objeto JSON.');
    $data = (array)$object;
    if (array_diff(array_keys($data), $allowed)) auth_error(422, 'A solicitação contém campos não permitidos.');
    return $data;
}

function auth_string(array $data, string $key, int $max, bool $required = true, bool $multiline = false): string
{
    $value = $data[$key] ?? '';
    $controlCharacters = $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u';
    if (!is_string($value) || !preg_match('//u', $value) || preg_match($controlCharacters, $value)) auth_error(422, 'Informe um valor válido para ' . $key . '.');
    $value = trim($value);
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max) auth_error(422, 'Confira o campo ' . $key . '.');
    return $value;
}

function auth_email(array $data): string
{
    $email = strtolower(auth_string($data, 'email', 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_error(422, 'Informe um e-mail válido.');
    return $email;
}

function auth_password(array $data, string $key, bool $new = false): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || str_contains($value, "\0") || strlen($value) > 72 || strlen($value) < ($new ? 12 : 1)) {
        auth_error(422, $new ? 'A senha deve ter entre 12 e 72 bytes.' : 'Informe a senha.');
    }
    return $value; // Não remover espaços ou alterar a senha recebida.
}

function auth_hash(string $password): string { return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]); }

function auth_key(): string
{
    $key = hydroponex_setting('HYDROPONEX_AUTH_KEY');
    if ($key === false || !preg_match('/\A[0-9a-fA-F]{64}\z/D', $key)) {
        error_log('[Hydroponex] auth_key_missing');
        auth_error(503, 'Serviço de autenticação temporariamente indisponível.');
    }
    return $key;
}

/** Reserva tentativas antes da verificação; persiste entre sessões e processos. */
function auth_limit(PDO $pdo, string $scope, string $identity, int $limit, int $seconds = 900): void
{
    $key = hash_hmac('sha256', $scope . ':' . $identity, auth_key(), true);
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('INSERT INTO auth_limite (chave,janela_em,tentativas) VALUES (?,UTC_TIMESTAMP(6),0) ON DUPLICATE KEY UPDATE chave=chave');
        $s->execute([$key]);
        $s = $pdo->prepare('SELECT tentativas,TIMESTAMPDIFF(SECOND,janela_em,UTC_TIMESTAMP(6)) AS idade FROM auth_limite WHERE chave=? FOR UPDATE');
        $s->execute([$key]); $row = $s->fetch();
        if ((int)$row['idade'] >= $seconds) {
            $s = $pdo->prepare('UPDATE auth_limite SET tentativas=0,janela_em=UTC_TIMESTAMP(6) WHERE chave=?'); $s->execute([$key]);
        } elseif ((int)$row['tentativas'] >= $limit) {
            $pdo->rollBack();
            header('Retry-After: ' . max(1, $seconds - (int)$row['idade']));
            auth_error(429, 'Não foi possível concluir. Aguarde antes de tentar novamente.');
        }
        $s = $pdo->prepare('UPDATE auth_limite SET tentativas=tentativas+1 WHERE chave=?'); $s->execute([$key]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function auth_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; } // Não confiar em X-Forwarded-For do cliente.

function auth_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function auth_destroy(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires'=>time()-42000, 'path'=>$params['path'], 'domain'=>$params['domain'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
    session_destroy();
}

function auth_establish(array $user): void
{
    $area = (int)($_SESSION['id_usuario'] ?? 0) === (int)$user['id_usuario'] ? ($_SESSION['area'] ?? null) : null;
    if (!session_regenerate_id(true)) throw new RuntimeException('Sessão indisponível.');
    $_SESSION = ['id_usuario'=>(int)$user['id_usuario'], 'auth_versao'=>(int)$user['auth_versao'], 'inicio'=>time(), 'atividade'=>time(), 'csrf_token'=>bin2hex(random_bytes(32))];
    if ($area !== null) $_SESSION['area'] = $area;
}

function require_authenticated_user(): array
{
    if (!isset($_SESSION['id_usuario'], $_SESSION['auth_versao'], $_SESSION['inicio'], $_SESSION['atividade'])) auth_error(401, 'Autenticação necessária.');
    if (time() - (int)$_SESSION['atividade'] > 1800 || time() - (int)$_SESSION['inicio'] > 43200) {
        auth_destroy(); auth_error(401, 'Sua sessão expirou. Entre novamente.');
    }
    $pdo = database_connection();
    $s = $pdo->prepare('SELECT * FROM usuario WHERE id_usuario=? AND ativo=1 AND excluido_em IS NULL');
    $s->execute([(int)$_SESSION['id_usuario']]); $user = $s->fetch();
    if (!$user || (int)$user['auth_versao'] !== (int)$_SESSION['auth_versao']) {
        auth_destroy(); auth_error(401, 'Autenticação necessária.');
    }
    $_SESSION['atividade'] = time();
    return $user;
}

function auth_accessible_gardens(PDO $pdo, int $userId): array
{
    $s = $pdo->prepare('SELECT h.id_horta,h.nome,uh.papel FROM usuario_horta uh JOIN horta h ON h.id_horta=uh.id_horta WHERE uh.id_usuario=? AND h.arquivada_em IS NULL ORDER BY h.id_horta');
    $s->execute([$userId]);
    return $s->fetchAll();
}

function auth_choose_area(array $areas, mixed $area): string
{
    if (!in_array($area, ['administrativa', 'agricultor'], true)) auth_error(422, 'Selecione uma área válida.');
    if (!in_array($area, $areas, true)) auth_error(403, 'Sua conta não tem acesso à área selecionada.');
    return $area;
}

function auth_public_user(array $user): array
{
    $gardens = auth_accessible_gardens(database_connection(), (int)$user['id_usuario']);
    $areas = account_areas($user, $gardens);
    $preferred = (int)($_SESSION['id_usuario'] ?? 0) === (int)$user['id_usuario'] ? ($_SESSION['area'] ?? null) : null;
    $area = in_array($preferred, $areas, true) ? $preferred : $areas[0];
    return ['id'=>(int)$user['id_usuario'], 'nome'=>$user['nome'], 'email'=>$user['email'], 'avatar_url'=>$user['avatar_url'], 'id_cep'=>$user['id_cep'], 'numero_endereco'=>$user['numero_endereco'], 'complemento'=>$user['complemento'], 'hortas'=>$gardens,
        'perfil_entrada'=>$user['perfil_entrada'], 'pode_criar_horta'=>account_can_create_garden($user), 'areas'=>$areas, 'area'=>$area];
}

function auth_sensitive_password(PDO $pdo, array $user, array $data): void
{
    auth_limit($pdo, 'sensitive-user', (string)$user['id_usuario'], 5);
    if (!password_verify(auth_password($data, 'senha_atual'), $user['senha_hash'] ?? '')) auth_error(403, 'Não foi possível confirmar sua identidade.');
}
