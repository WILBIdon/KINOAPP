<?php
header('Content-Type: application/json');

// --- Función para eliminar un directorio y todo su contenido ---
function delete_directory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? delete_directory("$dir/$file") : unlink("$dir/$file");
    }
    rmdir($dir);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    // --- ACCIÓN PARA LISTAR CLIENTES ---
    case 'list':
        $clients_dir = __DIR__ . '/clientes/';
        $clients = [];
        if (is_dir($clients_dir)) {
            $client_folders = array_diff(scandir($clients_dir), ['.', '..']);
            foreach ($client_folders as $client_id) {
                $config_path = $clients_dir . $client_id . '/config.php';
                if (is_dir($clients_dir . $client_id) && file_exists($config_path)) {
                    $config = require $config_path;
                    $clients[] = [
                        'id' => $client_id,
                        'name' => $config['branding']['client_name'] ?? 'Nombre no encontrado'
                    ];
                }
            }
        }
        echo json_encode(['success' => true, 'clients' => $clients]);
        break;

    // --- ACCIÓN PARA ELIMINAR UN CLIENTE ---
    case 'delete':
        $client_id = $_GET['client'] ?? '';
        if (empty($client_id) || !preg_match('/^[a-z0-9_-]+$/', $client_id)) {
            echo json_encode(['success' => false, 'error' => 'ID de cliente no válido.']);
            exit;
        }

        $client_config_dir = __DIR__ . '/clientes/' . $client_id;
        $client_uploads_dir = __DIR__ . '/uploads/' . $client_id;

        if (!is_dir($client_config_dir)) {
            echo json_encode(['success' => false, 'error' => 'El cliente no existe.']);
            exit;
        }

        // Eliminar carpetas
        delete_directory($client_config_dir);
        delete_directory($client_uploads_dir);

        echo json_encode(['success' => true, 'message' => "Cliente '$client_id' eliminado correctamente."]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
        break;
}