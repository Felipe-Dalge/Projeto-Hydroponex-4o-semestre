<?php
declare(strict_types=1);

/** Exceção com mensagem pública segura, sem a exceção PDO original encadeada. */
final class DatabaseConnectionException extends RuntimeException {}

/** @internal Nunca registrar a configuração retornada ou enviar seus valores ao cliente. */
function database_configuration(): array
{
    $legacyDsn = getenv('HYDROPONEX_DSN');
    if ($legacyDsn !== false) {
        // Compatibilidade com os comandos da etapa anterior, sem opções DSN arbitrárias.
        if (!str_starts_with($legacyDsn, 'mysql:')) database_connection_failure('invalid_dsn');
        $parts = [];
        foreach (explode(';', substr($legacyDsn, 6)) as $part) {
            if ($part === '') continue;
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || !in_array($pair[0], ['host','port','dbname','charset'], true) || isset($parts[$pair[0]])) {
                database_connection_failure('invalid_dsn');
            }
            $parts[$pair[0]] = $pair[1];
        }
        if (($parts['charset'] ?? null) !== 'utf8mb4') database_connection_failure('invalid_charset');
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? '3306';
        $name = $parts['dbname'] ?? '';
    } else {
        $host = getenv('HYDROPONEX_DB_HOST');
        $host = $host === false ? '127.0.0.1' : $host;
        $port = getenv('HYDROPONEX_DB_PORT');
        $port = $port === false ? '3306' : $port;
        $name = getenv('HYDROPONEX_DB_NAME');
        $name = $name === false ? '' : $name;
    }
    $user = getenv('HYDROPONEX_DB_USER');
    $password = getenv('HYDROPONEX_DB_PASSWORD');
    if (!preg_match('/\A[A-Za-z0-9_.:\-]{1,253}\z/D', $host)
        || !preg_match('/\A[0-9]{1,5}\z/D', $port) || (int)$port < 1 || (int)$port > 65535
        || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name)) {
        database_connection_failure('invalid_address');
    }
    // Ausência de senha é diferente de senha vazia explicitamente configurada.
    if ($user === false || trim($user) === '' || preg_match('/[\x00-\x1f\x7f]/', $user) || $password === false) {
        database_connection_failure('missing_credentials');
    }
    return [
        'dsn' => "mysql:host=$host;port=" . (int)$port . ";dbname=$name;charset=utf8mb4",
        'user' => $user,
        'password' => $password,
    ];
}

/** @internal Categorias são constantes internas; nunca passar texto de exceção ou valores de ambiente. */
function database_connection_failure(string $category): never
{
    error_log('[Hydroponex] database_unavailable category=' . $category);
    throw new DatabaseConnectionException('Banco de dados indisponível. Verifique a configuração e o serviço MySQL.');
}

/**
 * Conexão sob demanda, reutilizada durante a requisição/processo PHP.
 * Não inicia sessão HTTP, não cria tabelas, não migra dados e não abre conexão ao incluir o módulo.
 */
function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) return $connection;

    $config = database_configuration();
    if (!extension_loaded('pdo_mysql')) database_connection_failure('missing_pdo_mysql');
    try {
        $candidate = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
    } catch (PDOException) {
        // Não imprimir/logar getMessage(), DSN, usuário, senha ou stack trace do driver.
        database_connection_failure('connection_failed');
    }

    try {
        $version = (string)$candidate->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (stripos($version, 'mariadb') !== false || !preg_match('/\A\d+\.\d+\.\d+/', $version, $match)
            || version_compare($match[0], '8.0.16', '<')) {
            database_connection_failure('unsupported_server');
        }
        // Apenas opções desta sessão; o servidor e outros clientes não são alterados.
        $candidate->exec("SET SESSION collation_connection = 'utf8mb4_0900_ai_ci'");
        $candidate->exec("SET SESSION time_zone = '+00:00'");
        $candidate->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    } catch (PDOException) {
        database_connection_failure('session_setup_failed');
    }
    $connection = $candidate; // Cache só após conexão e configuração bem-sucedidas.
    return $connection;
}
