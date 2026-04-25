<?php
/**
 * Módulo Facturación — Emisión, upload y control de facturas
 * AUTOMATIZACIÓN: Factura emitida → CxC automática → Abono si pagada → Ingreso en Finanzas
 */

$filtro_estado = $_GET['estado'] ?? '';
$filtro_periodo = $_GET['periodo'] ?? '';
$where = '';
$params = [];
if ($filtro_estado) { $where .= ' AND f.estado = ?'; $params[] = $filtro_estado; }
if ($filtro_periodo) { $where .= ' AND f.periodo_servicio = ?'; $params[] = $filtro_periodo; }

// Períodos disponibles para el filtro
$periodos_disponibles = query_all("SELECT DISTINCT periodo_servicio FROM facturas WHERE periodo_servicio != '' ORDER BY periodo_servicio DESC");

$facturas = query_all("SELECT f.*, c.nombre as cliente_nombre,
    (SELECT estado FROM cuentas_cobrar WHERE factura_id = f.id LIMIT 1) as estado_cobro,
    (SELECT GROUP_CONCAT(nombre_archivo, ', ') FROM archivos_factura WHERE factura_id = f.id) as archivos
    FROM facturas f
    LEFT JOIN clientes c ON f.cliente_id = c.id
    WHERE 1=1 $where
    ORDER BY f.created_at DESC", $params);

$clientes_list = query_all('SELECT id, nombre, servicios, fee_mensual FROM clientes WHERE tipo = "activo" ORDER BY nombre');
$proyectos_list = query_all('SELECT id, nombre FROM proyectos WHERE estado = "activo" ORDER BY nombre');

$total_emitidas = query_scalar('SELECT COUNT(*) FROM facturas WHERE estado = "emitida"') ?? 0;
$total_pagadas = query_scalar('SELECT COUNT(*) FROM facturas WHERE estado = "pagada" AND strftime("%Y-%m", pagado_at) = strftime("%Y-%m", "now")') ?? 0;
$monto_emitido_mes = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = strftime("%Y-%m", "now") AND estado != "anulada"') ?? 0;
$monto_cobrado_mes = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado = "pagada" AND strftime("%Y-%m", pagado_at) = strftime("%Y-%m", "now")') ?? 0;

$last_num = query_scalar('SELECT numero FROM facturas ORDER BY id DESC LIMIT 1') ?? 'F-0000';
$next_num_int = intval(preg_replace('/\D/', '', $last_num)) + 1;
$next_num = 'F-' . str_pad($next_num_int, 4, '0', STR_PAD_LEFT);
?>

<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--warning)"><div class="kpi-label">Pendientes de Cobro</div><div class="kpi-value warning"><?= $total_emitidas ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--success)"><div class="kpi-label">Cobradas Este Mes</div><div class="kpi-value success"><?= $total_pagadas ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)"><div class="kpi-label">Facturado Mes</div><div class="kpi-value"><?= format_money($monto_emitido_mes) ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--success)"><div class="kpi-label">Cobrado Mes</div><div class="kpi-value success"><?= format_money($monto_cobrado_mes) ?></div></div>
</div>

