<?php
declare(strict_types=1);
require_once __DIR__ . '/monitoring.php';
require_once __DIR__ . '/garden_plants.php';
require_once __DIR__ . '/tasks.php';

function operational_scope(int $user, ?int $garden): array
{
    return $garden === null ? [$user] : [$user, $garden];
}

function operational_gardens(PDO $pdo, int $user, ?int $garden): array
{
    // Agregações independentes impedem multiplicar plantas pelo número de tarefas.
    $sql = "SELECT h.id_horta,h.nome,h.localizacao,uh.papel,
        COALESCE(p.total,0) AS plantas,COALESCE(p.atencao,0) AS atencao,COALESCE(p.alerta,0) AS alerta,
        COALESCE(t.pendentes,0) AS pendentes
        FROM usuario_horta uh JOIN horta h ON h.id_horta=uh.id_horta AND h.arquivada_em IS NULL
        LEFT JOIN (
            SELECT p.id_horta,COUNT(*) AS total,SUM(p.status_planta='Atenção') AS atencao,SUM(p.status_planta='Alerta') AS alerta
            FROM planta p JOIN usuario_horta a ON a.id_horta=p.id_horta AND a.id_usuario=?
            WHERE p.arquivada_em IS NULL GROUP BY p.id_horta
        ) p ON p.id_horta=h.id_horta
        LEFT JOIN (
            SELECT id_horta,COUNT(*) AS pendentes FROM horta_tarefa
            WHERE id_responsavel=? AND estado='pendente' GROUP BY id_horta
        ) t ON t.id_horta=h.id_horta AND uh.papel IN ('proprietario','gestor','agricultor')
        WHERE uh.id_usuario=?" . ($garden === null ? '' : ' AND h.id_horta=?') . ' ORDER BY h.nome,h.id_horta';
    $s = $pdo->prepare($sql);
    $s->execute([$user, $user, ...operational_scope($user, $garden)]);
    return array_map(static function (array $row): array {
        foreach (['id_horta', 'plantas', 'atencao', 'alerta', 'pendentes'] as $field) $row[$field] = (int)$row[$field];
        return $row;
    }, $s->fetchAll());
}

function operational_summary(PDO $pdo, int $user, ?int $garden, ?array $gardens = null): array
{
    $gardens ??= operational_gardens($pdo, $user, $garden);
    $s = $pdo->prepare("SELECT
        COALESCE(SUM(t.estado='pendente'),0) AS pendentes,
        COALESCE(SUM(t.estado='em_andamento'),0) AS em_andamento,
        COALESCE(SUM(t.estado='concluida' AND t.concluida_em>=UTC_TIMESTAMP(6)-INTERVAL 7 DAY),0) AS concluidas_recentes
        FROM usuario_horta uh JOIN horta h ON h.id_horta=uh.id_horta AND h.arquivada_em IS NULL
        JOIN horta_tarefa t ON t.id_horta=h.id_horta AND t.id_responsavel=uh.id_usuario
        WHERE uh.id_usuario=? AND uh.papel IN ('proprietario','gestor','agricultor')"
        . ($garden === null ? '' : ' AND h.id_horta=?'));
    $s->execute(operational_scope($user, $garden));
    return ['hortas' => count($gardens), 'atencao' => array_sum(array_column($gardens, 'atencao')),
        'alerta' => array_sum(array_column($gardens, 'alerta')), ...array_map('intval', $s->fetch())];
}

function operational_overview(PDO $pdo, int $user, ?int $garden): array
{
    $gardens = operational_gardens($pdo, $user, null);
    $selected = $garden === null ? $gardens : array_values(array_filter($gardens,
        static fn(array $row): bool => $row['id_horta'] === $garden));
    return ['hortas' => $gardens, 'resumo' => operational_summary($pdo, $user, $garden, $selected)];
}

function operational_page(): int
{
    return isset($_GET['pagina']) ? infra_integer($_GET['pagina'], 1, 100000) : 1;
}

function operational_paginate(PDO $pdo, string $sql, array $params, string $order, int $page): array
{
    $s = $pdo->prepare($sql . $order . ' LIMIT 21 OFFSET ' . (($page - 1) * 20));
    $s->execute($params);
    $rows = $s->fetchAll();
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    return ['itens' => $rows, 'pagina' => $page, 'mais' => $more];
}

