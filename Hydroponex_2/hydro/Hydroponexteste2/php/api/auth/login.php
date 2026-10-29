<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
auth_api(['POST'], function (): void {
    require_csrf();
    $data = auth_body(['email','senha']);
    $email = auth_email($data); $password = auth_password($data,'senha');
    $pdo = database_connection();
    auth_limit($pdo,'login-ip',auth_ip(),30);
    auth_limit($pdo,'login-email',$email,5);
    $s = $pdo->prepare('SELECT * FROM usuario WHERE email=?'); $s->execute([$email]); $user = $s->fetch();
    // Hash fictício com o mesmo custo dos cadastros: e-mail inexistente também verifica senha.
    $dummy = '$2y$12$LQz4EzIdo5pnO9MTaeEgI.bSle6yB9h5Qu.ti.hdFsRrQ.FlnKFS2';
    $valid = password_verify($password, $user['senha_hash'] ?? $dummy);
    if (!$valid || !$user || !(int)$user['ativo'] || $user['excluido_em'] !== null) auth_error(401,'E-mail ou senha inválidos.');
    if (password_needs_rehash($user['senha_hash'],PASSWORD_BCRYPT,['cost'=>12])) {
        $s = $pdo->prepare('UPDATE usuario SET senha_hash=? WHERE id_usuario=? AND senha_hash=? AND ativo=1 AND excluido_em IS NULL');
        $s->execute([auth_hash($password),$user['id_usuario'],$user['senha_hash']]);
    }
    auth_establish($user);
    send_json(['success'=>true,'data'=>auth_public_user($user),'csrfToken'=>auth_csrf_token()]);
});
