<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/operational.php';

auth_api(['GET'], function (): void {
    $user = require_authenticated_user();
    $uid = (int)$user['id_usuario'];
    $pdo = database_connection();
    $resource = auth_string($_GET, 'recurso', 20, false) ?: 'resumo';
    $garden = isset($_GET['horta']) && $_GET['horta'] !== '' ? garden_id($_GET['horta']) : null;
    if ($garden !== null) garden_access($pdo, $garden, $uid);
    $data = match ($resource) {
        'resumo' => operational_summary($pdo, $uid, $garden),
        'visao' => operational_overview($pdo, $uid, $garden),
        'hortas' => operational_gardens($pdo, $uid, $garden),
        'plantas' => operational_plants($pdo, $uid, $garden),
        'tarefas' => operational_tasks($pdo, $uid, $garden),
        'detalhes' => operational_detail($pdo, $uid, $garden ?? garden_id(null), garden_id($_GET['planta'] ?? null)),
        'registros' => operational_records($pdo, $garden ?? garden_id(null), garden_id($_GET['planta'] ?? null)),
        default => auth_error(422, 'Consulta operacional inválida.'),
    };
    send_json(['success' => true, 'data' => $data]);
});
