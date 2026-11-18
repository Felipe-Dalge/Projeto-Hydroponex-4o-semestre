<?php
declare(strict_types=1);
require_once __DIR__ . '/collaboration.php';
require_once __DIR__ . '/monitoring.php';

const TASK_STATES = ['pendente', 'em_andamento', 'em_verificacao', 'concluida', 'cancelada'];
const TASK_PRIORITIES = ['baixa', 'media', 'alta', 'critica'];

function task_text(array $data, string $field, int $max, bool $required = false): string
{
    $value = $data[$field] ?? '';
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) auth_error(422, 'Texto inválido para ' . $field . '.');
    $value = trim($value);
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max) auth_error(422, 'Confira o campo ' . $field . '.');
    return $value;
}

function task_query(): string
{
    return "SELECT t.*,h.nome AS horta,u.nome AS responsavel,p.nome AS planta,
        (t.prazo_em<UTC_TIMESTAMP(6) AND t.estado NOT IN ('concluida','cancelada')) AS atrasada,
        (t.id_responsavel IS NOT NULL AND NOT EXISTS(
            SELECT 1 FROM usuario_horta a JOIN usuario au ON au.id_usuario=a.id_usuario
            WHERE a.id_horta=t.id_horta AND a.id_usuario=t.id_responsavel
            AND a.papel IN ('proprietario','gestor','agricultor') AND au.ativo=1 AND au.excluido_em IS NULL
        )) AS responsavel_sem_acesso
        FROM horta_tarefa t JOIN horta h ON h.id_horta=t.id_horta AND h.arquivada_em IS NULL
        LEFT JOIN usuario u ON u.id_usuario=t.id_responsavel
        LEFT JOIN planta p ON p.id_planta=t.id_planta AND p.id_horta=t.id_horta";
}

function task_public(array $row, int $user, bool $manage): array
{
    unset($row['problema_hash'], $row['criacao_hash'], $row['conteudo_hash']);
    foreach (['id_tarefa', 'id_horta', 'id_criador', 'versao'] as $field) $row[$field] = (int)$row[$field];
    foreach (['id_responsavel', 'id_planta'] as $field) $row[$field] = $row[$field] === null ? null : (int)$row[$field];
    $row['atrasada'] = (bool)$row['atrasada'];
    $row['responsavel_sem_acesso'] = (bool)$row['responsavel_sem_acesso'];
    $row['pode_editar'] = $manage && !in_array($row['estado'], ['concluida', 'cancelada'], true);
    $row['pode_intervir'] = $row['id_responsavel'] === $user && !$row['responsavel_sem_acesso']
        && in_array($row['estado'], ['em_andamento', 'em_verificacao'], true);
    $row['acoes'] = task_actions($row, $user, $manage);
    return $row;
}

function task_actions(array $task, int $user, bool $manage): array
{
    $own = (int)($task['id_responsavel'] ?? 0) === $user && !$task['responsavel_sem_acesso'];
    $actions = [];
    if ($own && $task['estado'] === 'pendente') $actions[] = 'iniciar';
    if ($own && $task['estado'] === 'em_andamento') $actions[] = 'verificar';
    if ($manage && $task['estado'] === 'em_verificacao') {
        $actions[] = 'concluir';
        $actions[] = 'corrigir';
    }
    if ($manage && $task['estado'] === 'concluida') $actions[] = 'reabrir';
    if ($manage && !in_array($task['estado'], ['concluida', 'cancelada'], true)) $actions[] = 'cancelar';
    return $actions;
}

function task_access(PDO $pdo, int $garden, int $id, int $user, bool $lock = false): array
{
    $access = garden_access($pdo, $garden, $user, 'tarefas.proprias', $lock);
    $manage = horta_allows($access['papel'], 'tarefas.gerenciar');
    $sql = task_query() . ' WHERE t.id_horta=? AND t.id_tarefa=?' . ($manage ? '' : ' AND t.id_responsavel=?');
    $s = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $s->execute($manage ? [$garden, $id] : [$garden, $id, $user]);
    $row = $s->fetch();
    if (!$row) auth_error(404, 'Tarefa não encontrada para este acesso.');
    return [$row, $manage];
}

function task_page(): int
{
    return isset($_GET['pagina']) ? infra_integer($_GET['pagina'], 1, 100000) : 1;
}

