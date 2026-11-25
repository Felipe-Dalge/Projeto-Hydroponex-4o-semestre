<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require_once __DIR__ . '/../public/php/config/database.php';
require_once __DIR__ . '/lib/database_schema.php';

function update_write_journal(string $path, array $value): void
{
    $temp = $path . '.tmp';
    if (file_put_contents($temp, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false
        || !rename($temp, $path)) throw new RuntimeException('Não foi possível registrar o progresso da migração.');
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $mode = $input['mode'] ?? 'inspect';
    if (!in_array($mode, ['inspect', 'migrate', 'validate'], true)) throw new RuntimeException('Modo de atualização inválido.');
    $settings = $input['settings'] ?? [];
    $port = filter_var($settings['HYDROPONEX_DB_PORT'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $database = $settings['HYDROPONEX_DB_NAME'] ?? '';
    if (!$port || !is_string($database) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $database)
        || !in_array($settings['HYDROPONEX_DB_HOST'] ?? '', ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('O atualizador precisa da configuração do MariaDB local desta instalação.');
    }
    $source = realpath($input['source'] ?? '');
    if (!$source) throw new RuntimeException('Pasta da versão nova não encontrada.');
    $schema = file_get_contents($source . '/database/mariadb/hydroponex_mariadb.sql');
    if ($schema === false) throw new RuntimeException('Esquema MariaDB ausente no pacote.');
    $manifestPath = $source . '/database/mariadb/migrations/manifest.json';
    // Pacotes anteriores ao atualizador seguem a cadeia conhecida, ainda sem manifesto.
    $manifest = json_decode((string)file_get_contents(is_file($manifestPath) ? $manifestPath : __DIR__ . '/../database/mariadb/migrations/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $target = $manifest['target'];
    if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $target)
        || !str_contains($schema, "'" . $target . "'")) throw new RuntimeException('Versão de banco inconsistente no pacote.');
    $user = $input['admin'] ?? $settings['HYDROPONEX_DB_USER'] ?? '';
    $password = $input['password'] ?? $settings['HYDROPONEX_DB_PASSWORD'] ?? '';
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$database;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $profile = database_server_profile((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    if ($profile['driver'] !== 'mariadb') throw new RuntimeException('Este atualizador usa as migrações MariaDB do XAMPP.');
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec('SET SESSION foreign_key_checks=ON');
    $pdo->exec('SET SESSION check_constraint_checks=ON');
    $pdo->exec('SET SESSION lock_wait_timeout=30');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=30');
    $versions = $pdo->query('SELECT versao FROM schema_version')->fetchAll(PDO::FETCH_COLUMN);
    if (!$versions) throw new RuntimeException('Banco sem versão registrada. Preserve os dados e revise a instalação legada.');
    foreach ($versions as $version) {
        if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version)) throw new RuntimeException('Histórico de versões inválido.');
    }
    usort($versions, 'version_compare');
    $current = end($versions);
    if (version_compare($current, $target, '>')) throw new RuntimeException('O banco instalado é mais novo que o pacote. Use a versão correta do projeto.');
    $plan = [];
    $cursor = $current;
    while ($cursor !== $target) {
        $matches = array_values(array_filter($manifest['migrations'], static fn(array $row): bool => $row['from'] === $cursor));
        if (count($matches) !== 1) throw new RuntimeException('Caminho de migração ausente ou ambíguo.');
        $migration = $matches[0];
        if (!preg_match('/\A[0-9]{3}_[a-z_]+\.sql\z/', $migration['file'])
            || version_compare($migration['to'], $cursor, '<=')
            || version_compare($migration['to'], $target, '>')) throw new RuntimeException('Manifesto de migrações inválido.');
        if (!is_file($source . '/database/mariadb/migrations/' . $migration['file'])) throw new RuntimeException('Arquivo de migração ausente no pacote.');
        $plan[] = $migration;
        $cursor = $migration['to'];
    }
    if ($mode === 'migrate') {
        $journal = $input['journal'] ?? '';
        if (!is_string($journal) || !is_dir(dirname($journal))) throw new RuntimeException('Pasta privada de progresso ausente.');
        if (is_file($journal)) {
            $prior = json_decode((string)file_get_contents($journal), true, 32, JSON_THROW_ON_ERROR);
            if (($prior['status'] ?? '') === 'executing') {
                throw new RuntimeException('Há uma migração interrompida. Revise o progresso e o backup antes de retomar. A execução parcial não será repetida.');
            }
        }
        $s = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $s->execute(['hydroponex-update-' . $database]);
        if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('Outro atualizador está usando este banco.');
        try {
            $lockedVersions = $pdo->query('SELECT versao FROM schema_version')->fetchAll(PDO::FETCH_COLUMN);
            usort($lockedVersions, 'version_compare');
            if (end($lockedVersions) !== $current) {
                throw new RuntimeException('A versão do banco mudou durante a verificação. Execute novamente antes de migrar.');
            }
            foreach ($plan as $migration) {
                update_write_journal($journal, ['status' => 'executing', 'from' => $migration['from'],
                    'to' => $migration['to'], 'file' => $migration['file'], 'started' => gmdate('c')]);
                foreach (install_sql((string)file_get_contents($source . '/database/mariadb/migrations/' . $migration['file'])) as $sql) $pdo->exec($sql);
                $s = $pdo->prepare('SELECT 1 FROM schema_version WHERE versao=?');
                $s->execute([$migration['to']]);
                if (!$s->fetchColumn()) throw new RuntimeException('Migração executada sem registrar a versão esperada.');
                update_write_journal($journal, ['status' => 'completed', 'version' => $migration['to'], 'finished' => gmdate('c')]);
            }
        } finally {
            $s = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute(['hydroponex-update-' . $database]);
        }
    }
    if ($mode !== 'inspect' || !$plan) install_validate($pdo, $schema, $target, true);
    echo json_encode(['success' => true, 'current' => $current, 'target' => $target, 'migrations' => $plan], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $message = $error instanceof PDOException
        ? 'Falha ao acessar ou atualizar o MariaDB. Confira credenciais, versão e integridade. Consulte o backup e o progresso antes de repetir.'
        : ($error instanceof DatabaseConnectionException ? 'Versão MariaDB não homologada para este pacote.' : $error->getMessage());
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit(1);
}
