<?php
/**
 * Módulo Cuentas por Cobrar — Seguimiento de cobros pendientes
 * AUTOMATIZACIÓN:
 *   - Se generan automáticamente al emitir facturas (trigger SQLite)
 *   - Al registrar abono → actualiza monto pendiente
 *   - Al completar pago → marca factura como pagada + registra ingreso en finanzas (trigger)
 *   - Cuentas vencidas se marcan automáticamente
 */

// Actualizar estados vencidos en cada carga — necesario porque SQLite no tiene eventos de tiempo.
// Los triggers solo actúan al insertar/actualizar, no al pasar el tiempo; este UPDATE es el único mecanismo que marca vencidas en tiempo real.
db_execute('UPDATE cuentas_cobrar SET estado = "vencido" WHERE estado = "pendiente" AND fecha_vencimiento < date("now") AND fecha_vencimiento IS NOT NULL');

$filtro = $_GET['filtro'] ?? 'pendientes';

$where_map = [
    'pendientes' => 'cc.estado IN ("pendiente","parcial","vencido")',
    'vencidas'   => 'cc.estado = "vencido"',
    'pagadas'    => 'cc.estado = "pagado"',
    'todas'      => '1=1',
];
$where = $where_map[$filtro] ?? $where_map['pendientes'];

