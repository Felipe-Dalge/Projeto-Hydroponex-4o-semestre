<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

ensure_storage();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($method) {
    case 'GET':
        $items = read_json_file();
        $idParam = $_GET['id'] ?? null;
        if ($idParam !== null) {
            $id = validate_id($idParam);
            foreach ($items as $item) {
                if ((int)($item['id'] ?? 0) === $id) {
                    send_json(['success' => true, 'data' => $item]);
                }
            }
            send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404);
        }
        send_json(['success' => true, 'data' => $items]);

    case 'POST':
        require_csrf();
        $input = request_body();
        $validated = validate_plant($input);
        $lock = acquire_storage_lock();
        $items = read_json_file();
        $maxId = 0;
        foreach ($items as $item) {
            $maxId = max($maxId, (int)($item['id'] ?? 0));
        }
        $new = array_merge([
            'id' => $maxId + 1,
            'umidade' => 60,
            'agua' => 1.5,
            'status' => 'Normal',
            'lastUpdate' => 'Agora',
        ], $validated);
        $items[] = $new;
        write_json_file($items);
        release_storage_lock($lock);
        send_json(['success' => true, 'message' => 'Planta criada com sucesso.', 'data' => $new], 201);

    case 'PUT':
    case 'PATCH':
        require_csrf();
        $input = request_body();
        $id = validate_id($input['id'] ?? null);
        $validated = validate_plant($input, true);
        if ($validated === []) {
            send_json(['success' => false, 'message' => 'Nenhum campo válido foi enviado.'], 400);
        }
        $lock = acquire_storage_lock();
        $items = read_json_file();
        $found = false;
        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) === $id) {
                $item = array_merge($item, $validated);
                $item['lastUpdate'] = 'Agora';
                $found = true;
                $updated = $item;
                break;
            }
        }
        unset($item);
        if (!$found) {
            release_storage_lock($lock);
            send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404);
        }
        write_json_file($items);
        release_storage_lock($lock);
        send_json(['success' => true, 'message' => 'Planta atualizada com sucesso.', 'data' => $updated]);

    case 'DELETE':
        require_csrf();
        $input = request_body();
        $id = validate_id($input['id'] ?? $_GET['id'] ?? null);
        $lock = acquire_storage_lock();
        $items = read_json_file();
        $filtered = [];
        $deleted = false;
        foreach ($items as $item) {
            if ((int)($item['id'] ?? 0) === $id) {
                $deleted = true;
                continue;
            }
            $filtered[] = $item;
        }
        if (!$deleted) {
            release_storage_lock($lock);
            send_json(['success' => false, 'message' => 'Planta não encontrada.'], 404);
        }
        write_json_file($filtered);
        release_storage_lock($lock);
        send_json(['success' => true, 'message' => 'Planta excluída com sucesso.']);

    default:
        header('Allow: GET, POST, PUT, PATCH, DELETE');
        send_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}
