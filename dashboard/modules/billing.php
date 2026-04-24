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
</script>
