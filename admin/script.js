/*
 * script.js for the admin area
 *
 * This JavaScript file is intentionally simple because the details of
 * individual customer dashboards vary widely.  The dynamic configuration
 * provided via the global `CLIENT_CONFIG` object (exposed in index.php)
 * allows you to tailor behaviour and presentation on a per‑client basis.
 */

document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.CLIENT_CONFIG !== 'undefined') {
        // Example: log the client configuration to the console
        console.log('Configuración del cliente cargada:', window.CLIENT_CONFIG);

        // Aquí puedes inicializar scripts personalizados en función de los
        // valores de configuración.  Por ejemplo, cambiar colores del tema,
        // logos o textos.
    } else {
        console.warn('No se encontró configuración del cliente.');
    }
});