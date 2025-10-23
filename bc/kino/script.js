// bc/script.js - JavaScript para Portal Público Multicliente
// Versión 3.0: Compatible con sistema multicliente

// Suprimir errores molestos de SVG
(function () {
    const orig = console.error;
    console.error = function (...args) {
        if (args[0] && typeof args[0] === 'string' && args[0].includes('Expected number')) {
            return;
        }
        orig.apply(console, args);
    };
    window.onerror = function () { return true; };
})();

// --- VARIABLES GLOBALES ---
// Estas variables son inyectadas desde PHP en bc/index.php
// const CLIENT_ID = '...';
// const API_URL = '/api.php?client=...';
// const IS_PUBLIC = true;

// --- FUNCIONES UTILITARIAS ---
function toast(msg, duration = 3000) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `<span>${msg}</span><button onclick="this.parentElement.remove()">×</button>`;
    container.appendChild(toast);
    
    setTimeout(() => toast.remove(), duration);
}

// --- RENDERIZADO DE DOCUMENTOS ---
function render(items, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    if (!items || items.length === 0) {
        container.innerHTML = '<p class="text-gray-500">No se encontraron documentos.</p>';
        return;
    }
    
    container.innerHTML = items.map(item => {
        const codesArray = Array.isArray(item.codes) ? item.codes : [];
        const codesStringForHighlight = codesArray.join(',');
        const codesStringForDisplay = codesArray.join('\n');
        
        return `
            <div class="border rounded p-4 bg-gray-50">
                <div class="flex justify-between items-start">
                    <div class="flex-grow">
                        <h3 class="font-semibold text-lg">${item.name}</h3>
                        <p class="text-gray-600">${item.date}</p>
                        <p class="text-gray-600 text-sm">Archivo: ${item.path}</p>
                        <a href="../uploads/$kino/${item.path}" target="_blank" class="text-indigo-600 underline">
                            Ver PDF Original
                        </a>
                    </div>
                    <div class="button-group text-right ml-4">
                        ${codesArray.length > 0 ? `
                        <button onclick="highlightPdf(${item.id}, '${codesStringForHighlight.replace(/'/g, "\\'")}', '${item.name.replace(/'/g, "\\'")}', '${item.path.replace(/'/g, "\\'")}')" 
                                class="btn btn--dark px-2 py-1 text-base">
                            Resaltar Códigos
                        </button>` : ''}
                        <button data-id="${item.id}" onclick="toggleCodes(this)" class="btn btn--secondary px-2 py-1 text-base">
                            Ver Códigos
                        </button>
                    </div>
                </div>
                <pre id="codes${item.id}" class="mt-2 p-2 bg-white rounded hidden whitespace-pre-wrap">${codesStringForDisplay}</pre>
            </div>`;
    }).join('');
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
    
    const codes = [...new Set(rawInput.split(/[\r\n\s,]+/).map(l => l.trim()).filter(Boolean))];
    const formData = new FormData();
    formData.append('action', 'search');
    formData.append('codes', codes.join('\n'));
    
    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();
        
        const foundCodes = new Set(data.flatMap(doc => doc.codes || []));
        const missingCodes = codes.filter(c => !foundCodes.has(c));
        
        document.getElementById('search-alert').innerText = missingCodes.length 
            ? 'Códigos no encontrados: ' + missingCodes.join(', ') 
            : '';
        
        render(data, 'results-search');
    } catch (error) {
        console.error('Error en búsqueda:', error);
        toast('Error al realizar la búsqueda. Intente nuevamente.');
    }
}

// --- AUTOCOMPLETADO DE CÓDIGOS ---
(function initAutocomplete() {
    const codeInput = document.getElementById('codeInput');
    const suggestions = document.getElementById('suggestions');
    
    if (!codeInput || !suggestions) return;
    
    let timeoutId;
    
    codeInput.addEventListener('input', () => {
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
                        `<div class="py-1 px-2 hover:bg-gray-100 cursor-pointer" data-code="${code}">${code}</div>`
                    ).join('');
                    suggestions.classList.remove('hidden');
                } else {
                    suggestions.classList.add('hidden');
                }
            } catch (error) {
                console.error('Error en autocompletado:', error);
                suggestions.classList.add('hidden');
            }
        }, 200);
    });
    
    suggestions.addEventListener('click', e => {
        const code = e.target.dataset.code;
        if (code) {
            codeInput.value = code;
            suggestions.classList.add('hidden');
            doCodeSearch();
        }
    });
    
    document.addEventListener('click', (e) => {
        if (!suggestions.contains(e.target) && e.target !== codeInput) {
            suggestions.classList.add('hidden');
        }
    });
})();

