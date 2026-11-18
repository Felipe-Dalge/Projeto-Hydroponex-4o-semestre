<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/team_invitations.php';

auth_api(['GET', 'PATCH', 'DELETE'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $gardenId = garden_id($_GET['horta'] ?? null);
    $userId = (int)$user['id_usuario'];
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        garden_access($pdo, $gardenId, $userId, 'membros.gerenciar');
        $s = $pdo->prepare('SELECT u.id_usuario,u.nome,u.email,uh.papel FROM usuario_horta uh
            JOIN usuario u ON u.id_usuario=uh.id_usuario WHERE uh.id_horta=? AND u.ativo=1 AND u.excluido_em IS NULL ORDER BY u.nome,u.id_usuario');
        $s->execute([$gardenId]);
        send_json(['success' => true, 'data' => $s->fetchAll()]);
    }
    require_csrf();
    $data = auth_body($method === 'PATCH' ? ['id_usuario', 'papel'] : ['id_usuario']);
    collaboration_write($pdo, function () use ($pdo, $gardenId, $userId, $method, $data): array {
        garden_access($pdo, $gardenId, $userId, 'membros.gerenciar', true);
        $memberId = garden_id($data['id_usuario'] ?? null);
        $s = $pdo->prepare('SELECT papel FROM usuario_horta WHERE id_horta=? AND id_usuario=? FOR UPDATE');
        $s->execute([$gardenId, $memberId]);
        $role = $s->fetchColumn();
        if ($role === false) auth_error(404, 'Membro não encontrado nesta horta.');
        if ($role === 'proprietario') auth_error(403, 'A propriedade da horta é protegida nesta operação.');
        if ($method === 'PATCH') {
            $s = $pdo->prepare('UPDATE usuario_horta SET papel=? WHERE id_horta=? AND id_usuario=?');
            $s->execute([collaboration_role($data['papel'] ?? null), $gardenId, $memberId]);
        } else {
            $s = $pdo->prepare('DELETE FROM usuario_horta WHERE id_horta=? AND id_usuario=?');
            $s->execute([$gardenId, $memberId]);
        }
        // Revogar códigos pendentes para que o acesso antigo não possa ser reaberto.
        $s = $pdo->prepare('UPDATE horta_convite c JOIN usuario u ON u.email=c.email
            SET c.revogado_em=UTC_TIMESTAMP(6) WHERE c.id_horta=? AND u.id_usuario=?
            AND c.aceito_em IS NULL AND c.revogado_em IS NULL');
        $s->execute([$gardenId, $memberId]);
        team_cancel_for_member($pdo, $gardenId, $memberId);
        return [];
    });
    send_json(['success' => true]);
});
