<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/collaboration.php';

auth_api(['GET', 'PATCH', 'DELETE'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'PATCH') {
        require_csrf();
        $data = auth_body(['codigo']);
        send_json(['success' => true, 'data' => accept_garden_invitation($pdo, $data['codigo'] ?? null)]);
    }
    $gardenId = garden_id($_GET['horta'] ?? null);
    $userId = (int)$user['id_usuario'];
    if ($method === 'GET') {
        garden_access($pdo, $gardenId, $userId, 'membros.gerenciar');
        $s = $pdo->prepare("SELECT id_convite,email,papel,expira_em,CASE
            WHEN aceito_em IS NOT NULL THEN 'aceito' WHEN revogado_em IS NOT NULL THEN 'revogado'
            WHEN expira_em<=UTC_TIMESTAMP(6) THEN 'expirado' ELSE 'pendente' END AS estado
            FROM horta_convite WHERE id_horta=? ORDER BY id_convite DESC");
        $s->execute([$gardenId]);
        send_json(['success' => true, 'data' => $s->fetchAll()]);
    }

    require_csrf();
    $data = auth_body(['id_convite']);
    collaboration_write($pdo, function () use ($pdo, $gardenId, $userId, $data): array {
        garden_access($pdo, $gardenId, $userId, 'membros.gerenciar', true);
        $s = $pdo->prepare('UPDATE horta_convite SET revogado_em=UTC_TIMESTAMP(6)
            WHERE id_convite=? AND id_horta=? AND aceito_em IS NULL AND revogado_em IS NULL');
        $s->execute([garden_id($data['id_convite'] ?? null), $gardenId]);
        if (!$s->rowCount()) auth_error(404, 'Convite pendente não encontrado.');
        return [];
    });
    send_json(['success' => true]);
});
