<?php
/**
 * Módulo Facturación — Emisión y control de facturas
 * AUTOMATIZACIÓN: Al emitir factura → genera cuenta por cobrar automáticamente (trigger SQLite)
 * Al anular factura → anula cuenta por cobrar automáticamente
 */

$filtro_estado = $_GET['estado'] ?? '';
$where = $filtro_estado ? 'AND f.estado = ?' : '';
$params = $filtro_estado ? [$filtro_estado] : [];

$facturas = query_all("SELECT f.*, c.nombre as cliente_nombre,
    (SELECT estado FROM cuentas_cobrar WHERE factura_id = f.id LIMIT 1) as estado_cobro
    FROM facturas f
    LEFT JOIN clientes c ON f.cliente_id = c.id
    WHERE 1=1 $where
    ORDER BY f.created_at DESC", $params);

$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');
$proyectos_list = query_all('SELECT id, nombre FROM proyectos WHERE estado = "activo" ORDER BY nombre');

// KPIs
$total_emitidas = query_scalar('SELECT COUNT(*) FROM facturas WHERE estado = "emitida"') ?? 0;
$total_pagadas = query_scalar('SELECT COUNT(*) FROM facturas WHERE estado = "pagada" AND strftime("%Y-%m", pagado_at) = strftime("%Y-%m", "now")') ?? 0;
$monto_emitido_mes = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = strftime("%Y-%m", "now") AND estado != "anulada"') ?? 0;
$monto_cobrado_mes = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado = "pagada" AND strftime("%Y-%m", pagado_at) = strftime("%Y-%m", "now")') ?? 0;

// Último número de factura
$last_num = query_scalar('SELECT numero FROM facturas ORDER BY id DESC LIMIT 1') ?? 'F-0000';
$next_num_int = intval(preg_replace('/\D/', '', $last_num)) + 1;
$next_num = 'F-' . str_pad($next_num_int, 4, '0', STR_PAD_LEFT);
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Emitidas Pendientes</div>
        <div class="kpi-value warning"><?= $total_emitidas ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Cobradas Este Mes</div>
        <div class="kpi-value success"><?= $total_pagadas ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Facturado Mes</div>
        <div class="kpi-value"><?= format_money($monto_emitido_mes) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Cobrado Mes</div>
        <div class="kpi-value success"><?= format_money($monto_cobrado_mes) ?></div>
    </div>
</div>

<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=billing&estado='+this.value">
        <option value="">Todos los estados</option>
        <option value="borrador" <?= $filtro_estado === 'borrador' ? 'selected' : '' ?>>Borrador</option>
        <option value="emitida" <?= $filtro_estado === 'emitida' ? 'selected' : '' ?>>Emitida</option>
        <option value="pagada" <?= $filtro_estado === 'pagada' ? 'selected' : '' ?>>Pagada</option>
        <option value="anulada" <?= $filtro_estado === 'anulada' ? 'selected' : '' ?>>Anulada</option>
    </select>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Facturas</span>
        <?php if (can_edit($current_user['id'], 'billing')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewInvoice()">+ Nueva Factura</button>
            <button class="btn btn-secondary btn-sm" onclick="generateMonthlyBilling()">Generar Facturacion Mensual</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>N°</th>
                <th>Cliente</th>
                <th>Concepto</th>
                <th>Neto</th>
                <th>IVA</th>
                <th>Total</th>
                <th>Estado</th>
                <th>Cobro</th>
                <th>Emisión</th>
                <th>Vencimiento</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($facturas as $f): ?>
            <tr>
                <td><strong><?= safe($f['numero']) ?></strong></td>
                <td><?= safe($f['cliente_nombre']) ?></td>
                <td><?= safe($f['concepto']) ?></td>
                <td><?= format_money($f['monto']) ?></td>
                <td><?= format_money($f['impuesto']) ?></td>
                <td><strong><?= format_money($f['total']) ?></strong></td>
                <td><span class="badge <?= status_class($f['estado'] === 'pagada' ? 'completado' : ($f['estado'] === 'emitida' ? 'pendiente' : $f['estado'])) ?>"><?= safe(ucfirst($f['estado'])) ?></span></td>
                <td>
                    <?php if ($f['estado_cobro']): ?>
                        <span class="badge <?= status_class($f['estado_cobro'] === 'pagado' ? 'completado' : ($f['estado_cobro'] === 'vencido' ? 'vencido' : 'pendiente')) ?>"><?= safe(ucfirst($f['estado_cobro'])) ?></span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?= format_date($f['fecha_emision']) ?></td>
                <td><?= format_date($f['fecha_vencimiento']) ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'billing')): ?>
                        <?php if ($f['estado'] === 'borrador'): ?>
                            <button class="btn btn-primary btn-sm" onclick="emitInvoice(<?= $f['id'] ?>)">Emitir</button>
                        <?php endif; ?>
                        <?php if ($f['estado'] === 'emitida'): ?>
                            <button class="btn btn-danger btn-sm" onclick="cancelInvoice(<?= $f['id'] ?>)">Anular</button>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" onclick="editInvoice(<?= $f['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($facturas)): ?>
            <tr><td colspan="11" class="empty-state">No hay facturas</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Archivos adjuntos -->
<div class="table-container" style="margin-top:20px;">
    <div class="table-header">
        <span class="table-title">Archivos Adjuntos</span>
        <?php if (can_edit($current_user['id'], 'billing')): ?>
            <button class="btn btn-primary btn-sm" onclick="openUploadModal()">Adjuntar Facturas</button>
        <?php endif; ?>
    </div>
    <div id="archivosContainer">
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:.85rem;">Cargando archivos...</div>
    </div>
</div>

<div style="margin-top:16px; padding:16px; background:var(--surface); border:1px solid var(--border); border-radius:12px; font-size:.8rem; color:var(--text-muted);">
    <strong>Automatización:</strong> Al emitir una factura se genera automáticamente una Cuenta por Cobrar. Al registrar pagos en Cuentas por Cobrar, se actualiza el estado de la factura y se registra el ingreso en Finanzas.
</div>

<script>
const bClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const bProyectosList = <?= json_encode(array_column($proyectos_list, 'nombre', 'id')) ?>;
const nextNum = '<?= $next_num ?>';

function openNewInvoice() {
    const body = `<form id="frmInvoice" class="form-grid">
        ${formField('numero', 'Número Factura', 'text', nextNum, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: bClientesList})}
        ${formField('proyecto_id', 'Proyecto (opcional)', 'select', '', {options: {'':'Sin proyecto', ...bProyectosList}})}
        ${formField('concepto', 'Concepto', 'text', '', {required: true})}
        ${formField('monto', 'Monto Neto', 'number', '', {required: true})}
        ${formField('impuesto', 'IVA (19%)', 'number', '')}
        ${formField('estado', 'Estado', 'select', 'emitida', {options: {borrador:'Borrador', emitida:'Emitida'}})}
        ${formField('fecha_emision', 'Fecha Emisión', 'date', new Date().toISOString().split('T')[0])}
        ${formField('fecha_vencimiento', 'Fecha Vencimiento', 'date')}
        ${formField('detalle', 'Detalle', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nueva Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveInvoice()">Guardar</button>`);

    // Auto-calcular IVA
    setTimeout(() => {
        const montoInput = document.querySelector('#frmInvoice [name="monto"]');
        const ivaInput = document.querySelector('#frmInvoice [name="impuesto"]');
        if (montoInput && ivaInput) {
            montoInput.addEventListener('input', () => {
                ivaInput.value = Math.round(parseInt(montoInput.value || 0) * 0.19);
            });
        }
    }, 100);
}

async function saveInvoice() {
    const data = getFormData('frmInvoice');
    data.total = parseInt(data.monto || 0) + parseInt(data.impuesto || 0);
    const res = await API.post('create_invoice', data);
    if (res) { toast('Factura creada. Cuenta por cobrar generada automáticamente.'); refreshPage(); }
}

async function emitInvoice(id) {
    if (!confirmAction('¿Emitir esta factura? Se generará una cuenta por cobrar automáticamente.')) return;
    const res = await API.post('update_invoice', { id, estado: 'emitida' });
    if (res) { toast('Factura emitida. Cuenta por cobrar generada.'); refreshPage(); }
}

async function cancelInvoice(id) {
    if (!confirmAction('¿Anular esta factura? Se anulará también la cuenta por cobrar asociada.')) return;
    const res = await API.post('update_invoice', { id, estado: 'anulada' });
    if (res) { toast('Factura anulada'); refreshPage(); }
}

async function editInvoice(id) {
    const res = await API.get('get_invoice', { id });
    if (!res) return;
    const f = res.data;
    const body = `<form id="frmInvoice" class="form-grid">
        <input type="hidden" name="id" value="${f.id}">
        ${formField('numero', 'Número', 'text', f.numero, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', f.cliente_id, {options: bClientesList})}
        ${formField('proyecto_id', 'Proyecto (opcional)', 'select', f.proyecto_id || '', {options: {'':'Sin proyecto', ...bProyectosList}})}
        ${formField('concepto', 'Concepto', 'text', f.concepto, {required: true})}
        ${formField('monto', 'Monto Neto', 'number', f.monto)}
        ${formField('impuesto', 'IVA', 'number', f.impuesto)}
        ${formField('fecha_vencimiento', 'Fecha Vencimiento', 'date', f.fecha_vencimiento || '')}
        ${formField('detalle', 'Detalle', 'textarea', f.detalle, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateInvoice()">Actualizar</button>`);
}