function task_feed(PDO $pdo, string $table, int $garden, int $id, int $page = 1): array
{
    $pk = $table === 'tarefa_historico' ? 'id_evento' : 'id_intervencao';
    $s = $pdo->prepare("SELECT f.*,u.nome AS autor FROM $table f LEFT JOIN usuario u ON u.id_usuario=f.id_autor
        WHERE f.id_horta=? AND f.id_tarefa=? ORDER BY f.$pk DESC LIMIT 21 OFFSET " . (($page - 1) * 20));
    $s->execute([$garden, $id]);
    $rows = $s->fetchAll();
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    if ($table === 'tarefa_historico') {
        foreach ($rows as &$row) $row['detalhes'] = json_decode($row['detalhes'], true, 32, JSON_THROW_ON_ERROR);
        unset($row);
    }
    return ['itens' => $rows, 'pagina' => $page, 'mais' => $more];
}

function task_event(PDO $pdo, array $task, int $actor, string $event, array $details): void
{
    $s = $pdo->prepare('INSERT INTO tarefa_historico (id_tarefa,id_horta,id_autor,evento,detalhes) VALUES (?,?,?,?,?)');
    $s->execute([$task['id_tarefa'], $task['id_horta'], $actor, $event,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function task_assignee(PDO $pdo, int $garden, mixed $id): ?int
{
    if ($id === null || $id === '') return null;
    $id = garden_id($id);
    $s = $pdo->prepare("SELECT 1 FROM usuario_horta a JOIN usuario u ON u.id_usuario=a.id_usuario
        WHERE a.id_horta=? AND a.id_usuario=? AND a.papel IN ('proprietario','gestor','agricultor')
        AND u.ativo=1 AND u.excluido_em IS NULL FOR UPDATE");
    $s->execute([$garden, $id]);
    if (!$s->fetchColumn()) auth_error(422, 'Escolha um responsável operacional com acesso atual a esta horta.');
    return $id;
}

function task_reading_id(mixed $value): string
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string)$value)) auth_error(422, 'Identificador de leitura inválido.');
    $id = (string)$value;
    if (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0) auth_error(422, 'Identificador de leitura inválido.');
    return $id;
}

function task_version(array $task, mixed $version): void
{
    if ((!is_int($version) && !is_string($version)) || is_bool($version)
        || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$version)) auth_error(422, 'Informe a versão da tarefa consultada.');
    if ((int)$version !== (int)$task['versao']) auth_error(409, 'A tarefa mudou. Atualize os detalhes antes de tentar novamente.');
}

