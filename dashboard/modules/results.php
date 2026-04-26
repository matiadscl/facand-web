<?php
/**
 * Módulo Resultados — Estado de Resultados simplificado
 * Para dueños de PyME, no contadores. Lenguaje simple.
 * Toggle EERR (fecha contable) vs Flujo de Caja (fecha real)
 */

// Todos los movimientos para calcular EERR
$movimientos = query_all('SELECT tipo, categoria, subcategoria, monto, fecha, fecha_contable FROM finanzas ORDER BY fecha');
$meses_es = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];

// Balance: datos para la pestaña
$balance_cxc = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido")') ?? 0;
$balance_favor = query_scalar("SELECT COALESCE(SUM(CASE WHEN tipo='pago' THEN monto ELSE 0 END) - SUM(CASE WHEN tipo IN ('factura','gasto_ads','ajuste') THEN monto ELSE 0 END), 0) FROM cuenta_corriente") ?? 0;
$balance_favor = max(0, $balance_favor); // solo si es positivo (a favor del cliente)
$balance_ingresos_total = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso"') ?? 0;
$balance_gastos_total = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "gasto"') ?? 0;
$balance_resultado_acum = $balance_ingresos_total - $balance_gastos_total;
?>

<!-- Tabs principales -->
<div class="tabs" style="margin-bottom:20px;">
    <button class="tab active" data-tab="tab-eerr">Estado de Resultados</button>
    <button class="tab" data-tab="tab-balance">Balance</button>
</div>

<!-- TAB EERR -->
<div class="tab-content active" data-tab-content="tab-eerr">
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <span style="font-size:.82rem;color:var(--text-muted);">Modo:</span>
    <button class="btn btn-primary btn-sm" id="btnEERR" onclick="setMode('eerr')">Contable</button>
    <button class="btn btn-secondary btn-sm" id="btnFC" onclick="setMode('fc')">Flujo de Caja</button>
    <span style="font-size:.72rem;color:var(--text-muted);margin-left:auto;" id="modeHint">Usando fecha contable</span>
</div>

<!-- KPIs -->
<div class="kpi-grid" id="eerrKpis"></div>

<!-- Tabla EERR -->
<div class="table-container" style="margin-top:20px;">
    <div class="table-header">
        <span class="table-title" id="eerrTitle">Estado de Resultados</span>
    </div>
    <div style="overflow-x:auto;" id="eerrTable"></div>
</div>

<!-- Flujo 6 meses gráfico -->
<div class="chart-container" style="margin-top:20px;">
    <div class="chart-title">Evolución de Ingresos</div>
    <div id="incomeChart" class="bar-chart"></div>
</div>
</div><!-- /tab-eerr -->

