<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
auth_api(['POST'], function (): void {
    require_authenticated_user(); require_csrf(); auth_destroy();
    send_json(['success'=>true,'message'=>'Sessão encerrada.']);
});
