<?php
/**
 * Módulo Cuenta Corriente — Saldo por cliente
 * Consolida: facturas emitidas (+), pagos recibidos (-), gastos ads (+), ajustes
 * Saldo positivo = cliente nos debe. Saldo negativo = saldo a favor del cliente.
 */

// Resumen por cliente: facturas + gastos_ads - pagos
$clientes = query_all("SELECT c.id, c.nombre, c.tipo,
    COALESCE((SELECT SUM(monto) FROM cuenta_corriente WHERE cliente_id = c.id AND tipo IN ('factura','gasto_ads','ajuste')), 0) as cargos,
    COALESCE((SELECT SUM(monto) FROM cuenta_corriente WHERE cliente_id = c.id AND tipo = 'pago'), 0) as pagos,
    COALESCE((SELECT SUM(monto_pendiente) FROM cuentas_cobrar WHERE cliente_id = c.id AND estado IN ('pendiente','parcial','vencido')), 0) as cxc_pendiente
    FROM clientes c WHERE c.tipo = 'activo' ORDER BY c.nombre") ?: [];

// Totales
$total_cxc = 0; $total_favor = 0;
foreach ($clientes as &$cl) {
    $cl['saldo_cc'] = $cl['cargos'] - $cl['pagos'];
    if ($cl['saldo_cc'] > 0) $total_cxc += $cl['saldo_cc'];
    else $total_favor += abs($cl['saldo_cc']);
}
unset($cl);

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
    <div class="kpi-card" style="border-left:3px solid var(--warning)">
        <div class="kpi-label">Total por Cobrar</div>
        <div class="kpi-value"><?= format_money($total_cxc) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Saldos a Favor Clientes</div>
        <div class="kpi-value success"><?= format_money($total_favor) ?></div>
        <div class="kpi-sub">Comprometido para inversión/servicios</div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)">
        <div class="kpi-label">Clientes Activos</div>
        <div class="kpi-value"><?= count($clientes) ?></div>
    </div>
</div>

<!-- Tabla resumen por cliente -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Cuenta Corriente por Cliente</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" onclick="openNewCC('pago')">+ Abono</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('gasto_ads')">+ Gasto Ads</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('factura')">+ Cargo</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('ajuste')">+ Ajuste</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th style="text-align:right">Cargos</th>
                <th style="text-align:right">Pagos</th>
                <th style="text-align:right">Saldo</th>
                <th style="text-align:right">CxC Pendiente</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clientes as $cl):
                $saldo = $cl['saldo_cc'];
                // Mostrar solo clientes con movimientos o CxC
                if ($saldo == 0 && $cl['cxc_pendiente'] == 0 && $cl['cargos'] == 0) continue;
                $saldo_color = $saldo > 0 ? 'var(--warning)' : ($saldo < 0 ? 'var(--success)' : 'var(--text-muted)');
                $saldo_label = $saldo > 0 ? 'Debe' : ($saldo < 0 ? 'A favor' : 'Al día');
            ?>
            <tr>
                <td><strong><?= safe($cl['nombre']) ?></strong></td>
                <td style="text-align:right;font-size:.82rem;"><?= format_money($cl['cargos']) ?></td>
                <td style="text-align:right;font-size:.82rem;color:var(--success)"><?= format_money($cl['pagos']) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $saldo_color ?>">
                    <?= $saldo > 0 ? format_money($saldo) : ($saldo < 0 ? '-' . format_money(abs($saldo)) : '$0') ?>
                    <div style="font-size:.6rem;font-weight:400;"><?= $saldo_label ?></div>
                </td>
                <td style="text-align:right;font-size:.82rem;color:var(--warning)"><?= $cl['cxc_pendiente'] > 0 ? format_money($cl['cxc_pendiente']) : '-' ?></td>
                <td>
                    <a href="?page=cta_corriente&cliente=<?= $cl['id'] ?>" class="btn btn-secondary btn-sm">Ver detalle</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php
            $con_movs = array_filter($clientes, fn($c) => $c['saldo_cc'] != 0 || $c['cxc_pendiente'] > 0 || $c['cargos'] > 0);
            if (empty($con_movs)):
            ?>
            <tr><td colspan="6" class="empty-state">Sin movimientos en cuenta corriente. Registra un pago o cargo desde el detalle de cada cliente.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($cliente_sel && $cliente_nombre): ?>
<!-- Detalle del cliente seleccionado -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Detalle — <?= safe($cliente_nombre) ?></span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" onclick="openNewCC('pago')">+ Abono</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('gasto_ads')">+ Gasto Ads</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('factura')">+ Cargo</button>
            <button class="btn btn-secondary btn-sm" onclick="openNewCC('ajuste')">+ Ajuste</button>
        </div>
    </div>

    <?php
    // Calcular saldo acumulado
    $saldo_acum = 0;
    $detalle_rev = array_reverse($detalle); // cronológico para calcular
    $saldos = [];
    foreach ($detalle_rev as $d) {
        if ($d['tipo'] === 'pago') $saldo_acum -= $d['monto'];
        else $saldo_acum += $d['monto'];
        $saldos[$d['id']] = $saldo_acum;
    }
    ?>

    <!-- Saldo actual -->
    <div style="padding:16px 20px;background:var(--bg);border-bottom:1px solid var(--border);">
        <?php $saldo_final = $saldo_acum; ?>
        <div style="display:flex;align-items:center;gap:16px;">
            <div>
                <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase;">Saldo Actual</div>
                <div style="font-size:1.3rem;font-weight:700;color:<?= $saldo_final > 0 ? 'var(--warning)' : ($saldo_final < 0 ? 'var(--success)' : 'var(--text-muted)') ?>">
                    <?= $saldo_final > 0 ? format_money($saldo_final) . ' (debe)' : ($saldo_final < 0 ? format_money(abs($saldo_final)) . ' (a favor)' : '$0 (al día)') ?>
                </div>
            </div>
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
                $tipo_labels = ['factura' => 'Factura', 'pago' => 'Pago', 'gasto_ads' => 'Gasto Ads', 'ajuste' => 'Ajuste'];
                $tipo_badges = ['factura' => 'status-info', 'pago' => 'status-success', 'gasto_ads' => 'status-warning', 'ajuste' => 'status-muted'];
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
            <tr><td colspan="6" class="empty-state">Sin movimientos. Registra un pago o cargo.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const ccClienteId = <?= (int)$cliente_sel ?: 0 ?>;
const ccClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

function openNewCC(tipo) {
    const labels = { pago: 'Registrar Abono', gasto_ads: 'Registrar Gasto Ads', factura: 'Registrar Cargo', ajuste: 'Registrar Ajuste' };
    const hoy = new Date().toISOString().split('T')[0];
    let clienteField = '';
    if (!ccClienteId) {
        clienteField = formField('cliente_id', 'Cliente', 'select', '', {required: true, options: ccClientesList});
    }
    const body = `<form id="frmCC">
        <input type="hidden" name="tipo" value="${tipo}">
        ${ccClienteId ? `<input type="hidden" name="cliente_id" value="${ccClienteId}">` : clienteField}
        ${formField('descripcion', 'Descripción', 'text', '', {required: true})}
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