<!-- TAB BALANCE -->
<div class="tab-content" data-tab-content="tab-balance">
    <div style="max-width:600px;">
        <div class="table-container" style="margin-bottom:20px;">
            <div class="table-header"><span class="table-title">Balance Simplificado</span></div>
            <table>
                <thead><tr><th>Concepto</th><th style="text-align:right">Monto</th></tr></thead>
                <tbody>
                    <tr style="font-weight:700;background:var(--bg);"><td colspan="2" style="padding:10px 14px;">ACTIVOS</td></tr>
                    <tr>
                        <td style="padding-left:24px;">Cuentas por cobrar</td>
                        <td style="text-align:right;font-weight:600;"><?= format_money($balance_cxc) ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:24px;">Resultado acumulado</td>
                        <td style="text-align:right;font-weight:600;color:<?= $balance_resultado_acum >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= format_money($balance_resultado_acum) ?></td>
                    </tr>
                    <tr style="font-weight:700;border-top:2px solid var(--border);">
                        <td>Total Activos</td>
                        <td style="text-align:right;color:var(--success)"><?= format_money($balance_cxc + max(0, $balance_resultado_acum)) ?></td>
                    </tr>

                    <tr style="font-weight:700;background:var(--bg);"><td colspan="2" style="padding:10px 14px;">PASIVOS</td></tr>
                    <tr>
                        <td style="padding-left:24px;">Cuentas clientes (disponible)</td>
                        <td style="text-align:right;font-weight:600;color:var(--warning)"><?= format_money($balance_favor) ?></td>
                    </tr>
                    <tr style="font-size:.72rem;color:var(--text-muted);">
                        <td style="padding-left:32px;">Fondos comprometidos para inversión publicitaria</td>
                        <td></td>
                    </tr>
                    <tr style="font-weight:700;border-top:2px solid var(--border);">
                        <td>Total Pasivos</td>
                        <td style="text-align:right;color:var(--warning)"><?= format_money($balance_favor) ?></td>
                    </tr>

                    <tr style="font-weight:700;background:var(--bg);"><td colspan="2" style="padding:10px 14px;">PATRIMONIO</td></tr>
                    <?php $patrimonio = ($balance_cxc + max(0, $balance_resultado_acum)) - $balance_favor; ?>
                    <tr>
                        <td style="padding-left:24px;">Activos - Pasivos</td>
                        <td style="text-align:right;font-weight:700;font-size:1.1rem;color:<?= $patrimonio >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= format_money($patrimonio) ?></td>
                    </tr>
                    <tr style="font-size:.72rem;color:var(--text-muted);">
                        <td style="padding-left:32px;">Lo que es realmente de la empresa</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="padding:12px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:.72rem;color:var(--text-muted);">
            Este balance es simplificado — no incluye activos fijos, inventario ni obligaciones tributarias. Para contabilidad formal, consulta a tu contador.
        </div>
    </div>
</div><!-- /tab-balance -->

<script>
const allMov = <?= json_encode($movimientos) ?>;
const MN = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
let mode = 'eerr';

function setMode(m) {
    mode = m;
    document.getElementById('btnEERR').className = 'btn btn-sm ' + (m==='eerr'?'btn-primary':'btn-secondary');
    document.getElementById('btnFC').className = 'btn btn-sm ' + (m==='fc'?'btn-primary':'btn-secondary');
    document.getElementById('modeHint').textContent = m==='eerr' ? 'Usando fecha contable' : 'Usando fecha de movimiento bancario';
    document.getElementById('eerrTitle').textContent = m==='eerr' ? 'Estado de Resultados' : 'Flujo de Caja';
    render();
}

function getMes(mov) {
    const f = mode === 'eerr' ? (mov.fecha_contable || mov.fecha) : mov.fecha;
    return f ? f.substring(0, 7) : '';
}

