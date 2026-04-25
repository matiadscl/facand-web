<?php
/**
 * Módulo Movimientos — Registro completo de ingresos y gastos
 * Combina: movimientos automáticos (facturas/abonos) + manuales + banco + SII
 * Incluye resumen, filtros, paginación y exportación
 */

$meses_es = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes_sel = $_GET['mes'] ?? date('Y-m');
$mes_num = substr($mes_sel, 5, 2);
$mes_label = ($meses_es[$mes_num] ?? $mes_num) . ' ' . substr($mes_sel, 0, 4);
$meses_selector = [];
for ($i = -6; $i <= 2; $i++) {
    $m = date('Y-m', strtotime("$i months"));
    $meses_selector[$m] = $meses_es[substr($m, 5)] . ' ' . substr($m, 0, 4);
}

$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_cat = $_GET['cat'] ?? '';
$filtro_origen = $_GET['origen'] ?? '';

// KPIs del mes
$ingresos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso" AND strftime("%Y-%m", fecha) = ?', [$mes_sel]) ?? 0;
$gastos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "gasto" AND strftime("%Y-%m", fecha) = ?', [$mes_sel]) ?? 0;
$resultado = $ingresos - $gastos;
$cxc_pendiente = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido")') ?? 0;

// Filtrar movimientos
$where = 'strftime("%Y-%m", f.fecha) = ?';
$params = [$mes_sel];
if ($filtro_tipo) { $where .= ' AND f.tipo = ?'; $params[] = $filtro_tipo; }
if ($filtro_cat) { $where .= ' AND f.categoria = ?'; $params[] = $filtro_cat; }
if ($filtro_origen) { $where .= ' AND f.origen = ?'; $params[] = $filtro_origen; }

