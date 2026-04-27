<?php
/**
 * Módulo Cuenta Corriente Externa
 * Movimientos que NO son del EERR: abonos de clientes para ads, préstamos socios, traspasos
 * Separado de Cuentas por Cobrar (receivables) que maneja facturas
 */

// Resumen por cliente: solo movimientos de cuenta_corriente
$clientes = query_all("SELECT c.id, c.nombre,
    COALESCE((SELECT SUM(monto) FROM cuenta_corriente WHERE cliente_id = c.id AND tipo IN ('factura','gasto','gasto_ads','ajuste')), 0) as cargos,
    COALESCE((SELECT SUM(monto) FROM cuenta_corriente WHERE cliente_id = c.id AND tipo = 'pago'), 0) as pagos
    FROM clientes c WHERE c.tipo = 'activo' ORDER BY c.nombre") ?: [];

$total_cargos = 0; $total_pagos = 0;
foreach ($clientes as &$cl) {
    $cl['saldo'] = $cl['cargos'] - $cl['pagos'];
    $total_cargos += $cl['cargos'];
    $total_pagos += $cl['pagos'];
}
unset($cl);
$saldo_total = $total_cargos - $total_pagos;
// Saldo negativo = hay plata disponible de clientes/socios
$disponible = $saldo_total < 0 ? abs($saldo_total) : 0;
$por_cobrar = $saldo_total > 0 ? $saldo_total : 0;

// Filtrar solo clientes con movimientos
$con_movs = array_filter($clientes, fn($c) => $c['cargos'] > 0 || $c['pagos'] > 0);

$clientes_list = query_all('SELECT id, nombre FROM clientes WHERE tipo = "activo" ORDER BY nombre');
$cliente_sel = $_GET['cliente'] ?? '';
$detalle = [];
$cliente_nombre = '';
if ($cliente_sel) {
    $detalle = query_all('SELECT * FROM cuenta_corriente WHERE cliente_id = ? ORDER BY fecha DESC, id DESC', [(int)$cliente_sel]);
    $cn = query_one('SELECT nombre FROM clientes WHERE id = ?', [(int)$cliente_sel]);
    $cliente_nombre = $cn ? $cn['nombre'] : '';
}
?>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Disponible (a favor)</div>
        <div class="kpi-value success"><?= format_money($disponible) ?></div>
        <div class="kpi-sub">Fondos de clientes/socios en custodia</div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--warning)">
        <div class="kpi-label">Pendiente de cobro</div>
        <div class="kpi-value"><?= format_money($por_cobrar) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)">
        <div class="kpi-label">Cuentas activas</div>
        <div class="kpi-value"><?= count($con_movs) ?></div>
    </div>
</div>

<!-- Tabla resumen -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Cuenta Corriente Externa</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" onclick="openNewCC('pago')">+ Abono</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('gasto')">+ Gasto/Inversión</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('ajuste')">+ Ajuste</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Concepto / Cliente</th>
                <th style="text-align:right">Abonos</th>
                <th style="text-align:right">Cargos</th>
                <th style="text-align:right">Saldo</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($con_movs as $cl):
                $saldo = $cl['saldo'];
                $saldo_color = $saldo > 0 ? 'var(--warning)' : ($saldo < 0 ? 'var(--success)' : 'var(--text-muted)');
                $saldo_label = $saldo > 0 ? 'Debe' : ($saldo < 0 ? 'A favor' : 'Al día');
            ?>
            <tr>
                <td><strong><?= safe($cl['nombre']) ?></strong></td>
                <td style="text-align:right;font-size:.82rem;color:var(--success)"><?= format_money($cl['pagos']) ?></td>
                <td style="text-align:right;font-size:.82rem;"><?= format_money($cl['cargos']) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $saldo_color ?>">
                    <?= $saldo > 0 ? format_money($saldo) : ($saldo < 0 ? '-' . format_money(abs($saldo)) : '$0') ?>
                    <div style="font-size:.6rem;font-weight:400;"><?= $saldo_label ?></div>
                </td>
                <td>
                    <a href="?page=cta_corriente&cliente=<?= $cl['id'] ?>" class="btn btn-secondary btn-sm">Ver detalle</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($con_movs)): ?>
            <tr><td colspan="5" class="empty-state">Sin movimientos. Importa desde Mercado Pago o registra un abono manual.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($cliente_sel && $cliente_nombre): ?>
