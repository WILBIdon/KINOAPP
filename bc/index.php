<?php
// Dynamic backoffice index
//
// Similar to the admin area, this script loads per‑client configuration and
// displays a customised backoffice interface.  The `.htaccess` file rewrites
// requests such that the path segment after `/bc/` is treated as the client
// identifier.

$client = isset($_GET['client']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['client']) : '';
$baseDir = __DIR__ . '/../clientes/';
$configFile = $client ? $baseDir . $client . '/config.php' : '';

if ($client && file_exists($configFile)) {
    /** @var array $config */
    $config = include $configFile;
} else {
    http_response_code(404);
    echo "Cliente no encontrado";
    exit;
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($config['title_bc']) ? htmlspecialchars($config['title_bc']) : 'Back‑office'; ?></title>
    <script>
        window.CLIENT_CONFIG = <?php echo json_encode($config); ?>;
    </script>
    <script src="script.js"></script>
</head>
<body>
    <h1><?php echo isset($config['heading_bc']) ? htmlspecialchars($config['heading_bc']) : 'Área de back‑office'; ?></h1>
    <p>Bienvenido al área de back‑office para el cliente <strong><?php echo htmlspecialchars($client); ?></strong>.</p>
    <!-- Aquí iría el contenido específico del back‑office -->
</body>
</html>