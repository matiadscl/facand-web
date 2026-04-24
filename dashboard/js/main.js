/* ═══ Facand Dashboard — JS ═══ */

// ── Navegación ──────────────────────────────────────────────────────────────
function showPage(pageId) {
    document.querySelectorAll('.page').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.nav-item[data-page]').forEach(n => n.classList.remove('active'));
    const page = document.getElementById('page-' + pageId);
    const nav = document.querySelector(`.nav-item[data-page="${pageId}"]`);
    if (page) page.style.display = 'block';
    if (nav) nav.classList.add('active');
    localStorage.setItem('facand_page', pageId);
    if (pageId === 'calendario') renderCalendar();
}

document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('facand_page');
    if (saved && document.getElementById('page-' + saved) && document.querySelector(`.nav-item[data-page="${saved}"]`)) showPage(saved);

    // Auto-fill monto en pago al seleccionar cliente
    const pagoCliente = document.getElementById('pago-cliente');
    if (pagoCliente) {
        pagoCliente.addEventListener('change', () => {
            const opt = pagoCliente.selectedOptions[0];
            if (opt && opt.dataset.fee) {
                document.getElementById('pago-monto').value = opt.dataset.fee;
            }
        });
    }
});

// ── Modales ─────────────────────────────────────────────────────────────────
function openModal(type) {
    const modal = document.getElementById('modal-' + type);
    if (modal) modal.classList.add('active');
}
function closeModal(type) {
    const modal = document.getElementById('modal-' + type);
    if (modal) { modal.classList.remove('active'); modal.querySelector('form')?.reset(); }
}
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
});

// ── API ─────────────────────────────────────────────────────────────────────
async function apiPost(action, data) {
    const r = await fetch('api/data.php?action=' + action, {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data)
    });
    return r.json();
}

// ── Tarea CRUD ──────────────────────────────────────────────────────────────
async function submitTarea(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(document.getElementById('form-tarea')).entries());
    Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
    const r = await apiPost('crear_tarea', data);
    if (r.ok) { closeModal('tarea'); location.reload(); }
    return false;
}

async function updateTarea(id, estado) {
    const r = await apiPost('actualizar_tarea', { id, estado });
    if (r.ok) location.reload();
}

function openDatePicker(id, cell) {
    const input = cell.querySelector('.fecha-input');
    if (input.showPicker) input.showPicker();
    else input.click();
}

async function updateFechaLimite(id, fecha, input) {
    const r = await apiPost('actualizar_tarea', { id, fecha_limite: fecha || null });
    if (r.ok) {
        const display = input.closest('td').querySelector('.fecha-display');
        display.textContent = fecha || '-';
        input.closest('td').style.color = '';
        input.closest('td').style.fontWeight = '';
    }
}

// ── Proyecto CRUD ───────────────────────────────────────────────────────────
async function submitProyecto(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(document.getElementById('form-proyecto')).entries());
    Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
    const r = await apiPost('crear_proyecto', data);
    if (r.ok) { closeModal('proyecto'); location.reload(); }
    return false;
}

// ── Pago ────────────────────────────────────────────────────────────────────
async function submitPago(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(document.getElementById('form-pago')).entries());
    Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
    const r = await apiPost('registrar_pago', data);
    if (r.ok) { closeModal('pago'); location.reload(); }
    return false;
}

async function registrarPagoRapido(clienteId, monto) {
    if (!confirm(`¿Registrar pago de $${monto.toLocaleString('es-CL')} ?`)) return;
    const r = await apiPost('registrar_pago', {
        cliente_id: clienteId, monto, fecha: new Date().toISOString().split('T')[0], metodo: 'transferencia'
    });
    if (r.ok) location.reload();
}

// ── Suscripción ─────────────────────────────────────────────────────────────
let currentSubClienteId = null;

async function editSuscripcion(clienteId, clienteNombre) {
    currentSubClienteId = clienteId;
    document.getElementById('sub-modal-title').textContent = 'Suscripción — ' + clienteNombre;
    document.getElementById('sub-cliente-id').value = clienteId;

    try {
        const r = await fetch('api/data.php?action=suscripcion&cliente_id=' + clienteId);
        const data = await r.json();
        const s = data.suscripcion;
        if (s) {
            document.getElementById('sub-fee').value = s.fee_mensual || '';
            document.getElementById('sub-ciclo').value = s.ciclo_facturacion || 'mensual';
            document.getElementById('sub-dia').value = s.dia_facturacion || '';
            document.getElementById('sub-inicio').value = s.fecha_inicio || '';
            document.getElementById('sub-fin').value = s.fecha_fin || '';
            document.getElementById('sub-impl-monto').value = s.implementacion_monto || '';
            document.getElementById('sub-impl-pagado').value = s.implementacion_pagado || '';
            document.getElementById('sub-impl-estado').value = s.implementacion_estado || 'pendiente';
            document.getElementById('sub-notas').value = s.notas || '';
        } else {
            document.getElementById('form-suscripcion').reset();
            document.getElementById('sub-cliente-id').value = clienteId;
        }
        renderAdicionales(data.adicionales || []);
    } catch (e) { console.error(e); }
    openModal('suscripcion');
}