<!-- Detalle -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Detalle — <?= safe($cliente_nombre) ?></span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" onclick="openNewCC('pago')">+ Abono</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('gasto')">+ Gasto/Inversión</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('ajuste')">+ Ajuste</button>
        </div>
    </div>

    <?php
    $saldo_acum = 0;
    $detalle_rev = array_reverse($detalle);
    $saldos = [];
    foreach ($detalle_rev as $d) {
        if ($d['tipo'] === 'pago') $saldo_acum -= $d['monto'];
        else $saldo_acum += $d['monto'];
        $saldos[$d['id']] = $saldo_acum;
    }
    ?>

    <div style="padding:16px 20px;background:var(--bg);border-bottom:1px solid var(--border);">
        <?php $saldo_final = $saldo_acum; ?>
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase;">Saldo Actual</div>
        <div style="font-size:1.3rem;font-weight:700;color:<?= $saldo_final > 0 ? 'var(--warning)' : ($saldo_final < 0 ? 'var(--success)' : 'var(--text-muted)') ?>">
            <?= $saldo_final > 0 ? format_money($saldo_final) . ' (debe)' : ($saldo_final < 0 ? format_money(abs($saldo_final)) . ' (a favor)' : '$0 (al día)') ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Descripción</th>
                <th style="text-align:right">Cargo</th>
                <th style="text-align:right">Abono</th>
                <th style="text-align:right">Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detalle as $d):
                $es_cargo = ($d['tipo'] !== 'pago');
                $tipo_labels = ['pago' => 'Abono', 'gasto' => 'Gasto/Inv.', 'gasto_ads' => 'Inversión Ads', 'factura' => 'Cargo', 'ajuste' => 'Ajuste'];
                $tipo_badges = ['pago' => 'status-success', 'gasto' => 'status-warning', 'gasto_ads' => 'status-warning', 'factura' => 'status-info', 'ajuste' => 'status-muted'];
                $saldo_row = $saldos[$d['id']] ?? 0;
            ?>
            <tr>
                <td style="font-size:.82rem;white-space:nowrap"><?= format_date($d['fecha']) ?></td>
                <td><span class="badge <?= $tipo_badges[$d['tipo']] ?? 'status-muted' ?>" style="font-size:.65rem;"><?= $tipo_labels[$d['tipo']] ?? $d['tipo'] ?></span></td>
                <td><?= safe($d['descripcion']) ?></td>
                <td style="text-align:right;font-weight:600;color:var(--warning)"><?= $es_cargo ? format_money($d['monto']) : '' ?></td>
                <td style="text-align:right;font-weight:600;color:var(--success)"><?= !$es_cargo ? format_money($d['monto']) : '' ?></td>
                <td style="text-align:right;font-size:.8rem;color:<?= $saldo_row > 0 ? 'var(--warning)' : ($saldo_row < 0 ? 'var(--success)' : 'var(--text-muted)') ?>">
                    <?= $saldo_row >= 0 ? format_money($saldo_row) : '-' . format_money(abs($saldo_row)) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($detalle)): ?>
            <tr><td colspan="6" class="empty-state">Sin movimientos.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const ccClienteId = <?= (int)$cliente_sel ?: 0 ?>;
const ccClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

function openNewCC(tipo) {
    const labels = { pago: 'Registrar Abono', gasto: 'Registrar Gasto/Inversión', ajuste: 'Registrar Ajuste' };
    const hoy = new Date().toISOString().split('T')[0];
    let clienteField = '';
    if (!ccClienteId) {
        clienteField = formField('cliente_id', 'Cliente / Concepto', 'select', '', {required: true, options: ccClientesList});
    }
    const body = `<form id="frmCC">
        <input type="hidden" name="tipo" value="${tipo}">
        ${ccClienteId ? `<input type="hidden" name="cliente_id" value="${ccClienteId}">` : clienteField}
        ${formField('descripcion', 'Descripción', 'text', '', {required: true, placeholder: 'Ej: Abono Google Ads, Préstamo socio...'})}
        ${formField('monto', 'Monto ($)', 'number', '', {required: true})}
        <div class="form-group"><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-input" value="${hoy}"></div>
    </form>`;
    Modal.open(labels[tipo] || 'Movimiento', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveCC()">Registrar</button>`);
}

async function saveCC() {
    const data = getFormData('frmCC');
    if (!data.descripcion || !data.monto) { toast('Completa todos los campos', 'error'); return; }
    const res = await API.post('create_cc_movimiento', data);
    if (res) { toast('Movimiento registrado'); refreshPage(); }
}
</script>