async function updateInvoice() {
    const data = getFormData('frmInvoice');
    data.total = parseInt(data.monto || 0) + parseInt(data.impuesto || 0);
    const res = await API.post('update_invoice', data);
    if (res) { toast('Factura actualizada'); refreshPage(); }
}

async function generateMonthlyBilling() {
    const mes = new Date().toLocaleString('es-CL', { month: 'long', year: 'numeric' });
    if (!confirmAction(`¿Generar facturas mensuales para todos los clientes activos con fee_mensual > 0? (${mes})`)) return;
    const res = await API.post('generate_monthly_billing', {});
    if (res) {
        toast(`Facturacion mensual generada: ${res.data.created} facturas creadas, ${res.data.skipped} omitidas`);
        refreshPage();
    }
}

// ---- Archivos adjuntos ----

// Cargar servicios al seleccionar cliente
function onUploadClientChange(select) {
    const clienteId = select.value;
    const serviciosDiv = document.getElementById('uploadServicios');
    if (!clienteId) { serviciosDiv.innerHTML = ''; return; }

    // Buscar servicios del cliente via API
    API.get('get_client', { id: clienteId }).then(res => {
        if (!res || !res.data.servicios) { serviciosDiv.innerHTML = ''; return; }
        const svcs = res.data.servicios.split(',').map(s => s.trim()).filter(s => s);
        if (svcs.length === 0) { serviciosDiv.innerHTML = '<span style="font-size:.8rem;color:var(--text-muted)">Sin servicios registrados</span>'; return; }
        serviciosDiv.innerHTML = '<label class="form-label">Asociar a servicio</label><select name="servicio" class="form-select">' +
            '<option value="">General (sin servicio específico)</option>' +
            svcs.map(s => `<option value="${escHtml(s)}">${escHtml(s)}</option>`).join('') +
            '</select>';
    });
}

