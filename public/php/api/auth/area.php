<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';

auth_api(['PATCH'], function (): void {
    $user = require_authenticated_user();
    require_csrf();
    $data = auth_body(['area']);
    $profile = auth_public_user($user);
    $profile['area'] = auth_choose_area($profile['areas'], $data['area'] ?? null);
    $_SESSION['area'] = $profile['area'];
    send_json(['success' => true, 'data' => $profile]);
});
