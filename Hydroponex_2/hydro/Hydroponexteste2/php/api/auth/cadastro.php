<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
auth_api(['POST'], function (): void {
    require_csrf();
    $data = auth_body(['nome','email','senha']);
    $name = auth_string($data,'nome',100); $email = auth_email($data); $password = auth_password($data,'senha',true);
    $pdo = database_connection();
    auth_limit($pdo,'register-ip',auth_ip(),20,3600);
    $hash = auth_hash($password);
    try {
        $s = $pdo->prepare('INSERT INTO usuario (nome,email,senha_hash,ativo) VALUES (?,?,?,1)');
        $s->execute([$name,$email,$hash]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        // Mesma resposta para cadastro novo e e-mail já registrado.
    }
    send_json(['success'=>true,'message'=>'Solicitação processada. Se os dados estiverem disponíveis, você poderá entrar com o e-mail e a senha informados.'],202);
});
