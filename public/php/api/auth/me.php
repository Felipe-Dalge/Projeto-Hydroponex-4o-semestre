<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
auth_api(['GET'], function (): void {
    $user = require_authenticated_user();
    send_json(['success'=>true,'data'=>auth_public_user($user),'csrfToken'=>auth_csrf_token()]);
});
