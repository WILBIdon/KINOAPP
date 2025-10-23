// admin/script.js - JavaScript para Panel de Administración Multicliente
// Versión 3.0: Compatible con sistema multicliente

// Suprimir errores molestos de SVG en consola
(function () {
    const orig = console.error;
    console.error = function (...args) {
        if (args[0] && typeof args[0] === 'string' && args[0].includes('Expected number')) {
            return;
        }
        orig.apply(console, args);
    };
})();

// --- VARIABLES GLOBALES ---
// Estas variables son inyectadas desde PHP en admin/index.php
// const CLIENT_ID = '...';
// const API_URL = '/api.php?client=...';
// const DELETION_KEY = '0101';

let fullList = [];
let pendingDeleteId = null;
let intervalId = null;

// --- FUNCIONES UTILITARIAS ---
function startPolling(refreshFn) {
    stopPolling();
    intervalId = setInterval(refreshFn, 60000); // Cada 60 segundos
}

function stopPolling() {
    if (intervalId !== null) clearInterval(intervalId);
    intervalId = null;
}

function toast(msg, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    // NOTE: Tailwind styles (colors, etc.) for 'toast' class are likely defined in style.css/config-visual.css
    toast.className = `toast ${type}`; 
    toast.innerHTML = `<span>${msg}</span><button onclick="this.parentElement.remove()">×</button>`;
    container.appendChild(toast);
    
    setTimeout(() => toast.remove(), duration);
}

function confirmDialog(msg) {
    return new Promise(resolve => {
        const overlay = document.getElementById('confirmOverlay');
        if (!overlay) return resolve(false);
        
        document.getElementById('confirmMsg').textContent = msg;
        overlay.classList.remove('hidden');
        
        document.getElementById('confirmOk').onclick = () => {
            overlay.classList.add('hidden');
            resolve(true);
        };
        
        document.getElementById('confirmCancel').onclick = () => {
            overlay.classList.add('hidden');
            resolve(false);
        };
    });
}

async function handleApiResponse(response) {
    // Si la respuesta es 204 No Content (como en algunos DELETE), el body es vacío.
    if (response.status === 204) {
        return { success: true, message: "Operación completada." };
    }
    
    try {
        const json = await response.json();
        
        if (response.ok && json.success) {
            if (json.message) toast(json.message, 'success');
            return json;
        } else {
            const errorMessage = json.message || 'Ocurrió un error inesperado.';
            const errorDetails = json.details || 'Sin detalles.';
            toast(errorMessage, 'error');
            console.error("Error de API:", errorMessage, "\nDetalles:", errorDetails);
            return Promise.reject(json);
        }
    } catch (e) {
        // Manejar error si la respuesta no es un JSON válido (ej: PDF/ZIP)
        if (response.headers.get('Content-Type')?.includes('application/pdf') || response.headers.get('Content-Type')?.includes('application/zip')) {
             return { success: true, message: "Archivo binario recibido exitosamente." };
        }
        
        // Si no es un archivo binario, es un error de formato
        const text = await response.text();
        toast('Error de formato de respuesta del servidor.', 'error');
        console.error("Respuesta del servidor no válida:", text);
        return Promise.reject(new Error("Respuesta de API no válida."));
    }
}

// --- RENDERIZADO DE DOCUMENTOS ---
function render(items, containerId, isSearchResult) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    if (!items || items.length === 0) {
        container.innerHTML = '<p class="text-gray-500">No se encontraron documentos.</p>';
        return;
    }
    
    container.innerHTML = items.map(item => {
        const codesArray = Array.isArray(item.codes) ? item.codes : [];
        const codesStringForDisplay = codesArray.join('\n');
        
        let actionButtons = '';
        const codesForHighlight = codesArray.join(',').replace(/"/g, '&quot;');
        
        // Si es resultado de búsqueda, solo Resaltar (si hay códigos)
        if (isSearchResult) {
            if (codesArray.length > 0) {
                actionButtons = `
                    <button class="btn btn--dark px-2 py-1 text-base btn-highlight" 
                            data-id="${item.id}" 
                            data-codes="${codesForHighlight}" 
                            data-docname="${item.name.replace(/"/g, '&quot;')}" 
                            data-pdfpath="${item.path.replace(/"/g, '&quot;')}">
                        Resaltar Códigos
                    </button>`;
            }
        } else {
            // Lista completa: editar, eliminar, resaltar
            actionButtons = `
                <button onclick="editDoc(${item.id})" class="btn btn--warning px-2 py-1 text-base">
                    Editar
                </button>
                <button onclick="requestDelete(${item.id})" class="btn btn--primary px-2 py-1 text-base">
                    Eliminar
                </button>
                ${codesArray.length > 0 ? `
                <button class="btn btn--dark px-2 py-1 text-base btn-highlight" 
                        data-id="${item.id}" 
                        data-codes="${codesForHighlight}" 
                        data-docname="${item.name.replace(/"/g, '&quot;')}" 
                        data-pdfpath="${item.path.replace(/"/g, '&quot;')}">
                    Resaltar
                </button>` : ''}`;
        }
        
        return `
            <div class="border rounded p-4 bg-gray-50">
                <div class="flex justify-between items-start">
                    <div class="flex-grow">
                        <h3 class="font-semibold text-lg">${item.name}</h3>
                        <p class="text-gray-600">${item.date}</p>
                        <p class="text-gray-600 text-sm">Archivo: ${item.path}</p>
                        <a href="../uploads/${CLIENT_ID}/${item.path}" target="_blank" class="text-indigo-600 underline">
                            Ver PDF Original
                        </a>
                    </div>
                    <div class="button-group text-right ml-4">
                        ${actionButtons}
                        <button class="btn btn--secondary px-2 py-1 text-base btn-toggle-codes" data-id="${item.id}">
                            Ver Códigos
                        </button>
                    </div>
                </div>
                <pre id="codes${item.id}" class="mt-2 p-2 bg-white rounded hidden whitespace-pre-wrap">${codesStringForDisplay}</pre>
            </div>`;
    }).join('');
}

