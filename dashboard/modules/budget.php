<?php
/**
 * Módulo Presupuesto — Vista anual Ene-Dic
 * Cada categoría tiene monto plan + check por mes
 * Comparación plan vs real con indicadores visuales
 */

$anio = $_GET['anio'] ?? date('Y');
$meses_corto = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];

// Presupuesto completo del año (todos los meses)
$pres_all = query_all('SELECT mes, categoria, monto_plan FROM presupuesto WHERE mes LIKE ? ORDER BY categoria, mes', ["$anio-%"]) ?: [];
$pres_grid = []; // [categoria][mes] = monto
foreach ($pres_all as $p) $pres_grid[$p['categoria']][$p['mes']] = $p['monto_plan'];

// Gastos reales del año por categoría y mes
$gastos_all = query_all("SELECT strftime('%Y-%m', fecha) as mes, categoria, SUM(monto) as total FROM finanzas WHERE tipo = 'gasto' AND strftime('%Y', fecha) = ? GROUP BY mes, categoria", [$anio]) ?: [];
$real_grid = []; // [categoria][mes] = total
foreach ($gastos_all as $g) $real_grid[$g['categoria']][$g['mes']] = $g['total'];

// Ingresos del año por mes
$ingresos_all = query_all("SELECT strftime('%Y-%m', fecha) as mes, SUM(monto) as total FROM finanzas WHERE tipo = 'ingreso' AND strftime('%Y', fecha) = ? GROUP BY mes", [$anio]) ?: [];
$ing_grid = [];
foreach ($ingresos_all as $i) $ing_grid[$i['mes']] = $i['total'];

// Todas las categorías
$todas_cats = array_unique(array_merge(array_keys($pres_grid), array_keys($real_grid)));
sort($todas_cats);

$categorias_gasto = query_all('SELECT nombre FROM categorias_finanzas WHERE tipo IN ("gasto","ambos") AND activa = 1 ORDER BY nombre') ?: [];

// Totales anuales
$total_plan_anual = 0; $total_real_anual = 0; $total_ing_anual = 0;
foreach ($meses_corto as $num => $label) {
    $mes = "$anio-$num";
    $total_ing_anual += $ing_grid[$mes] ?? 0;
    foreach ($todas_cats as $cat) {
        $total_plan_anual += $pres_grid[$cat][$mes] ?? 0;
        $total_real_anual += $real_grid[$cat][$mes] ?? 0;
    }
}
$mes_actual = date('Y-m');
?>

<!-- Selector de año -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <a href="?page=budget&anio=<?= $anio - 1 ?>" class="btn btn-secondary btn-sm">←</a>
    <span style="font-size:1rem;font-weight:700;"><?= $anio ?></span>
    <a href="?page=budget&anio=<?= $anio + 1 ?>" class="btn btn-secondary btn-sm">→</a>
    <?php if (can_edit($current_user['id'], 'budget')): ?>
        <button class="btn btn-primary btn-sm" style="margin-left:auto;" onclick="openEditBudget()">Editar</button>
    <?php endif; ?>
</div>

<!-- KPIs anuales -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);gap:8px;">
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Ingresos <?= $anio ?></div>
        <div class="kpi-value success"><?= format_money($total_ing_anual) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)">
        <div class="kpi-label">Presupuesto Gastos</div>
        <div class="kpi-value"><?= format_money($total_plan_anual) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid <?= $total_real_anual > $total_plan_anual && $total_plan_anual > 0 ? 'var(--danger)' : 'var(--success)' ?>">
        <div class="kpi-label">Gasto Real</div>
        <div class="kpi-value <?= $total_real_anual > $total_plan_anual && $total_plan_anual > 0 ? 'danger' : '' ?>"><?= format_money($total_real_anual) ?></div>
        <?php if ($total_plan_anual > 0): ?>
            <div class="kpi-sub"><?= round($total_real_anual / $total_plan_anual * 100) ?>% ejecutado</div>
        <?php endif; ?>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--text-muted)">
        <div class="kpi-label">Resultado Neto</div>
        <?php $neto = $total_ing_anual - $total_real_anual; ?>
        <div class="kpi-value <?= $neto >= 0 ? 'success' : 'danger' ?>"><?= format_money($neto) ?></div>
    </div>
