<?php
/**
 * Módulo Facturación — Servicios del mes con estado de facturación
 * Lee servicios_cliente activos y cruza con facturas emitidas por período
 * Fecha límite: día 5 del mes siguiente (configurable)
 */

$meses_es = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];

// Mes seleccionado (default: mes actual)
$mes_sel = $_GET['mes'] ?? date('Y-m');
$mes_num = substr($mes_sel, 5, 2);
$mes_anio = substr($mes_sel, 0, 4);
$mes_label = ($meses_es[$mes_num] ?? $mes_num) . ' ' . $mes_anio;

// Fecha límite de facturación (default: día 5 del mes siguiente, editable)
$dia_limite = (int)($_GET['dia_limite'] ?? 5);
$fecha_limite = date('Y-m-d', strtotime("$mes_sel-01 +1 month +" . ($dia_limite - 1) . " days"));
$hoy = date('Y-m-d');

// Meses disponibles para selector (últimos 6 + próximos 2)
$meses_selector = [];
for ($i = -6; $i <= 2; $i++) {
    $m = date('Y-m', strtotime("$i months"));
    $ml = $meses_es[substr($m, 5)] . ' ' . substr($m, 0, 4);
    $meses_selector[$m] = $ml;
}

// Servicios activos del mes seleccionado
// Suscripciones: activas y con fecha_inicio <= último día del mes
// Implementaciones: activas y con fecha_inicio dentro del mes
$ultimo_dia = date('Y-m-t', strtotime("$mes_sel-01"));
$primer_dia = "$mes_sel-01";

