/*
 * script.js for the back‑office area
 *
 * This file mirrors the admin `script.js` but lives in the `/bc/` folder.
 * It can be extended to provide functionality specific to the back‑office
 * dashboard.  The configuration for the current client is exposed via
 * `window.CLIENT_CONFIG` just like in the admin area.
 */

document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.CLIENT_CONFIG !== 'undefined') {
        console.log('Configuración del cliente cargada en back‑office:', window.CLIENT_CONFIG);
        // Personalización específica para el back‑office...
    } else {
        console.warn('No se encontró configuración del cliente (back‑office).');
    }
});