<div class="filters-bar">
    <select class="form-select" onchange="updateBillingFilter()">
        <option value="">Todos los estados</option>
        <option value="borrador" <?= $filtro_estado === 'borrador' ? 'selected' : '' ?>>Borrador</option>
        <option value="emitida" <?= $filtro_estado === 'emitida' ? 'selected' : '' ?>>Emitida</option>
        <option value="pagada" <?= $filtro_estado === 'pagada' ? 'selected' : '' ?>>Pagada</option>
        <option value="anulada" <?= $filtro_estado === 'anulada' ? 'selected' : '' ?>>Anulada</option>
    </select>
    <select class="form-select" id="filtroPeriodo" onchange="updateBillingFilter()">
        <option value="">Todos los períodos</option>
        <?php foreach ($periodos_disponibles as $p): ?>
            <option value="<?= safe($p['periodo_servicio']) ?>" <?= $filtro_periodo === $p['periodo_servicio'] ? 'selected' : '' ?>><?= safe($p['periodo_servicio']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<script>
function updateBillingFilter() {
    const estado = document.querySelector('.filters-bar select:first-child').value;
    const periodo = document.getElementById('filtroPeriodo').value;
    let url = '?page=billing';
    if (estado) url += '&estado=' + estado;
    if (periodo) url += '&periodo=' + periodo;
    location.href = url;
}
</script>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Facturas</span>
        <div class="table-actions">
        <?php if (can_edit($current_user['id'], 'billing')): ?>
            <button class="btn btn-primary btn-sm" onclick="openUploadModal()">Subir Factura</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewInvoice()">+ Crear Manual</button>
            <button class="btn btn-secondary btn-sm" onclick="generateMonthlyBilling()">Facturacion Mensual</button>
        <?php endif; ?>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>N°</th>
                <th>Cliente</th>
                <th>Concepto</th>
                <th>Total</th>
                <th>Estado</th>
                <th>Cobro</th>
                <th>Archivo</th>
                <th>Período</th>
                <th>Emisión</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($facturas as $f): ?>
            <tr>
                <td><strong><?= safe($f['numero']) ?></strong></td>
                <td><?= safe($f['cliente_nombre']) ?></td>
                <td style="max-width:200px"><?= safe($f['concepto']) ?></td>
                <td><strong><?= format_money($f['total']) ?></strong><br><span style="font-size:.7rem;color:var(--text-muted)">Neto <?= format_money($f['monto']) ?> + IVA <?= format_money($f['impuesto']) ?></span></td>
                <td><span class="badge <?= $f['estado'] === 'pagada' ? 'status-success' : ($f['estado'] === 'emitida' ? 'status-warning' : ($f['estado'] === 'anulada' ? 'status-danger' : 'status-muted')) ?>"><?= ucfirst($f['estado']) ?></span></td>
                <td>
                    <?php if ($f['estado_cobro']): ?>
                        <span class="badge <?= $f['estado_cobro'] === 'pagado' ? 'status-success' : ($f['estado_cobro'] === 'vencido' ? 'status-danger' : 'status-warning') ?>"><?= ucfirst($f['estado_cobro']) ?></span>
                    <?php else: echo '-'; endif; ?>
                </td>
                <td>
                    <?php if ($f['archivos']): ?>
                        <span style="font-size:.75rem;color:var(--accent)" title="<?= safe($f['archivos']) ?>">&#128196; Adjunto</span>
                    <?php else: echo '-'; endif; ?>
                </td>
                <td style="font-size:.82rem"><?= $f['periodo_servicio'] ? safe($f['periodo_servicio']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                <td style="font-size:.82rem"><?= format_date($f['fecha_emision']) ?></td>
                <td style="white-space:nowrap">
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
            <tr><td colspan="10" class="empty-state">No hay facturas. Usa "Subir Factura" para cargar un PDF y crear la factura automaticamente.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:16px;padding:14px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:.78rem;color:var(--text-muted);">
    <strong>Flujo automatico:</strong> Subir Factura → crea factura emitida → genera Cuenta por Cobrar → si marcas como pagada, registra abono + ingreso en Finanzas.
</div>

<script>
const bClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const bClientesData = <?= json_encode(array_combine(array_column($clientes_list, 'id'), $clientes_list)) ?>;
const bProyectosList = <?= json_encode(array_column($proyectos_list, 'nombre', 'id')) ?>;
const nextNum = '<?= $next_num ?>';

// ============================================================
// SUBIR FACTURA (flujo principal)
// ============================================================
function openUploadModal() {
    // Paso 1: Subir PDF para extraer datos
    const body = `
    <div style="display:grid;gap:16px;">
        <p style="font-size:.85rem;color:var(--text-muted)">Sube las facturas en PDF. Se extraeran automaticamente el monto, razon social, numero y fecha.</p>
        <div class="form-group">
            <label class="form-label">Archivos de factura (PDF, XML, imagen)</label>
            <input type="file" name="archivos" id="inputArchivosStep1" multiple accept=".pdf,.png,.jpg,.jpeg,.xml"
                style="padding:16px;background:var(--bg);border:2px dashed var(--border);border-radius:10px;color:var(--text);cursor:pointer;width:100%;font-size:.85rem;"
                onchange="extractPdfData(this)">
            <div id="fileCountStep1" style="font-size:.72rem;color:var(--text-muted);margin-top:4px;"></div>
        </div>
        <div id="extractionResult" style="display:none;"></div>
    </div>`;
    Modal.open('Subir Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="goToStep2()" id="btnStep1" disabled>Continuar</button>`);
}

let extractedData = {};

async function extractPdfData(input) {
    const files = input.files;
    if (!files.length) return;

    const countEl = document.getElementById('fileCountStep1');
    countEl.textContent = `${files.length} archivo${files.length > 1 ? 's' : ''} seleccionado${files.length > 1 ? 's' : ''}`;

    const resultDiv = document.getElementById('extractionResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:.85rem;">Analizando PDF...</div>';

    // Extraer datos del primer PDF
    const formData = new FormData();
    formData.append('action', 'extract_pdf_data');
    formData.append('csrf_token', APP.csrf);
    formData.append('pdf', files[0]);

    try {
        const res = await fetch('api/data.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.ok) {
            extractedData = json.data;
            const hasData = extractedData.monto || extractedData.razon_social || extractedData.numero_factura;
            if (hasData) {
                let html = '<div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;font-size:.82rem;">';
                html += '<div style="font-weight:600;margin-bottom:8px;color:var(--accent)">Datos extraidos del PDF:</div>';
                html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">';
                if (extractedData.numero_factura) html += `<div><span style="color:var(--text-muted)">N° Factura:</span> <strong>${escHtml(extractedData.numero_factura)}</strong></div>`;
                if (extractedData.monto) html += `<div><span style="color:var(--text-muted)">Monto:</span> <strong style="color:var(--success)">${fmtMoney(extractedData.monto)}</strong></div>`;
                if (extractedData.razon_social) html += `<div><span style="color:var(--text-muted)">Razon Social:</span> ${escHtml(extractedData.razon_social)}</div>`;
                if (extractedData.rut) html += `<div><span style="color:var(--text-muted)">RUT:</span> ${escHtml(extractedData.rut)}</div>`;
                if (extractedData.fecha) html += `<div><span style="color:var(--text-muted)">Fecha:</span> ${escHtml(extractedData.fecha)}</div>`;
                if (extractedData.cliente_sugerido) html += `<div><span style="color:var(--success)">Cliente detectado automaticamente</span></div>`;
                html += '</div></div>';
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = `<div style="color:var(--text-muted);font-size:.82rem;">No se encontraron datos en el PDF. Completa manualmente en el siguiente paso.</div>`;
            }
            document.getElementById('btnStep1').disabled = false;
        } else {
            resultDiv.innerHTML = `<div style="color:var(--warning);font-size:.82rem;">${escHtml(json.error || 'Error al procesar')}. Puedes continuar y completar manualmente.</div>`;
            extractedData = {};
            document.getElementById('btnStep1').disabled = false;
        }
    } catch(e) {
        console.error('Extract error:', e);
        resultDiv.innerHTML = '<div style="color:var(--text-muted);font-size:.82rem;">No se pudo leer el PDF automaticamente. Completa los campos en el siguiente paso.</div>';
        extractedData = {};
        document.getElementById('btnStep1').disabled = false;
    }
}

function goToStep2() {
    const files = document.getElementById('inputArchivosStep1').files;
    openUploadStep2(files);
}

function openUploadStep2(files) {
    const clienteOpts = Object.entries(bClientesList).map(([id, name]) =>
        `<option value="${id}" ${extractedData.cliente_sugerido == id ? 'selected' : ''}>${escHtml(name)}</option>`).join('');

    const numFact = extractedData.numero_factura ? 'F-' + extractedData.numero_factura : nextNum;
    const montoVal = extractedData.monto || '';
    const fechaVal = extractedData.fecha || new Date().toISOString().split('T')[0];
    const razonSocial = extractedData.razon_social || '';
    const rutFact = extractedData.rut || '';
    const conceptoVal = extractedData.concepto || '';

    const body = `
    <form id="frmUpload" enctype="multipart/form-data" style="display:grid;gap:14px;">
        ${razonSocial ? `<div style="background:var(--bg);padding:10px 14px;border-radius:8px;font-size:.82rem;border:1px solid var(--border)"><span style="color:var(--text-muted)">Razon social detectada:</span> <strong>${escHtml(razonSocial)}</strong> ${rutFact ? '(' + escHtml(rutFact) + ')' : ''}<input type="hidden" name="razon_social" value="${escHtml(razonSocial)}"><input type="hidden" name="rut_factura" value="${escHtml(rutFact)}"></div>` : ''}

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Cliente *</label>
                <select name="cliente_id" class="form-select" required onchange="onClienteChange(this.value)">
                    <option value="">Seleccionar...</option>
                    ${clienteOpts}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">N° Factura</label>
                <input type="text" name="numero" class="form-input" value="${escHtml(numFact)}">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Concepto *</label>
                <input type="text" name="concepto" class="form-input" placeholder="Ej: Suscripción abril 2026" value="${escHtml(conceptoVal)}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Monto (exento IVA) *</label>
                <input type="number" name="monto" class="form-input" value="${montoVal}" required style="font-weight:600;color:var(--success)">
            </div>
        </div>

        <div id="serviciosChecklist" style="display:none;">
            <label class="form-label">Servicios asociados (selecciona los que aplican)</label>
            <div id="serviciosCheckboxes" style="display:grid;gap:6px;margin-top:6px;max-height:150px;overflow-y:auto;padding:10px;background:var(--bg);border-radius:8px;border:1px solid var(--border);"></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Período del servicio *</label>
                <input type="month" name="periodo_servicio" class="form-input" value="${new Date().toISOString().slice(0,7)}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Fecha Emisión</label>
                <input type="date" name="fecha_emision" class="form-input" value="${fechaVal}">
            </div>
            <div class="form-group">
                <label class="form-label">Estado de pago *</label>
                <select name="estado_pago" class="form-select" onchange="toggleFechaPago(this.value)">
                    <option value="pendiente">Pendiente de pago</option>
                    <option value="pagada">Ya pagada</option>
                </select>
            </div>
        </div>

        <div id="fechaPagoGroup" style="display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Fecha de pago</label>
                    <input type="date" name="fecha_pago" class="form-input" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="form-group">
                    <label class="form-label">Metodo de pago</label>
                    <select name="metodo_pago" class="form-select">
                        <option value="transferencia">Transferencia</option>
                        <option value="efectivo">Efectivo</option>
                        <option value="cheque">Cheque</option>
                        <option value="tarjeta">Tarjeta</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Notas</label>
            <textarea name="notas" class="form-textarea" rows="2" placeholder="Observaciones..."></textarea>
        </div>
    </form>`;

    Modal.open('Confirmar Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="submitUpload()" id="btnUpload">Crear Factura</button>`);

    // Auto-trigger client change if pre-selected
    if (extractedData.cliente_sugerido) {
        setTimeout(() => onClienteChange(extractedData.cliente_sugerido), 100);
    }
}

// Store files from step 1 globally
let uploadedFiles = null;

function goToStep2() {
    uploadedFiles = document.getElementById('inputArchivosStep1').files;
    openUploadStep2(uploadedFiles);
}

function toggleFechaPago(val) {
    document.getElementById('fechaPagoGroup').style.display = val === 'pagada' ? 'block' : 'none';
}

function onClienteChange(clienteId) {
    const container = document.getElementById('serviciosChecklist');
    const checkboxes = document.getElementById('serviciosCheckboxes');
    if (!clienteId) { container.style.display = 'none'; return; }

    const cliente = bClientesData[clienteId];
    if (!cliente || !cliente.servicios) { container.style.display = 'none'; return; }

    const svcs = cliente.servicios.split(',').map(s => s.trim()).filter(s => s);
    if (svcs.length === 0) { container.style.display = 'none'; return; }

    checkboxes.innerHTML = svcs.map((s, i) =>
        `<label style="display:flex;align-items:center;gap:8px;font-size:.82rem;cursor:pointer;padding:4px 0;">
            <input type="checkbox" name="svc_${i}" value="${escHtml(s)}" style="accent-color:var(--accent);width:16px;height:16px;cursor:pointer;">
            ${escHtml(s)}
        </label>`
    ).join('');
    container.style.display = 'block';

    // Auto-fill concepto con número de factura y monto si fee > 0
    const concepto = document.querySelector('#frmUpload [name="concepto"]');
    const monto = document.querySelector('#frmUpload [name="monto"]');
    const numFactura = document.querySelector('#frmUpload [name="numero"]');
    if (concepto && !concepto.value) {
        concepto.value = `Pago factura ${numFactura ? numFactura.value : ''}`;
    }
    if (monto && !monto.value && cliente.fee_mensual > 0) {
        monto.value = cliente.fee_mensual;
        // calcIVA() uses name="monto_neto"; this form uses name="monto"/"impuesto" — calculate inline
        const impuesto = document.querySelector('#frmUpload [name="impuesto"]');
        if (impuesto) impuesto.value = Math.round(cliente.fee_mensual * 0.19);
    }
}

function updateFileCount(input) {
    const count = input.files.length;
    const totalSize = Array.from(input.files).reduce((s, f) => s + f.size, 0);
    document.getElementById('fileCount').textContent = count > 0
        ? `${count} archivo${count > 1 ? 's' : ''} (${(totalSize / 1024 / 1024).toFixed(1)} MB)`
        : '';
}

async function submitUpload() {
    const form = document.getElementById('frmUpload');
    const files = uploadedFiles;
    const clienteId = form.querySelector('[name="cliente_id"]').value;
    const concepto = form.querySelector('[name="concepto"]').value;
    const monto = form.querySelector('[name="monto"]').value;

    if (!clienteId) { toast('Selecciona un cliente', 'error'); return; }
    if (!concepto) { toast('Ingresa un concepto', 'error'); return; }
    if (!monto || monto <= 0) { toast('Ingresa el monto neto', 'error'); return; }
    if (!files.length) { toast('Selecciona al menos un archivo', 'error'); return; }

    // Collect checked services
    const checkedSvcs = [];
    form.querySelectorAll('#serviciosCheckboxes input[type="checkbox"]:checked').forEach(cb => {
        checkedSvcs.push(cb.value);
    });

    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.textContent = 'Procesando...';

    const formData = new FormData();
    formData.append('action', 'upload_and_create_invoice');
    formData.append('csrf_token', APP.csrf);
    formData.append('cliente_id', clienteId);
    formData.append('numero', form.querySelector('[name="numero"]').value || '');
    formData.append('concepto', concepto);
    formData.append('monto', monto);
    formData.append('impuesto', form.querySelector('[name="impuesto"]').value || '0');
    formData.append('fecha_emision', form.querySelector('[name="fecha_emision"]').value || '');
    formData.append('periodo_servicio', form.querySelector('[name="periodo_servicio"]').value || '');
    formData.append('estado_pago', form.querySelector('[name="estado_pago"]').value);
    formData.append('fecha_pago', form.querySelector('[name="fecha_pago"]')?.value || '');
    formData.append('metodo_pago', form.querySelector('[name="metodo_pago"]')?.value || 'transferencia');
    formData.append('servicios', checkedSvcs.join(', '));
    formData.append('notas', form.querySelector('[name="notas"]').value || '');

    for (let i = 0; i < files.length; i++) {
        formData.append('archivos[]', files[i]);
    }

    try {
        const res = await fetch('api/data.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.ok) {
            let msg = `Factura ${json.data.numero} creada`;
            if (json.data.pagada) msg += ' y marcada como pagada';
            msg += `. ${json.data.archivos} archivo${json.data.archivos > 1 ? 's' : ''} adjunto${json.data.archivos > 1 ? 's' : ''}.`;
            toast(msg);
            Modal.close();
            refreshPage();
        } else {
            toast(json.error || 'Error al procesar', 'error');
        }
    } catch (e) {
        toast('Error de conexion', 'error');
        console.error(e);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Subir y Crear Factura';
    }
}

// ============================================================
// FACTURA MANUAL (sin archivo)
// ============================================================
function openNewInvoice() {
    const body = `<form id="frmInvoice" class="form-grid">
        ${formField('numero', 'N° Factura', 'text', nextNum, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: bClientesList})}
        ${formField('proyecto_id', 'Proyecto (opcional)', 'select', '', {options: {'':'Sin proyecto', ...bProyectosList}})}
        ${formField('concepto', 'Concepto', 'text', '', {required: true})}
        ${formField('monto', 'Monto Neto', 'number', '', {required: true})}
        ${formField('impuesto', 'IVA (19%)', 'number', '')}
        ${formField('estado', 'Estado', 'select', 'emitida', {options: {borrador:'Borrador', emitida:'Emitida'}})}
        ${formField('periodo_servicio', 'Período del servicio', 'month', new Date().toISOString().slice(0,7), {required: true})}
        ${formField('fecha_emision', 'Fecha Emisión', 'date', new Date().toISOString().split('T')[0])}
        ${formField('fecha_vencimiento', 'Fecha Vencimiento', 'date')}
        ${formField('detalle', 'Detalle', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nueva Factura Manual', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveInvoice()">Guardar</button>`);
    setTimeout(() => {
        const m = document.querySelector('#frmInvoice [name="monto"]');
        const i = document.querySelector('#frmInvoice [name="impuesto"]');
        if (m && i) m.addEventListener('input', () => { i.value = Math.round(parseInt(m.value||0)*0.19); });
    }, 100);
}

async function saveInvoice() {
    const data = getFormData('frmInvoice');
    data.total = parseInt(data.monto||0) + parseInt(data.impuesto||0);
    const res = await API.post('create_invoice', data);
    if (res) { toast('Factura creada. CxC generada automaticamente.'); refreshPage(); }
}

async function emitInvoice(id) {
    if (!confirmAction('Emitir factura? Se genera CxC automaticamente.')) return;
    const res = await API.post('update_invoice', { id, estado: 'emitida' });
    if (res) { toast('Factura emitida'); refreshPage(); }
}

async function cancelInvoice(id) {
    if (!confirmAction('Anular factura? Se anula la CxC asociada.')) return;
    const res = await API.post('update_invoice', { id, estado: 'anulada' });
    if (res) { toast('Factura anulada'); refreshPage(); }
}

async function editInvoice(id) {
    const res = await API.get('get_invoice', { id });
    if (!res) return;
    const f = res.data;
    const body = `<form id="frmInvoice" class="form-grid">
        <input type="hidden" name="id" value="${f.id}">
        ${formField('numero', 'N°', 'text', f.numero)}
        ${formField('cliente_id', 'Cliente', 'select', f.cliente_id, {options: bClientesList})}
        ${formField('concepto', 'Concepto', 'text', f.concepto)}
        ${formField('monto', 'Monto Neto', 'number', f.monto)}
        ${formField('impuesto', 'IVA', 'number', f.impuesto)}
        ${formField('periodo_servicio', 'Período del servicio', 'month', f.periodo_servicio || '')}
        ${formField('fecha_vencimiento', 'Fecha Vencimiento', 'date', f.fecha_vencimiento || '')}
        ${formField('detalle', 'Detalle', 'textarea', f.detalle, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateInvoice()">Actualizar</button>`);
}

async function updateInvoice() {
    const data = getFormData('frmInvoice');
    data.total = parseInt(data.monto||0) + parseInt(data.impuesto||0);
    const res = await API.post('update_invoice', data);
    if (res) { toast('Factura actualizada'); refreshPage(); }
}

async function generateMonthlyBilling() {
    if (!confirmAction('Generar facturas mensuales para todos los clientes activos con fee > 0?')) return;
    const res = await API.post('generate_monthly_billing', {});
    if (res) {
        toast(`${res.data.created} facturas creadas, ${res.data.skipped} omitidas`);
        refreshPage();
    }
}
</script>
