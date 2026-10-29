<?php
declare(strict_types=1);
// Diagnóstico administrativo somente leitura; não disponibilizar como endpoint HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';

$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    echo "Uso: php database/test_connection.php [--schema]\n";
    echo "Use HYDROPONEX_DB_HOST/PORT/NAME/USER/PASSWORD; alternativa: HYDROPONEX_DSN + USER/PASSWORD.\n";
    exit(0);
}
if (array_diff($args, ['--schema']) !== []) {
    fwrite(STDERR, "Argumento inválido. Use --help.\n");
    exit(2);
}
try {
    $pdo = database_connection();
    if ($pdo !== database_connection()
        || (int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
        || (bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
        || (int)$pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) !== PDO::FETCH_ASSOC) {
        throw new RuntimeException('Configuração PDO divergente.');
    }
    $text = "Conexão Hydroponex 🌱 ' ; --";
    $statement = $pdo->prepare('SELECT :texto AS texto, @@character_set_connection AS charset, @@collation_connection AS collation, @@session.time_zone AS fuso');
    $statement->execute(['texto' => $text]);
    $row = $statement->fetch();
    if (!$row || $row['texto'] !== $text || $row['charset'] !== 'utf8mb4'
        || $row['collation'] !== 'utf8mb4_0900_ai_ci' || $row['fuso'] !== '+00:00') {
        throw new RuntimeException('Configuração de sessão divergente.');
    }
    $statement->closeCursor();
    echo "OK: MySQL compatível; PDO reutilizado, exceções e consultas preparadas nativas.\n";
    echo "OK: parâmetro com acentos/emoji preservado; utf8mb4 e sessão UTC.\n";
    if (in_array('--schema', $args, true)) {
        $statement = $pdo->prepare('SELECT versao FROM schema_version WHERE versao = :versao');
        $statement->execute(['versao' => '1.3.0']);
        if ($statement->fetchColumn() !== '1.3.0') throw new RuntimeException('Schema esperado ausente.');
        $statement->closeCursor();
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine='InnoDB' AND table_collation='utf8mb4_0900_ai_ci'")->fetchColumn();
        if ((int)$count !== 21) throw new RuntimeException('Inventário do schema divergente.');
        echo "OK: schema 1.3.0 e 21 tabelas InnoDB/utf8mb4 encontrados.\n";
    }
} catch (DatabaseConnectionException $error) {
    fwrite(STDERR, 'FALHA: ' . $error->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    error_log('[Hydroponex] database_diagnostic_failed');
    fwrite(STDERR, "FALHA: diagnóstico do banco não concluído. Confira instalação do schema e configuração do servidor.\n");
    exit(1);
}
