<?php
// admin/index.php - Panel de Administración Multicliente
session_start();

// --- CARGADOR DE CLIENTE ---
$client_id = $_GET['client'] ?? null;
if (!$client_id) {
    // Si no hay cliente, podemos redirigir a una página de selección o mostrar un error general
    // Pero mantendremos el flujo actual para el contexto
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
$admin_config = $config['admin'];

// Verificar si el usuario está logueado
$is_logged_in = (
    isset($_SESSION['user_logged_in']) && 
    $_SESSION['user_logged_in'] === true && 
    isset($_SESSION['client_id']) && 
    $_SESSION['client_id'] === $client_id
);

// Si intenta acceder sin login, mostrar pantalla de login
if (!$is_logged_in) {
    if (isset($_POST['login_attempt'])) {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        
        if ($user === $admin_config['user'] && password_verify($pass, $admin_config['pass_hash'])) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['client_id'] = $client_id;
            // Redirigir para evitar reenvío del formulario
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// Manejar cierre de sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <title>Admin - <?php echo htmlspecialchars($branding['client_name']); ?></title>
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

<?php if (!$is_logged_in): ?>
    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 mt-20">
        <img src="<?php echo htmlspecialchars($branding['logo_path']); ?>" alt="Logo" class="mx-auto h-20 mb-6">
        <h1 class="text-2xl font-bold text-center mb-6">Panel de Administración</h1>
        <h2 class="text-xl text-center mb-4 text-gray-700"><?php echo htmlspecialchars($branding['client_name']); ?></h2>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="login_attempt" value="1">
            <div>
                <label class="block mb-1 text-gray-700">Usuario</label>
                <input type="text" name="username" required class="w-full border rounded px-3 py-2" autofocus>
            </div>
            <div>
                <label class="block mb-1 text-gray-700">Contraseña</label>
                <input type="password" name="password" required class="w-full border rounded px-3 py-2">
            </div>
            <button type="submit" class="btn btn--primary btn--full text-lg">Ingresar</button>
        </form>
        
        <?php if (isset($_POST['login_attempt']) && !$is_logged_in): ?>
            <p class="mt-4 text-red-500 text-center">Credenciales incorrectas</p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div id="confirmOverlay" class="overlay hidden"><div id="confirmBox" class="modal confirm"><p id="confirmMsg">¿Confirmar?</p><button id="confirmOk" class="btn btn--primary">Aceptar</button><button id="confirmCancel" class="btn btn--secondary">Cancelar</button></div></div>
    <div id="deleteOverlay" class="overlay hidden"><div class="modal deleteKey"><h2 class="text-xl font-semibold">Clave de Eliminación</h2><p class="mt-2 text-gray-700">Ingrese la clave para eliminar:</p><input id="deleteKeyInput" type="password" placeholder="Clave de borrado" class="mt-2 w-full border rounded px-3 py-2" /><p id="deleteKeyError" class="mt-2 text-red-500 hidden">Clave incorrecta.</p><button id="deleteKeyOk" class="btn btn--primary btn--full">Enviar</button><button id="deleteKeyCancel" class="btn btn--secondary btn--full">Cancelar</button></div></div>
    
    <div id="highlightConfirmOverlay" class="overlay hidden">
        <div id="highlightConfirmBox" class="modal">
            <h2 class="text-xl font-semibold">Confirmar Resaltado</h2>
            <div id="highlightConfirmContent" class="text-left my-4 space-y-3">
                <p>Se resaltarán los siguientes códigos en el documento:</p>
                <div class="p-2 bg-gray-100 rounded border">
                    <p class="font-semibold text-lg" id="highlightConfirmDocName"></p>
                    <p class="text-sm text-gray-600" id="highlightConfirmPdfPath"></p>
                </div>
                <pre id="highlightConfirmCodes" class="mt-2 p-2 bg-gray-100 rounded border max-h-32 overflow-auto whitespace-pre-wrap"></pre>
            </div>
            <div class="flex gap-4">
                <button id="highlightConfirmOk" class="btn btn--primary btn--flex1 text-lg">Resaltar Documento</button>
                <button id="highlightConfirmCancel" class="btn btn--secondary btn--flex1 text-lg">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="highlightResultOverlay" class="overlay hidden"><div id="highlightResultBox" class="modal"><h2 class="text-xl font-semibold">Resultado del Resaltado</h2><div id="highlightResultContent" class="text-left my-4"></div><button id="highlightResultClose" class="btn btn--secondary btn--full">Cerrar</button></div></div>
    <div id="toast-container"></div>

    <div id="mainContent" class="w-full max-w-4xl bg-white rounded-2xl shadow-lg">
        <header class="bg-white border-b flex items-center justify-between px-6 py-4">
            <div class="flex items-center">
                <img src="<?php echo htmlspecialchars($branding['logo_path']); ?>" alt="Logo" class="h-10 mr-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($branding['client_name']); ?></h1>
                    <p class="text-sm text-gray-600">Panel de Administración</p>
                </div>
            </div>
            <a href="?logout=1" class="btn btn--secondary text-sm">Cerrar Sesión</a>
        </header>
        
        <nav class="border-b bg-white shadow-sm">
            <ul id="tabs" class="flex">
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4 active" data-tab="tab-search">Buscar</li>
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4" data-tab="tab-upload">Subir</li>
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4" data-tab="tab-list">Consultar</li>
                <li class="tab flex-1 text-center cursor-pointer px-6 py-4" data-tab="tab-code">Código</li>
            </ul>
        </nav>
        
        <main class="p-6 space-y-6">
            <div id="tab-search" class="tab-content">
                <h2 class="text-xl font-semibold mb-4">Búsqueda Inteligente</h2>
                <textarea id="searchInput" rows="6" class="w-full border rounded px-3 py-2 text-lg mb-4" placeholder="Pega aquí tus códigos..."></textarea>
                <div class="flex gap-4 mb-4">
                    <button onclick="doSearch()" class="btn btn--primary btn--flex1 text-lg">Buscar</button>
                    <button onclick="clearSearch()" class="btn btn--secondary btn--flex1 text-lg">Limpiar</button>
                </div>
                <div id="search-alert" class="text-red-600 font-medium text-lg mb-4"></div>
                <div id="results-search" class="space-y-4"></div>
            </div>
            
            <div id="tab-upload" class="tab-content hidden">
                <h2 class="text-xl font-semibold mb-4">Subir / Editar Documento</h2>
                <form id="form-upload" enctype="multipart/form-data" class="space-y-4">
                    <input id="docId" type="hidden" name="id" />
                    <div><label class="block mb-1 text-lg">Nombre</label><input id="name" name="name" type="text" required class="w-full border rounded px-3 py-2 text-lg"/></div>
                    <div><label class="block mb-1 text-lg">Fecha</label><input id="date" name="date" type="date" required class="w-full border rounded px-3 py-2 text-lg"/></div>
                    <div><label class="block mb-1 text-lg">PDF</label><input id="file" name="file" type="file" accept="application/pdf" class="w-full text-lg"/><p id="uploadWarning" class="mt-1 text-red-600 text-sm hidden">El archivo excede 10 MB.</p></div>
                    <div><label class="block mb-1 text-lg">Códigos (uno por línea)</label><textarea id="codes" name="codes" rows="4" class="w-full border rounded px-3 py-2 text-lg"></textarea></div>
                    <button type="submit" class="btn btn--primary btn--full text-lg">Guardar</button>
                </form>
            </div>
            
            <div id="tab-list" class="tab-content hidden">
                <h2 class="text-xl font-semibold mb-4">Consultar Documentos</h2>
                <div class="flex gap-4 mb-4">
                    <input id="consultFilterInput" type="text" class="flex-1 border rounded px-3 py-2 text-lg" placeholder="Filtrar por nombre" oninput="doConsultFilter()" />
                    <button onclick="clearConsultFilter()" class="btn btn--secondary text-lg">Limpiar</button>
                    <button onclick="downloadCsv()" class="btn btn--primary text-lg">CSV</button>
                    <button onclick="downloadPdfs()" class="btn btn--dark text-lg">PDFs</button>
                </div>
                <div id="results-list" class="space-y-4"></div>
            </div>
            
            <div id="tab-code" class="tab-content hidden">
                <h2 class="text-xl font-semibold mb-4">Búsqueda por Código</h2>
                <div class="relative mb-4">
                    <input id="codeInput" type="text" class="w-full border rounded px-3 py-2 text-lg" placeholder="Código" autocomplete="off" />
                    <div id="suggestions" class="absolute top-full left-0 right-0 bg-white border rounded-b px-2 shadow max-h-48 overflow-auto hidden z-20"></div>
                </div>
                <button onclick="doCodeSearch()" class="btn btn--primary btn--full mb-4 text-lg">Buscar</button>
                <div id="results-code" class="space-y-4"></div>
            </div>
        </main>
    </div>

    <script>
        // CONFIGURACIÓN DINÁMICA DEL CLIENTE
        const CLIENT_ID = '<?php echo $client_id; ?>';
        const API_URL = '../api.php?client=' + CLIENT_ID; // Se corrigió la ruta a '../api.php'
        const DELETION_KEY = '0101';
        
        // Cargar el script principal
        const script = document.createElement('script');
        script.src = '../admin/script.js?client=' + CLIENT_ID + '&v=' + Date.now(); // Se corrigió la ruta a '../admin/script.js'
        document.body.appendChild(script);
    </script>
<?php endif; ?>
</body>
</html>