// --- BÚSQUEDA POR CÓDIGO ---
function clearCode() {
    document.getElementById('codeInput').value = '';
    document.getElementById('results-code').innerHTML = '';
}

async function doCodeSearch() {
    const code = document.getElementById('codeInput').value.trim();
    if (!code) return;
    
    const formData = new FormData();
    formData.append('action', 'search_by_code');
    formData.append('code', code);
    
    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();
        render(data, 'results-code');
    } catch (error) {
        console.error('Error en búsqueda por código:', error);
        toast('Error al buscar. Intente nuevamente.');
    }
}

// --- TOGGLE DE CÓDIGOS ---
function toggleCodes(btn) {
    const id = btn.dataset.id;
    const pre = document.getElementById(`codes${id}`);
    if (!pre) return;
    
    const isHidden = pre.classList.toggle('hidden');
    btn.textContent = isHidden ? 'Ver Códigos' : 'Ocultar Códigos';
}

// --- RESALTAR PDF ---
async function highlightPdf(docId, codes, docName, pdfPath) {
    if (!codes) {
        toast('Este documento no tiene códigos para resaltar.');
        return;
    }
    
    toast('Procesando PDF, por favor espera...');
    
    const formData = new FormData();
    formData.append('action', 'highlight_pdf');
    formData.append('id', docId);
    formData.append('codes', codes);
    
    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
            const error = await response.json();
            throw new Error(error.message || 'Error al procesar el PDF.');
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        window.open(url, '_blank');
        
        // Obtener información de páginas encontradas
        let pagesFound = [];
        const pagesHeader = response.headers.get('X-Pages-Found');
        if (pagesHeader) {
            try {
                pagesFound = JSON.parse(pagesHeader);
            } catch (e) {
                console.error("Error al parsear X-Pages-Found:", e);
            }
        }
        
        // Mostrar modal con resultado
        const resultModal = document.getElementById('highlightResultOverlay');
        const resultContent = document.getElementById('highlightResultContent');
        
        let pagesHtml = '<p class="font-semibold">No se encontraron los códigos en el contenido del PDF.</p><p>Puedes descargar el archivo para revisarlo.</p>';
        if (pagesFound.length > 0) {
            pagesHtml = `<p class="font-semibold">Códigos encontrados en las páginas:</p><ul class="list-disc list-inside mt-2"><li>${pagesFound.join('</li><li>')}</li></ul>`;
        }
        
        resultContent.innerHTML = `
            <p class="mb-4">El PDF resaltado se ha abierto en una nueva pestaña.</p>
            <div class="p-2 bg-gray-100 rounded border mb-2">
                <p class="font-semibold">${docName}</p>
                <p class="text-sm text-gray-600">${pdfPath}</p>
            </div>
            <div class="mt-4 p-2 bg-gray-100 rounded">${pagesHtml}</div>
            <a href="${url}" download="resaltado_${pdfPath}" class="btn btn--secondary btn--full mt-4">Descargar de nuevo</a>
        `;
        
        resultModal.classList.remove('hidden');
        toast('PDF procesado exitosamente', 'success');
        
    } catch (error) {
        console.error('Error en highlightPdf:', error);
        toast('Error: ' + error.message);
    }
}

// --- INICIALIZACIÓN ---
document.addEventListener('DOMContentLoaded', () => {
    // Cerrar modal de resultado
    const highlightResultClose = document.getElementById('highlightResultClose');
    if (highlightResultClose) {
        highlightResultClose.onclick = () => {
            document.getElementById('highlightResultOverlay').classList.add('hidden');
        };
    }
    
    // Pestañas
    document.querySelectorAll('.tab').forEach(tab => {
        tab.onclick = () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab).classList.remove('hidden');
            
            if (tab.dataset.tab === 'tab-search') clearSearch();
            if (tab.dataset.tab === 'tab-code') clearCode();
        };
    });
    
    // Activar primera pestaña
    const firstTab = document.querySelector('.tab.active');
    if (firstTab) firstTab.click();
});

// Exponer funciones globales
window.doSearch = doSearch;
window.clearSearch = clearSearch;
window.doCodeSearch = doCodeSearch;
window.clearCode = clearCode;
window.toggleCodes = toggleCodes;
window.highlightPdf = highlightPdf;