$movimientos = query_all("SELECT f.*, c.nombre as cliente_nombre
    FROM finanzas f
    LEFT JOIN clientes c ON f.cliente_id = c.id
    WHERE $where
    ORDER BY f.fecha DESC, f.id DESC
    LIMIT 200", $params);

// Categorías y clientes para formularios
$categorias_ingreso = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("ingreso","ambos") AND activa = 1 ORDER BY nombre');
$categorias_gasto = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("gasto","ambos") AND activa = 1 ORDER BY nombre');
$todas_categorias = query_all('SELECT DISTINCT categoria FROM finanzas WHERE categoria != "" ORDER BY categoria');
$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');

// Resumen por categoría para el mes
$por_categoria = query_all('SELECT categoria, tipo, SUM(monto) as total FROM finanzas WHERE strftime("%Y-%m", fecha) = ? GROUP BY categoria, tipo ORDER BY total DESC', [$mes_sel]);

// Flujo últimos 6 meses
$flujo_meses = query_all('SELECT strftime("%Y-%m", fecha) as mes,
    SUM(CASE WHEN tipo = "ingreso" THEN monto ELSE 0 END) as ingresos,
    SUM(CASE WHEN tipo = "gasto" THEN monto ELSE 0 END) as gastos
    FROM finanzas WHERE fecha >= date("now", "-6 months")
    GROUP BY mes ORDER BY mes');
?>

<!-- Selector de mes -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=movements&mes=<?= date('Y-m', strtotime("$mes_sel-01 -1 month")) ?>" class="btn btn-secondary btn-sm">← Anterior</a>
    <select class="form-select" style="font-size:.9rem;font-weight:600;min-width:180px;" onchange="location.href='?page=movements&mes='+this.value">
        <?php foreach ($meses_selector as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $mv === $mes_sel ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=movements&mes=<?= date('Y-m', strtotime("$mes_sel-01 +1 month")) ?>" class="btn btn-secondary btn-sm">Siguiente →</a>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Ingresos</div>
        <div class="kpi-value success"><?= format_money($ingresos) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--danger)">
        <div class="kpi-label">Gastos</div>
        <div class="kpi-value danger"><?= format_money($gastos) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid <?= $resultado >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
        <div class="kpi-label">Resultado</div>
        <div class="kpi-value <?= $resultado >= 0 ? 'success' : 'danger' ?>"><?= format_money($resultado) ?></div>
        <div class="kpi-sub"><?= $ingresos > 0 ? round($resultado / $ingresos * 100) : 0 ?>% margen</div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--warning)">
        <div class="kpi-label">Por Cobrar</div>
        <div class="kpi-value"><?= format_money($cxc_pendiente) ?></div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" data-tab="mov-tabla">Movimientos</button>
    <button class="tab" data-tab="mov-categorias">Por Categoría</button>
    <button class="tab" data-tab="mov-flujo">Flujo 6 Meses</button>
</div>

<!-- Tab: Movimientos -->
<div class="tab-content active" data-tab-content="mov-tabla">
    <div class="filters-bar" style="gap:8px;">
        <select class="form-select" onchange="location.href='?page=movements&mes=<?= $mes_sel ?>&tipo='+this.value+'&cat=<?= $filtro_cat ?>&origen=<?= $filtro_origen ?>'">
            <option value="">Todos los tipos</option>
            <option value="ingreso" <?= $filtro_tipo === 'ingreso' ? 'selected' : '' ?>>Ingresos</option>
            <option value="gasto" <?= $filtro_tipo === 'gasto' ? 'selected' : '' ?>>Gastos</option>
        </select>
        <select class="form-select" onchange="location.href='?page=movements&mes=<?= $mes_sel ?>&tipo=<?= $filtro_tipo ?>&cat='+this.value+'&origen=<?= $filtro_origen ?>'">
            <option value="">Todas las categorías</option>
            <?php foreach ($todas_categorias as $tc): ?>
                <option value="<?= safe($tc['categoria']) ?>" <?= $filtro_cat === $tc['categoria'] ? 'selected' : '' ?>><?= safe($tc['categoria']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select" onchange="location.href='?page=movements&mes=<?= $mes_sel ?>&tipo=<?= $filtro_tipo ?>&cat=<?= $filtro_cat ?>&origen='+this.value">
            <option value="">Todos los orígenes</option>
            <option value="factura" <?= $filtro_origen === 'factura' ? 'selected' : '' ?>>Factura</option>
            <option value="banco" <?= $filtro_origen === 'banco' ? 'selected' : '' ?>>Banco</option>
            <option value="manual" <?= $filtro_origen === 'manual' ? 'selected' : '' ?>>Manual</option>
            <option value="sii" <?= $filtro_origen === 'sii' ? 'selected' : '' ?>>SII</option>
        </select>
    </div>

    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Movimientos — <?= $mes_label ?> (<?= count($movimientos) ?>)</span>
            <?php if (can_edit($current_user['id'], 'movements')): ?>
                <div class="table-actions">
                    <button class="btn btn-primary btn-sm" onclick="openNewMovement('gasto')">+ Gasto</button>
                    <button class="btn btn-secondary btn-sm" onclick="openNewMovement('ingreso')">+ Ingreso</button>
                </div>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Origen</th>
                    <th>Descripción</th>
                    <th>Categoría</th>
                    <th>Cliente</th>
                    <th style="text-align:right">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $m):
                    $origen = $m['origen'] ?? ($m['factura_id'] ? 'factura' : 'manual');
                    $origen_badge = match($origen) {
                        'factura' => 'status-info',
                        'banco'   => 'status-success',
                        'sii'     => 'status-warning',
                        default   => 'status-muted',
                    };
                ?>
                <tr>
                    <td style="font-size:.82rem;white-space:nowrap"><?= format_date($m['fecha']) ?></td>
                    <td><span class="badge <?= $origen_badge ?>" style="font-size:.65rem;"><?= ucfirst($origen) ?></span></td>
                    <td><?= safe($m['descripcion']) ?></td>
                    <td style="font-size:.82rem"><?= safe($m['categoria']) ?></td>
                    <td style="font-size:.82rem"><?= safe($m['cliente_nombre'] ?? '-') ?></td>
                    <td style="font-weight:600;text-align:right;white-space:nowrap;color:<?= $m['tipo'] === 'ingreso' ? 'var(--success)' : 'var(--danger)' ?>">
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

<!-- Tab: Por Categoría -->
<div class="tab-content" data-tab-content="mov-categorias">
    <div class="grid-2">
        <div class="chart-container">
            <div class="chart-title">Ingresos por Categoría</div>
            <?php
            $cats_ingreso = array_filter($por_categoria, fn($c) => $c['tipo'] === 'ingreso');
            $max_ing = !empty($cats_ingreso) ? max(array_column($cats_ingreso, 'total')) : 1;
            foreach ($cats_ingreso as $ci): ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:2px;">
                        <span><?= safe($ci['categoria']) ?></span>
                        <span style="color:var(--success)"><?= format_money($ci['total']) ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill success" style="width:<?= round($ci['total'] / $max_ing * 100) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($cats_ingreso)): ?><div class="empty-state"><p>Sin ingresos</p></div><?php endif; ?>
        </div>
        <div class="chart-container">
            <div class="chart-title">Gastos por Categoría</div>
            <?php
            $cats_gasto = array_filter($por_categoria, fn($c) => $c['tipo'] === 'gasto');
            $max_gas = !empty($cats_gasto) ? max(array_column($cats_gasto, 'total')) : 1;
            foreach ($cats_gasto as $cg): ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:2px;">
                        <span><?= safe($cg['categoria']) ?></span>
                        <span style="color:var(--danger)"><?= format_money($cg['total']) ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill danger" style="width:<?= round($cg['total'] / $max_gas * 100) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($cats_gasto)): ?><div class="empty-state"><p>Sin gastos</p></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab: Flujo de Caja -->