function renderAdicionales(adicionales) {
    const list = document.getElementById('sub-adicionales-list');
    if (!adicionales.length) {
        list.innerHTML = '<p style="font-size:0.82rem;color:var(--text-muted)">Sin servicios adicionales</p>';
        return;
    }
    list.innerHTML = adicionales.map(a => `
        <div style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem 0.75rem;background:var(--bg-dark);border-radius:6px;margin-bottom:0.4rem">
            <span style="flex:1;font-size:0.85rem">${a.nombre}</span>
            <span style="font-size:0.85rem;font-weight:600;color:var(--accent)">$${parseInt(a.monto).toLocaleString('es-CL')}</span>
            <span class="badge badge-${a.tipo === 'recurrente' ? 'en-progreso' : 'nuevo'}" style="font-size:0.65rem">${a.tipo}</span>
            <button type="button" class="btn-icon" onclick="removeServicioAdicional(${a.id})" style="font-size:0.75rem" title="Eliminar">✕</button>
        </div>
    `).join('');
}

function addServicioAdicional() {
    document.getElementById('sub-add-form').style.display = '';
    document.getElementById('add-srv-nombre').focus();
}

async function saveServicioAdicional() {
    const nombre = document.getElementById('add-srv-nombre').value.trim();
    const monto = document.getElementById('add-srv-monto').value;
    const tipo = document.getElementById('add-srv-tipo').value;
    if (!nombre || !monto) return;
    await apiPost('agregar_servicio_adicional', { cliente_id: currentSubClienteId, nombre, monto: parseInt(monto), tipo });
    document.getElementById('add-srv-nombre').value = '';
    document.getElementById('add-srv-monto').value = '';
    document.getElementById('sub-add-form').style.display = 'none';
    const r = await fetch('api/data.php?action=suscripcion&cliente_id=' + currentSubClienteId);
    const data = await r.json();
    renderAdicionales(data.adicionales || []);
}

async function removeServicioAdicional(id) {
    if (!confirm('¿Eliminar este servicio adicional?')) return;
    await apiPost('eliminar_servicio_adicional', { id });
    const r = await fetch('api/data.php?action=suscripcion&cliente_id=' + currentSubClienteId);
    const data = await r.json();
    renderAdicionales(data.adicionales || []);
}

async function submitSuscripcion(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(document.getElementById('form-suscripcion')).entries());
    Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
    const r = await apiPost('guardar_suscripcion', data);
    if (r.ok) { closeModal('suscripcion'); location.reload(); }
    return false;
}

// ── Facturación ─────────────────────────────────────────────────────────────
async function generarFacturacion() {
    const periodo = new Date().toISOString().slice(0, 7);
    const r = await apiPost('generar_facturacion', { periodo });
    if (r.ok) {
        if (r.lineas > 0) alert(`Facturación generada: ${r.lineas} líneas para ${periodo}`);
        else alert('La facturación de este mes ya está generada.');
        location.reload();
    }
}

async function updateFacturacion(id, estado) {
    await apiPost('actualizar_facturacion', { id, estado });
}

// ── Cliente edición ─────────────────────────────────────────────────────────
function editCliente(id) {
    const cl = clientesData.find(c => c.id === id);
    if (!cl) return;
    document.getElementById('edit-cliente-id').value = cl.id;
    document.getElementById('edit-tipo').value = cl.tipo || 'suscripcion';
    document.getElementById('edit-estado').value = cl.estado || 'activo';
    document.getElementById('edit-responsable').value = cl.responsable_id || '';
    const feeEl = document.getElementById('edit-fee');
    if (feeEl) feeEl.value = cl.fee_mensual || '';
    const facEl = document.getElementById('edit-facturacion');
    if (facEl) facEl.value = cl.fecha_facturacion || '';
    document.getElementById('edit-servicios').value = cl.servicios || '';
    document.getElementById('edit-contacto').value = cl.contacto_nombre || '';
    document.getElementById('edit-email').value = cl.contacto_email || '';
    document.getElementById('edit-telefono').value = cl.contacto_telefono || '';
    const epEl = document.getElementById('edit-estado-pago');
    if (epEl) epEl.value = cl.estado_pago || 'pendiente';
    document.getElementById('edit-url-dashboard').value = cl.url_dashboard || '';
    document.getElementById('edit-notas').value = cl.notas || '';
    openModal('cliente');
}

