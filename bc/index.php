<?php
// bc/index.php - Portal Público Multicliente

// --- CARGADOR DE CLIENTE ---
$client_id = $_GET['client'] ?? null;
if (!$client_id) {
    die("ERROR: Cliente no especificado en la URL.");
}

// Sanitizar el ID del cliente
$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $client_id);

// Verificar que existe la configuración del cliente
$config_path = __DIR__ . '/../clientes/' . $client_id . '/config.php';
if (!file_exists($config_path)) {
    http_response_code(404);
    die("ERROR: Cliente '$client_id' no encontrado.");
}

// Cargar la configuración del cliente
$config = require $config_path;
$branding = $config['branding'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <title>Buscador - <?php echo htmlspecialchars($branding['client_name']); ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../config-visual.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Inyectar colores personalizados del cliente */
        :root {
            --color-primary: <?php echo $branding['colors']['primary']; ?>;
            --color-primary-hover: <?php echo $branding['colors']['primary_hover']; ?>;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-start justify-center p-6">

    <div id="highlightResultOverlay" class="overlay hidden">
        <div id="highlightResultBox" class="modal">
            <h2 class="text-xl font-semibold">Resultado del Resaltado</h2>
            <div id="highlightResultContent" class="text-left my-4"></div>
            <button id="highlightResultClose" class="btn btn--secondary btn--full">Cerrar</button>
        </div>
    </div>
    
    <div id="toast-container"></div>

    <div id="mainContent" class="w-full max-w-4xl bg-white rounded-2xl shadow-lg">
        <div class="bg-white border-b flex flex-col items-center justify-center py-6 px-4">
            <img src="<?php echo htmlspecialchars($branding['logo_path']); ?>" alt="Logo <?php echo htmlspecialchars($branding['client_name']); ?>" class="h-20 mb-4">
            <h1 class="text-2xl font-bold text-center text-gray-800"><?php echo htmlspecialchars($branding['client_name']); ?></h1>
            <p class="text-gray-600 text-center mt-2">Sistema de Consulta de Documentos</p>
        </div>
        
        <nav class="border-b bg-white shadow-sm">
            <ul id="tabs" class="flex">
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4 active" data-tab="tab-search">Búsqueda Inteligente</li>
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4" data-tab="tab-code">Búsqueda por Código</li>
            </ul>
        </nav>
        
        <div class="p-6 space-y-6">
            <div id="tab-search" class="tab-content">
                <h2 class="text-xl font-semibold mb-4">Búsqueda Inteligente</h2>
                <p class="text-gray-600 mb-4">Pegue aquí los códigos que desea buscar (uno por línea o separados por espacios):</p>
                <textarea id="searchInput" rows="6" class="w-full border rounded px-3 py-2 text-lg mb-4" placeholder="Ejemplo:&#10;ABC123&#10;DEF456&#10;GHI789"></textarea>
                <div class="flex gap-4 mb-4">
                    <button onclick="doSearch()" class="btn btn--primary btn--flex1 text-lg">Buscar</button>
                    <button onclick="clearSearch()" class="btn btn--secondary btn--flex1 text-lg">Limpiar</button>
                </div>
                <div id="search-alert" class="text-red-600 font-medium text-lg mb-4"></div>
                <div id="results-search" class="space-y-4"></div>
            </div>
            
            <div id="tab-code" class="tab-content hidden">
                <h2 class="text-xl font-semibold mb-4">Búsqueda por Código Individual</h2>
                <p class="text-gray-600 mb-4">Ingrese un código para buscar los documentos asociados:</p>
                <div class="relative mb-4">
                    <input id="codeInput" type="text" class="w-full border rounded px-3 py-2 text-lg" placeholder="Ejemplo: ABC123" autocomplete="off" />
                    <div id="suggestions" class="absolute top-full left-0 right-0 bg-white border rounded-b px-2 shadow max-h-48 overflow-auto hidden z-20"></div>
                </div>
                <button onclick="doCodeSearch()" class="btn btn--primary btn--full mb-4 text-lg">Buscar por Código</button>
                <div id="results-code" class="space-y-4"></div>
            </div>
        </div>
    </div>

    <script>
        // CONFIGURACIÓN DINÁMICA DEL CLIENTE
        const CLIENT_ID = '<?php echo $client_id; ?>';
        const API_URL = '/api.php?client=' + CLIENT_ID;
        const IS_PUBLIC = true; // Indica que es el portal público
        
        // Cargar el script principal
        const script = document.createElement('script');
        script.src = '../bc/script.js?client=' + CLIENT_ID + '&v=' + Date.now();
        document.body.appendChild(script);
    </script>
</body>
</html>