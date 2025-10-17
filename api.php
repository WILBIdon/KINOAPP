<?php
// api.php - API Multicliente Completa
// Versión 3.0: Soporte completo para múltiples clientes con resaltado de PDFs

// --- CONFIGURACIÓN DE LOGS ---
define('LOG_FILE', __DIR__ . '/debug_log.txt');
function write_log($message) {
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents(LOG_FILE, "[$timestamp] " . print_r($message, true) . "\n", FILE_APPEND);
}
write_log("--- NUEVA SOLICITUD API ---");

// --- MANEJO DE CORS ---
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    http_response_code(200);
    exit();
}
header("Access-Control-Allow-Origin: *");

// --- CONFIGURACIÓN PHP ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// --- FUNCIÓN PARA RESPUESTAS JSON ---
function send_json($success, $message, $data = [], $details = '') {
    http_response_code($success ? 200 : 400);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'details' => $details
    ]);
    exit;
}

// --- CARGAR CONFIGURACIÓN DEL CLIENTE ---
$client_id = $_REQUEST['client'] ?? null;
if (!$client_id) {
    send_json(false, 'ID de cliente no proporcionado en la solicitud.');
}

$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $client_id);
$config_path = __DIR__ . '/clientes/' . $client_id . '/config.php';

if (!file_exists($config_path)) {
    write_log("ERROR: Cliente '$client_id' no encontrado.");
    send_json(false, "Cliente '$client_id' no encontrado.");
}

$config = require $config_path;
write_log("Cliente cargado: $client_id");

// --- CONEXIÓN A BASE DE DATOS DEL CLIENTE ---
try {
    $db_config = $config['db'];
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset=utf8mb4";
    $db = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    write_log("Conexión a BD exitosa: {$db_config['dbname']}");
} catch (PDOException $e) {
    write_log("ERROR de conexión BD: " . $e->getMessage());
    send_json(false, 'Error de conexión con la base de datos.', [], $e->getMessage());
}

// --- DIRECTORIO DE UPLOADS DEL CLIENTE ---
$uploads_dir = __DIR__ . '/uploads/' . $client_id . '/';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}

// --- ENRUTADOR DE ACCIONES ---
$action = $_REQUEST['action'] ?? '';
write_log("Acción: $action");

// ========================================
// ACCIONES PÚBLICAS (NO REQUIEREN LOGIN)
// ========================================