$suscripciones = query_all("SELECT s.*, c.nombre as cliente_nombre
    FROM servicios_cliente s JOIN clientes c ON s.cliente_id = c.id
    WHERE s.tipo = 'suscripcion' AND s.estado IN ('activo')
    AND (s.fecha_inicio IS NULL OR s.fecha_inicio <= '$ultimo_dia')
    ORDER BY c.nombre", []);

$implementaciones = query_all("SELECT s.*, c.nombre as cliente_nombre
    FROM servicios_cliente s JOIN clientes c ON s.cliente_id = c.id
    WHERE s.tipo IN ('implementacion','adicional') AND s.estado = 'activo'
    AND s.fecha_inicio >= '$primer_dia' AND s.fecha_inicio <= '$ultimo_dia'
    ORDER BY c.nombre", []);

$todos_servicios = array_merge($suscripciones, $implementaciones);

// Facturas ya emitidas para este período
$facturas_periodo = query_all("SELECT f.*, c.nombre as cliente_nombre,
    (SELECT estado FROM cuentas_cobrar WHERE factura_id = f.id LIMIT 1) as estado_cobro,
    (SELECT GROUP_CONCAT(nombre_archivo, ', ') FROM archivos_factura WHERE factura_id = f.id) as archivos
    FROM facturas f LEFT JOIN clientes c ON f.cliente_id = c.id
    WHERE f.periodo_servicio = ? AND f.estado != 'anulada'
    ORDER BY f.created_at DESC", [$mes_sel]);

// Crear mapa de facturas por cliente_id para cruzar
$facturado_por_cliente = [];
foreach ($facturas_periodo as $fp) {
    $facturado_por_cliente[$fp['cliente_id']] = ($facturado_por_cliente[$fp['cliente_id']] ?? 0) + $fp['total'];
}

// KPIs
$total_servicios_mes = array_sum(array_column($todos_servicios, 'monto'));
$total_facturado = array_sum(array_column($facturas_periodo, 'total'));
$total_pendiente = $total_servicios_mes - $total_facturado;
if ($total_pendiente < 0) $total_pendiente = 0;
$cant_emitidas = count($facturas_periodo);
$cant_pendientes = 0;
$cant_atrasadas = 0;
foreach ($todos_servicios as $sv) {
    $ya_facturado = $facturado_por_cliente[$sv['cliente_id']] ?? 0;
    if ($ya_facturado < $sv['monto']) {
        if ($hoy > $fecha_limite) $cant_atrasadas++;
        else $cant_pendientes++;
    }
}

$clientes_list = query_all('SELECT id, nombre, servicios, fee_mensual FROM clientes WHERE tipo = "activo" ORDER BY nombre');
$last_num = query_scalar('SELECT numero FROM facturas ORDER BY id DESC LIMIT 1') ?? 'F-0000';
$next_num = 'F-' . str_pad(intval(preg_replace('/\D/', '', $last_num)) + 1, 4, '0', STR_PAD_LEFT);
?>

<!-- Selector de mes -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=billing&mes=<?= date('Y-m', strtotime("$mes_sel-01 -1 month")) ?>&dia_limite=<?= $dia_limite ?>" class="btn btn-secondary btn-sm">← Anterior</a>
    <select class="form-select" style="font-size:.9rem;font-weight:600;min-width:180px;" onchange="location.href='?page=billing&mes='+this.value+'&dia_limite=<?= $dia_limite ?>'">
        <?php foreach ($meses_selector as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $mv === $mes_sel ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=billing&mes=<?= date('Y-m', strtotime("$mes_sel-01 +1 month")) ?>&dia_limite=<?= $dia_limite ?>" class="btn btn-secondary btn-sm">Siguiente →</a>
    <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
        <span style="font-size:.78rem;color:var(--text-muted);">Fecha límite: día</span>
        <select class="form-select" style="width:60px;font-size:.82rem;" onchange="location.href='?page=billing&mes=<?= $mes_sel ?>&dia_limite='+this.value">
            <?php for ($d = 1; $d <= 15; $d++): ?>
                <option value="<?= $d ?>" <?= $d === $dia_limite ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
        </select>
        <span style="font-size:.78rem;color:var(--text-muted);">del mes siguiente</span>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--primary)">
        <div class="kpi-label">Facturación <?= $meses_es[$mes_num] ?? '' ?></div>
        <div class="kpi-value" style="color:var(--primary)"><?= format_money($total_servicios_mes) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Emitidas</div>
        <div class="kpi-value success"><?= format_money($total_facturado) ?></div>
        <div class="kpi-sub"><?= $cant_emitidas ?> factura<?= $cant_emitidas !== 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--warning)">
        <div class="kpi-label">Pendientes de emitir</div>
        <div class="kpi-value warning"><?= format_money($total_pendiente) ?></div>
        <div class="kpi-sub"><?= $cant_pendientes ?> pendiente<?= $cant_pendientes !== 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid <?= $cant_atrasadas > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>">
        <div class="kpi-label">Atrasadas</div>
        <div class="kpi-value <?= $cant_atrasadas > 0 ? 'danger' : '' ?>"><?= $cant_atrasadas ?></div>
        <div class="kpi-sub">Límite: <?= format_date($fecha_limite) ?></div>
    </div>
</div>

<!-- Tabla de servicios del mes -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Servicios de <?= $mes_label ?></span>
        <div class="table-actions">
        <?php if (can_edit($current_user['id'], 'billing')): ?>
            <button class="btn btn-primary btn-sm" onclick="openUploadModal()">Subir Factura</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewInvoice()">+ Crear Manual</button>
        <?php endif; ?>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Servicio</th>
                <th>Tipo</th>
                <th>Monto</th>
                <th>Estado factura</th>
                <th>Fecha límite</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($todos_servicios)): ?>
            <tr><td colspan="7" class="empty-state">No hay servicios activos en <?= $mes_label ?>.</td></tr>
        <?php else: ?>
            <?php foreach ($todos_servicios as $sv):
                $facturado = $facturado_por_cliente[$sv['cliente_id']] ?? 0;
                $pendiente_monto = $sv['monto'] - $facturado;
                if ($pendiente_monto < 0) $pendiente_monto = 0;

                if ($facturado >= $sv['monto']) {
                    $estado = 'emitida';
                    $estado_class = 'status-success';
                    $estado_label = 'Emitida ✓';
                } elseif ($hoy > $fecha_limite) {
                    $estado = 'atrasada';
                    $estado_class = 'status-danger';
                    $estado_label = 'Atrasada';
                } elseif ($hoy >= date('Y-m-d', strtotime($fecha_limite . ' -4 days'))) {
                    $estado = 'plazo';
                    $estado_class = 'status-warning';
                    $estado_label = 'Dentro del plazo';
                } else {
                    $estado = 'a_tiempo';
                    $estado_class = 'status-info';
                    $estado_label = 'A tiempo';
                }

                $tipo_label = $sv['tipo'] === 'suscripcion' ? 'Suscripción' : ($sv['tipo'] === 'implementacion' ? 'Implementación' : 'Adicional');
                $tipo_class = $sv['tipo'] === 'suscripcion' ? 'status-info' : ($sv['tipo'] === 'implementacion' ? 'status-warning' : 'status-muted');
            ?>
            <tr>
                <td><strong><?= safe($sv['cliente_nombre']) ?></strong></td>
                <td style="font-size:.82rem;"><?= safe($sv['nombre']) ?></td>
                <td><span class="badge <?= $tipo_class ?>" style="font-size:.68rem;"><?= $tipo_label ?></span></td>
                <td style="font-weight:600;"><?= format_money($sv['monto']) ?><?= $sv['tipo'] === 'suscripcion' ? '<span style="font-size:.7rem;color:var(--text-muted)">/mes</span>' : '' ?></td>
                <td><span class="badge <?= $estado_class ?>"><?= $estado_label ?></span></td>
                <td style="font-size:.82rem;<?= $estado === 'atrasada' ? 'color:var(--danger);font-weight:600;' : '' ?>"><?= format_date($fecha_limite) ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'billing') && $estado !== 'emitida'): ?>
                        <button class="btn btn-primary btn-sm" onclick="emitirServicio(<?= $sv['cliente_id'] ?>, '<?= safe(addslashes($sv['nombre'])) ?>', <?= $sv['monto'] ?>, '<?= $mes_sel ?>')">Emitir</button>
                    <?php elseif ($estado === 'emitida'): ?>
                        <span style="font-size:.75rem;color:var(--success);">✓</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Facturas emitidas del período -->
