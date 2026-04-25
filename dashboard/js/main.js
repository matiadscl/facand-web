/**
 * Dashboard Base — JavaScript principal
 * Navegación, modales, API client, toasts, utilidades
 */

// ============================================================
// SIDEBAR MOBILE
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    const btnMenu = document.getElementById('btnMenu');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (btnMenu) {
        btnMenu.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }

    // Init tabs if present
    initTabs();

    // Modal close handlers (need DOM ready)
    document.getElementById('modalClose')?.addEventListener('click', Modal.close);
    document.getElementById('modalOverlay')?.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) Modal.close();
    });
});

// ============================================================
// API CLIENT — wrapper para fetch con CSRF
// ============================================================
const API = {
    /**
     * Realiza petición POST a la API
     * @param {string} action - Nombre de la acción
     * @param {object} data - Datos a enviar
     * @returns {Promise<object>} Respuesta JSON
     */
    async post(action, data = {}) {
        try {
            const body = new FormData();
            body.append('action', action);
            body.append('csrf_token', APP.csrf);
            for (const [key, val] of Object.entries(data)) {
                if (val !== null && val !== undefined) {
                    body.append(key, val);
                }
            }
            const res = await fetch('api/data.php', { method: 'POST', body });
            const json = await res.json();
            if (!json.ok) {
                toast(json.error || 'Error en la operación', 'error');
                return null;
            }
            return json;
        } catch (err) {
            toast('Error de conexión', 'error');
            console.error(err);
            return null;
        }
    },

    /**
     * Realiza petición GET a la API
     * @param {string} action - Nombre de la acción
     * @param {object} params - Parámetros de query
     * @returns {Promise<object>} Respuesta JSON
     */
    async get(action, params = {}) {
        try {
            const query = new URLSearchParams({ action, ...params });
            const res = await fetch('api/data.php?' + query.toString());
            const json = await res.json();
            if (!json.ok) {
                toast(json.error || 'Error al cargar datos', 'error');
                return null;
            }
            return json;
        } catch (err) {
            toast('Error de conexión', 'error');
            console.error(err);
            return null;
        }
    }
};

// ============================================================
// MODAL
// ============================================================
const Modal = {
    /**
     * Abre el modal con contenido dinámico
     * @param {string} title - Título del modal
     * @param {string} bodyHtml - HTML del body
     * @param {string} footerHtml - HTML del footer (botones)
     */
    open(title, bodyHtml, footerHtml = '') {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = bodyHtml;
        document.getElementById('modalFooter').innerHTML = footerHtml;
        document.getElementById('modalOverlay').classList.add('active');
    },

    /** Cierra el modal */
    close() {
        document.getElementById('modalOverlay').classList.remove('active');
    }
};

// Close modal handlers moved to DOMContentLoaded above

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================
/**
 * Muestra una notificación toast
 * @param {string} message - Mensaje
 * @param {string} type - success|error|info
 * @param {number} duration - Milisegundos antes de desaparecer
 */
function toast(message, type = 'success', duration = 3000) {
    const container = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.textContent = message;
    container.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(100%)';
        el.style.transition = 'all .3s ease';
        setTimeout(() => el.remove(), 300);
    }, duration);
}

// ============================================================
// TABS
// ============================================================
function initTabs() {
    document.querySelectorAll('.tabs').forEach(tabGroup => {
        const tabs = tabGroup.querySelectorAll('.tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                // Deactivate all tabs in group
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                // Show target content
                const parent = tabGroup.parentElement;
                parent.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                const content = parent.querySelector(`[data-tab-content="${target}"]`);
                if (content) content.classList.add('active');
            });
        });
    });
}

// ============================================================
// FORM HELPERS
// ============================================================
/**
 * Recolecta datos de un formulario dentro del modal
 * @param {string} formId - ID del form
 * @returns {object} Datos del form como objeto
 */
function getFormData(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    const data = {};
    new FormData(form).forEach((val, key) => { data[key] = val; });
    return data;
}

/**
 * Genera HTML de un form-group
 * @param {string} name - Nombre del campo
 * @param {string} label - Label visible
 * @param {string} type - Tipo de input
 * @param {string} value - Valor actual
 * @param {object} opts - Opciones extra (required, options para select, fullWidth)
 * @returns {string} HTML
 */
function formField(name, label, type = 'text', value = '', opts = {}) {
    const cls = opts.fullWidth ? 'form-group full-width' : 'form-group';
    const req = opts.required ? 'required' : '';

    if (type === 'select' && opts.options) {
        let optionsHtml = '<option value="">Seleccionar...</option>';
        for (const [val, text] of Object.entries(opts.options)) {
            const sel = val == value ? 'selected' : '';
            optionsHtml += `<option value="${val}" ${sel}>${text}</option>`;
        }
        return `<div class="${cls}">
            <label class="form-label">${label}</label>
            <select name="${name}" class="form-select" ${req}>${optionsHtml}</select>
        </div>`;
    }

    if (type === 'textarea') {
        return `<div class="${cls}">
            <label class="form-label">${label}</label>
            <textarea name="${name}" class="form-textarea" ${req}>${escHtml(value)}</textarea>
        </div>`;
    }

    return `<div class="${cls}">
        <label class="form-label">${label}</label>
        <input type="${type}" name="${name}" class="form-input" value="${escHtml(value)}" ${req}>
    </div>`;
}

/**
 * Escapa HTML para prevenir XSS
 * @param {string} str
 * @returns {string}
 */
function escHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

// ============================================================
// IVA HELPERS
// ============================================================
/**
 * Calcula IVA (19%) desde neto y actualiza campos del formulario
 * Busca inputs con name="monto_neto", "iva", "total"
 */
function calcIVA() {
    const netoEl = document.querySelector('[name="monto_neto"]');
    const ivaEl = document.querySelector('[name="iva"]');
    const totalEl = document.querySelector('[name="total"]');
    if (!netoEl) return;
    const neto = parseInt(netoEl.value) || 0;
    const iva = Math.round(neto * 0.19);
    if (ivaEl) ivaEl.value = iva;
    if (totalEl) totalEl.value = neto + iva;
}

// ============================================================
// FORMAT HELPERS
// ============================================================
/**
 * Formatea número como moneda CLP
 * @param {number} n
 * @returns {string}
 */
function fmtMoney(n) {
    return '$' + parseInt(n || 0).toLocaleString('es-CL');
}

/**
 * Formatea fecha ISO a dd/mm/yyyy
 * @param {string} d - Fecha ISO
 * @returns {string}
 */
function fmtDate(d) {
    if (!d) return '-';
    const parts = d.split('-');
    if (parts.length >= 3) return `${parts[2].substring(0,2)}/${parts[1]}/${parts[0]}`;
    return d;
}

// ============================================================
// TABLE HELPERS
// ============================================================
/**
 * Refresca la página actual (recarga el módulo)
 */
function refreshPage() {
    window.location.reload();
}

/**
 * Confirma una acción destructiva
 * @param {string} msg - Mensaje de confirmación
 * @returns {boolean}
 */
function confirmAction(msg = '¿Estás seguro?') {
    return confirm(msg);
}