function task_list(PDO $pdo, int $garden, int $user): array
{
    $access = garden_access($pdo, $garden, $user, 'tarefas.proprias');
    $manage = horta_allows($access['papel'], 'tarefas.gerenciar');
    $where = ['t.id_horta=?'];
    $params = [$garden];
    if (!$manage) { $where[] = 't.id_responsavel=?'; $params[] = $user; }
    foreach (['estado' => TASK_STATES, 'prioridade' => TASK_PRIORITIES] as $field => $allowed) {
        $value = auth_string($_GET, $field, 20, false);
        if ($value === '') continue;
        if (!in_array($value, $allowed, true)) auth_error(422, 'Filtro de tarefa inválido.');
        $where[] = "t.$field=?";
        $params[] = $value;
    }
    if (isset($_GET['responsavel']) && $_GET['responsavel'] !== '') {
        $value = $_GET['responsavel'];
        if ($value === 'sem_responsavel') $where[] = 't.id_responsavel IS NULL';
        else { $where[] = 't.id_responsavel=?'; $params[] = garden_id($value); }
    }
    if (isset($_GET['atrasadas']) && $_GET['atrasadas'] !== '') {
        if ($_GET['atrasadas'] !== '1') auth_error(422, 'Filtro de prazo inválido.');
        $where[] = "t.prazo_em<UTC_TIMESTAMP(6) AND t.estado NOT IN ('concluida','cancelada')";
    }
    $page = task_page();
    $s = $pdo->prepare(task_query() . ' WHERE ' . implode(' AND ', $where)
        . " ORDER BY FIELD(t.prioridade,'critica','alta','media','baixa'),t.prazo_em IS NULL,t.prazo_em,t.id_tarefa DESC LIMIT 21 OFFSET " . (($page - 1) * 20));
    $s->execute($params);
    $rows = $s->fetchAll();
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    $filters = [];
    if ($manage) {
        $s = $pdo->prepare('SELECT DISTINCT u.id_usuario,u.nome FROM horta_tarefa t
            JOIN usuario u ON u.id_usuario=t.id_responsavel WHERE t.id_horta=? ORDER BY u.nome,u.id_usuario');
        $s->execute([$garden]);
        $filters = $s->fetchAll();
    }
    return ['data' => array_map(static fn(array $row): array => task_public($row, $user, $manage), $rows),
        'responsaveis' => $manage ? collaboration_assignees($pdo, $garden) : [],
        'responsaveis_filtro' => $filters,
        'pode_gerenciar' => $manage, 'pagina' => $page, 'mais' => $more];
}

function task_create(PDO $pdo, int $garden, int $user, array $data): array
{
    return collaboration_write($pdo, function () use ($pdo, $garden, $user, $data): array {
        garden_access($pdo, $garden, $user, 'tarefas.gerenciar', true);
        $title = auth_string($data, 'titulo', 160);
        $description = task_text($data, 'descricao', 1000);
        $problem = task_text($data, 'problema', 1000);
        $priority = $data['prioridade'] ?? 'media';
        if (!in_array($priority, TASK_PRIORITIES, true)) auth_error(422, 'Prioridade inválida.');
        $assignee = task_assignee($pdo, $garden, $data['id_responsavel'] ?? null);
        $plant = collaboration_plant($pdo, $garden, $data['id_planta'] ?? null);
        $deadline = ($data['prazo_em'] ?? null) === null || $data['prazo_em'] === '' ? null : monitoring_date($data['prazo_em']);
        $source = $data['origem_problema'] ?? 'manual';
        if (!in_array($source, ['manual', 'estado_planta', 'leitura'], true)) auth_error(422, 'Origem do problema inválida.');
        if (isset($data['id_leitura']) && $source !== 'leitura') auth_error(422, 'A leitura exige a origem leitura.');
        $reading = null;
        $reference = $problem === '' ? null : 'manual:' . ($plant ?? 0) . ':' . strtolower(preg_replace('/\s+/u', ' ', $problem));
        if ($source === 'estado_planta') {
            if ($plant === null) auth_error(422, 'Escolha a planta com problema.');
            $s = $pdo->prepare('SELECT status_planta FROM planta WHERE id_planta=? AND id_horta=?');
            $s->execute([$plant, $garden]);
            $state = $s->fetchColumn();
            if (!in_array($state, ['Atenção', 'Alerta'], true)) auth_error(422, 'A planta precisa estar em Atenção ou Alerta.');
            $reference = 'planta:' . $plant . ':' . $state;
        } elseif ($source === 'leitura') {
            $reading = task_reading_id($data['id_leitura'] ?? null);
            $s = $pdo->prepare('SELECT ms.*,s.id_planta FROM monitoramento_sensor ms
                JOIN sensor s ON s.id_sensor=ms.id_sensor AND s.id_horta=ms.id_horta
                WHERE ms.id_leitura=? AND ms.id_horta=?');
            $s->execute([$reading, $garden]);
            $row = $s->fetch();
            if (!$row || monitoring_status($row) !== 'alerta') auth_error(422, 'Escolha uma leitura fora dos limites desta horta.');
            if ($plant !== null && $plant !== (int)$row['id_planta']) auth_error(422, 'A leitura precisa estar diretamente associada à planta.');
            $plant = collaboration_plant($pdo, $garden, $row['id_planta']);
            $reference = 'leitura:' . $reading;
        }
        $values = [$title, $description, $assignee, $plant, $priority, $deadline, $problem, $source, $reading];
        $contentHash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR), true);
        $requestHash = null;
        if (isset($data['chave_criacao'])) {
            $key = auth_string($data, 'chave_criacao', 80);
            if (!preg_match('/\A[a-zA-Z0-9-]{16,80}\z/', $key)) auth_error(422, 'Chave de criação inválida.');
            $requestHash = hash('sha256', $garden . ':' . $user . ':' . $key, true);
            $s = $pdo->prepare('SELECT id_tarefa,conteudo_hash FROM horta_tarefa WHERE criacao_hash=? FOR UPDATE');
            $s->execute([$requestHash]);
            if ($previous = $s->fetch()) {
                if (!hash_equals($previous['conteudo_hash'], $contentHash)) auth_error(409, 'A chave de criação já foi usada com outros dados.');
                [$task, $manage] = task_access($pdo, $garden, (int)$previous['id_tarefa'], $user);
                return task_public($task, $user, $manage);
            }
        }
        $problemHash = $reference === null ? null : hash('sha256', $reference, true);
        if ($problemHash !== null) {
            $s = $pdo->prepare("SELECT id_tarefa FROM horta_tarefa WHERE id_horta=? AND problema_hash=?
                AND estado NOT IN ('concluida','cancelada') LIMIT 1 FOR UPDATE");
            $s->execute([$garden, $problemHash]);
            if ($s->fetchColumn()) auth_error(409, 'Já existe uma tarefa aberta para esse problema. Consulte a lista antes de criar outra.');
        }
        $s = $pdo->prepare('INSERT INTO horta_tarefa
            (id_horta,id_criador,titulo,descricao,id_responsavel,id_planta,prioridade,prazo_em,problema,origem_problema,id_leitura,problema_hash,criacao_hash,conteudo_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$garden, $user, $title, $description ?: null, $assignee, $plant, $priority, $deadline,
            $problem ?: null, $source, $reading, $problemHash, $requestHash, $contentHash]);
        $id = (int)$pdo->lastInsertId();
        [$task, $manage] = task_access($pdo, $garden, $id, $user);
        task_event($pdo, $task, $user, 'criacao', ['estado' => 'pendente', 'titulo' => $title, 'descricao' => $description,
            'id_planta' => $plant, 'prioridade' => $priority, 'prazo_em' => $deadline, 'problema' => $problem,
            'origem_problema' => $source, 'id_leitura' => $reading]);
        if ($assignee !== null) task_event($pdo, $task, $user, 'atribuicao', ['id_responsavel' => $assignee]);
        return task_public($task, $user, $manage);
    });
}

function task_update(PDO $pdo, int $garden, int $user, array $data): array
{
    return collaboration_write($pdo, function () use ($pdo, $garden, $user, $data): array {
        $id = garden_id($data['id_tarefa'] ?? null);
        [$old, $manage] = task_access($pdo, $garden, $id, $user, true);
        task_version($old, $data['versao'] ?? null);
        $action = $data['acao'] ?? null;
        if (isset($data['estado'])) {
            if ($action !== null) auth_error(422, 'Escolha uma ação por atualização.');
            $action = match ($data['estado']) {
                'em_andamento' => 'iniciar', 'em_verificacao' => 'verificar',
                'concluida' => 'concluir', 'pendente' => 'reabrir', 'cancelada' => 'cancelar',
                default => auth_error(422, 'Estado de tarefa inválido.'),
            };
        }
        $reason = task_text($data, 'justificativa', 1000);
        $changes = [];
        $event = '';
        $details = [];
        if ($action === 'editar') {
            if (!$manage) auth_error(403, 'Somente a gestão da horta pode editar a tarefa.');
            if (in_array($old['estado'], ['concluida', 'cancelada'], true)) auth_error(409, 'Tarefa encerrada. Reabra uma conclusão para alterar seus dados.');
            foreach (['titulo', 'descricao', 'prioridade', 'prazo_em', 'id_responsavel'] as $field) {
                if (!array_key_exists($field, $data)) continue;
                $value = match ($field) {
                    'titulo' => auth_string($data, $field, 160),
                    'descricao' => task_text($data, $field, 1000) ?: null,
                    'id_responsavel' => task_assignee($pdo, $garden, $data[$field]),
                    'prazo_em' => $data[$field] === null || $data[$field] === '' ? null : monitoring_date($data[$field]),
                    'prioridade' => in_array($data[$field], TASK_PRIORITIES, true) ? $data[$field] : auth_error(422, 'Prioridade inválida.'),
                };
                if ((string)$value === (string)$old[$field]) continue;
                $changes[$field] = $value;
                $details[$field] = ['antes' => $old[$field], 'depois' => $value];
            }
            if (!$changes) return task_public($old, $user, $manage);
            if (array_key_exists('id_responsavel', $changes)) {
                if ($reason === '') auth_error(422, 'Informe a justificativa da atribuição.');
                $changes['estado'] = 'pendente';
                $details['estado'] = ['antes' => $old['estado'], 'depois' => 'pendente'];
                $event = $old['id_responsavel'] === null ? 'atribuicao' : 'reatribuicao';
            } else $event = 'edicao';
        } else {
            if (array_intersect(array_keys($data), ['titulo', 'descricao', 'prioridade', 'prazo_em', 'id_responsavel'])) auth_error(422, 'Edite dados administrativos em uma ação separada.');
            if (!is_string($action) || !in_array($action, task_actions($old, $user, $manage), true)) auth_error(403, 'Essa ação não está disponível para seu papel e o estado atual da tarefa.');
            if (in_array($action, ['corrigir', 'reabrir', 'cancelar'], true) && $reason === '') auth_error(422, 'Informe a justificativa desta ação.');
            [$state, $event] = match ($action) {
                'iniciar' => ['em_andamento', 'inicio'],
                'verificar' => ['em_verificacao', 'verificacao'],
                'concluir' => ['concluida', 'aprovacao'],
                'corrigir' => ['em_andamento', 'correcao'],
                'reabrir' => ['pendente', 'reabertura'],
                'cancelar' => ['cancelada', 'cancelamento'],
            };
            if ($action === 'reabrir' && $old['problema_hash'] !== null) {
                $s = $pdo->prepare("SELECT 1 FROM horta_tarefa WHERE id_horta=? AND problema_hash=? AND id_tarefa<>?
                    AND estado NOT IN ('concluida','cancelada') LIMIT 1 FOR UPDATE");
                $s->execute([$garden, $old['problema_hash'], $id]);
                if ($s->fetchColumn()) auth_error(409, 'Outra tarefa desse problema já está aberta.');
            }
            $changes['estado'] = $state;
            $details['estado'] = ['antes' => $old['estado'], 'depois' => $state];
        }
        $details['justificativa'] = $reason ?: null;
        $set = [];
        $params = [];
        foreach ($changes as $field => $value) { $set[] = "$field=?"; $params[] = $value; }
        if (isset($changes['estado'])) $set[] = 'concluida_em=' . ($changes['estado'] === 'concluida' ? 'UTC_TIMESTAMP(6)' : 'NULL');
        $set[] = 'versao=versao+1';
        $s = $pdo->prepare('UPDATE horta_tarefa SET ' . implode(',', $set) . ' WHERE id_tarefa=? AND id_horta=?');
        $s->execute([...$params, $id, $garden]);
        $details['versao'] = (int)$old['versao'] + 1;
        task_event($pdo, $old, $user, $event, $details);
        [$task] = task_access($pdo, $garden, $id, $user);
        return task_public($task, $user, $manage);
    });
}

function task_intervene(PDO $pdo, int $garden, int $user, array $data): array
{
    return collaboration_write($pdo, function () use ($pdo, $garden, $user, $data): array {
        $id = garden_id($data['id_tarefa'] ?? null);
        [$task, $manage] = task_access($pdo, $garden, $id, $user, true);
        task_version($task, $data['versao'] ?? null);
        if (!task_public($task, $user, $manage)['pode_intervir']) auth_error(403, 'Somente o responsável atual pode registrar trabalho em uma tarefa iniciada.');
        $type = $data['tipo'] ?? null;
        if (!in_array($type, ['observacao', 'intervencao'], true)) auth_error(422, 'Tipo de registro inválido.');
        $description = task_text($data, 'descricao', 2000, true);
        $notes = task_text($data, 'observacoes', 1000);
        $date = monitoring_date($data['realizada_em'] ?? null);
        if (new DateTimeImmutable($date, new DateTimeZone('UTC')) > new DateTimeImmutable('now +5 minutes', new DateTimeZone('UTC'))) auth_error(422, 'A realização não pode estar no futuro.');
        $s = $pdo->prepare('INSERT INTO tarefa_intervencao (id_tarefa,id_horta,id_autor,tipo,descricao,observacoes,realizada_em) VALUES (?,?,?,?,?,?,?)');
        $s->execute([$id, $garden, $user, $type, $description, $notes ?: null, $date]);
        $entry = $pdo->lastInsertId();
        $s = $pdo->prepare('UPDATE horta_tarefa SET versao=versao+1,atualizado_em=UTC_TIMESTAMP(6) WHERE id_tarefa=? AND id_horta=?');
        $s->execute([$id, $garden]);
        task_event($pdo, $task, $user, $type, ['id_intervencao' => $entry, 'realizada_em' => $date, 'versao' => (int)$task['versao'] + 1]);
        return ['id_intervencao' => $entry, 'versao' => (int)$task['versao'] + 1];
    });
}
