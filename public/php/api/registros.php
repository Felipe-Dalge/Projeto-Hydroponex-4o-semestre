<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/collaboration.php';

auth_api(['GET', 'POST'], function (): void {
    $user = require_authenticated_user();
    $pdo = database_connection();
    $gardenId = garden_id($_GET['horta'] ?? null);
    $userId = (int)$user['id_usuario'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        garden_access($pdo, $gardenId, $userId, 'registros.consultar');
        $s = $pdo->prepare('SELECT r.id_registro,r.id_planta,r.tipo,r.descricao,r.criado_em,u.nome AS autor FROM horta_registro r
            JOIN usuario u ON u.id_usuario=r.id_autor WHERE r.id_horta=? ORDER BY r.id_registro DESC');
        $s->execute([$gardenId]);
        send_json(['success' => true, 'data' => $s->fetchAll()]);
    }
    require_csrf();
    $data = auth_body(['tipo', 'descricao', 'id_planta']);
    $result = collaboration_write($pdo, function () use ($pdo, $gardenId, $userId, $data): array {
        garden_access($pdo, $gardenId, $userId, 'registros.criar', true);
        $type = $data['tipo'] ?? null;
        if (!in_array($type, ['observacao', 'intervencao'], true)) auth_error(422, 'Tipo de registro inválido.');
        $description = auth_string($data, 'descricao', 1000);
        $plant = collaboration_plant($pdo, $gardenId, $data['id_planta'] ?? null);
        $s = $pdo->prepare('INSERT INTO horta_registro (id_horta,id_autor,tipo,descricao,id_planta) VALUES (?,?,?,?,?)');
        $s->execute([$gardenId, $userId, $type, $description, $plant]);
        return ['id_registro' => (int)$pdo->lastInsertId()];
    });
    send_json(['success' => true, 'data' => $result], 201);
});