if ($action === 'suggest') {
    $term = $_GET['term'] ?? '';
    $stmt = $db->prepare("SELECT DISTINCT code FROM codes WHERE code LIKE ? ORDER BY code LIMIT 10");
    $stmt->execute([$term . '%']);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

if ($action === 'search_by_code') {
    $code = $_POST['code'] ?? '';
    $stmt = $db->prepare("
        SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c.code) as codes
        FROM documents d
        JOIN codes c ON d.id = c.document_id
        WHERE UPPER(c.code) = UPPER(?)
        GROUP BY d.id
    ");
    $stmt->execute([$code]);
    $docs = $stmt->fetchAll();
    foreach ($docs as &$doc) {
        $doc['codes'] = $doc['codes'] ? explode(',', $doc['codes']) : [];
    }
    echo json_encode($docs);
    exit;
}

if ($action === 'search') {
    $codes_raw = $_POST['codes'] ?? '';
    $codes = array_filter(array_map('trim', explode("\n", $codes_raw)));
    
    if (empty($codes)) {
        echo json_encode([]);
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare("
        SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c.code) as codes
        FROM documents d
        JOIN codes c ON d.id = c.document_id
        WHERE UPPER(c.code) IN (" . str_repeat('UPPER(?),', count($codes) - 1) . "UPPER(?))
        GROUP BY d.id
        ORDER BY d.date DESC
    ");
    $stmt->execute($codes);
    $docs = $stmt->fetchAll();
    
    foreach ($docs as &$doc) {
        $doc['codes'] = $doc['codes'] ? explode(',', $doc['codes']) : [];
    }
    
    echo json_encode($docs);
    exit;
}

// ========================================
// ACCIÓN DE LOGIN (ESTABLECE SESIÓN)
// ========================================

if ($action === 'login') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    
    if ($user === $config['admin']['user'] && password_verify($pass, $config['admin']['pass_hash'])) {
        $_SESSION['user_logged_in'] = true;
        $_SESSION['client_id'] = $client_id;
        write_log("Login exitoso para: $user");
        send_json(true, 'Login exitoso.');
    } else {
        write_log("Login fallido para: $user");
        send_json(false, 'Credenciales incorrectas.');
    }
}

// ========================================
// VERIFICACIÓN DE SESIÓN PARA ACCIONES DE ADMIN
// ========================================

$is_admin = (
    isset($_SESSION['user_logged_in']) && 
    $_SESSION['user_logged_in'] === true && 
    isset($_SESSION['client_id']) && 
    $_SESSION['client_id'] === $client_id
);

if (!$is_admin && !in_array($action, ['suggest', 'search_by_code', 'search', 'login', 'highlight_pdf'])) {
    write_log("Acceso no autorizado. Sesión inválida.");
    send_json(false, 'Acceso no autorizado. Inicie sesión primero.');
}

// ========================================
// ACCIONES DE ADMINISTRADOR
// ========================================

switch ($action) {
    
    // --- LISTAR TODOS LOS DOCUMENTOS ---
    case 'list':
        $stmt = $db->query("
            SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c.code ORDER BY c.code) as codes
            FROM documents d
            LEFT JOIN codes c ON d.id = c.document_id
            GROUP BY d.id
            ORDER BY d.date DESC
        ");
        $docs = $stmt->fetchAll();
        
        foreach ($docs as &$doc) {
            $doc['codes'] = $doc['codes'] ? explode(',', $doc['codes']) : [];
        }
        
        send_json(true, 'Documentos obtenidos.', $docs);
        break;

    // --- SUBIR NUEVO DOCUMENTO ---
    case 'upload':
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            send_json(false, 'No se recibió ningún archivo o hubo un error.');
        }
        
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
        $target_path = $uploads_dir . $filename;
        
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
            send_json(false, 'Error al guardar el archivo.');
        }
        
        // Insertar documento
        $stmt = $db->prepare('INSERT INTO documents (name, date, path) VALUES (?, ?, ?)');
        $stmt->execute([$_POST['name'], $_POST['date'], $filename]);
        $doc_id = $db->lastInsertId();
        
        // Insertar códigos
        $codes_raw = $_POST['codes'] ?? '';
        $codes = array_filter(array_map('trim', explode("\n", $codes_raw)));
        
        if (!empty($codes)) {
            // Ordenar códigos alfabéticamente
            sort($codes, SORT_NATURAL | SORT_FLAG_CASE);
            
            $stmt = $db->prepare('INSERT INTO codes (document_id, code) VALUES (?, ?)');
            foreach ($codes as $code) {
                $stmt->execute([$doc_id, $code]);
            }
        }
        
        write_log("Documento subido: $filename (ID: $doc_id)");
        send_json(true, 'Documento guardado exitosamente.');
        break;

    // --- EDITAR DOCUMENTO ---
    case 'edit':
        $doc_id = $_POST['id'] ?? 0;
        
        // Actualizar información del documento
        $stmt = $db->prepare('UPDATE documents SET name = ?, date = ? WHERE id = ?');
        $stmt->execute([$_POST['name'], $_POST['date'], $doc_id]);
        
        // Eliminar códigos antiguos
        $db->prepare('DELETE FROM codes WHERE document_id = ?')->execute([$doc_id]);
        
        // Insertar nuevos códigos
        $codes_raw = $_POST['codes'] ?? '';
        $codes = array_filter(array_map('trim', explode("\n", $codes_raw)));
        
        if (!empty($codes)) {
            sort($codes, SORT_NATURAL | SORT_FLAG_CASE);
            
            $stmt = $db->prepare('INSERT INTO codes (document_id, code) VALUES (?, ?)');
            foreach ($codes as $code) {
                $stmt->execute([$doc_id, $code]);
            }
        }
        
        // Si hay nuevo archivo, reemplazar
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            // Eliminar archivo antiguo
            $old_path = $db->query("SELECT path FROM documents WHERE id = $doc_id")->fetchColumn();
            if ($old_path && file_exists($uploads_dir . $old_path)) {
                unlink($uploads_dir . $old_path);
            }
            
            // Subir nuevo archivo
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
            move_uploaded_file($_FILES['file']['tmp_name'], $uploads_dir . $filename);
            $db->prepare('UPDATE documents SET path = ? WHERE id = ?')->execute([$filename, $doc_id]);
        }
        
        write_log("Documento editado: ID $doc_id");
        send_json(true, 'Documento actualizado exitosamente.');
        break;

    // --- ELIMINAR DOCUMENTO ---
    case 'delete':
        $doc_id = $_GET['id'] ?? 0;
        
        // Obtener path del archivo
        $path = $db->query("SELECT path FROM documents WHERE id = $doc_id")->fetchColumn();
        
        // Eliminar archivo físico
        if ($path && file_exists($uploads_dir . $path)) {
            unlink($uploads_dir . $path);
        }
        
        // Eliminar códigos (CASCADE lo hace automáticamente, pero por seguridad)
        $db->prepare('DELETE FROM codes WHERE document_id = ?')->execute([$doc_id]);
        
        // Eliminar documento
        $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$doc_id]);
        
        write_log("Documento eliminado: ID $doc_id");
        send_json(true, 'Documento eliminado exitosamente.');
        break;

    // --- DESCARGAR TODOS LOS PDFs EN ZIP ---
    case 'download_pdfs':
        $zip_filename = 'documentos_' . $client_id . '_' . date('Y-m-d') . '.zip';
        $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            send_json(false, 'Error al crear el archivo ZIP.');
        }
        
        $files = glob($uploads_dir . '*.pdf');
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path);
        exit;

    // --- RESALTAR CÓDIGOS EN PDF ---
    case 'highlight_pdf':
        header_remove('Content-Type');
        write_log("Iniciando resaltado de PDF");
        
        $doc_id = (int)($_POST['id'] ?? 0);
        $codes_raw = $_POST['codes'] ?? '';
        $codes = array_filter(array_map('trim', explode(',', $codes_raw)));
        
        if (!$doc_id || empty($codes)) {
            send_json(false, 'Faltan el ID del documento o los códigos para resaltar.');
        }
        
        // Obtener ruta del archivo
        $stmt = $db->prepare('SELECT path FROM documents WHERE id = ?');
        $stmt->execute([$doc_id]);
        $path = $stmt->fetchColumn();
        $full_path = $uploads_dir . $path;
        
        if (!$path || !file_exists($full_path)) {
            write_log("PDF no encontrado: $full_path");
            send_json(false, 'Archivo PDF no encontrado.', [], 'Ruta: ' . $full_path);
        }
        
        // Preparar datos para enviar al servicio de resaltado
        $post_data = [
            'specific_codes' => implode("\n", $codes),
            'pdf_file' => new CURLFile($full_path, 'application/pdf', basename($path))
        ];
        
        write_log("Enviando a servicio de resaltado: " . $config['pdf_highlighter_url']);
        
        // Realizar petición cURL al servicio de resaltado
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $config['pdf_highlighter_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response_headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$response_headers) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;
            $response_headers[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        });
        
        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        write_log("Respuesta del servicio: HTTP $http_code");
        
        if ($curl_error) {
            write_log("Error cURL: $curl_error");
            send_json(false, 'Error de comunicación con el servicio de resaltado.', [], $curl_error);
        }
        
        // Si el servicio devolvió un PDF exitosamente
        if ($http_code === 200 && isset($response_headers['content-type'][0]) && 
            strpos($response_headers['content-type'][0], 'application/pdf') !== false) {
            
            write_log("PDF procesado exitosamente");
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="extracto_' . basename($path) . '"');
            
            if (isset($response_headers['x-pages-found'])) {
                header('X-Pages-Found: ' . $response_headers['x-pages-found'][0]);
                header('Access-Control-Expose-Headers: X-Pages-Found');
            }
            
            echo $response_body;
            exit;
        } else {
            write_log("Error del servicio: " . $response_body);
            $error_data = json_decode($response_body, true) ?? ['error' => 'Error desconocido'];
            send_json(false, $error_data['error'] ?? 'El servicio de resaltado falló.', [], $response_body);
        }
        break;

    default:
        send_json(false, 'Acción no reconocida: ' . $action);
        break;
}