<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/users.php';
auth_api(['GET','PATCH','DELETE'], function (): void {
    $user = require_authenticated_user();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') send_json(['success'=>true,'data'=>auth_public_user($user),'csrfToken'=>auth_csrf_token()]);
    require_csrf();
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        delete_own_account(auth_body(['senha_atual','confirmacao']));
        send_json(['success'=>true,'message'=>'Conta excluída.']);
    }
    $data = auth_body(['nome','email','avatar_url','id_cep','numero_endereco','complemento','senha_atual','nova_senha','confirmacao_senha']);
    $updated = update_own_profile($data);
    send_json(['success'=>true,'data'=>auth_public_user($updated),'csrfToken'=>auth_csrf_token(),'message'=>'Perfil atualizado.']);
});