<div class="tab-content" data-tab-content="mov-flujo">
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
                $neto = $fm['ingresos'] - $fm['gastos'];
            ?>
            <div class="bar-item">
                <div class="bar-value" style="font-size:.6rem;color:<?= $neto >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= format_money($neto) ?></div>
                <div style="display:flex;gap:2px;align-items:flex-end;height:120px;width:100%;">
                    <div class="bar" style="height:<?= $ing_h ?>%;background:var(--success);flex:1;border-radius:3px 3px 0 0;"></div>
                    <div class="bar" style="height:<?= $gas_h ?>%;background:var(--danger);flex:1;border-radius:3px 3px 0 0;"></div>
                </div>
                <div class="bar-label"><?= ($meses_es[substr($fm['mes'], 5)] ?? substr($fm['mes'], 5)) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:8px;font-size:.7rem;color:var(--text-muted)">
            <span style="color:var(--success)">■</span> Ingresos &nbsp; <span style="color:var(--danger)">■</span> Gastos
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
    const hoy = new Date().toISOString().split('T')[0];

    const body = `<form id="frmFinance" class="form-grid">
        <input type="hidden" name="tipo" value="${tipo}">
        ${formField('descripcion', 'Descripción', 'text', '', {required: true})}
        ${formField('monto', 'Monto ($)', 'number', '', {required: true})}
        ${formField('categoria', 'Categoría', 'select', '', {required: true, options: catsObj})}
        ${formField('subcategoria', 'Subcategoría (opcional)', 'text', '')}
        ${formField('cliente_id', 'Cliente (opcional)', 'select', '', {options: fClientesList})}
        <div class="form-group"><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-input" value="${hoy}"></div>
        ${formField('notas', 'Notas', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open(`Nuevo ${tipo === 'ingreso' ? 'Ingreso' : 'Gasto'}`, body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveMovement()">Guardar</button>`);
}

async function saveMovement() {
    const data = getFormData('frmFinance');
    if (!data.descripcion || !data.monto) { toast('Completa descripción y monto', 'error'); return; }
    data.origen = 'manual';
    const res = await API.post('create_finance', data);
    if (res) { toast('Movimiento registrado'); refreshPage(); }
}
</script>
