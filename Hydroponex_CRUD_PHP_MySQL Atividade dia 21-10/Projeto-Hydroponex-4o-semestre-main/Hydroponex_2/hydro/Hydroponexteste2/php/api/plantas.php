<?php
declare(strict_types=1);
/** API JSON compatível com o frontend existente; PDO/MySQL é a única fonte de dados. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repository/PlantaRepository.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, PATCH, DELETE');
    send_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}
if ($method !== 'GET') {
    require_csrf();
}
try {
    $repo = new PlantaRepository(Database::getInstance());
    switch ($method) {
        case 'GET':
            if (array_key_exists('id', $_GET)) {
                $plant = $repo->find(validate_id($_GET['id']));
                if ($plant === null) { send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404); }
                send_json(['success' => true, 'data' => $plant]);
            }
            send_json(['success' => true, 'data' => $repo->all()]);
        case 'POST':
            $input = validate_plant(request_body());
            send_json(['success' => true, 'message' => 'Planta criada com sucesso.', 'data' => $repo->create($input)], 201);
        case 'PUT':
        case 'PATCH':
            $body = request_body();
            $id = validate_id($body['id'] ?? null);
            $data = validate_plant($body, true);
            if ($data === []) { send_json(['success' => false, 'message' => 'Nenhum campo válido foi enviado.'], 400); }
            $plant = $repo->update($id, $data);
            if ($plant === null) { send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404); }
            send_json(['success' => true, 'message' => 'Planta atualizada com sucesso.', 'data' => $plant]);
        case 'DELETE':
            $body = request_body();
            $id = validate_id($body['id'] ?? null);
            if (!$repo->delete($id)) { send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404); }
            send_json(['success' => true, 'message' => 'Planta excluída com sucesso.']);
    }
} catch (PDOException $e) {
    error_log('[Hydroponex SQL] ' . $e->getMessage());
    send_json(['success' => false, 'message' => 'Não foi possível processar a operação no banco de dados.'], 500);
} catch (Throwable $e) {
    error_log('[Hydroponex] ' . $e->getMessage());
    send_json(['success' => false, 'message' => 'Não foi possível processar a operação.'], 500);
}
