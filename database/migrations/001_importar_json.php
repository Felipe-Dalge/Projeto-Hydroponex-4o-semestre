<?php
declare(strict_types=1);

// Ferramenta administrativa CLI. Nenhum endpoint web ou arquivo existente é alterado.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__, 2) . '/Hydroponex_2/hydro/Hydroponexteste2/php/config/database.php';
require_once dirname(__DIR__, 2) . '/Hydroponex_2/hydro/Hydroponexteste2/php/services/plant_fields.php';

$options = getopt('', ['arquivo:', 'horta:', 'aplicar']);
$pdo = null;
$locked = false;
try {
    $file = $options['arquivo'] ?? dirname(__DIR__, 2) . '/Hydroponex_2/hydro/Hydroponexteste2/php/storage/plantas.json';
    $horta = filter_var($options['horta'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($horta === false) throw new RuntimeException('Informe --horta=ID de uma horta já cadastrada.');
    $raw = file_get_contents($file);
    if ($raw === false) throw new RuntimeException('Não foi possível ler o arquivo de origem.');
    $hash = hash('sha256', $raw);
    $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('A origem deve ser uma lista JSON.');
    $ids = [];
    $prepared = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Registro inválido.');
        $id = plant_identifier($row['id'] ?? null);
        if (isset($ids[$id])) throw new RuntimeException('ID duplicado na origem.');
        $ids[$id] = true;
        $known = array_intersect_key($row,array_flip(array_merge(PLANT_FIELDS,array_keys(PLANT_ALIASES))));
        if (!array_key_exists('ultima_medicao_em',$known) && isset($row['lastUpdate'])) {
            // Só converter uma data absoluta válida; "Agora"/horário local ficam no payload.
            try { $known['ultima_medicao_em']=plant_timestamp($row['lastUpdate']); }
            catch (InvalidArgumentException) { $known['ultima_medicao_em']=null; }
        }
        $values=array_fill_keys(PLANT_FIELDS,null);$values['status_planta']='Normal';
        $values=array_replace($values,plant_validate($known));
        $prepared[] = [$id,$row,$values];
    }
    echo 'Origem: ' . count($prepared) . " registros; SHA-256 $hash; horta $horta.\n";
    if (!isset($options['aplicar'])) {
        echo "Simulação concluída: dados válidos; nenhuma conexão ou escrita no banco. Use --aplicar para importar.\n";
        exit(0);
    }
    $pdo = database_connection();
    $dsn = database_configuration()['dsn'];
    if ($pdo->query("SELECT versao FROM schema_version WHERE versao = '1.0.0'")->fetchColumn() === false) throw new RuntimeException('Schema 1.0.0 ausente.');
    $lockName = 'hydroponex:json:' . substr(hash('sha256', $dsn . ':' . $horta), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Outra importação está em andamento.');
    $locked = true;
    $pdo->beginTransaction();
    $exists = $pdo->prepare('SELECT id_horta FROM horta WHERE id_horta = ? AND arquivada_em IS NULL FOR UPDATE');
    $exists->execute([$horta]);
    if ($exists->fetchColumn() === false) throw new RuntimeException('Horta inexistente ou arquivada.');
    $previous = $pdo->prepare('SELECT DISTINCT i.origem_sha256 FROM importacao_json i JOIN planta p ON p.id_planta=i.id_planta WHERE p.id_horta=?');
    $previous->execute([$horta]);
    foreach ($previous->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
        if ($oldHash !== $hash) throw new RuntimeException('Esta horta já recebeu outro snapshot. Reconcilie as alterações antes de uma nova importação.');
    }
    $lookup = $pdo->prepare('SELECT p.id_horta FROM importacao_json i JOIN planta p ON p.id_planta=i.id_planta WHERE i.origem_sha256=? AND i.id_origem=?');
    $columns=implode(',',PLANT_FIELDS);$marks=implode(',',array_fill(0,count(PLANT_FIELDS),'?'));
    $insert = $pdo->prepare("INSERT INTO planta (id_horta,$columns) VALUES (?,$marks)");
    $audit = $pdo->prepare('INSERT INTO importacao_json (origem_sha256,id_origem,id_planta,payload) VALUES (?,?,?,?)');
    $imported = $skipped = 0;
    foreach ($prepared as [$id, $row, $values]) {
        $lookup->execute([$hash, $id]);
        $target = $lookup->fetchColumn();
        if ($target !== false) {
            if ((int)$target !== $horta) throw new RuntimeException('Este snapshot já foi importado em outra horta.');
            $skipped++;
            continue;
        }
        foreach (['id_estrutura','id_cultura'] as $field) {
            if ($values[$field]===null) continue;
            $sql=$field==='id_estrutura'?'SELECT 1 FROM estrutura WHERE id_horta=? AND id_estrutura=?':'SELECT 1 FROM horta_cultura WHERE id_horta=? AND id_cultura=?';
            $link=$pdo->prepare($sql);$link->execute([$horta,$values[$field]]);
            if (!$link->fetchColumn()) throw new RuntimeException('Estrutura/cultura da origem não pertence à horta de destino.');
        }
        $insert->execute(array_merge([$horta],array_values($values)));
        $audit->execute([$hash,$id,$pdo->lastInsertId(),json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $imported++;
    }
    $pdo->commit();
    echo "Importação concluída: $imported criados; $skipped já importados. Origem preservada.\n";
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    // Não imprimir DSN, senha, payloads ou exceções PDO com possíveis dados pessoais.
    $message = $error instanceof PDOException ? 'Falha de banco: confira conexão, schema e integridade. Transação revertida.' : $error->getMessage();
    fwrite(STDERR, "Erro: $message\n");
    exit(1);
} finally {
    if ($locked && $pdo instanceof PDO) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
