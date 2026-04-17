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
    if (saved && document.getElementById('page-' + saved)) showPage(saved);

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
    document.getElementById('edit-fee').value = cl.fee_mensual || '';
    document.getElementById('edit-facturacion').value = cl.fecha_facturacion || '';
    document.getElementById('edit-servicios').value = cl.servicios || '';
    document.getElementById('edit-contacto').value = cl.contacto_nombre || '';
    document.getElementById('edit-email').value = cl.contacto_email || '';
    document.getElementById('edit-telefono').value = cl.contacto_telefono || '';
    document.getElementById('edit-estado-pago').value = cl.estado_pago || 'pendiente';
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
