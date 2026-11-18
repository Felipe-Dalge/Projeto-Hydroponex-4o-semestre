<?php
declare(strict_types=1);
require_once __DIR__ . '/collaboration.php';
require_once __DIR__ . '/mail.php';

function team_garden_ids(mixed $value): array
{
    if (!is_array($value) || count($value) < 1 || count($value) > 30) auth_error(422, 'Selecione de uma a 30 hortas.');
    $ids = array_values(array_unique(array_map('garden_id', $value)));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function team_targets(PDO $pdo, int $inviteId): array
{
    $s = $pdo->prepare('SELECT id_horta FROM equipe_convite_horta WHERE id_convite=? ORDER BY id_horta');
    $s->execute([$inviteId]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

/** Bloqueia hortas em ordem antes de bloquear um convite. */
function team_lock_gardens(PDO $pdo, array $ids, int $issuer, bool $recipient = false): array
{
    if (!$ids || in_array(null, $ids, true)) auth_error(404, 'Uma ou mais hortas do convite estão indisponíveis.');
    $gardens = [];
    foreach ($ids as $id) {
        try {
            $garden = garden_access($pdo, (int)$id, $issuer, 'membros.gerenciar', true);
        } catch (AuthApiException $e) {
            if (!$recipient) throw $e;
            auth_error(404, 'Convite indisponível para esta conta.');
        }
        $gardens[] = ['id_horta' => (int)$id, 'nome' => $garden['nome']];
    }
    return $gardens;
}

function team_invite(PDO $pdo, int $id, bool $lock = false): array
{
    $s = $pdo->prepare('SELECT c.*, expira_em > UTC_TIMESTAMP(6) AS vigente,
        TIMESTAMPDIFF(SECOND, tentativa_em, UTC_TIMESTAMP(6)) AS segundos
        FROM equipe_convite c WHERE id_convite=?' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute([$id]);
    return $s->fetch() ?: [];
}

function team_invite_open(array $invite): bool
{
    return $invite && $invite['aceito_em'] === null && $invite['recusado_em'] === null && $invite['cancelado_em'] === null;
}

function team_assert_available(PDO $pdo, string $email, array $ids, int $except = 0): void
{
    $members = $pdo->prepare('SELECT 1 FROM usuario_horta uh JOIN usuario u ON u.id_usuario=uh.id_usuario
        WHERE uh.id_horta=? AND u.email=? AND u.ativo=1 AND u.excluido_em IS NULL FOR UPDATE');
    $duplicates = $pdo->prepare('SELECT 1 FROM equipe_convite_horta a JOIN equipe_convite c ON c.id_convite=a.id_convite
        WHERE a.id_horta=? AND c.email=? AND c.id_convite<>? AND c.aceito_em IS NULL AND c.recusado_em IS NULL
        AND c.cancelado_em IS NULL AND c.expira_em>UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE');
    $legacy = $pdo->prepare('SELECT 1 FROM horta_convite WHERE id_horta=? AND email=?
        AND aceito_em IS NULL AND revogado_em IS NULL AND expira_em>UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE');
    foreach ($ids as $id) {
        $members->execute([$id, $email]);
        if ($members->fetchColumn()) auth_error(409, 'O destinatário já tem vínculo com uma das hortas selecionadas.');
        $duplicates->execute([$id, $email, $except]);
        if ($duplicates->fetchColumn()) auth_error(409, 'Já existe um convite aberto para esse e-mail em uma das hortas.');
        $legacy->execute([$id, $email]);
        if ($legacy->fetchColumn()) auth_error(409, 'Há um código antigo aberto. Revogue-o em Trabalho na horta antes de enviar o convite.');
    }
}

function team_prepare_delivery(PDO $pdo, int $issuer, array $data, bool $resend): array
{
    $id = $resend ? garden_id($data['id_convite'] ?? null) : 0;
    $requested = $resend ? [] : team_garden_ids($data['hortas'] ?? null);
    $email = $resend ? '' : auth_email($data);
    auth_limit($pdo, 'team-invite-sender', (string)$issuer, 20, 3600);
    return collaboration_write($pdo, function () use ($pdo, $issuer, $resend, $id, $requested, $email): array {
        $ids = $requested;
        if ($resend) {
            $current = team_invite($pdo, $id);
            if (!$current || (int)$current['id_emissor'] !== $issuer) auth_error(404, 'Convite não encontrado.');
            $ids = team_targets($pdo, $id);
            $email = $current['email'];
        }
        team_lock_gardens($pdo, $ids, $issuer);
        if ($resend) {
            $current = team_invite($pdo, $id, true);
            if (!team_invite_open($current)) auth_error(409, 'Este convite já foi encerrado.');
            if ((int)$current['segundos'] < 60) {
                header('Retry-After: ' . (60 - (int)$current['segundos']));
                auth_error(429, 'Aguarde um minuto entre tentativas de envio.');
            }
        }
        team_assert_available($pdo, $email, $ids, $id);
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token, true);
        if ($resend) {
            $s = $pdo->prepare('UPDATE equipe_convite SET codigo_hash=?, tentativa_em=UTC_TIMESTAMP(6),
                expira_em=UTC_TIMESTAMP(6)+INTERVAL 7 DAY, enviado_em=NULL, falhou_em=NULL WHERE id_convite=?');
            $s->execute([$hash, $id]);
        } else {
            $s = $pdo->prepare('INSERT INTO equipe_convite (id_emissor,email,codigo_hash,expira_em)
                VALUES (?,?,?,UTC_TIMESTAMP(6)+INTERVAL 7 DAY)');
            $s->execute([$issuer, $email, $hash]);
            $id = (int)$pdo->lastInsertId();
            $target = $pdo->prepare('INSERT INTO equipe_convite_horta (id_convite,id_horta) VALUES (?,?)');
            foreach ($ids as $gardenId) $target->execute([$id, $gardenId]);
        }
        return ['id' => $id, 'email' => $email, 'token' => $token, 'hash' => $hash];
    });
}

function team_deliver(PDO $pdo, array $delivery): array
{
    $sent = send_team_invitation_mail($delivery['email'], $delivery['token']);
    $field = $sent ? 'enviado_em' : 'falhou_em';
    $s = $pdo->prepare("UPDATE equipe_convite SET $field=UTC_TIMESTAMP(6) WHERE id_convite=? AND codigo_hash=?");
    $s->execute([$delivery['id'], $delivery['hash']]);
    if (!$sent) auth_error(502, 'O SMTP não confirmou o envio. O convite foi registrado e pode ser reenviado pela Equipe.');
    if (!$s->rowCount()) auth_error(409, 'Uma tentativa mais recente substituiu este link.');
    return ['id_convite' => $delivery['id'], 'entrega' => 'enviado'];
}

function team_cancel(PDO $pdo, int $issuer, int $id): void
{
    collaboration_write($pdo, function () use ($pdo, $issuer, $id): array {
        $invite = team_invite($pdo, $id);
        if (!$invite || (int)$invite['id_emissor'] !== $issuer) auth_error(404, 'Convite não encontrado.');
        team_lock_gardens($pdo, team_targets($pdo, $id), $issuer);
        if (!team_invite_open(team_invite($pdo, $id, true))) auth_error(409, 'Este convite já foi encerrado.');
        $s = $pdo->prepare('UPDATE equipe_convite SET cancelado_em=UTC_TIMESTAMP(6) WHERE id_convite=?');
        $s->execute([$id]);
        return [];
    });
}

function team_respond(PDO $pdo, mixed $token, string $action): array
{
    if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)) auth_error(422, 'Link de convite inválido.');
    $hash = hash('sha256', $token, true);
    return collaboration_write($pdo, function (array $actor) use ($pdo, $hash, $action): array {
        $s = $pdo->prepare('SELECT id_convite,id_emissor FROM equipe_convite WHERE codigo_hash=? AND email=?');
        $s->execute([$hash, $actor['email']]);
        $found = $s->fetch();
        if (!$found) auth_error(404, 'Convite indisponível para esta conta. Entre com o e-mail convidado.');
        $id = (int)$found['id_convite'];
        $issuer = (int)$found['id_emissor'];
        $gardens = team_lock_gardens($pdo, team_targets($pdo, $id), $issuer, true);
        $invite = team_invite($pdo, $id, true);
        $active = $pdo->prepare('SELECT 1 FROM usuario WHERE id_usuario=? AND ativo=1 AND excluido_em IS NULL FOR UPDATE');
        $active->execute([$issuer]);
        if (!team_invite_open($invite) || !$invite['vigente'] || !$invite['enviado_em']
            || !hash_equals($invite['codigo_hash'], $hash) || !hash_equals($invite['email'], strtolower($actor['email']))
            || !$active->fetchColumn()) {
            auth_error(404, 'Convite indisponível para esta conta.');
        }
        if ($action === 'consultar') return ['hortas' => $gardens, 'expira_em' => $invite['expira_em']];
        if ($action === 'recusar') {
            $s = $pdo->prepare('UPDATE equipe_convite SET recusado_em=UTC_TIMESTAMP(6) WHERE id_convite=?');
            $s->execute([$id]);
            return ['estado' => 'recusado'];
        }

        $member = $pdo->prepare('SELECT 1 FROM usuario_horta WHERE id_horta=? AND id_usuario=?');
        $join = $pdo->prepare('INSERT INTO usuario_horta (id_horta,id_usuario,papel) VALUES (?,?,?)');
        foreach ($gardens as $garden) {
            $params = [$garden['id_horta'], $actor['id_usuario']];
            $member->execute($params);
            if ($member->fetchColumn()) auth_error(409, 'A conta já tem um vínculo em uma das hortas. Solicite um novo convite.');
            $join->execute([...$params, $invite['papel']]);
        }
        // O token recebido na caixa de e-mail e a sessão com o mesmo endereço comprovam a posse.
        $s = $pdo->prepare('UPDATE usuario SET email_verificado_em=COALESCE(email_verificado_em, UTC_TIMESTAMP(6)) WHERE id_usuario=?');
        $s->execute([$actor['id_usuario']]);
        $s = $pdo->prepare('UPDATE equipe_convite SET aceito_em=UTC_TIMESTAMP(6) WHERE id_convite=?');
        $s->execute([$id]);
        return ['estado' => 'aceito', 'hortas' => $gardens];
    });
}

function team_cancel_for_member(PDO $pdo, int $garden, int $member): void
{
    $s = $pdo->prepare('UPDATE equipe_convite c JOIN equipe_convite_horta a ON a.id_convite=c.id_convite
        JOIN usuario u ON u.email=c.email SET c.cancelado_em=UTC_TIMESTAMP(6)
        WHERE a.id_horta=? AND u.id_usuario=? AND c.aceito_em IS NULL
        AND c.recusado_em IS NULL AND c.cancelado_em IS NULL');
    $s->execute([$garden, $member]);
}