function operational_plants(PDO $pdo, int $user, ?int $garden): array
{
    $status = auth_string($_GET, 'estado', 15, false);
    if (!in_array($status, ['', 'todas', 'Normal', 'Atenção', 'Alerta'], true)) auth_error(422, 'Estado de planta inválido.');
    $type = auth_string($_GET, 'tipo', 100, false);
    $sql = 'SELECT p.*,uh.papel,h.nome AS horta,e.nome AS estrutura FROM planta p
        JOIN horta h ON h.id_horta=p.id_horta AND h.arquivada_em IS NULL
        JOIN usuario_horta uh ON uh.id_horta=h.id_horta AND uh.id_usuario=?
        LEFT JOIN estrutura e ON e.id_estrutura=p.id_estrutura AND e.id_horta=p.id_horta
        WHERE p.arquivada_em IS NULL';
    $params = [$user];
    if ($garden !== null) { $sql .= ' AND p.id_horta=?'; $params[] = $garden; }
    if ($status === '') $sql .= " AND p.status_planta IN ('Atenção','Alerta')";
    elseif ($status !== 'todas') { $sql .= ' AND p.status_planta=?'; $params[] = $status; }
    if ($type !== '') { $sql .= ' AND p.tipo=?'; $params[] = $type; }
    $result = operational_paginate($pdo, $sql, $params,
        " ORDER BY CASE p.status_planta WHEN 'Alerta' THEN 0 WHEN 'Atenção' THEN 1 ELSE 2 END,p.atualizado_em DESC,p.id_planta", operational_page());
    $ids = array_column($result['itens'], 'id_planta');
    $latest = [];
    $tasks = [];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT ranked.* FROM (
            SELECT s.id_planta,ms.valor,ms.unidade,ms.tipo_medicao,ms.limite_min,ms.limite_max,m.data_hora,m.origem,
            ROW_NUMBER() OVER (PARTITION BY s.id_planta ORDER BY m.data_hora DESC,ms.id_leitura DESC) AS pos
            FROM sensor s JOIN usuario_horta a ON a.id_horta=s.id_horta AND a.id_usuario=?
            JOIN monitoramento_sensor ms ON ms.id_sensor=s.id_sensor AND ms.id_horta=s.id_horta
            JOIN monitoramento m ON m.id_monitoramento=ms.id_monitoramento AND m.id_horta=ms.id_horta
            WHERE s.id_planta IN ($marks)
        ) ranked WHERE pos=1");
        $s->execute([$user, ...$ids]);
        foreach ($s->fetchAll() as $reading) $latest[$reading['id_planta']] = monitoring_reading($reading)
            + ['atualidade' => operational_freshness($reading['data_hora'])];
        $s = $pdo->prepare("SELECT id_planta,COUNT(*) AS total FROM horta_tarefa WHERE id_responsavel=?
            AND id_planta IN ($marks) GROUP BY id_planta");
        $s->execute([$user, ...$ids]);
        $tasks = array_column($s->fetchAll(), 'total', 'id_planta');
    }
    $result['itens'] = array_map(static function (array $row) use ($latest, $tasks): array {
        $out = garden_plant_public($row);
        return $out + ['horta' => $row['horta'], 'estrutura' => $row['estrutura'],
            'ultima_leitura' => $latest[$row['id_planta']] ?? null,
            'tarefas_proprias' => horta_allows($row['papel'], 'tarefas.proprias') ? (int)($tasks[$row['id_planta']] ?? 0) : 0,
            'medicao_cadastro' => operational_freshness($row['ultima_medicao_em'])];
    }, $result['itens']);
    $s = $pdo->prepare('SELECT DISTINCT p.tipo FROM planta p JOIN usuario_horta uh ON uh.id_horta=p.id_horta AND uh.id_usuario=?
        JOIN horta h ON h.id_horta=p.id_horta AND h.arquivada_em IS NULL
        WHERE p.arquivada_em IS NULL AND p.tipo IS NOT NULL' . ($garden === null ? '' : ' AND p.id_horta=?') . ' ORDER BY p.tipo');
    $s->execute(operational_scope($user, $garden));
    $result['tipos'] = $s->fetchAll(PDO::FETCH_COLUMN);
    return $result;
}

function operational_freshness(?string $date): string
{
    if ($date === null) return 'ausente';
    $when = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    if ($when > new DateTimeImmutable('now +5 minutes', new DateTimeZone('UTC'))) return 'futura';
    return $when < new DateTimeImmutable('-24 hours', new DateTimeZone('UTC')) ? 'desatualizada' : 'recente';
}

function operational_tasks(PDO $pdo, int $user, ?int $garden, ?int $plant = null): array
{
    $state = auth_string($_GET, 'estado', 20, false);
    if (!in_array($state, ['', 'historico', ...TASK_STATES], true)) auth_error(422, 'Estado de tarefa inválido.');
    $sql = task_query() . "
        JOIN usuario_horta uh ON uh.id_horta=h.id_horta AND uh.id_usuario=t.id_responsavel
        WHERE t.id_responsavel=? AND uh.papel IN ('proprietario','gestor','agricultor')";
    $params = [$user];
    if ($garden !== null) { $sql .= ' AND t.id_horta=?'; $params[] = $garden; }
    if ($plant !== null) { $sql .= ' AND t.id_planta=?'; $params[] = $plant; }
    if ($state !== '' && $state !== 'historico') { $sql .= ' AND t.estado=?'; $params[] = $state; }
    $result = operational_paginate($pdo, $sql, $params, ' ORDER BY t.atualizado_em DESC,t.id_tarefa DESC', operational_page());
    $result['itens'] = array_map(static fn(array $row): array => task_public($row, $user, false), $result['itens']);
    return $result;
}

function operational_detail(PDO $pdo, int $user, int $garden, int $plant): array
{
    $access = garden_access($pdo, $garden, $user);
    $row = garden_plant_read($pdo, $garden, $plant);
    $public = garden_plant_public($row);
    $s = $pdo->prepare('SELECT e.nome FROM estrutura e WHERE e.id_estrutura=? AND e.id_horta=?');
    $s->execute([$row['id_estrutura'], $garden]);
    $public['estrutura'] = $s->fetchColumn() ?: null;
    $public['horta'] = $access['nome'];
    $public['medicao_cadastro'] = operational_freshness($row['ultima_medicao_em']);
    $s = $pdo->prepare('SELECT ms.*,s.nome AS sensor,m.origem,m.data_hora,m.medicao AS descricao FROM monitoramento_sensor ms
        JOIN monitoramento m ON m.id_monitoramento=ms.id_monitoramento AND m.id_horta=ms.id_horta
        JOIN sensor s ON s.id_sensor=ms.id_sensor AND s.id_horta=ms.id_horta
        WHERE ms.id_horta=? AND s.id_planta=? ORDER BY m.data_hora DESC,ms.id_leitura DESC LIMIT 21');
    $s->execute([$garden, $plant]);
    $readings = $s->fetchAll();
    $more = count($readings) > 20;
    if ($more) array_pop($readings);
    $last = $readings ? end($readings) : null;
    $cursor = $more ? base64_encode(json_encode([$last['data_hora'], (string)$last['id_leitura']])) : null;
    $readings = array_map(static function (array $reading): array {
        return monitoring_reading($reading) + ['atualidade' => operational_freshness($reading['data_hora'])];
    }, $readings);
    return ['planta' => $public, 'leituras' => $readings, 'proximo_cursor' => $cursor,
        'registros' => operational_records($pdo, $garden, $plant),
        'tarefas' => operational_tasks($pdo, $user, $garden, $plant),
        'pode_criar_tarefa' => horta_allows($access['papel'], 'tarefas.gerenciar'),
        'pode_registrar' => horta_allows($access['papel'], 'registros.criar')];
}

function operational_records(PDO $pdo, int $garden, int $plant): array
{
    garden_plant_read($pdo, $garden, $plant);
    return operational_paginate($pdo, 'SELECT r.id_registro,r.tipo,r.descricao,r.criado_em,u.nome AS autor FROM horta_registro r
        JOIN usuario u ON u.id_usuario=r.id_autor WHERE r.id_horta=? AND r.id_planta=?',
        [$garden, $plant], ' ORDER BY r.id_registro DESC', operational_page());
}
