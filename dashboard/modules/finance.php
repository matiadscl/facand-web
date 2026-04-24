<?php
/**
 * Módulo Finanzas — Ingresos, gastos y flujo de caja
 * AUTOMATIZACIÓN: Los ingresos se generan automáticamente al cobrar facturas (trigger SQLite)
 * Los gastos se registran manualmente
 */

$mes_actual = $_GET['mes'] ?? date('Y-m');

// KPIs del mes
$ingresos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso" AND strftime("%Y-%m", fecha) = ?', [$mes_actual]) ?? 0;
$gastos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "gasto" AND strftime("%Y-%m", fecha) = ?', [$mes_actual]) ?? 0;
$resultado = $ingresos - $gastos;

// Movimientos del mes
$movimientos = query_all('SELECT f.*, c.nombre as cliente_nombre
    FROM finanzas f
    LEFT JOIN clientes c ON f.cliente_id = c.id
    WHERE strftime("%Y-%m", f.fecha) = ?
    ORDER BY f.fecha DESC, f.id DESC', [$mes_actual]);

// Categorías para gastos
$categorias_ingreso = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("ingreso","ambos") AND activa = 1 ORDER BY nombre');
$categorias_gasto = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("gasto","ambos") AND activa = 1 ORDER BY nombre');
$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');

// Resumen por categoría
$por_categoria = query_all('SELECT categoria, tipo, SUM(monto) as total FROM finanzas WHERE strftime("%Y-%m", fecha) = ? GROUP BY categoria, tipo ORDER BY total DESC', [$mes_actual]);

// Flujo últimos 6 meses
$flujo_meses = query_all('SELECT strftime("%Y-%m", fecha) as mes,
    SUM(CASE WHEN tipo = "ingreso" THEN monto ELSE 0 END) as ingresos,
    SUM(CASE WHEN tipo = "gasto" THEN monto ELSE 0 END) as gastos
    FROM finanzas WHERE fecha >= date("now", "-6 months")
    GROUP BY mes ORDER BY mes');

// Selector de meses disponibles
$meses_disp = query_all('SELECT DISTINCT strftime("%Y-%m", fecha) as mes FROM finanzas ORDER BY mes DESC LIMIT 12');

$meses_nombres = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
$mes_label = $meses_nombres[substr($mes_actual, 5, 2)] ?? '' ;
$mes_label .= ' ' . substr($mes_actual, 0, 4);
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Ingresos <?= $mes_label ?></div>
        <div class="kpi-value success"><?= format_money($ingresos) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Gastos <?= $mes_label ?></div>
        <div class="kpi-value danger"><?= format_money($gastos) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Resultado <?= $mes_label ?></div>
        <div class="kpi-value <?= $resultado >= 0 ? 'success' : 'danger' ?>"><?= format_money($resultado) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Margen</div>
        <div class="kpi-value"><?= $ingresos > 0 ? round($resultado / $ingresos * 100) : 0 ?>%</div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" data-tab="movimientos">Movimientos</button>
    <button class="tab" data-tab="categorias">Por Categoría</button>
    <button class="tab" data-tab="flujo">Flujo de Caja</button>
</div>

<!-- Movimientos -->
<div class="tab-content active" data-tab-content="movimientos">
    <div class="filters-bar">
        <select class="form-select" onchange="location.href='?page=finance&mes='+this.value">
            <option value="<?= date('Y-m') ?>">Mes Actual</option>
            <?php foreach ($meses_disp as $md): ?>
                <option value="<?= $md['mes'] ?>" <?= $md['mes'] === $mes_actual ? 'selected' : '' ?>><?= $md['mes'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Movimientos — <?= $mes_label ?></span>
            <?php if (can_edit($current_user['id'], 'finance')): ?>
                <div class="table-actions">
                    <button class="btn btn-primary btn-sm" onclick="openNewMovement('gasto')">+ Gasto</button>
                    <button class="btn btn-secondary btn-sm" onclick="openNewMovement('ingreso')">+ Ingreso Manual</button>
                </div>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Categoría</th>
                    <th>Descripción</th>
                    <th>Cliente</th>
                    <th>Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $m): ?>
                <tr>
                    <td><?= format_date($m['fecha']) ?></td>
                    <td><span class="badge <?= $m['tipo'] === 'ingreso' ? 'status-success' : 'status-danger' ?>"><?= ucfirst($m['tipo']) ?></span></td>
                    <td><?= safe($m['categoria']) ?></td>
                    <td><?= safe($m['descripcion']) ?></td>
                    <td><?= safe($m['cliente_nombre'] ?? '-') ?></td>
                    <td style="font-weight:600; color:<?= $m['tipo'] === 'ingreso' ? 'var(--success)' : 'var(--danger)' ?>">
                        <?= $m['tipo'] === 'gasto' ? '-' : '+' ?><?= format_money($m['monto']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($movimientos)): ?>
                <tr><td colspan="6" class="empty-state">Sin movimientos este mes</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Por Categoría -->
<div class="tab-content" data-tab-content="categorias">
    <div class="grid-2">
        <div class="chart-container">
            <div class="chart-title">Ingresos por Categoría</div>
            <?php
            $cats_ingreso = array_filter($por_categoria, fn($c) => $c['tipo'] === 'ingreso');
            $max_ing = !empty($cats_ingreso) ? max(array_column($cats_ingreso, 'total')) : 1;
            foreach ($cats_ingreso as $ci):
            ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:2px;">
                        <span><?= safe($ci['categoria']) ?></span>
                        <span style="color:var(--success)"><?= format_money($ci['total']) ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill success" style="width:<?= round($ci['total'] / $max_ing * 100) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($cats_ingreso)): ?>
                <div class="empty-state"><p>Sin ingresos</p></div>
            <?php endif; ?>
        </div>
        <div class="chart-container">
            <div class="chart-title">Gastos por Categoría</div>
            <?php
            $cats_gasto = array_filter($por_categoria, fn($c) => $c['tipo'] === 'gasto');
            $max_gas = !empty($cats_gasto) ? max(array_column($cats_gasto, 'total')) : 1;
            foreach ($cats_gasto as $cg):
            ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; font-size:.8rem; margin-bottom:2px;">
                        <span><?= safe($cg['categoria']) ?></span>
                        <span style="color:var(--danger)"><?= format_money($cg['total']) ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill danger" style="width:<?= round($cg['total'] / $max_gas * 100) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($cats_gasto)): ?>
                <div class="empty-state"><p>Sin gastos</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Flujo de Caja -->
