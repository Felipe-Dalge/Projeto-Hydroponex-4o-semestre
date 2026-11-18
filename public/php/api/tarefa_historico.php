<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/tasks.php';
auth_api(['GET'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $garden = garden_id($_GET['horta'] ?? null);
    $id = garden_id($_GET['id_tarefa'] ?? null);
    task_access($pdo, $garden, $id, (int)$user['id_usuario']);
    send_json(['success' => true, 'data' => task_feed($pdo, 'tarefa_historico', $garden, $id, task_page())]);
});