<?php if (!empty($facturas_periodo)): ?>
<div class="table-container" style="margin-top:20px;">
    <div class="table-header">
        <span class="table-title">Facturas emitidas — <?= $mes_label ?></span>
    </div>
    <table>
        <thead>
            <tr><th>N°</th><th>Cliente</th><th>Concepto</th><th>Total</th><th>Estado</th><th>Cobro</th><th>Archivo</th><th>Emisión</th><th>Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($facturas_periodo as $f): ?>
            <tr>
                <td><strong><?= safe($f['numero']) ?></strong></td>
                <td><?= safe($f['cliente_nombre']) ?></td>
                <td style="max-width:200px;font-size:.82rem;"><?= safe($f['concepto']) ?></td>
                <td><strong><?= format_money($f['total']) ?></strong></td>
                <td><span class="badge <?= $f['estado'] === 'pagada' ? 'status-success' : 'status-warning' ?>"><?= ucfirst($f['estado']) ?></span></td>
                <td><?php if ($f['estado_cobro']): ?><span class="badge <?= $f['estado_cobro'] === 'pagado' ? 'status-success' : ($f['estado_cobro'] === 'vencido' ? 'status-danger' : 'status-warning') ?>"><?= ucfirst($f['estado_cobro']) ?></span><?php else: echo '—'; endif; ?></td>
                <td><?= $f['archivos'] ? '<span style="font-size:.75rem;color:var(--accent)">📄</span>' : '—' ?></td>
                <td style="font-size:.82rem;"><?= format_date($f['fecha_emision']) ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'billing')): ?>
                        <?php if ($f['estado'] === 'emitida'): ?>
                            <button class="btn btn-danger btn-sm" onclick="cancelInvoice(<?= $f['id'] ?>)">Anular</button>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" onclick="editInvoice(<?= $f['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top:16px;padding:12px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:.78rem;color:var(--text-muted);">
    <strong>Flujo:</strong> Servicios activos del mes → Emitir factura → CxC automática → Registrar pago → Ingreso en Finanzas.
    Las suscripciones se facturan dentro de los <?= $dia_limite ?> primeros días del mes siguiente. Las implementaciones según avance.
</div>

<script>
const bClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const bClientesData = <?= json_encode(array_combine(array_column($clientes_list, 'id'), $clientes_list)) ?>;
const nextNum = '<?= $next_num ?>';
const mesSel = '<?= $mes_sel ?>';

function emitirServicio(clienteId, servicio, monto, periodo) {
    const clienteNombre = bClientesList[clienteId] || '';
    const body = `<form id="frmEmitir" style="display:grid;gap:14px;">
        <input type="hidden" name="cliente_id" value="${clienteId}">
        <div style="background:var(--bg);padding:12px;border-radius:8px;border:1px solid var(--border);">
            <div style="font-size:.82rem;color:var(--text-muted);">Cliente</div>
            <div style="font-weight:700;margin-top:2px;">${escHtml(clienteNombre)}</div>
            <div style="font-size:.82rem;color:var(--text-muted);margin-top:6px;">Servicio</div>
            <div style="margin-top:2px;">${escHtml(servicio)}</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            ${formField('numero', 'N° Factura', 'text', nextNum)}
            ${formField('monto', 'Monto Neto ($)', 'number', monto)}
        </div>
        ${formField('concepto', 'Concepto', 'text', servicio + ' — ' + periodo)}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            ${formField('fecha_emision', 'Fecha Emisión', 'date', new Date().toISOString().split('T')[0])}
            <div class="form-group">
                <label class="form-label">Estado de pago</label>
                <select name="estado_pago" class="form-select" onchange="document.getElementById('fpGroup').style.display=this.value==='pagada'?'block':'none'">
                    <option value="pendiente">Pendiente</option>
                    <option value="pagada">Ya pagada</option>
                </select>
            </div>
        </div>
        <div id="fpGroup" style="display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                ${formField('fecha_pago', 'Fecha pago', 'date', new Date().toISOString().split('T')[0])}
                ${formField('metodo_pago', 'Método', 'select', 'transferencia', {options:{transferencia:'Transferencia',efectivo:'Efectivo',cheque:'Cheque',tarjeta:'Tarjeta'}})}
            </div>
        </div>
        <input type="hidden" name="periodo_servicio" value="${periodo}">
    </form>`;
    Modal.open('Emitir Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="submitEmitir()">Emitir Factura</button>`);
}

async function submitEmitir() {
    const data = getFormData('frmEmitir');
    if (!data.monto || data.monto <= 0) { toast('Ingresa el monto', 'error'); return; }
    data.impuesto = 0;
    data.total = parseInt(data.monto);
    data.estado = 'emitida';
    const res = await API.post('upload_and_create_invoice', data);
    if (res) { toast('Factura emitida'); refreshPage(); }
}

// ============================================================
// SUBIR FACTURA (flujo con PDF)
// ============================================================
function openUploadModal() {
    const body = `
    <div style="display:grid;gap:16px;">
        <p style="font-size:.85rem;color:var(--text-muted)">Sube la factura en PDF. Se extraerán los datos automáticamente.</p>
        <div class="form-group">
            <label class="form-label">Archivo de factura</label>
            <input type="file" name="archivos" id="inputArchivosStep1" accept=".pdf,.png,.jpg,.jpeg,.xml"
                style="padding:16px;background:var(--bg);border:2px dashed var(--border);border-radius:10px;color:var(--text);cursor:pointer;width:100%;font-size:.85rem;"
                onchange="extractPdfData(this)">
        </div>
        <div id="extractionResult" style="display:none;"></div>
    </div>`;
    Modal.open('Subir Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="goToStep2()" id="btnStep1" disabled>Continuar</button>`);
}

let extractedData = {};
let uploadedFiles = null;

async function extractPdfData(input) {
    const files = input.files;
    if (!files.length) return;
    const resultDiv = document.getElementById('extractionResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:.85rem;">Analizando...</div>';
    const fd = new FormData();
    fd.append('action', 'extract_pdf_data');
    fd.append('csrf_token', APP.csrf);
    fd.append('pdf', files[0]);
    const ctrl = new AbortController();
    setTimeout(() => ctrl.abort(), 5000);
    try {
        const res = await fetch('api/data.php', { method:'POST', body:fd, signal:ctrl.signal });
        const json = await res.json();
        if (json.ok && json.data) {
            extractedData = json.data;
            resultDiv.innerHTML = '<div style="color:var(--success);font-size:.82rem;">Datos extraídos correctamente.</div>';
        } else {
            resultDiv.innerHTML = '<div style="color:var(--text-muted);font-size:.82rem;">No se pudieron extraer datos. Completa manualmente.</div>';
            extractedData = {};
        }
    } catch(e) {
        resultDiv.innerHTML = '<div style="color:var(--text-muted);font-size:.82rem;">Completa los datos manualmente.</div>';
        extractedData = {};
    }
    document.getElementById('btnStep1').disabled = false;
}

function goToStep2() {
    uploadedFiles = document.getElementById('inputArchivosStep1').files;
    const clienteOpts = Object.entries(bClientesList).map(([id, name]) =>
        `<option value="${id}" ${extractedData.cliente_sugerido == id ? 'selected' : ''}>${escHtml(name)}</option>`).join('');
    const numFact = extractedData.numero_factura ? 'F-' + extractedData.numero_factura : nextNum;
    const montoVal = extractedData.monto || '';

    const body = `<form id="frmUpload" style="display:grid;gap:14px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label class="form-label">Cliente *</label><select name="cliente_id" class="form-select" required><option value="">Seleccionar...</option>${clienteOpts}</select></div>
            ${formField('numero', 'N° Factura', 'text', numFact)}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            ${formField('concepto', 'Concepto *', 'text', '')}
            ${formField('monto', 'Monto Neto ($) *', 'number', montoVal)}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            ${formField('fecha_emision', 'Fecha Emisión', 'date', new Date().toISOString().split('T')[0])}
            <div class="form-group"><label class="form-label">Período</label><input type="month" name="periodo_servicio" class="form-input" value="${mesSel}"></div>
            <div class="form-group"><label class="form-label">Estado pago</label><select name="estado_pago" class="form-select"><option value="pendiente">Pendiente</option><option value="pagada">Ya pagada</option></select></div>
        </div>
    </form>`;
    Modal.open('Confirmar Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="submitUpload()" id="btnUpload">Crear Factura</button>`);
}

async function submitUpload() {
    const form = document.getElementById('frmUpload');
    const btn = document.getElementById('btnUpload');
    btn.disabled = true; btn.textContent = 'Procesando...';
    const fd = new FormData();
    fd.append('action', 'upload_and_create_invoice');
    fd.append('csrf_token', APP.csrf);
    ['cliente_id','numero','concepto','monto','fecha_emision','periodo_servicio','estado_pago'].forEach(k => {
        fd.append(k, form.querySelector(`[name="${k}"]`)?.value || '');
    });
    fd.append('impuesto', '0');
    if (uploadedFiles) for (let i = 0; i < uploadedFiles.length; i++) fd.append('archivos[]', uploadedFiles[i]);
    try {
        const res = await fetch('api/data.php', { method:'POST', body:fd });
        const json = await res.json();
        if (json.ok) { toast('Factura creada'); Modal.close(); refreshPage(); }
        else toast(json.error || 'Error', 'error');
    } catch(e) { toast('Error de conexión', 'error'); }
    finally { btn.disabled = false; btn.textContent = 'Crear Factura'; }
}

function openNewInvoice() {
    const body = `<form id="frmInvoice" class="form-grid">
        ${formField('numero', 'N° Factura', 'text', nextNum, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: bClientesList})}
        ${formField('concepto', 'Concepto', 'text', '', {required: true})}
        ${formField('monto', 'Monto Neto', 'number', '', {required: true})}
        ${formField('impuesto', 'IVA (19%)', 'number', '')}
        ${formField('estado', 'Estado', 'select', 'emitida', {options: {borrador:'Borrador', emitida:'Emitida'}})}
        <div class="form-group"><label class="form-label">Período</label><input type="month" name="periodo_servicio" class="form-input" value="${mesSel}"></div>
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
    if (res) { toast('Factura creada'); refreshPage(); }
}

async function cancelInvoice(id) {
    if (!confirmAction('¿Anular factura?')) return;
    await API.post('update_invoice', { id, estado: 'anulada' });
    toast('Factura anulada'); refreshPage();
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
        <div class="form-group"><label class="form-label">Período</label><input type="month" name="periodo_servicio" class="form-input" value="${f.periodo_servicio || ''}"></div>
        ${formField('fecha_vencimiento', 'Vencimiento', 'date', f.fecha_vencimiento || '')}
        ${formField('detalle', 'Detalle', 'textarea', f.detalle, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Factura', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateInvoice()">Actualizar</button>`);
}

async function updateInvoice() {
    const data = getFormData('frmInvoice');
    data.total = parseInt(data.monto||0) + parseInt(data.impuesto||0);
    await API.post('update_invoice', data);
    toast('Factura actualizada'); refreshPage();
}
</script>
