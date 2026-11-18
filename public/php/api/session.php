<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    send_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

send_json(['success' => true, 'csrfToken' => $_SESSION['csrf_token']]);