async function submitCliente(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(document.getElementById('form-cliente')).entries());
    Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
    if (data.fee_mensual) data.fee_mensual = parseInt(data.fee_mensual);
    if (data.fecha_facturacion) data.fecha_facturacion = parseInt(data.fecha_facturacion);
    const r = await apiPost('actualizar_cliente', data);
    if (r.ok) { closeModal('cliente'); location.reload(); }
    return false;
}

// ── Filtros Tareas ──────────────────────────────────────────────────────────
function filterTareas() {
    const cliente = document.getElementById('filter-t-cliente').value;
    const asignado = document.getElementById('filter-t-asignado').value;
    const prioridad = document.getElementById('filter-t-prioridad').value;

    document.querySelectorAll('.tarea-row').forEach(row => {
        const match =
            (!cliente || row.dataset.cliente === cliente) &&
            (!asignado || row.dataset.asignado === asignado) &&
            (!prioridad || row.dataset.prioridad === prioridad);
        row.style.display = match ? '' : 'none';
    });

    // Also filter kanban
    document.querySelectorAll('.kanban-card').forEach(card => {
        const meta = card.querySelector('.kanban-card-meta')?.textContent || '';
        const pDot = card.querySelector('.priority-dot');
        const pClass = pDot ? [...pDot.classList].find(c => c !== 'priority-dot') : '';
        const match =
            (!cliente || meta.includes(cliente)) &&
            (!asignado || meta.includes(asignado)) &&
            (!prioridad || pClass === prioridad);
        card.style.display = match ? '' : 'none';
    });
}

function filterClientes() {
    const estado = document.getElementById('filter-clientes-estado').value;
    document.querySelectorAll('#tabla-clientes tbody tr').forEach(row => {
        row.style.display = (!estado || row.dataset.estado === estado) ? '' : 'none';
    });
}