function openUploadModal() {
    // Opciones de facturas existentes
    const facturasOpts = <?= json_encode(array_map(fn($f) => ['id' => $f['id'], 'num' => $f['numero'], 'cliente' => $f['cliente_nombre']], $facturas)) ?>;
    let facturaOptions = '<option value="">Sin asociar a factura</option>';
    facturasOpts.forEach(f => {
        facturaOptions += `<option value="${f.id}">${escHtml(f.num)} — ${escHtml(f.cliente)}</option>`;
    });

    const body = `
    <form id="frmUpload" enctype="multipart/form-data" style="display:grid;gap:16px;">
        <div class="form-group">
            <label class="form-label">Archivos PDF (puedes seleccionar varios)</label>
            <input type="file" name="archivos" id="inputArchivos" multiple accept=".pdf,.png,.jpg,.jpeg,.xml"
                style="padding:12px;background:var(--bg);border:2px dashed var(--border);border-radius:10px;color:var(--text);cursor:pointer;width:100%;font-size:.85rem;"
                onchange="updateFileCount(this)">
            <div id="fileCount" style="font-size:.75rem;color:var(--text-muted);margin-top:4px;"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Cliente</label>
            <select name="cliente_id" class="form-select" required onchange="onUploadClientChange(this)">
                <option value="">Seleccionar cliente...</option>
                ${Object.entries(bClientesList).map(([id, name]) => '<option value="'+id+'">'+escHtml(name)+'</option>').join('')}
            </select>
        </div>
        <div id="uploadServicios"></div>
        <div class="form-group">
            <label class="form-label">Asociar a factura (opcional)</label>
            <select name="factura_id" class="form-select">${facturaOptions}</select>
        </div>
        <div class="form-group">
            <label class="form-label">Notas (opcional)</label>
            <textarea name="notas" class="form-textarea" rows="2" placeholder="Descripción o comentarios..."></textarea>
        </div>
    </form>`;

    Modal.open('Adjuntar Facturas / Documentos', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="uploadFiles()" id="btnUpload">Subir Archivos</button>`);
}

function updateFileCount(input) {
    const count = input.files.length;
    const totalSize = Array.from(input.files).reduce((s, f) => s + f.size, 0);
    document.getElementById('fileCount').textContent = count > 0
        ? `${count} archivo${count > 1 ? 's' : ''} seleccionado${count > 1 ? 's' : ''} (${(totalSize / 1024 / 1024).toFixed(1)} MB)`
        : '';
}

async function uploadFiles() {
    const form = document.getElementById('frmUpload');
    const files = document.getElementById('inputArchivos').files;
    const clienteId = form.querySelector('[name="cliente_id"]').value;

    if (!files.length) { toast('Selecciona al menos un archivo', 'error'); return; }
    if (!clienteId) { toast('Selecciona un cliente', 'error'); return; }

    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.textContent = 'Subiendo...';

    const formData = new FormData();
    formData.append('action', 'upload_invoices');
    formData.append('csrf_token', APP.csrf);
    formData.append('cliente_id', clienteId);
    formData.append('factura_id', form.querySelector('[name="factura_id"]').value || '');
    formData.append('servicio', form.querySelector('[name="servicio"]')?.value || '');
    formData.append('notas', form.querySelector('[name="notas"]').value || '');

    for (let i = 0; i < files.length; i++) {
        formData.append('archivos[]', files[i]);
    }

    try {
        const res = await fetch('api/data.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.ok) {
            toast(`${json.data.uploaded} archivo${json.data.uploaded > 1 ? 's' : ''} subido${json.data.uploaded > 1 ? 's' : ''} correctamente`);
            Modal.close();
            loadArchivos();
        } else {
            toast(json.error || 'Error al subir', 'error');
        }
    } catch (e) {
        toast('Error de conexión', 'error');
        console.error(e);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Subir Archivos';
    }
}

async function loadArchivos() {
    const container = document.getElementById('archivosContainer');
    try {
        const res = await fetch('api/data.php?action=get_invoice_files');
        const json = await res.json();
        if (!json.ok || !json.data.length) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:.85rem;">Sin archivos adjuntos. Usa el boton "Adjuntar Facturas" para subir documentos.</div>';
            return;
        }
        let html = '<table><thead><tr><th>Archivo</th><th>Cliente</th><th>Factura</th><th>Servicio</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>';
        json.data.forEach(f => {
            const ext = f.nombre_archivo.split('.').pop().toLowerCase();
            const icon = ext === 'pdf' ? '&#128196;' : (ext === 'xml' ? '&#128195;' : '&#128247;');
            html += `<tr>
                <td>${icon} <strong>${escHtml(f.nombre_archivo)}</strong></td>
                <td>${escHtml(f.cliente_nombre || '-')}</td>
                <td>${f.factura_numero ? escHtml(f.factura_numero) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${f.servicio ? '<span class="badge status-info" style="font-size:.7rem">' + escHtml(f.servicio) + '</span>' : '-'}</td>
                <td style="font-size:.8rem;color:var(--text-muted)">${escHtml(f.created_at || '-')}</td>
                <td>
                    <a href="${escHtml(f.ruta)}" target="_blank" class="btn btn-secondary btn-sm">Ver</a>
                    ${APP.canEdit ? '<button class="btn btn-danger btn-sm" onclick="deleteFile('+f.id+')">Eliminar</button>' : ''}
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<div style="padding:20px;color:var(--danger);font-size:.85rem;">Error al cargar archivos</div>';
    }
}

async function deleteFile(id) {
    if (!confirmAction('¿Eliminar este archivo?')) return;
    const res = await API.post('delete_invoice_file', { id });
    if (res) { toast('Archivo eliminado'); loadArchivos(); }
}

// Cargar archivos al iniciar
loadArchivos();
</script>