function render() {
    // Agrupar por mes
    const monthly = {};
    allMov.forEach(m => {
        const mes = getMes(m);
        if (!mes) return;
        if (!monthly[mes]) monthly[mes] = { ingresos: 0, gastos: 0 };
        if (m.tipo === 'ingreso') monthly[mes].ingresos += m.monto;
        else monthly[mes].gastos += m.monto;
    });

    const months = Object.keys(monthly).sort();
    if (!months.length) {
        document.getElementById('eerrKpis').innerHTML = '';
        document.getElementById('eerrTable').innerHTML = '<div class="empty-state"><p>Sin datos financieros. Registra movimientos o importa cartolas.</p></div>';
        document.getElementById('incomeChart').innerHTML = '';
        return;
    }

    // Totales
    let tIng = 0, tGas = 0;
    months.forEach(m => { tIng += monthly[m].ingresos; tGas += monthly[m].gastos; });
    const tRes = tIng - tGas;
    const margen = tIng > 0 ? Math.round(tRes / tIng * 100) : 0;
    const promMensual = Math.round(tIng / months.length);

    // KPIs
    document.getElementById('eerrKpis').innerHTML = `
        <div class="kpi-card" style="border-left:3px solid var(--success)">
            <div class="kpi-label">Ingresos Totales</div>
            <div class="kpi-value success">${fmtMoney(tIng)}</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid var(--danger)">
            <div class="kpi-label">Gastos Totales</div>
            <div class="kpi-value danger">${fmtMoney(tGas)}</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid ${tRes >= 0 ? 'var(--success)' : 'var(--danger)'}">
            <div class="kpi-label">Resultado Neto</div>
            <div class="kpi-value ${tRes >= 0 ? 'success' : 'danger'}">${fmtMoney(tRes)}</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid var(--accent)">
            <div class="kpi-label">Margen</div>
            <div class="kpi-value">${margen}%</div>
            <div class="kpi-sub">Promedio mensual: ${fmtMoney(promMensual)}</div>
        </div>`;

    // Tabla EERR
    let html = '<table><thead><tr><th>Concepto</th>';
    const lastMonths = months.slice(-6);
    lastMonths.forEach(m => {
        const parts = m.split('-');
        html += `<th style="text-align:right">${MN[parseInt(parts[1])]} ${parts[0].slice(2)}</th>`;
    });
    html += '<th style="text-align:right;color:var(--accent)">Total</th></tr></thead><tbody>';

    // Fila Ingresos
    let ingTotal = 0;
    html += '<tr style="font-weight:600"><td>Ingresos</td>';
    lastMonths.forEach(m => { const v = monthly[m].ingresos; ingTotal += v; html += `<td style="text-align:right;color:var(--success)">${fmtMoney(v)}</td>`; });
    html += `<td style="text-align:right;color:var(--success);font-weight:700">${fmtMoney(ingTotal)}</td></tr>`;

    // Fila Gastos
    let gasTotal = 0;
    html += '<tr style="font-weight:600"><td>Gastos</td>';
    lastMonths.forEach(m => { const v = monthly[m].gastos; gasTotal += v; html += `<td style="text-align:right;color:var(--danger)">-${fmtMoney(v)}</td>`; });
    html += `<td style="text-align:right;color:var(--danger);font-weight:700">-${fmtMoney(gasTotal)}</td></tr>`;

    // Fila Resultado
    let resTotal = 0;
    html += '<tr style="font-weight:700;border-top:2px solid var(--border)"><td>Resultado</td>';
    lastMonths.forEach(m => { const v = monthly[m].ingresos - monthly[m].gastos; resTotal += v; const c = v >= 0 ? 'var(--success)' : 'var(--danger)'; html += `<td style="text-align:right;color:${c}">${fmtMoney(v)}</td>`; });
    html += `<td style="text-align:right;color:${resTotal >= 0 ? 'var(--success)' : 'var(--danger)'};font-weight:700">${fmtMoney(resTotal)}</td></tr>`;

    // Fila Margen
    html += '<tr style="font-size:.8rem;color:var(--text-muted)"><td>Margen</td>';
    lastMonths.forEach(m => { const ing = monthly[m].ingresos; const res = ing - monthly[m].gastos; const pct = ing > 0 ? Math.round(res/ing*100) : 0; html += `<td style="text-align:right">${pct}%</td>`; });
    html += `<td style="text-align:right">${margen}%</td></tr>`;

    html += '</tbody></table>';
    document.getElementById('eerrTable').innerHTML = html;

    // Gráfico de evolución
    const maxVal = Math.max(...lastMonths.map(m => Math.max(monthly[m].ingresos, monthly[m].gastos)), 1);
    let chartHtml = '';
    lastMonths.forEach(m => {
        const ing = monthly[m].ingresos;
        const gas = monthly[m].gastos;
        const neto = ing - gas;
        const ingH = Math.round(ing / maxVal * 100);
        const gasH = Math.round(gas / maxVal * 100);
        const parts = m.split('-');
        chartHtml += `<div class="bar-item">
            <div class="bar-value" style="font-size:.6rem;color:${neto >= 0 ? 'var(--success)' : 'var(--danger)'}">${fmtMoney(neto)}</div>
            <div style="display:flex;gap:2px;align-items:flex-end;height:120px;width:100%;">
                <div class="bar" style="height:${ingH}%;background:var(--success);flex:1;border-radius:3px 3px 0 0;"></div>
                <div class="bar" style="height:${gasH}%;background:var(--danger);flex:1;border-radius:3px 3px 0 0;"></div>
            </div>
            <div class="bar-label">${MN[parseInt(parts[1])]}</div>
        </div>`;
    });
    document.getElementById('incomeChart').innerHTML = chartHtml;
}

render();
</script>