// ── Vista Tareas (tabla/kanban) ─────────────────────────────────────────────
function setTareasView(view, btn) {
    document.getElementById('tareas-table-view').style.display = view === 'table' ? '' : 'none';
    document.getElementById('tareas-kanban-view').style.display = view === 'kanban' ? '' : 'none';
    document.querySelectorAll('.view-toggle button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── Kanban Drag & Drop ──────────────────────────────────────────────────────
let draggedTareaId = null;

function dragTarea(e, id) {
    draggedTareaId = id;
    e.target.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
}

async function dropTarea(e, nuevoEstado) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    document.querySelectorAll('.kanban-card.dragging').forEach(c => c.classList.remove('dragging'));
    if (draggedTareaId) {
        await updateTarea(draggedTareaId, nuevoEstado);
        draggedTareaId = null;
    }
}

// ── Calendario ──────────────────────────────────────────────────────────────
let calYear = new Date().getFullYear();
let calMonth = new Date().getMonth(); // 0-indexed

const MESES_ES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const DIAS_ES = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

function changeMonth(delta) {
    if (delta === 0) { calYear = new Date().getFullYear(); calMonth = new Date().getMonth(); }
    else { calMonth += delta; if (calMonth < 0) { calMonth = 11; calYear--; } if (calMonth > 11) { calMonth = 0; calYear++; } }
    renderCalendar();
}

async function renderCalendar() {
    const grid = document.getElementById('calendar-grid');
    const title = document.getElementById('calendar-title');
    if (!grid || !title) return;

    const mesStr = `${calYear}-${String(calMonth + 1).padStart(2, '0')}`;
    title.textContent = `${MESES_ES[calMonth]} ${calYear}`;

    // Fetch eventos
    let eventos = [];
    try {
        const r = await fetch(`api/data.php?action=calendario&mes=${mesStr}`);
        eventos = await r.json();
    } catch (e) { console.error(e); }

    // Crear mapa fecha -> eventos
    const eventosPorDia = {};
    eventos.forEach(ev => {
        if (!ev.fecha) return;
        if (!eventosPorDia[ev.fecha]) eventosPorDia[ev.fecha] = [];
        eventosPorDia[ev.fecha].push(ev);
    });

    // Generar grid
    const primerDia = new Date(calYear, calMonth, 1);
    const ultimoDia = new Date(calYear, calMonth + 1, 0);
    let startDay = primerDia.getDay(); // 0=dom
    startDay = startDay === 0 ? 6 : startDay - 1; // Convertir a lun=0

    const hoy = new Date().toISOString().split('T')[0];
    let html = DIAS_ES.map(d => `<div class="calendar-header">${d}</div>`).join('');

    // Días del mes anterior
    const prevMonth = new Date(calYear, calMonth, 0);
    for (let i = startDay - 1; i >= 0; i--) {
        const day = prevMonth.getDate() - i;
        html += `<div class="calendar-day other-month"><div class="calendar-day-num">${day}</div></div>`;
    }

    // Días del mes
    for (let d = 1; d <= ultimoDia.getDate(); d++) {
        const fecha = `${calYear}-${String(calMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const isToday = fecha === hoy;
        const dayEvents = eventosPorDia[fecha] || [];

        html += `<div class="calendar-day${isToday ? ' today' : ''}">`;
        html += `<div class="calendar-day-num">${d}</div>`;
        dayEvents.slice(0, 3).forEach(ev => {
            const tipo = ev.tipo_evento || 'tarea';
            const isVencido = tipo === 'tarea' && ev.estado !== 'completada' && fecha < hoy;
            html += `<div class="calendar-event ${isVencido ? 'vencido' : tipo}" title="${ev.titulo}">${ev.titulo}</div>`;
        });
        if (dayEvents.length > 3) {
            html += `<div style="font-size:0.65rem;color:var(--text-muted)">+${dayEvents.length - 3} más</div>`;
        }
        html += '</div>';
    }

    // Rellenar fin
    const totalCells = startDay + ultimoDia.getDate();
    const remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let i = 1; i <= remaining; i++) {
        html += `<div class="calendar-day other-month"><div class="calendar-day-num">${i}</div></div>`;
    }

    grid.innerHTML = html;
}

// ── Admin: Permisos ─────────────────────────────────────────────────────────
async function togglePermiso(userId, seccion, el) {
    const r = await apiPost('toggle_permiso', { user_id: userId, seccion });
    if (r.ok) {
        el.textContent = r.permitido ? '✅' : '❌';
        el.title = `${userId} — ${seccion}: ${r.permitido ? 'Permitido' : 'Bloqueado'}`;
    }
}

// ── Ficha de Cliente ────────────────────────────────────────────────────────
let currentFichaSlug = null;

async function loadFichaCliente(slug) {
    currentFichaSlug = slug;
    const container = document.getElementById('ficha-content');
    container.innerHTML = '<div class="empty-state"><p>Cargando...</p></div>';

    try {
        const r = await fetch(`api/data.php?action=ficha_cliente&slug=${slug}`);
        const data = await r.json();
        if (!data.ok) { container.innerHTML = '<div class="empty-state"><h3>Error cargando ficha</h3></div>'; return; }

        const f = data.ficha;
        const plan = data.plan;
        const precioCustom = data.precio_custom;

        document.getElementById('ficha-title').textContent = f.nombre || slug;
        document.getElementById('ficha-subtitle').textContent = 'Actualizado: ' + (f.ultima_actualizacion || '-');

        let html = '';

        // Info cards
        html += '<div class="kpi-grid" style="margin-bottom:1.5rem">';
        const infoFields = Object.entries(f.info).filter(([k]) => k && k !== 'Detalle' && !k.startsWith('-'));
        infoFields.forEach(([key, val]) => {
            html += '<div class="kpi-card"><div class="kpi-label">' + esc(key) + '</div><div class="kpi-value" style="font-size:1rem">' + esc(val) + '</div></div>';
        });
        if (f.etapa) {
            html += '<div class="kpi-card"><div class="kpi-label">Etapa</div><div class="kpi-value" style="font-size:1rem">' + esc(f.etapa) + '</div></div>';
        }
        if (f.estado_pagos) {
            const isPending = f.estado_pagos.toLowerCase().includes('debe');
            html += '<div class="kpi-card" style="border-color:' + (isPending ? 'var(--danger)' : 'var(--success)') + '"><div class="kpi-label">Estado de Pagos</div><div class="kpi-value" style="font-size:0.9rem;color:' + (isPending ? 'var(--danger)' : 'var(--success)') + '">' + esc(f.estado_pagos.replace(/\*\*/g, '')) + '</div></div>';
        }
        if (plan) {
            html += '<div class="kpi-card kpi-highlight"><div class="kpi-label">Plan</div><div class="kpi-value" style="font-size:1rem">' + esc(plan.nombre) + '</div><div class="kpi-detail">' + esc(precioCustom || plan.precio) + '</div></div>';
        }
        html += '</div>';

        // Grid: Servicios + Herramientas
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">';
        if (f.servicios && f.servicios.length) {
            html += '<div class="section"><div class="section-header"><h2>Servicios Contratados</h2></div><ul style="list-style:none;padding:0">';
            f.servicios.forEach(function(s) {
                html += '<li style="padding:0.5rem 0;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.5rem"><span style="color:var(--success);font-weight:700">✓</span> ' + esc(s) + '</li>';
            });
            html += '</ul></div>';
        }
        if (f.herramientas && f.herramientas.length) {
            html += '<div class="section"><div class="section-header"><h2>Herramientas</h2></div><div class="client-services" style="gap:0.5rem">';
            f.herramientas.forEach(function(h) {
                html += '<span class="service-tag" style="padding:0.4rem 0.8rem;font-size:0.78rem">' + esc(h) + '</span>';
            });
            html += '</div></div>';
        }
        html += '</div>';

        // Equipo
        if (f.equipo && f.equipo.length) {
            html += '<div class="section"><div class="section-header"><h2>Equipo Asignado</h2></div><div class="team-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">';
            f.equipo.forEach(function(e) {
                html += '<div class="team-card"><div class="team-info"><h3>' + esc(e.persona) + '</h3><div class="team-role">' + esc(e.rol) + '</div><div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem">' + esc(e.acceso) + '</div></div></div>';
            });
            html += '</div></div>';
        }

        // Plan scope
        if (plan) {
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">';
            html += '<div class="section"><div class="section-header"><h2>Incluido en el Plan</h2></div><ul style="list-style:none;padding:0">';
            plan.incluye.forEach(function(i) {
                html += '<li style="padding:0.4rem 0;border-bottom:1px solid var(--border);font-size:0.82rem"><span style="color:var(--success)">✓</span> ' + esc(i) + '</li>';
            });
            html += '</ul></div>';
            html += '<div class="section"><div class="section-header"><h2>No Incluido</h2></div><ul style="list-style:none;padding:0">';
            plan.no_incluye.forEach(function(i) {
                html += '<li style="padding:0.4rem 0;border-bottom:1px solid var(--border);font-size:0.82rem;color:var(--text-secondary)"><span style="color:var(--danger)">✕</span> ' + esc(i) + '</li>';
            });
            html += '</ul></div></div>';
        }

        // Pendientes
        if ((f.pendientes && f.pendientes.length) || (f.pendientes_done && f.pendientes_done.length)) {
            html += '<div class="section"><div class="section-header"><h2>Pendientes</h2></div><ul style="list-style:none;padding:0">';
            if (f.pendientes) f.pendientes.forEach(function(p) {
                var isUrgent = p.toLowerCase().indexOf('cobrar') >= 0 || p.toLowerCase().indexOf('debe') >= 0;
                html += '<li style="padding:0.5rem 0;border-bottom:1px solid var(--border);' + (isUrgent ? 'color:var(--danger);font-weight:600' : '') + '"><span style="color:var(--warning)">○</span> ' + esc(p) + '</li>';
            });
            if (f.pendientes_done) f.pendientes_done.forEach(function(p) {
                html += '<li style="padding:0.4rem 0;border-bottom:1px solid var(--border);color:var(--text-muted);text-decoration:line-through;font-size:0.82rem"><span style="color:var(--success)">✓</span> ' + esc(p) + '</li>';
            });
            html += '</ul></div>';
        }

        container.innerHTML = html;
    } catch (e) {
        console.error(e);
        container.innerHTML = '<div class="empty-state"><h3>Error de conexión</h3></div>';
    }
}

function downloadFicha() {
    if (currentFichaSlug) window.open('ficha-pdf.php?cliente=' + currentFichaSlug, '_blank');
}

function downloadServicio() {
    if (currentFichaSlug) window.open('servicio-pdf.php?cliente=' + currentFichaSlug, '_blank');
}

function esc(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Herramientas ────────────────────────────────────────────────────────────
async function toggleHerramienta(id, estadoActual, el) {
    const r = await apiPost('toggle_herramienta', { id, estado_actual: estadoActual });
    if (r.ok) {
        const icons = { configurado: '✅', no_aplica: '➖', pendiente: '⬜' };
        el.textContent = icons[r.nuevo_estado] || '⬜';
        el.setAttribute('onclick', `toggleHerramienta(${id}, '${r.nuevo_estado}', this)`);
    }
}