// --- CONFIRMACIÓN DE RESALTADO ---
function showHighlightConfirmation(docId, codes, docName, pdfPath) {
    if (!docId || !codes || codes.trim() === "") {
        toast('Error: Faltan datos del documento para resaltar.', 'error');
        return;
    }
    
    document.getElementById('highlightConfirmDocName').textContent = docName;
    document.getElementById('highlightConfirmPdfPath').textContent = pdfPath;
    document.getElementById('highlightConfirmCodes').textContent = codes.replace(/,/g, '\n');
    
    const okButton = document.getElementById('highlightConfirmOk');
    okButton.onclick = () => {
        document.getElementById('highlightConfirmOverlay').classList.add('hidden');
        highlightPdf(docId, codes);
    };
    
    document.getElementById('highlightConfirmOverlay').classList.remove('hidden');
}

// --- RESALTAR PDF ---
async function highlightPdf(docId, codes) {
    toast('Procesando PDF, por favor espera...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'highlight_pdf');
    formData.append('id', docId);
    formData.append('codes', codes);

    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const contentType = response.headers.get('Content-Type');
        
        if (response.ok && contentType?.includes('application/pdf')) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            window.open(url, '_blank');
            
            const pagesHeader = response.headers.get('X-Pages-Found');
            const pagesFound = pagesHeader ? JSON.parse(pagesHeader) : [];
            
            const resultModal = document.getElementById('highlightResultOverlay');
            const resultContent = document.getElementById('highlightResultContent');
            
            let pagesHtml = pagesFound.length > 0
                ? `<p class="font-semibold">Códigos encontrados en las páginas:</p>
                   <ul class="list-disc list-inside mt-2"><li>${pagesFound.join('</li><li>')}</li></ul>`
                : `<p class="font-semibold">No se encontraron los códigos en el PDF.</p>
                   <p>Se ha abierto el documento para revisión manual.</p>`;
            
            if (resultContent) {
                resultContent.innerHTML = `
                    <p class="mb-4">El PDF procesado se ha abierto en una nueva pestaña.</p>
                    <div class="mt-4 p-2 bg-gray-100 rounded">${pagesHtml}</div>
                    <a href="${url}" download="extracto.pdf" class="btn btn--secondary btn--full mt-4">
                        Descargar de nuevo
                    </a>`;
            }
            if (resultModal) resultModal.classList.remove('hidden');
            
            toast('PDF procesado exitosamente', 'success');
        } else {
            await handleApiResponse(response);
        }
    } catch (error) {
        toast('Error al comunicarse con el servicio de resaltado.', 'error');
        console.error('Error en highlightPdf:', error);
    }
}

// --- BÚSQUEDA INTELIGENTE ---
function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('results-search').innerHTML = '';
    document.getElementById('search-alert').innerText = '';
}

