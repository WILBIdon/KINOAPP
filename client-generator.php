<?php
// client-generator.php
// Versión 3.0: Soluciona el problema de generación de carpetas y archivos base

header('Content-Type: application/json');

// Habilitar errores detallados
ini_set('display_errors', 0); // No mostrar en pantalla
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Log de debug
$debug_log = [];

try {
    $debug_log[] = "Inicio del proceso";
    
    // --- VALIDACIÓN DE CAMPOS REQUERIDOS ---
    $required = ['client_name', 'client_id', 'admin_user', 'admin_pass', 'db_name', 'db_user', 'db_pass', 'db_host', 'color_primary'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("El campo '$field' es obligatorio.");
        }
    }
    
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("El logo es obligatorio.");
    }

    $debug_log[] = "Validación de campos completada";

    // --- SANITIZAR Y VALIDAR DATOS ---
    $client_id = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['client_id'])));
    if (empty($client_id)) {
        throw new Exception("El ID de cliente no es válido.");
    }

    $client_name = trim($_POST['client_name']);
    $admin_user = trim($_POST['admin_user']);
    $admin_pass = trim($_POST['admin_pass']);
    $db_name = trim($_POST['db_name']);
    $db_user = trim($_POST['db_user']);
    $db_pass = trim($_POST['db_pass']);
    $db_host = trim($_POST['db_host']);
    $color_primary = trim($_POST['color_primary']);

    $debug_log[] = "Datos sanitizados - DB: $db_name, User: $db_user, Host: $db_host";

    // --- VALIDAR COLOR HEXADECIMAL ---
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_primary)) {
        throw new Exception("El color debe ser un valor hexadecimal válido (ejemplo: #DC2626).");
    }

    // --- VERIFICAR SI EL CLIENTE YA EXISTE ---
    $client_config_dir = __DIR__ . '/clientes/' . $client_id . '/';
    if (is_dir($client_config_dir)) {
        throw new Exception("El cliente con ID '$client_id' ya existe. Elija otro ID.");
    }

    $debug_log[] = "Verificación de cliente existente completada";

    // --- PROBAR CONEXIÓN A BD CON MÚLTIPLES INTENTOS ---
    $connection_attempts = [
        // Intento 1: Puerto 3306 explícito
        [
            'dsn' => "mysql:host=$db_host;port=3306;dbname=$db_name;charset=utf8mb4",
            'description' => 'Puerto 3306 explícito'
        ],
        // Intento 2: Sin puerto (usa el predeterminado)
        [
            'dsn' => "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            'description' => 'Sin puerto especificado'
        ],
        // Intento 3: Con timeout mayor
        [
            'dsn' => "mysql:host=$db_host;port=3306;dbname=$db_name;charset=utf8mb4",
            'description' => 'Puerto 3306 con timeout extendido',
            'timeout' => 10
        ]
    ];

    $pdo = null;
    $connection_errors = [];

    foreach ($connection_attempts as $attempt) {
        try {
            $debug_log[] = "Intentando conexión: " . $attempt['description'];
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $attempt['timeout'] ?? 5
            ];
            
            $pdo = new PDO($attempt['dsn'], $db_user, $db_pass, $options);
            
            // Probar la conexión con una query simple
            $pdo->query("SELECT 1");
            
            $debug_log[] = "✓ Conexión exitosa con: " . $attempt['description'];
            break; // Conexión exitosa, salir del loop
            
        } catch (PDOException $e) {
            $error_detail = [
                'attempt' => $attempt['description'],
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
            $connection_errors[] = $error_detail;
            $debug_log[] = "✗ Fallo en " . $attempt['description'] . ": " . $e->getMessage();
            continue; // Intentar siguiente método
        }
    }

    // Si ninguna conexión funcionó
    if ($pdo === null) {
        $error_details = "No se pudo conectar a la base de datos después de múltiples intentos:\n\n";
        $error_details .= "Datos de conexión:\n";
        $error_details .= "- Host: $db_host\n";
        $error_details .= "- Database: $db_name\n";
        $error_details .= "- User: $db_user\n";
        $error_details .= "- Password length: " . strlen($db_pass) . " caracteres\n\n";
        $error_details .= "Errores encontrados:\n";
        
        foreach ($connection_errors as $idx => $err) {
            $error_details .= ($idx + 1) . ". " . $err['attempt'] . "\n";
            $error_details .= "   Error: " . $err['error'] . "\n";
            $error_details .= "   Código: " . $err['code'] . "\n\n";
        }
        
        $error_details .= "\nPosibles causas:\n";
        $error_details .= "1. La contraseña de la base de datos es incorrecta\n";
        $error_details .= "2. El usuario no tiene permisos sobre esta base de datos\n";
        $error_details .= "3. La base de datos no existe o no está activa\n";
        $error_details .= "4. El host de la base de datos es incorrecto\n";
        $error_details .= "5. Tu IP está bloqueada por el firewall del hosting\n";
        
        throw new Exception($error_details);
    }

    $debug_log[] = "Conexión a BD establecida correctamente";

    // --- CREAR ESTRUCTURA DE CARPETAS BASE ---
    if (!mkdir($client_config_dir, 0755, true)) {
        throw new Exception("No se pudo crear la carpeta de configuración del cliente.");
    }
    $debug_log[] = "Carpeta de configuración del cliente creada: $client_config_dir";
    
    $uploads_dir = __DIR__ . '/uploads/' . $client_id . '/';
    if (!mkdir($uploads_dir, 0755, true)) {
        throw new Exception("No se pudo crear la carpeta de uploads.");
    }
    $debug_log[] = "Carpeta de uploads creada: $uploads_dir";
    
    // =============================================================
    // --- NUEVO: CREAR ESTRUCTURA DE ENRUTAMIENTO Y COPIAR ARCHIVOS ---
    // =============================================================
    
    $admin_template_dir = __DIR__ . '/admin/';
    $bc_template_dir = __DIR__ . '/bc/';
    $client_admin_dir = $admin_template_dir . $client_id . '/';
    $client_bc_dir = $bc_template_dir . $client_id . '/';

    // 1. Crear carpetas de enrutamiento
    if (!mkdir($client_admin_dir, 0755, true) || !mkdir($client_bc_dir, 0755, true)) {
        throw new Exception("No se pudo crear la estructura de carpetas de enrutamiento (/admin/{$client_id}, /bc/{$client_id}).");
    }
    $debug_log[] = "Carpetas de enrutamiento creadas.";

    // 2. Copiar archivos del núcleo de Admin (index.php y script.js del nivel superior)
    foreach (['index.php', 'script.js'] as $file) {
        if (!copy($admin_template_dir . $file, $client_admin_dir . $file)) {
            throw new Exception("Fallo al copiar archivo base de admin: " . $file);
        }
    }
    // Crear .htaccess para admin
    $htaccess_admin_content = "RewriteEngine On\nRewriteBase /admin/{$client_id}/\nRewriteRule ^$ index.php [L]";
    if (!file_put_contents($client_admin_dir . '.htaccess', $htaccess_admin_content)) {
         throw new Exception("Fallo al crear .htaccess de admin para el cliente.");
    }

    // 3. Copiar archivos del núcleo de BC (usando bc/kino como plantilla, pues bc/index.php es un loader simple)
    $bc_kino_template_dir = $bc_template_dir . 'kino/';
    // Asegurarse de que el directorio de plantilla exista
    if (is_dir($bc_kino_template_dir)) {
        foreach (['index.php', 'script.js'] as $file) {
            if (!copy($bc_kino_template_dir . $file, $client_bc_dir . $file)) {
                $debug_log[] = "Advertencia: Fallo al copiar $file de $bc_kino_template_dir. Intentando fallback.";
                // Fallback a bc/index.php (el loader) si la copia de plantilla falla
                if (!copy($bc_template_dir . 'index.php', $client_bc_dir . 'index.php')) {
                    throw new Exception("Fallo al copiar archivo base de bc: " . $file);
                }
            }
        }
    } else {
        // Si no existe la carpeta de plantilla kino, se copia el loader base
        if (!copy($bc_template_dir . 'index.php', $client_bc_dir . 'index.php')) {
            throw new Exception("Fallo al copiar el archivo base bc/index.php.");
        }
        // y script.js genérico si existe
        if (file_exists($bc_template_dir . 'script.js') && !copy($bc_template_dir . 'script.js', $client_bc_dir . 'script.js')) {
             $debug_log[] = "Advertencia: Fallo al copiar el script base bc/script.js.";
        }
    }

    // Crear .htaccess para bc
    $htaccess_bc_content = "RewriteEngine On\nRewriteBase /bc/{$client_id}/\nRewriteRule ^$ index.php [L]";
    if (!file_put_contents($client_bc_dir . '.htaccess', $htaccess_bc_content)) {
         throw new Exception("Fallo al crear .htaccess de bc para el cliente.");
    }
    
    $debug_log[] = "Archivos de enrutamiento copiados y creados.";

    // =============================================================
    // --- FIN NUEVO BLOQUE ---
    // =============================================================

    // --- GUARDAR LOGO ---
    $logo_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if ($logo_ext !== 'png') {
        throw new Exception("El logo debe ser un archivo PNG.");
    }
    
    $logo_path = $client_config_dir . 'logo.png';
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
        throw new Exception("No se pudo guardar el logo.");
    }
    $debug_log[] = "Logo guardado correctamente";

    // --- GENERAR COLOR HOVER ---
    function darken_color($hex, $percent = 20) {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = max(0, min(255, $r * (1 - $percent / 100)));
        $g = max(0, min(255, $g * (1 - $percent / 100)));
        $b = max(0, min(255, $b * (1 - $percent / 100)));
        
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) 
                   . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) 
                   . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }
    
    $color_hover = darken_color($color_primary, 20);

    // --- HASH DE CONTRASEÑA DEL ADMIN ---
    $admin_pass_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
    $debug_log[] = "Contraseña de admin hasheada";

    // --- GENERAR ARCHIVO config.php ---
    $config_content = "<?php
