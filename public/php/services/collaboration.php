<?php
declare(strict_types=1);
require_once __DIR__ . '/gardens.php';

/** Todas as escritas revalidam a sessão e bloqueiam a horta antes de modificar seus dados. */
function collaboration_write(PDO $pdo, callable $write): array
{
    $pdo->beginTransaction();
    try {
        $actor = garden_lock_user($pdo);
        $result = $write($actor);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function collaboration_role(mixed $role): string
{
    if (!in_array($role, HORTA_MANAGED_ROLES, true)) auth_error(422, 'Selecione gestor, agricultor ou leitor.');
    return $role;
}

function collaboration_plant(PDO $pdo, int $garden, mixed $id): ?int
{
    if ($id === null || $id === '') return null;
    $id = garden_id($id);
    $s = $pdo->prepare('SELECT 1 FROM planta WHERE id_planta=? AND id_horta=? AND arquivada_em IS NULL');
    $s->execute([$id, $garden]);
    if (!$s->fetchColumn()) auth_error(422, 'Selecione uma planta ativa desta horta.');
    return $id;
}

function accept_garden_invitation(PDO $pdo, mixed $code): array
{
    if (!is_string($code) || !preg_match('/\A[0-9a-f]{64}\z/', $code)) auth_error(422, 'Informe um código de convite válido.');
    $hash = hash('sha256', $code, true);
    return collaboration_write($pdo, function (array $actor) use ($pdo, $hash): array {
        $find = $pdo->prepare('SELECT id_horta FROM horta_convite WHERE codigo_hash=?');
        $find->execute([$hash]);
        $gardenId = $find->fetchColumn();
        if (!$gardenId) auth_error(404, 'Convite indisponível para esta conta.');

        // Aceitar é a única operação que pode iniciar um vínculo sem papel prévio.
        $garden = $pdo->prepare('SELECT id_horta FROM horta WHERE id_horta=? AND arquivada_em IS NULL FOR UPDATE');
        $garden->execute([$gardenId]);
        if (!$garden->fetchColumn()) auth_error(404, 'Convite indisponível para esta conta.');
        $find = $pdo->prepare("SELECT c.id_convite,c.email,c.papel FROM horta_convite c
            JOIN usuario_horta uh ON uh.id_horta=c.id_horta AND uh.id_usuario=c.id_criador AND uh.papel='proprietario'
            JOIN usuario u ON u.id_usuario=c.id_criador AND u.ativo=1 AND u.excluido_em IS NULL
            WHERE c.id_horta=? AND c.codigo_hash=? AND c.aceito_em IS NULL AND c.revogado_em IS NULL
            AND c.expira_em>UTC_TIMESTAMP(6) FOR UPDATE");
        $find->execute([$gardenId, $hash]);
        $invite = $find->fetch();
        if (!$invite || !hash_equals(strtolower($invite['email']), strtolower($actor['email']))) {
            auth_error(404, 'Convite indisponível para esta conta.');
        }
        $member = $pdo->prepare('SELECT 1 FROM usuario_horta WHERE id_horta=? AND id_usuario=?');
        $member->execute([$gardenId, $actor['id_usuario']]);
        if ($member->fetchColumn()) auth_error(409, 'Sua conta já tem acesso a esta horta.');
        $join = $pdo->prepare('INSERT INTO usuario_horta (id_horta,id_usuario,papel) VALUES (?,?,?)');
        $join->execute([$gardenId, $actor['id_usuario'], $invite['papel']]);
        $consume = $pdo->prepare('UPDATE horta_convite SET aceito_em=UTC_TIMESTAMP(6) WHERE id_convite=? AND id_horta=?');
        $consume->execute([$invite['id_convite'], $gardenId]);
        return ['id_horta' => (int)$gardenId, 'papel' => $invite['papel']];
    });
}

function collaboration_assignees(PDO $pdo, int $gardenId): array
{
    $s = $pdo->prepare('SELECT u.id_usuario,u.nome,uh.papel FROM usuario_horta uh
        JOIN usuario u ON u.id_usuario=uh.id_usuario
        WHERE uh.id_horta=? AND u.ativo=1 AND u.excluido_em IS NULL ORDER BY u.nome,u.id_usuario');
    $s->execute([$gardenId]);
    return array_values(array_filter($s->fetchAll(), static fn(array $member): bool => horta_allows($member['papel'], 'tarefas.proprias')));
}
