<?php
declare(strict_types=1);
require_once __DIR__ . '/../../services/users.php';
auth_api(['POST'], function (): void {
    require_authenticated_user(); require_csrf();
    $data = auth_body(['senha_atual','nova_senha','confirmacao_senha']);
    if (!isset($data['nova_senha'],$data['confirmacao_senha'])) auth_error(422,'Informe e confirme a nova senha.');
    $user = update_own_profile($data);
    send_json(['success'=>true,'data'=>auth_public_user($user),'csrfToken'=>auth_csrf_token(),'message'=>'Senha alterada. Entre novamente nos outros dispositivos.']);
});
