<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/team_invitations.php';

auth_api(['GET', 'POST', 'PATCH', 'DELETE'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $issuer = (int)$user['id_usuario'];
    $method = $_SERVER['REQUEST_METHOD'];
    $gardens = array_values(array_filter(auth_accessible_gardens($pdo, $issuer),
        static fn(array $garden): bool => horta_allows($garden['papel'], 'membros.gerenciar')));
    if (!$gardens) auth_error(403, 'Sua conta não gerencia acessos a nenhuma horta.');

    if ($method === 'GET') {
        $s = $pdo->prepare("SELECT u.id_usuario,u.nome,u.email,h.id_horta,h.nome AS horta,m.criado_em AS vinculado_em
            FROM usuario_horta dono
            JOIN horta h ON h.id_horta=dono.id_horta AND h.arquivada_em IS NULL
            JOIN usuario_horta m ON m.id_horta=h.id_horta AND m.papel='agricultor'
            JOIN usuario u ON u.id_usuario=m.id_usuario AND u.ativo=1 AND u.excluido_em IS NULL
            WHERE dono.id_usuario=? AND dono.papel='proprietario' ORDER BY u.nome,u.id_usuario,h.nome,h.id_horta");
        $s->execute([$issuer]);
        $members = [];
        foreach ($s->fetchAll() as $row) {
            $key = $row['id_usuario'];
            $members[$key] ??= ['id_usuario' => $key, 'nome' => $row['nome'], 'email' => $row['email'], 'hortas' => []];
            $members[$key]['hortas'][] = ['id_horta' => $row['id_horta'], 'nome' => $row['horta'],
                'vinculado_em' => $row['vinculado_em'], 'estado' => 'ativo'];
        }
        $s = $pdo->prepare("SELECT c.id_convite,c.email,c.papel,c.criado_em,c.expira_em,c.aceito_em,c.recusado_em,c.cancelado_em,
            CASE WHEN c.aceito_em IS NOT NULL THEN 'aceito' WHEN c.recusado_em IS NOT NULL THEN 'recusado'
                 WHEN c.cancelado_em IS NOT NULL THEN 'cancelado' WHEN c.expira_em<=UTC_TIMESTAMP(6) THEN 'expirado'
                 ELSE 'pendente' END AS estado,
            CASE WHEN c.enviado_em IS NOT NULL THEN 'enviado' WHEN c.falhou_em IS NOT NULL THEN 'falhou'
                 WHEN c.tentativa_em<=UTC_TIMESTAMP(6)-INTERVAL 60 SECOND THEN 'inconclusivo' ELSE 'enviando' END AS entrega,
            h.id_horta,h.nome AS horta
            FROM equipe_convite c JOIN equipe_convite_horta a ON a.id_convite=c.id_convite
            LEFT JOIN usuario_horta dono ON dono.id_horta=a.id_horta AND dono.id_usuario=c.id_emissor AND dono.papel='proprietario'
            LEFT JOIN horta h ON h.id_horta=dono.id_horta AND h.arquivada_em IS NULL
            WHERE c.id_emissor=? ORDER BY c.id_convite DESC, a.id_alvo");
        $s->execute([$issuer]);
        $invites = [];
        foreach ($s->fetchAll() as $row) {
            $key = $row['id_convite'];
            $invites[$key] ??= array_diff_key($row, array_flip(['id_horta', 'horta'])) + ['hortas' => [], 'disponivel' => true];
            if ($row['id_horta'] === null) {
                $invites[$key]['disponivel'] = false;
            } else {
                $invites[$key]['hortas'][] = ['id_horta' => $row['id_horta'], 'nome' => $row['horta']];
            }
        }
        send_json(['success' => true, 'data' => [
            'hortas' => $gardens,
            'agricultores' => array_values($members),
            'convites' => array_values(array_filter($invites, static fn(array $invite): bool => (bool)$invite['hortas'])),
        ]]);
    }
    require_csrf();
    if ($method === 'DELETE') {
        $data = auth_body(['id_convite']);
        team_cancel($pdo, $issuer, garden_id($data['id_convite'] ?? null));
        send_json(['success' => true]);
    }
    $delivery = team_prepare_delivery($pdo, $issuer, auth_body($method === 'POST' ? ['email', 'hortas'] : ['id_convite']), $method === 'PATCH');
    send_json(['success' => true, 'data' => team_deliver($pdo, $delivery)], $method === 'POST' ? 201 : 200);
});
