<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../public/php/config/database.php';

$pdo = database_connection();
$name = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_tests?\z/', $name)) {
    fwrite(STDERR, "Use uma base exclusiva terminada em _test ou _tests.\n");
    exit(1);
}
$checks = 0;
$reject = static function (string $sql, array $params, array $codes) use ($pdo, &$checks): void {
    $pdo->exec('SAVEPOINT integrity_case');
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
    } catch (PDOException $error) {
        $pdo->exec('ROLLBACK TO SAVEPOINT integrity_case');
        if (!in_array((int)($error->errorInfo[1] ?? 0), $codes, true)) throw $error;
        $checks++;
        return;
    }
    throw new RuntimeException('O banco aceitou uma relação ou valor inválido.');
};

$pdo->beginTransaction();
try {
    $s = $pdo->prepare("INSERT INTO usuario (nome,email,ativo) VALUES ('Integridade',?,0)");
    $s->execute([bin2hex(random_bytes(12)) . '@example.test']);
    $user = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO horta (nome) VALUES ('Teste A')");
    $first = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO horta (nome) VALUES ('Teste B')");
    $second = (int)$pdo->lastInsertId();
    $s = $pdo->prepare("INSERT INTO planta (id_horta,nome,localizacao) VALUES (?,'Teste','A1')");
    $s->execute([$second]);
    $plant = (int)$pdo->lastInsertId();
    $s = $pdo->prepare("INSERT INTO horta_tarefa (id_horta,id_criador,titulo) VALUES (?,?,'Teste')");
    $s->execute([$first, $user]);
    $task = (int)$pdo->lastInsertId();
    $reject('UPDATE horta_tarefa SET id_planta=? WHERE id_tarefa=?', [$plant, $task], [1452]);
    $reject("UPDATE horta_tarefa SET prioridade='urgente' WHERE id_tarefa=?", [$task], [4025, 3819]);
    $reject("UPDATE horta_tarefa SET estado='inexistente' WHERE id_tarefa=?", [$task], [4025, 3819]);
    $reject("INSERT INTO tarefa_historico (id_tarefa,id_horta,id_autor,evento,detalhes) VALUES (?,?,?,'edicao','{}')",
        [$task, $second, $user], [1452]);
    $reject("INSERT INTO tarefa_intervencao (id_tarefa,id_horta,id_autor,tipo,descricao,realizada_em) VALUES (?,?,?,'intervencao','Teste',UTC_TIMESTAMP(6))",
        [$task, $second, $user], [1452]);
    $reject("INSERT INTO tarefa_historico (id_tarefa,id_horta,id_autor,evento,detalhes) VALUES (?,?,?,'edicao','invalid-json')",
        [$task, $first, $user], [4025, 3140]);
    $reject("INSERT INTO tarefa_historico (id_tarefa,id_horta,id_autor,evento,detalhes) VALUES (?,?,NULL,'edicao','{}')",
        [$task, $first], [4025, 3819]);
    $s = $pdo->prepare("INSERT INTO sensor (id_horta,nome,tipo,unidade) VALUES (?,'pH','ph','pH')");
    $s->execute([$second]);
    $sensor = $pdo->lastInsertId();
    $s = $pdo->prepare("INSERT INTO monitoramento (id_horta,id_usuario,origem,data_hora) VALUES (?,?,'manual',UTC_TIMESTAMP(6))");
    $s->execute([$second, $user]);
    $monitoring = $pdo->lastInsertId();
    $s = $pdo->prepare("INSERT INTO monitoramento_sensor (id_monitoramento,id_sensor,id_horta,valor,tipo_medicao,unidade) VALUES (?,?,?,6,'ph','pH')");
    $s->execute([$monitoring, $sensor, $second]);
    $reading = $pdo->lastInsertId();
    $reject('UPDATE horta_tarefa SET id_leitura=? WHERE id_tarefa=?', [$reading, $task], [1452]);
    $s = $pdo->prepare("SELECT 1 FROM horta_tarefa WHERE id_tarefa=? AND id_horta=? AND id_responsavel IS NULL
        AND estado='pendente' AND prioridade='media' AND versao=1");
    $s->execute([$task, $first]);
    if (!$s->fetchColumn()) throw new RuntimeException('Valores padrão da tarefa divergentes.');
    $checks++;
    echo "$checks verificações de integridade de tarefas aprovadas, com transação revertida.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
