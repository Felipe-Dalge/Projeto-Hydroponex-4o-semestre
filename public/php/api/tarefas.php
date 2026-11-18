<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/tasks.php';

auth_api(['GET', 'POST', 'PATCH'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $garden = garden_id($_GET['horta'] ?? null);
    $uid = (int)$user['id_usuario'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) {
            $id = garden_id($_GET['id']);
            [$task, $manage] = task_access($pdo, $garden, $id, $uid);
            send_json(['success' => true, 'data' => task_public($task, $uid, $manage),
                'historico' => task_feed($pdo, 'tarefa_historico', $garden, $id),
                'intervencoes' => task_feed($pdo, 'tarefa_intervencao', $garden, $id),
                'responsaveis' => $manage ? collaboration_assignees($pdo, $garden) : []]);
        }
        send_json(['success' => true, ...task_list($pdo, $garden, $uid)]);
    }
    require_csrf();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = auth_body(['titulo', 'descricao', 'id_responsavel', 'id_planta', 'prioridade', 'prazo_em',
            'problema', 'origem_problema', 'id_leitura', 'chave_criacao'], 49152);
        send_json(['success' => true, 'data' => task_create($pdo, $garden, $uid, $data)], 201);
    }
    $data = auth_body(['id_tarefa', 'versao', 'acao', 'estado', 'titulo', 'descricao', 'id_responsavel',
        'prioridade', 'prazo_em', 'justificativa'], 49152);
    send_json(['success' => true, 'data' => task_update($pdo, $garden, $uid, $data)]);
});
