<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/team_invitations.php';

auth_api(['POST'], function (): void {
    $user = require_authenticated_user();
    require_csrf();
    $data = auth_body(['token', 'acao']);
    $action = $data['acao'] ?? null;
    if (!in_array($action, ['consultar', 'aceitar', 'recusar'], true)) auth_error(422, 'Ação inválida.');
    $pdo = database_connection();
    auth_limit($pdo, 'team-invite-recipient', (string)$user['id_usuario'], 60);
    send_json(['success' => true, 'data' => team_respond($pdo, $data['token'] ?? null, $action)]);
});