<div class="tab-content" data-tab-content="flujo">
    <?php if (!empty($flujo_meses)): ?>
    <div class="chart-container">
        <div class="chart-title">Flujo de Caja — Últimos 6 Meses</div>
        <div class="bar-chart">
            <?php
            $max_f = 1;
            foreach ($flujo_meses as $fm) { $max_f = max($max_f, $fm['ingresos'], $fm['gastos']); }
            foreach ($flujo_meses as $fm):
                $ing_h = round($fm['ingresos'] / $max_f * 100);
                $gas_h = round($fm['gastos'] / $max_f * 100);
            ?>
            <div class="bar-item">
                <div class="bar-value" style="font-size:.6rem"><?= format_money($fm['ingresos'] - $fm['gastos']) ?></div>
                <div style="display:flex; gap:2px; align-items:flex-end; height:120px; width:100%;">
                    <div class="bar" style="height:<?= $ing_h ?>%; background:var(--success); flex:1;"></div>
                    <div class="bar" style="height:<?= $gas_h ?>%; background:var(--danger); flex:1;"></div>
                </div>
                <div class="bar-label"><?= substr($fm['mes'], 5) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
        <div class="empty-state"><p>Sin datos de flujo de caja</p></div>
    <?php endif; ?>
</div>

<script>
const fCategoriasIngreso = <?= json_encode(array_column($categorias_ingreso, 'nombre')) ?>;
const fCategoriasGasto = <?= json_encode(array_column($categorias_gasto, 'nombre')) ?>;
const fClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

function openNewMovement(tipo) {
    const cats = tipo === 'ingreso' ? fCategoriasIngreso : fCategoriasGasto;
    const catsObj = {};
    cats.forEach(c => catsObj[c] = c);

    const body = `<form id="frmFinance" class="form-grid">
        <input type="hidden" name="tipo" value="${tipo}">
        ${formField('descripcion', 'Descripción', 'text', '', {required: true})}
        ${formField('monto', 'Monto', 'number', '', {required: true})}
        ${formField('categoria', 'Categoría', 'select', '', {required: true, options: catsObj})}
        ${formField('cliente_id', 'Cliente (opcional)', 'select', '', {options: fClientesList})}
        ${formField('fecha', 'Fecha', 'date', new Date().toISOString().split('T')[0])}
    </form>`;
    Modal.open(`Nuevo ${tipo === 'ingreso' ? 'Ingreso' : 'Gasto'}`, body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveMovement()">Guardar</button>`);
}

async function saveMovement() {
    const res = await API.post('create_finance', getFormData('frmFinance'));
    if (res) { toast('Movimiento registrado'); refreshPage(); }
}
</script>