$cuentas = query_all("SELECT cc.*, c.nombre as cliente_nombre, f.numero as factura_numero, f.concepto as factura_concepto,
    (SELECT COUNT(*) FROM abonos WHERE cuenta_cobrar_id = cc.id) as total_abonos,
    (SELECT MAX(fecha) FROM abonos WHERE cuenta_cobrar_id = cc.id) as ultimo_abono
    FROM cuentas_cobrar cc
    JOIN clientes c ON cc.cliente_id = c.id
    JOIN facturas f ON cc.factura_id = f.id
    WHERE $where
    ORDER BY CASE cc.estado WHEN 'vencido' THEN 1 WHEN 'parcial' THEN 2 WHEN 'pendiente' THEN 3 ELSE 4 END, cc.fecha_vencimiento ASC");

// KPIs
$total_pendiente = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial")') ?? 0;
$total_vencido = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado = "vencido"') ?? 0;
$cobrado_mes = query_scalar('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE strftime("%Y-%m", fecha) = strftime("%Y-%m", "now")') ?? 0;
$n_vencidas = query_scalar('SELECT COUNT(*) FROM cuentas_cobrar WHERE estado = "vencido"') ?? 0;

// Aging (antigüedad de deuda)
$aging_30 = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido") AND fecha_vencimiento >= date("now", "-30 days")') ?? 0;
$aging_31_60 = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido") AND fecha_vencimiento BETWEEN date("now", "-60 days") AND date("now", "-31 days")') ?? 0;
$aging_60plus = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido") AND fecha_vencimiento < date("now", "-60 days")') ?? 0;

// Deuda por cliente (top 5)
$deuda_por_cliente = query_all('SELECT c.nombre, SUM(cc.monto_pendiente) as deuda FROM cuentas_cobrar cc JOIN clientes c ON cc.cliente_id = c.id WHERE cc.estado IN ("pendiente","parcial","vencido") GROUP BY cc.cliente_id ORDER BY deuda DESC LIMIT 5');
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Por Cobrar</div>
        <div class="kpi-value warning"><?= format_money($total_pendiente + $total_vencido) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Vencido</div>
        <div class="kpi-value danger"><?= format_money($total_vencido) ?></div>
        <div class="kpi-sub"><?= $n_vencidas ?> cuentas vencidas</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Cobrado Este Mes</div>
        <div class="kpi-value success"><?= format_money($cobrado_mes) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Pendiente (no vencido)</div>
        <div class="kpi-value"><?= format_money($total_pendiente) ?></div>
    </div>
</div>

<!-- Aging + Top Deudores -->
<div class="grid-2">
    <div class="chart-container">
        <div class="chart-title">Antigüedad de Deuda</div>
        <div style="display:flex; flex-direction:column; gap:12px;">
            <div>
                <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:4px;">
                    <span>0-30 días</span><span><?= format_money($aging_30) ?></span>
                </div>
                <div class="progress-bar"><div class="progress-fill success" style="width:<?= ($aging_30 + $aging_31_60 + $aging_60plus) > 0 ? round($aging_30 / ($aging_30 + $aging_31_60 + $aging_60plus) * 100) : 0 ?>%"></div></div>
            </div>
            <div>
                <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:4px;">
                    <span>31-60 días</span><span><?= format_money($aging_31_60) ?></span>
                </div>
                <div class="progress-bar"><div class="progress-fill warning" style="width:<?= ($aging_30 + $aging_31_60 + $aging_60plus) > 0 ? round($aging_31_60 / ($aging_30 + $aging_31_60 + $aging_60plus) * 100) : 0 ?>%"></div></div>
            </div>
            <div>
                <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:4px;">
                    <span>60+ días</span><span><?= format_money($aging_60plus) ?></span>
                </div>
                <div class="progress-bar"><div class="progress-fill danger" style="width:<?= ($aging_30 + $aging_31_60 + $aging_60plus) > 0 ? round($aging_60plus / ($aging_30 + $aging_31_60 + $aging_60plus) * 100) : 0 ?>%"></div></div>
            </div>
        </div>
    </div>
    <div class="chart-container">
        <div class="chart-title">Top Deudores</div>
        <?php if (empty($deuda_por_cliente)): ?>
            <div class="empty-state"><p>Sin deudas registradas</p></div>
        <?php else: ?>
            <?php $max_deuda = $deuda_por_cliente[0]['deuda'] ?? 1; ?>
            <?php foreach ($deuda_por_cliente as $dc): ?>
                <div style="margin-bottom:10px;">
                    <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:3px;">
                        <span><?= safe($dc['nombre']) ?></span>
                        <span style="font-weight:600;"><?= format_money($dc['deuda']) ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= round($dc['deuda'] / $max_deuda * 100) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=receivables&filtro='+this.value">
        <option value="pendientes" <?= $filtro === 'pendientes' ? 'selected' : '' ?>>Pendientes + Vencidas</option>
        <option value="vencidas" <?= $filtro === 'vencidas' ? 'selected' : '' ?>>Solo Vencidas</option>
        <option value="pagadas" <?= $filtro === 'pagadas' ? 'selected' : '' ?>>Pagadas</option>
        <option value="todas" <?= $filtro === 'todas' ? 'selected' : '' ?>>Todas</option>
    </select>
</div>

<!-- Tabla principal -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Cuentas por Cobrar</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Factura</th>
                <th>Cliente</th>
                <th>Concepto</th>
                <th>Original</th>
                <th>Pagado</th>
                <th>Pendiente</th>
                <th>Estado</th>
                <th>Vencimiento</th>
                <th>Último Abono</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cuentas as $cc):
                $dias_vencido = $cc['fecha_vencimiento'] ? days_overdue($cc['fecha_vencimiento']) : 0;
            ?>
            <tr>
                <td><strong><?= safe($cc['factura_numero']) ?></strong></td>
                <td><?= safe($cc['cliente_nombre']) ?></td>
                <td><?= safe($cc['factura_concepto']) ?></td>
                <td><?= format_money($cc['monto_original']) ?></td>
                <td style="color:var(--success)"><?= format_money($cc['monto_pagado']) ?></td>
                <td><strong><?= format_money($cc['monto_pendiente']) ?></strong></td>
                <td>
                    <span class="badge <?= status_class($cc['estado'] === 'pagado' ? 'completado' : $cc['estado']) ?>">
                        <?= safe(ucfirst($cc['estado'])) ?>
                    </span>
                    <?php if ($dias_vencido > 0 && $cc['estado'] !== 'pagado'): ?>
                        <span style="font-size:.7rem; color:var(--danger); display:block;"><?= $dias_vencido ?> días</span>
                    <?php endif; ?>
                </td>
                <td style="<?= $dias_vencido > 0 && $cc['estado'] !== 'pagado' ? 'color:var(--danger)' : '' ?>"><?= format_date($cc['fecha_vencimiento']) ?></td>
                <td><?= $cc['ultimo_abono'] ? format_date($cc['ultimo_abono']) : '-' ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'receivables') && in_array($cc['estado'], ['pendiente','parcial','vencido'])): ?>
                        <button class="btn btn-primary btn-sm" onclick="openPayment(<?= $cc['id'] ?>, <?= $cc['monto_pendiente'] ?>)">Registrar Pago</button>
                        <button class="btn btn-secondary btn-sm" onclick="viewPayments(<?= $cc['id'] ?>)">Historial</button>
                    <?php elseif ($cc['estado'] === 'pagado'): ?>
                        <button class="btn btn-secondary btn-sm" onclick="viewPayments(<?= $cc['id'] ?>)">Historial</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($cuentas)): ?>
            <tr><td colspan="10" class="empty-state">No hay cuentas por cobrar</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:16px; padding:16px; background:var(--surface); border:1px solid var(--border); border-radius:12px; font-size:.8rem; color:var(--text-muted);">
    <strong>Automatización activa:</strong> Las cuentas por cobrar se generan automáticamente al emitir facturas. Al registrar un pago aquí, se actualiza la factura correspondiente y se registra el ingreso en el módulo de Finanzas.