</div>

<!-- Tabla anual: categorías en filas, meses en columnas -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">Presupuesto Anual <?= $anio ?></span>
    </div>
    <div style="overflow-x:auto;">
        <table style="font-size:.68rem;table-layout:fixed;width:100%;">
            <thead>
                <tr>
                    <th style="width:100px;position:sticky;left:0;background:var(--surface);z-index:2;font-size:.65rem;">Categoría</th>
                    <?php foreach ($meses_corto as $num => $label): ?>
                        <th style="text-align:center;width:auto;font-size:.65rem;padding:4px 2px;<?= "$anio-$num" === $mes_actual ? 'color:var(--accent);' : '' ?>"><?= $label ?></th>
                    <?php endforeach; ?>
                    <th style="text-align:right;width:60px;color:var(--accent);font-size:.65rem;padding:4px 2px;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($todas_cats as $cat):
                    $cat_total_plan = 0; $cat_total_real = 0;
                ?>
                <tr>
                    <td style="position:sticky;left:0;background:var(--surface);z-index:1;"><strong><?= safe($cat) ?></strong></td>
                    <?php foreach ($meses_corto as $num => $label):
                        $mes = "$anio-$num";
                        $plan = $pres_grid[$cat][$mes] ?? 0;
                        $real = $real_grid[$cat][$mes] ?? 0;
                        $cat_total_plan += $plan;
                        $cat_total_real += $real;
                        $is_current = ($mes === $mes_actual);
                        $is_past = ($mes < $mes_actual);
                        $is_future = ($mes > $mes_actual);

                        // Determinar estado del check
                        if ($is_future) {
                            $check = ''; // futuro: sin check
                            $bg = '';
                        } elseif ($plan > 0 && $real <= $plan) {
                            $check = '<span style="color:var(--success);">&#10003;</span>'; // dentro del presupuesto
                            $bg = 'rgba(65,215,126,.06)';
                        } elseif ($plan > 0 && $real > $plan) {
                            $check = '<span style="color:var(--danger);">&#10007;</span>'; // sobrepasado
                            $bg = 'rgba(239,68,68,.06)';
                        } elseif ($plan == 0 && $real > 0) {
                            $check = '<span style="color:var(--warning);">!</span>'; // sin presupuesto pero con gasto
                            $bg = 'rgba(234,179,8,.06)';
                        } else {
                            $check = '<span style="color:var(--text-muted);">—</span>';
                            $bg = '';
                        }
                    ?>
                        <td style="text-align:center;padding:3px 1px;<?= $bg ? "background:$bg;" : '' ?><?= $is_current ? 'outline:1px solid var(--accent);' : '' ?>">
                            <div style="font-weight:600;font-size:.65rem;"><?= $plan > 0 ? '$' . number_format($plan/1000, 0, ',', '.') . 'k' : '<span style="color:var(--text-muted);">-</span>' ?></div>
                            <?php if ($is_past || $is_current): ?>
                                <div style="font-size:.58rem;color:<?= $real > $plan && $plan > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>;"><?= $real > 0 ? '$' . number_format($real/1000, 0, ',', '.') . 'k' : '' ?></div>
                                <div style="font-size:.7rem;line-height:1;"><?= $check ?></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td style="text-align:right;padding:3px 2px;">
                        <div style="font-weight:600;font-size:.65rem;"><?= format_money($cat_total_plan) ?></div>
                        <div style="font-size:.58rem;color:<?= $cat_total_real > $cat_total_plan && $cat_total_plan > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= format_money($cat_total_real) ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($todas_cats)): ?>
                <tr><td colspan="14" class="empty-state">Sin presupuesto configurado. Usa "Editar Presupuesto" para definir montos.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($todas_cats)): ?>
            <tfoot>
                <tr style="font-weight:700;border-top:2px solid var(--border);">
                    <td style="position:sticky;left:0;background:var(--surface);">Total</td>
                    <?php foreach ($meses_corto as $num => $label):
                        $mes = "$anio-$num";
                        $m_plan = 0; $m_real = 0;
                        foreach ($todas_cats as $c) { $m_plan += $pres_grid[$c][$mes] ?? 0; $m_real += $real_grid[$c][$mes] ?? 0; }
                    ?>
                        <td style="text-align:center;padding:3px 1px;">
                            <div style="font-size:.62rem;"><?= $m_plan > 0 ? '$' . number_format($m_plan/1000, 0, ',', '.') . 'k' : '-' ?></div>
                            <div style="font-size:.58rem;color:var(--text-muted)"><?= $m_real > 0 ? '$' . number_format($m_real/1000, 0, ',', '.') . 'k' : '' ?></div>
                        </td>
                    <?php endforeach; ?>
                    <td style="text-align:right;">
                        <div><?= format_money($total_plan_anual) ?></div>
                        <div style="font-size:.65rem;color:var(--text-muted)"><?= format_money($total_real_anual) ?></div>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Leyenda -->
