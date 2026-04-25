<?php
/**
 * Módulo Presupuesto — Plan mensual vs real por categoría
 * Configurar monto esperado por categoría/mes, comparar con gastos reales
 */

$mes_sel = $_GET['mes'] ?? date('Y-m');
$meses_es = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes_label = ($meses_es[substr($mes_sel, 5, 2)] ?? '') . ' ' . substr($mes_sel, 0, 4);
$meses_selector = [];
for ($i = -6; $i <= 2; $i++) {
    $m = date('Y-m', strtotime("$i months"));
    $meses_selector[$m] = $meses_es[substr($m, 5)] . ' ' . substr($m, 0, 4);
}

// Presupuesto del mes
$presupuesto = query_all('SELECT * FROM presupuesto WHERE mes = ? ORDER BY categoria', [$mes_sel]) ?: [];
$pres_map = [];
foreach ($presupuesto as $p) $pres_map[$p['categoria']] = $p['monto_plan'];
$total_plan = array_sum(array_column($presupuesto, 'monto_plan'));

// Gastos reales del mes por categoría
$gastos_real = query_all('SELECT categoria, SUM(monto) as total FROM finanzas WHERE tipo = "gasto" AND strftime("%Y-%m", fecha) = ? GROUP BY categoria ORDER BY total DESC', [$mes_sel]) ?: [];
$real_map = [];
foreach ($gastos_real as $g) $real_map[$g['categoria']] = $g['total'];
$total_real = array_sum(array_column($gastos_real, 'total'));

// Ingresos del mes
$ingresos_mes = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso" AND strftime("%Y-%m", fecha) = ?', [$mes_sel]) ?? 0;

// Todas las categorías (unión de presupuesto + reales)
$todas_cats = array_unique(array_merge(array_keys($pres_map), array_keys($real_map)));
sort($todas_cats);

$categorias_gasto = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("gasto","ambos") AND activa = 1 ORDER BY nombre') ?: [];
?>

<!-- Selector de mes -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=budget&mes=<?= date('Y-m', strtotime("$mes_sel-01 -1 month")) ?>" class="btn btn-secondary btn-sm">← Anterior</a>
    <select class="form-select" style="font-size:.9rem;font-weight:600;min-width:180px;" onchange="location.href='?page=budget&mes='+this.value">
        <?php foreach ($meses_selector as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $mv === $mes_sel ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=budget&mes=<?= date('Y-m', strtotime("$mes_sel-01 +1 month")) ?>" class="btn btn-secondary btn-sm">Siguiente →</a>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Ingresos <?= substr($mes_label, 0, 3) ?></div>
        <div class="kpi-value success"><?= format_money($ingresos_mes) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)">
        <div class="kpi-label">Presupuesto Gastos</div>
        <div class="kpi-value"><?= format_money($total_plan) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid <?= $total_real > $total_plan && $total_plan > 0 ? 'var(--danger)' : 'var(--success)' ?>">
        <div class="kpi-label">Gasto Real</div>
        <div class="kpi-value <?= $total_real > $total_plan && $total_plan > 0 ? 'danger' : '' ?>"><?= format_money($total_real) ?></div>
        <?php if ($total_plan > 0): ?>
            <div class="kpi-sub"><?= round($total_real / $total_plan * 100) ?>% del presupuesto</div>
        <?php endif; ?>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--text-muted)">
        <div class="kpi-label">Disponible</div>
        <div class="kpi-value"><?= format_money(max(0, $total_plan - $total_real)) ?></div>
    </div>
</div>

<!-- Tabla comparativa -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Presupuesto vs Real — <?= $mes_label ?></span>
        <?php if (can_edit($current_user['id'], 'budget')): ?>
            <button class="btn btn-primary btn-sm" onclick="openEditBudget()">Editar Presupuesto</button>
        <?php endif; ?>
    </div>
    <table>
        <thead><tr><th>Categoría</th><th style="text-align:right">Presupuestado</th><th style="text-align:right">Real</th><th style="text-align:right">Diferencia</th><th>Uso</th></tr></thead>
        <tbody>
            <?php foreach ($todas_cats as $cat):
                $plan = $pres_map[$cat] ?? 0;
                $real = $real_map[$cat] ?? 0;
                $diff = $plan - $real;
                $pct = $plan > 0 ? min(round($real / $plan * 100), 150) : ($real > 0 ? 100 : 0);
                $bar_color = $pct > 100 ? 'var(--danger)' : ($pct > 80 ? 'var(--warning)' : 'var(--success)');
            ?>
            <tr>
                <td><strong><?= safe($cat) ?></strong></td>
                <td style="text-align:right"><?= $plan > 0 ? format_money($plan) : '<span style="color:var(--text-muted)">-</span>' ?></td>
                <td style="text-align:right;font-weight:600"><?= format_money($real) ?></td>
                <td style="text-align:right;color:<?= $diff >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $diff >= 0 ? '+' : '' ?><?= format_money($diff) ?></td>
                <td style="width:120px;">
                    <div style="background:var(--bg);border-radius:4px;height:8px;overflow:hidden;">
                        <div style="height:100%;width:<?= min($pct, 100) ?>%;background:<?= $bar_color ?>;border-radius:4px;"></div>
                    </div>
                    <div style="font-size:.65rem;color:var(--text-muted);text-align:center;margin-top:2px;"><?= $pct ?>%</div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($todas_cats)): ?>
            <tr><td colspan="5" class="empty-state">Sin presupuesto configurado. Usa "Editar Presupuesto" para definir montos por categoría.</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if (!empty($todas_cats)): ?>
        <tfoot>
            <tr style="font-weight:700;border-top:2px solid var(--border)">
                <td>Total</td>
                <td style="text-align:right"><?= format_money($total_plan) ?></td>
                <td style="text-align:right"><?= format_money($total_real) ?></td>
                <td style="text-align:right;color:<?= $total_plan - $total_real >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= format_money($total_plan - $total_real) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<script>
const bCategorias = <?= json_encode(array_column($categorias_gasto, 'nombre')) ?>;
const bPresupuesto = <?= json_encode($pres_map) ?>;
const bMes = '<?= $mes_sel ?>';

function openEditBudget() {
    let rows = '';
    bCategorias.forEach(cat => {
        const val = bPresupuesto[cat] || '';
        rows += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <span style="flex:1;font-size:.85rem;">${escHtml(cat)}</span>
            <input type="number" name="cat_${escHtml(cat)}" class="form-input" style="width:140px;text-align:right;" value="${val}" placeholder="0" data-cat="${escHtml(cat)}">
        </div>`;
    });
    const body = `<form id="frmBudget" style="max-height:400px;overflow-y:auto;padding-right:8px;">
        <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:12px;">Define el monto presupuestado para cada categoría de gasto en ${bMes}.</p>
        ${rows}
    </form>`;
    Modal.open('Editar Presupuesto — ' + bMes, body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveBudget()">Guardar</button>`);
}

async function saveBudget() {
    const inputs = document.querySelectorAll('#frmBudget input[data-cat]');
    let saved = 0;
    for (const input of inputs) {
        const cat = input.dataset.cat;
        const monto = parseInt(input.value) || 0;
        if (monto > 0) {
            const res = await API.post('save_budget', { mes: bMes, categoria: cat, monto_plan: monto });
            if (res) saved++;
        }
    }
    toast(saved + ' categorías guardadas');
    Modal.close();
    refreshPage();
}
</script>