async function doSearch() {
    const rawInput = document.getElementById('searchInput').value.trim();
    if (!rawInput) return;
    
    // Solo toma el primer 'código' de cada línea
    const codes = [...new Set(rawInput.split(/\r?\n/).map(line => line.trim().split(/\s+/)[0]).filter(Boolean))]; 
    const formData = new FormData();
    formData.append('action', 'search');
    formData.append('codes', codes.join('\n'));
    
    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();
        
        // La API devuelve los documentos ordenados por mejor cobertura (lógica voraz)
        const foundCodes = new Set(data.flatMap(doc => doc.codes || []));
        const missingCodes = codes.filter(c => !foundCodes.has(c));
        
        const alertEl = document.getElementById('search-alert');
        if (alertEl) {
            alertEl.innerText = missingCodes.length 
                ? 'Códigos no encontrados: ' + missingCodes.join(', ') 
                : '';
        }
        
        render(data, 'results-search', true);
    } catch (error) {
        toast('Error de red al buscar.', 'error');
    }
}

// --- SUBIR/EDITAR DOCUMENTO ---
function clearUploadForm() {
    document.getElementById('form-upload').reset();
    document.getElementById('docId').value = '';
    toast('Formulario limpiado.', 'info');
}

// --- CONSULTAR DOCUMENTOS ---
function clearConsultFilter() {
    document.getElementById('consultFilterInput').value = '';
    doConsultFilter();
}

function doConsultFilter() {
    const term = document.getElementById('consultFilterInput').value.trim().toLowerCase();
    const filtered = fullList.filter(doc => 
        doc.name.toLowerCase().includes(term) || 
        doc.path.toLowerCase().includes(term)
    );
    render(filtered, 'results-list', false);
}

