<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/tasks.php';
auth_api(['GET', 'POST'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $garden = garden_id($_GET['horta'] ?? null);
    $uid = (int)$user['id_usuario'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = garden_id($_GET['id_tarefa'] ?? null);
        task_access($pdo, $garden, $id, $uid);
        send_json(['success' => true, 'data' => task_feed($pdo, 'tarefa_intervencao', $garden, $id, task_page())]);
    }
    require_csrf();
    $data = auth_body(['id_tarefa', 'versao', 'tipo', 'descricao', 'observacoes', 'realizada_em'], 49152);
    send_json(['success' => true, 'data' => task_intervene($pdo, $garden, $uid, $data)], 201);
});