</div>

<script>
/**
 * Abre modal para registrar pago/abono
 * @param {number} cuentaId - ID de la cuenta por cobrar
 * @param {number} pendiente - Monto pendiente actual
 */
function openPayment(cuentaId, pendiente) {
    const body = `<form id="frmPayment" class="form-grid">
        <input type="hidden" name="cuenta_cobrar_id" value="${cuentaId}">
        <div class="form-group">
            <label class="form-label">Monto pendiente</label>
            <div style="font-size:1.2rem; font-weight:700; color:var(--warning)">${fmtMoney(pendiente)}</div>
        </div>
        ${formField('monto', 'Monto a Pagar', 'number', pendiente, {required: true})}
        ${formField('metodo_pago', 'Método de Pago', 'select', 'transferencia', {options: {transferencia:'Transferencia', efectivo:'Efectivo', cheque:'Cheque', tarjeta:'Tarjeta', otro:'Otro'}})}
        ${formField('referencia', 'N° Referencia / Comprobante', 'text')}
        ${formField('fecha', 'Fecha de Pago', 'date', new Date().toISOString().split('T')[0])}
        ${formField('nota', 'Nota', 'textarea', '', {fullWidth: true})}
    </form>
    <div style="margin-top:12px; padding:10px; background:rgba(34,197,94,.1); border-radius:8px; font-size:.8rem; color:#86efac;">
        Al confirmar el pago, se actualizará automáticamente la factura y se registrará el ingreso en Finanzas.
    </div>`;
    Modal.open('Registrar Pago', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="savePayment()">Confirmar Pago</button>`);
}

/** Guarda el pago via API — desencadena triggers automáticos */
async function savePayment() {
    const data = getFormData('frmPayment');
    if (!data.monto || parseInt(data.monto) <= 0) {
        toast('El monto debe ser mayor a 0', 'error');
        return;
    }
    const res = await API.post('create_payment', data);
    if (res) {
        toast('Pago registrado. Factura y Finanzas actualizados automáticamente.');
        refreshPage();
    }
}

/** Muestra historial de pagos de una cuenta */
async function viewPayments(cuentaId) {
    const res = await API.get('get_payments', { cuenta_cobrar_id: cuentaId });
    if (!res) return;
    const payments = res.data;
    let html = '';
    if (payments.length === 0) {
        html = '<p style="color:var(--text-muted)">Sin pagos registrados</p>';
    } else {
        html = '<table style="width:100%;font-size:.85rem;"><thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Nota</th></tr></thead><tbody>';
        for (const p of payments) {
            html += `<tr>
                <td>${fmtDate(p.fecha)}</td>
                <td style="color:var(--success);font-weight:600">${fmtMoney(p.monto)}</td>
                <td>${escHtml(p.metodo_pago)}</td>
                <td>${escHtml(p.referencia || '-')}</td>
                <td>${escHtml(p.nota || '-')}</td>
            </tr>`;
        }
        html += '</tbody></table>';
    }
    Modal.open('Historial de Pagos', html, '<button class="btn btn-secondary" onclick="Modal.close()">Cerrar</button>');
}
</script>