// Configuración del Cliente: " . addslashes($client_name) . "
// Generado automáticamente el " . date('Y-m-d H:i:s') . "

return [
    // --- CONFIGURACIÓN DE BASE DE DATOS ---
    'db' => [
        'host'   => '" . addslashes($db_host) . "',
        'dbname' => '" . addslashes($db_name) . "',
        'user'   => '" . addslashes($db_user) . "',
        'pass'   => '" . addslashes($db_pass) . "'
    ],
    
    // --- BRANDING Y PERSONALIZACIÓN ---
    'branding' => [
        'client_name' => '" . addslashes($client_name) . "',
        'logo_path'   => '../clientes/" . $client_id . "/logo.png',
        'colors' => [
            'primary'       => '" . $color_primary . "',
            'primary_hover' => '" . $color_hover . "'
        ]
    ],
    
    // --- CREDENCIALES DE ADMINISTRADOR ---
    'admin' => [
        'user'      => '" . addslashes($admin_user) . "',
        'pass_hash' => '" . $admin_pass_hash . "'
    ],
    
    // --- URL DEL SERVICIO DE RESALTADO DE PDF ---
    'pdf_highlighter_url' => 'https://buscadordockino1-production.up.railway.app/highlight'
];
";

    if (!file_put_contents($client_config_dir . 'config.php', $config_content)) {
        throw new Exception("No se pudo crear el archivo de configuración.");
    }
    $debug_log[] = "Archivo config.php creado";

    // --- CREAR TABLAS EN LA BASE DE DATOS ---
    $debug_log[] = "Iniciando creación de tablas";
    
    $sql_statements = [
        "CREATE TABLE IF NOT EXISTS `documents` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `date` DATE NOT NULL,
            `path` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_date` (`date`),
            INDEX `idx_path` (`path`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "CREATE TABLE IF NOT EXISTS `codes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `document_id` INT(11) NOT NULL,
            `code` VARCHAR(100) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_document_id` (`document_id`),
            INDEX `idx_code` (`code`),
            FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($sql_statements as $idx => $statement) {
        try {
            $pdo->exec($statement);
            $debug_log[] = "Tabla " . ($idx + 1) . " creada correctamente";
        } catch (PDOException $e) {
            throw new Exception("Error al crear tabla " . ($idx + 1) . ": " . $e->getMessage());
        }
    }

    // --- VERIFICAR TABLAS CREADAS ---
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $debug_log[] = "Tablas verificadas: " . implode(', ', $tables);
    
    if (!in_array('documents', $tables) || !in_array('codes', $tables)) {
        throw new Exception("Las tablas no se crearon correctamente. Tablas encontradas: " . implode(', ', $tables));
    }

    // --- GENERAR URLs ---
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $base_uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    
    // Si la URI contiene el nombre de la carpeta contenedora, se elimina para obtener la URI raíz.
    $base_uri = str_replace("/KINOAPP-5f6e2cdcd4f9681292fb573868ba6965ac7e7935", "", $base_uri);

    $debug_log[] = "Cliente creado exitosamente";

    // --- RESPUESTA EXITOSA ---
    echo json_encode([
        'success' => true,
        'message' => "Cliente '$client_name' creado exitosamente.",
        'data' => [
            'client_id'   => $client_id,
            'client_name' => $client_name,
            'public_url'  => $protocol . $host . $base_uri . '/bc/' . $client_id . '/',
            'admin_url'   => $protocol . $host . $base_uri . '/admin/' . $client_id . '/',
            'database'    => $db_name,
            'tables_created' => ['documents', 'codes']
        ],
        'debug' => $debug_log
    ]);

} catch (Exception $e) {
    // --- LIMPIEZA EN CASO DE ERROR ---
    
    // Función para eliminar directorio recursivamente (necesaria para la limpieza)
    function delete_directory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? delete_directory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
    
    if (isset($client_config_dir) && is_dir($client_config_dir)) {
        delete_directory($client_config_dir);
    }
    if (isset($uploads_dir) && is_dir($uploads_dir)) {
        rmdir($uploads_dir);
    }
    // Limpieza de directorios de enrutamiento creados
    if (isset($client_admin_dir) && is_dir($client_admin_dir)) {
         delete_directory($client_admin_dir);
    }
    if (isset($client_bc_dir) && is_dir($client_bc_dir)) {
         delete_directory($client_bc_dir);
    }

    // --- RESPUESTA DE ERROR DETALLADA ---
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'debug'   => $debug_log
    ]);
}