function downloadCsv() {
    let csv = 'Código,Documento\n';
    fullList.forEach(doc => {
        doc.codes?.forEach(code => {
            csv += `"${code.replace(/"/g, '""')}","${doc.name.replace(/"/g, '""')}"\n`;
        });
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `documentos_${CLIENT_ID}_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function downloadPdfs() {
    window.location.href = `${API_URL}&action=download_pdfs`;
}

// --- BÚSQUEDA POR CÓDIGO ---
async function doCodeSearch() {
    const code = document.getElementById('codeInput').value.trim();
    if (!code) return;
    
    const formData = new FormData();
    formData.append('action', 'search_by_code');
    formData.append('code', code);
    
    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();
        render(data, 'results-code', true);
    } catch (error) {
        toast('Error de red al buscar por código.', 'error');
    }
}

// --- EDITAR/ELIMINAR ---
function editDoc(id) {
    const doc = fullList.find(d => d.id === id);
    if (!doc) return;
    
    document.querySelector('[data-tab="tab-upload"]')?.click();
    clearUploadForm();
    
    document.getElementById('docId').value = doc.id;
    document.getElementById('name').value = doc.name;
    document.getElementById('date').value = doc.date;
    document.getElementById('codes').value = doc.codes?.join('\n') || '';
}

async function deleteDoc(id) {
    try {
        const response = await fetch(`${API_URL}&action=delete&id=${id}`);
        await handleApiResponse(response);
        document.querySelector('.tab.active')?.click();
    } catch (error) {
        console.error('Error al eliminar:', error);
    }
}

function requestDelete(id) {
    pendingDeleteId = id;
    document.getElementById('deleteOverlay')?.classList.remove('hidden');
    document.getElementById('deleteKeyInput')?.focus();
}

function toggleCodes(btn) {
    const pre = document.getElementById(`codes${btn.dataset.id}`);
    if (!pre) return;
    
    const isHidden = pre.classList.toggle('hidden');
    btn.textContent = isHidden ? 'Ver Códigos' : 'Ocultar Códigos';
}

// --- REFRESCAR LISTA ---
async function refreshList() {
    try {
        const response = await fetch(`${API_URL}&action=list`);
        const json = await handleApiResponse(response);
        fullList = json.data || [];
        
        const activeTab = document.querySelector('.tab.active');
        if (activeTab && activeTab.dataset.tab === 'tab-list') {
            doConsultFilter();
        }
    } catch (error) {
        console.error('Error al refrescar lista:', error);
    }
}

// --- INICIALIZACIÓN ---
document.addEventListener('DOMContentLoaded', function() {
    
    // Event listeners de modales
    const highlightConfirmCancel = document.getElementById('highlightConfirmCancel');
    if (highlightConfirmCancel) {
        highlightConfirmCancel.onclick = () => {
            document.getElementById('highlightConfirmOverlay').classList.add('hidden');
        };
    }
    
    const highlightResultClose = document.getElementById('highlightResultClose');
    if (highlightResultClose) {
        highlightResultClose.onclick = () => {
            document.getElementById('highlightResultOverlay').classList.add('hidden');
        };
    }
    
    // Modal de eliminación
    const deleteKeyOk = document.getElementById('deleteKeyOk');
    const deleteKeyInput = document.getElementById('deleteKeyInput');
    if (deleteKeyOk) {
        deleteKeyOk.onclick = async () => {
            if (deleteKeyInput.value !== DELETION_KEY) {
                document.getElementById('deleteKeyError').classList.remove('hidden');
                deleteKeyInput.value = '';
                deleteKeyInput.focus();
                return;
            }
            
            document.getElementById('deleteOverlay').classList.add('hidden');
            document.getElementById('deleteKeyError').classList.add('hidden');
            deleteKeyInput.value = '';
            
            const ok = await confirmDialog('¿Está seguro de eliminar este documento? Esta acción no se puede deshacer.');
            if (ok) await deleteDoc(pendingDeleteId);
        };
    }
    
    const deleteKeyCancel = document.getElementById('deleteKeyCancel');
    if (deleteKeyCancel) {
        deleteKeyCancel.onclick = () => {
            document.getElementById('deleteOverlay').classList.add('hidden');
            document.getElementById('deleteKeyError').classList.add('hidden');
            deleteKeyInput.value = '';
        };
    }
    
    // Event delegation para botones de resaltado y toggle
    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.addEventListener('click', (event) => {
            const target = event.target.closest('button');
            if (!target) return;
            
            if (target.classList.contains('btn-highlight')) {
                const docId = target.dataset.id;
                const codes = target.dataset.codes;
                const docName = target.dataset.docname;
                const pdfPath = target.dataset.pdfpath;
                showHighlightConfirmation(docId, codes, docName, pdfPath);
            }
            
            if (target.classList.contains('btn-toggle-codes')) {
                toggleCodes(target);
            }
        });
    }
    
    // Formulario de upload
    const uploadForm = document.getElementById('form-upload');
    if (uploadForm) {
        uploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(uploadForm);
            const docId = document.getElementById('docId');
            formData.append('action', docId && docId.value ? 'edit' : 'upload');
            
            try {
                const response = await fetch(API_URL, { method: 'POST', body: formData });
                await handleApiResponse(response);
                clearUploadForm();
                document.querySelector('[data-tab="tab-list"]')?.click();
            } catch (error) {
                console.error('Error en upload:', error);
            }
        });
    }
    
    // Autocompletado de códigos
    const codeInput = document.getElementById('codeInput');
    const suggestions = document.getElementById('suggestions');
    if (codeInput && suggestions) {
        let timeoutId;
        codeInput.addEventListener('input', function() {
            clearTimeout(timeoutId);
            const term = codeInput.value.trim();
            if (!term) {
                suggestions.classList.add('hidden');
                return;
            }
            
            timeoutId = setTimeout(async () => {
                try {
                    const response = await fetch(`${API_URL}&action=suggest&term=${encodeURIComponent(term)}`);
                    const data = await response.json();
                    
                    if (data.length) {
                        suggestions.innerHTML = data.map(code => 
                            `<div class="p-2 hover:bg-gray-100 cursor-pointer" data-code="${code}">${code}</div>`
                        ).join('');
                        suggestions.classList.remove('hidden');
                    } else {
                        suggestions.classList.add('hidden');
                    }
                } catch (error) {
                    console.error('Error en sugerencias:', error);
                }
            }, 200);
        });
        
        suggestions.addEventListener('click', function(e) {
            if (e.target.dataset.code) {
                codeInput.value = e.target.dataset.code;
                suggestions.classList.add('hidden');
                doCodeSearch();
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!suggestions.contains(e.target) && e.target !== codeInput) {
                suggestions.classList.add('hidden');
            }
        });
    }
    
    // Pestañas
    document.querySelectorAll('.tab').forEach(tab => {
        tab.onclick = () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
            tab.classList.add('active');
            
            const tabContent = document.getElementById(tab.dataset.tab);
            if (tabContent) tabContent.classList.remove('hidden');
            
            if (tab.dataset.tab === 'tab-list') {
                refreshList();
                startPolling(refreshList);
            } else {
                stopPolling();
            }
            
            // Si el código se oculta, reactivar el polling
            if (tab.dataset.tab !== 'tab-list') {
                if (intervalId === null) {
                   refreshList(); // Refrescar una vez para mantener los datos frescos si es necesario
                }
            }
        };
    });
    
    // Activar primera pestaña y cargar datos
    const firstTab = document.querySelector('.tab.active');
    if (firstTab) firstTab.click();
});

// Exponer funciones globales necesarias
window.doSearch = doSearch;
window.clearSearch = clearSearch;
window.doCodeSearch = doCodeSearch;
window.editDoc = editDoc;
window.requestDelete = requestDelete;
window.clearConsultFilter = clearConsultFilter;
window.doConsultFilter = doConsultFilter;
window.downloadCsv = downloadCsv;
window.downloadPdfs = downloadPdfs;
window.toggleCodes = toggleCodes;
window.highlightPdf = highlightPdf;