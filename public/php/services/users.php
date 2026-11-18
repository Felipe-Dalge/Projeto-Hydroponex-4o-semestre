<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';

function auth_lock_current(PDO $pdo): array
{
    $s = $pdo->prepare('SELECT * FROM usuario WHERE id_usuario=? FOR UPDATE');
    $s->execute([$_SESSION['id_usuario']]); $row = $s->fetch();
    if (!$row || !(int)$row['ativo'] || $row['excluido_em'] !== null || (int)$row['auth_versao'] !== (int)$_SESSION['auth_versao']) auth_error(401,'Autenticação necessária.');
    return $row;
}

function update_own_profile(array $data): array
{
    $user = require_authenticated_user(); $pdo = database_connection();
    if ($data === []) auth_error(422,'Informe os dados que deseja alterar.');
    $name = array_key_exists('nome',$data) ? auth_string($data,'nome',100) : $user['nome'];
    $email = array_key_exists('email',$data) ? auth_email($data) : $user['email'];
    $avatar = array_key_exists('avatar_url',$data) ? auth_string($data,'avatar_url',2048,false) : ($user['avatar_url'] ?? '');
    if ($avatar !== '' && (!filter_var($avatar,FILTER_VALIDATE_URL) || strtolower((string)parse_url($avatar,PHP_URL_SCHEME)) !== 'https'
        || parse_url($avatar,PHP_URL_USER) !== null || parse_url($avatar,PHP_URL_PASS) !== null)) auth_error(422,'Use uma URL HTTPS válida para o avatar.');
    $number = array_key_exists('numero_endereco',$data) ? auth_string($data,'numero_endereco',20,false) : $user['numero_endereco'];
    $complement = array_key_exists('complemento',$data) ? auth_string($data,'complemento',100,false) : $user['complemento'];
    $cep = $user['id_cep'];
    if (array_key_exists('id_cep',$data)) {
        $cep = $data['id_cep'];
        if ($cep !== null) {
            if (!is_int($cep) || $cep < 1) auth_error(422,'CEP inválido.');
            $s = $pdo->prepare('SELECT id_cep FROM cep WHERE id_cep=?'); $s->execute([$cep]);
            if (!$s->fetchColumn()) auth_error(422,'CEP inválido.');
        }
    }
    $changePassword = array_key_exists('nova_senha',$data) || array_key_exists('confirmacao_senha',$data);
    $newPassword = null;
    if ($changePassword) {
        $newPassword = auth_password($data,'nova_senha',true);
        if (!is_string($data['confirmacao_senha'] ?? null) || !hash_equals($newPassword,$data['confirmacao_senha'])) auth_error(422,'A confirmação da nova senha não confere.');
    }
    $sensitive = $changePassword || $email !== $user['email'];
    if ($sensitive) auth_sensitive_password($pdo,$user,$data);
    $newHash = $changePassword ? auth_hash($newPassword) : null;
    $pdo->beginTransaction();
    try {
        $locked = auth_lock_current($pdo);
        $version = (int)$locked['auth_versao'] + ($sensitive ? 1 : 0);
        $s = $pdo->prepare('UPDATE usuario SET nome=?,email=?,avatar_url=?,id_cep=?,numero_endereco=?,complemento=?,senha_hash=?,auth_versao=?,email_verificado_em=? WHERE id_usuario=?');
        $s->execute([$name,$email,$avatar === '' ? null : $avatar,$cep,$number === '' ? null : $number,$complement === '' ? null : $complement,$newHash ?? $locked['senha_hash'],$version,$email !== $locked['email'] ? null : $locked['email_verificado_em'],$locked['id_usuario']]);
        if ($sensitive) { $s = $pdo->prepare('DELETE FROM token_usuario WHERE id_usuario=?'); $s->execute([$locked['id_usuario']]); }
        $s = $pdo->prepare('SELECT * FROM usuario WHERE id_usuario=?'); $s->execute([$locked['id_usuario']]); $updated = $s->fetch();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) auth_error(409,'Não foi possível utilizar os dados informados.');
        throw $e;
    }
    if ($sensitive) auth_establish($updated);
    return $updated;
}

function delete_own_account(array $data): void
{
    $user = require_authenticated_user(); $pdo = database_connection();
    if (($data['confirmacao'] ?? null) !== 'EXCLUIR') auth_error(422,'Confirme a exclusão digitando EXCLUIR.');
    auth_sensitive_password($pdo,$user,$data);
    $pdo->beginTransaction();
    try {
        // Bloquear o usuário impede nova associação por FK enquanto sua exclusão é decidida.
        $locked = auth_lock_current($pdo);
        $s = $pdo->prepare('SELECT id_horta FROM usuario_horta WHERE id_usuario=? ORDER BY id_horta FOR UPDATE');
        $s->execute([$locked['id_usuario']]); $gardens = $s->fetchAll(PDO::FETCH_COLUMN);
        foreach ($gardens as $garden) {
            $s = $pdo->prepare('SELECT arquivada_em FROM horta WHERE id_horta=? FOR UPDATE'); $s->execute([$garden]); $h = $s->fetch();
            $s = $pdo->prepare("SELECT uh.id_usuario FROM usuario_horta uh JOIN usuario u ON u.id_usuario=uh.id_usuario WHERE uh.id_horta=? AND uh.papel='proprietario' AND u.ativo=1 AND u.excluido_em IS NULL FOR UPDATE");
            $s->execute([$garden]); $owners = array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
            if ($h && $h['arquivada_em'] === null && in_array((int)$user['id_usuario'],$owners,true) && count($owners) < 2) {
                auth_error(409,'Transfira a propriedade ou arquive suas hortas antes de excluir a conta.');
            }
        }
        $s = $pdo->prepare('DELETE FROM usuario_horta WHERE id_usuario=?'); $s->execute([$locked['id_usuario']]);
        $s = $pdo->prepare('DELETE FROM token_usuario WHERE id_usuario=?'); $s->execute([$locked['id_usuario']]);
        // Tombstone mantém FKs de autoria e histórico; dados pessoais e credenciais são removidos.
        $s = $pdo->prepare("UPDATE usuario SET nome='Usuário excluído',email=?,senha_hash=NULL,ativo=0,auth_versao=auth_versao+1,excluido_em=UTC_TIMESTAMP(6),tipo=NULL,avatar_url=NULL,id_cep=NULL,numero_endereco=NULL,complemento=NULL,email_verificado_em=NULL WHERE id_usuario=?");
        $s->execute([bin2hex(random_bytes(24)).'@deleted.invalid',$locked['id_usuario']]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    auth_destroy();
}