<div style="margin-top:12px;padding:12px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:.72rem;color:var(--text-muted);display:flex;gap:16px;flex-wrap:wrap;">
    <span><span style="color:var(--success)">&#10003;</span> Dentro del presupuesto</span>
    <span><span style="color:var(--danger)">&#10007;</span> Sobrepasado</span>
    <span><span style="color:var(--warning)">!</span> Gasto sin presupuesto</span>
    <span>Fila superior: plan — Fila inferior: real</span>
</div>

<script>
const bCategorias = <?= json_encode(array_column($categorias_gasto, 'nombre')) ?>;
const bPresGrid = <?= json_encode($pres_grid) ?>;
const bAnio = '<?= $anio ?>';
const bMeses = ['01','02','03','04','05','06','07','08','09','10','11','12'];
const bMesesLabel = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function openEditBudget() {
    let html = '<div style="max-height:70vh;overflow:auto;"><table style="font-size:.72rem;width:100%;border-collapse:collapse;">';
    html += '<thead><tr><th style="min-width:80px;text-align:left;padding:3px;font-size:.65rem;">Cat.</th>';
    bMesesLabel.forEach(m => html += `<th style="text-align:center;padding:3px;font-size:.65rem;">${m}</th>`);
    html += '</tr></thead><tbody>';

    bCategorias.forEach(cat => {
        html += `<tr><td style="padding:3px;font-size:.7rem;"><strong>${escHtml(cat)}</strong></td>`;
        bMeses.forEach(m => {
            const mes = bAnio + '-' + m;
            const val = (bPresGrid[cat] && bPresGrid[cat][mes]) || '';
            html += `<td style="padding:2px;"><input type="number" class="form-input" style="width:52px;font-size:.68rem;padding:3px 2px;text-align:right;" value="${val}" placeholder="0" data-cat="${escHtml(cat)}" data-mes="${mes}"></td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table></div>';

    Modal.open('Presupuesto ' + bAnio, html,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" id="btnSaveBudget" onclick="saveBudget()">Guardar</button>`);
}

async function saveBudget() {
    const btn = document.getElementById('btnSaveBudget');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const inputs = document.querySelectorAll('input[data-cat][data-mes]');
    let saved = 0;
    for (const input of inputs) {
        const monto = parseInt(input.value) || 0;
        if (monto > 0) {
            await API.post('save_budget', { mes: input.dataset.mes, categoria: input.dataset.cat, monto_plan: monto });
            saved++;
        }
    }
    btn.disabled = false; btn.textContent = 'Guardar';
    toast(saved + ' celdas guardadas');
    Modal.close();
    refreshPage();
}
</script>
