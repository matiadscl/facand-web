<?php
/**
 * Módulo Resultados — Estado de Resultados (EERR)
 * 4 niveles: Sección > Categoría > Subcategoría > Detalle
 * Ingresos - CdV = Margen Bruto - GAV = EBITDA - No Op = Utilidad Neta
 * Toggle EERR (fecha contable) vs Flujo de Caja (fecha real)
 */

$movimientos = query_all('SELECT tipo, seccion, categoria, subcategoria, descripcion, monto, fecha, fecha_contable, cliente_id FROM finanzas ORDER BY fecha');
$meses_es = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
$cats_eerr = query_all("SELECT seccion, categoria, subcategoria, tipo, orden FROM categorias_eerr WHERE activa = 1 ORDER BY orden") ?: [];

// Balance
$balance_cxc = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial","vencido")') ?? 0;
$balance_favor = query_scalar("SELECT COALESCE(SUM(CASE WHEN tipo='pago' THEN monto ELSE 0 END) - SUM(CASE WHEN tipo IN ('factura','gasto_ads','ajuste') THEN monto ELSE 0 END), 0) FROM cuenta_corriente") ?? 0;
$balance_favor = max(0, $balance_favor);
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

<div class="kpi-grid" id="eerrKpis"></div>

<div class="table-container" style="margin-top:20px;">
    <div class="table-header">
        <span class="table-title" id="eerrTitle">Estado de Resultados</span>
    </div>
    <div style="overflow-x:auto;" id="eerrTable"></div>
</div>

<div class="chart-container" style="margin-top:20px;">
    <div class="chart-title">Evolución Mensual</div>
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
                </tbody>
            </table>
        </div>
        <div style="padding:12px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:.72rem;color:var(--text-muted);">
            Balance simplificado — no incluye activos fijos ni obligaciones tributarias.
        </div>
    </div>
</div><!-- /tab-balance -->

<script>
const allMov = <?= json_encode($movimientos ?: []) ?>;
const catsEerr = <?= json_encode($cats_eerr) ?>;
const MN = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const SECCIONES_ORDEN = ['Ingresos', 'Costo de Ventas', 'GAV', 'No Operacionales'];
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
    // Agrupar: seccion > categoria > subcategoria > mes > monto
    const tree = {}; // tree[seccion][categoria][subcategoria][mes] = monto
    const allMonths = new Set();

    allMov.forEach(m => {
        const mes = getMes(m);
        if (!mes) return;
        allMonths.add(mes);

        const sec = m.seccion || (m.tipo === 'ingreso' ? 'Ingresos' : 'GAV');
        const cat = m.categoria || 'Sin categoría';
        const sub = m.subcategoria || 'Sin subcategoría';

        if (!tree[sec]) tree[sec] = {};
        if (!tree[sec][cat]) tree[sec][cat] = {};
        if (!tree[sec][cat][sub]) tree[sec][cat][sub] = {};
        tree[sec][cat][sub][mes] = (tree[sec][cat][sub][mes] || 0) + m.monto;
    });

    const months = [...allMonths].sort();
    const lastMonths = months.slice(-6);

    if (!lastMonths.length) {
        document.getElementById('eerrKpis').innerHTML = '';
        document.getElementById('eerrTable').innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted)">Sin datos. Importa movimientos desde Cartolas.</div>';
        document.getElementById('incomeChart').innerHTML = '';
        return;
    }

    // Calcular totales por sección por mes
    function secTotal(sec, mes) {
        let t = 0;
        if (!tree[sec]) return 0;
        for (const cat in tree[sec]) for (const sub in tree[sec][cat]) t += (tree[sec][cat][sub][mes] || 0);
        return t;
    }
    function secTotalAll(sec) {
        let t = 0; lastMonths.forEach(m => t += secTotal(sec, m)); return t;
    }
    function catTotal(sec, cat, mes) {
        let t = 0;
        if (!tree[sec] || !tree[sec][cat]) return 0;
        for (const sub in tree[sec][cat]) t += (tree[sec][cat][sub][mes] || 0);
        return t;
    }

    const tIng = secTotalAll('Ingresos');
    const tCdV = secTotalAll('Costo de Ventas');
    const tGAV = secTotalAll('GAV');
    const tNoOp = secTotalAll('No Operacionales');
    const margenBruto = tIng - tCdV;
    const ebitda = margenBruto - tGAV;
    const utilidadNeta = ebitda - tNoOp;
    const margenPct = tIng > 0 ? Math.round(utilidadNeta / tIng * 100) : 0;

    // KPIs
    document.getElementById('eerrKpis').innerHTML = `
        <div class="kpi-card" style="border-left:3px solid var(--success)">
            <div class="kpi-label">Ingresos</div>
            <div class="kpi-value success">${fmtMoney(tIng)}</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid var(--warning)">
            <div class="kpi-label">Margen Bruto</div>
            <div class="kpi-value">${fmtMoney(margenBruto)}</div>
            <div class="kpi-sub">${tIng > 0 ? Math.round(margenBruto/tIng*100) : 0}%</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid var(--accent)">
            <div class="kpi-label">EBITDA</div>
            <div class="kpi-value">${fmtMoney(ebitda)}</div>
            <div class="kpi-sub">${tIng > 0 ? Math.round(ebitda/tIng*100) : 0}%</div>
        </div>
        <div class="kpi-card" style="border-left:3px solid ${utilidadNeta >= 0 ? 'var(--success)' : 'var(--danger)'}">
            <div class="kpi-label">Utilidad Neta</div>
            <div class="kpi-value ${utilidadNeta >= 0 ? 'success' : 'danger'}">${fmtMoney(utilidadNeta)}</div>
            <div class="kpi-sub">Margen: ${margenPct}%</div>
        </div>`;

    // Tabla EERR con 4 niveles
    let html = '<table style="font-size:.8rem;"><thead><tr><th style="min-width:220px">Concepto</th>';
    lastMonths.forEach(m => {
        const p = m.split('-');
        html += `<th style="text-align:right;width:90px">${MN[parseInt(p[1])]} ${p[0].slice(2)}</th>`;
    });
    html += '<th style="text-align:right;width:100px;color:var(--accent)">Total</th></tr></thead><tbody>';

    // Helper para fila
    function row(label, values, style = '', indent = 0) {
        const pl = indent * 16;
        let total = 0;
        html += `<tr style="${style}"><td style="padding-left:${14 + pl}px">${label}</td>`;
        lastMonths.forEach(m => {
            const v = typeof values === 'function' ? values(m) : (values[m] || 0);
            total += v;
            const c = v > 0 ? 'var(--text)' : v < 0 ? 'var(--danger)' : 'var(--text-muted)';
            html += `<td style="text-align:right;color:${c}">${v ? fmtMoney(Math.abs(v)) : '-'}</td>`;
        });
        const tc = total > 0 ? 'var(--text)' : total < 0 ? 'var(--danger)' : 'var(--text-muted)';
        html += `<td style="text-align:right;font-weight:600;color:${tc}">${total ? fmtMoney(Math.abs(total)) : '-'}</td></tr>`;
    }

    function calcRow(label, calcFn, style) {
        let total = 0;
        html += `<tr style="${style}"><td style="padding-left:14px">${label}</td>`;
        lastMonths.forEach(m => {
            const v = calcFn(m);
            total += v;
            const c = v >= 0 ? 'var(--success)' : 'var(--danger)';
            html += `<td style="text-align:right;color:${c};font-weight:600">${fmtMoney(v)}</td>`;
        });
        const tc = total >= 0 ? 'var(--success)' : 'var(--danger)';
        html += `<td style="text-align:right;color:${tc};font-weight:700">${fmtMoney(total)}</td></tr>`;
    }

    // Renderizar cada sección
    SECCIONES_ORDEN.forEach(sec => {
        if (!tree[sec] && sec !== 'Ingresos') return; // skip empty non-income sections

        // Sección header
        html += `<tr style="background:var(--bg);font-weight:700;"><td colspan="${lastMonths.length + 2}" style="padding:10px 14px;font-size:.85rem;">${sec}</td></tr>`;

        if (tree[sec]) {
            // Ordenar categorías según orden en catsEerr
            const catOrder = [...new Set(catsEerr.filter(c => c.seccion === sec).map(c => c.categoria))];
            const catsInTree = Object.keys(tree[sec]);
            const orderedCats = catOrder.filter(c => catsInTree.includes(c));
            catsInTree.forEach(c => { if (!orderedCats.includes(c)) orderedCats.push(c); });

            orderedCats.forEach(cat => {
                // Categoría (N2) - bold
                row(cat, m => catTotal(sec, cat, m), 'font-weight:600;', 1);

                // Subcategorías (N3)
                const subs = Object.keys(tree[sec][cat]);
                if (subs.length > 1 || (subs.length === 1 && subs[0] !== 'Sin subcategoría')) {
                    subs.forEach(sub => {
                        row(sub, tree[sec][cat][sub], 'font-size:.75rem;color:var(--text-muted);', 2);
                    });
                }
            });
        }

        // Subtotal sección
        row(`Total ${sec}`, m => secTotal(sec, m), 'font-weight:700;border-top:1px solid var(--border);');

        // Líneas calculadas después de cada sección
        if (sec === 'Costo de Ventas') {
            calcRow('MARGEN BRUTO', m => secTotal('Ingresos', m) - secTotal('Costo de Ventas', m),
                'font-weight:700;background:rgba(56,189,248,.05);border-top:2px solid var(--border);');
        }
        if (sec === 'GAV') {
            calcRow('EBITDA', m => secTotal('Ingresos', m) - secTotal('Costo de Ventas', m) - secTotal('GAV', m),
                'font-weight:700;background:rgba(249,115,22,.05);border-top:2px solid var(--border);');
        }
        if (sec === 'No Operacionales') {
            calcRow('UTILIDAD NETA', m => secTotal('Ingresos', m) - secTotal('Costo de Ventas', m) - secTotal('GAV', m) - secTotal('No Operacionales', m),
                'font-weight:700;font-size:.9rem;background:rgba(56,189,248,.08);border-top:3px solid var(--accent);');
        }
    });

    html += '</tbody></table>';
    document.getElementById('eerrTable').innerHTML = html;

    // Gráfico
    const maxVal = Math.max(...lastMonths.map(m => Math.max(secTotal('Ingresos', m), secTotal('Costo de Ventas', m) + secTotal('GAV', m) + secTotal('No Operacionales', m))), 1);
    let chartHtml = '';
    lastMonths.forEach(m => {
        const ing = secTotal('Ingresos', m);
        const gas = secTotal('Costo de Ventas', m) + secTotal('GAV', m) + secTotal('No Operacionales', m);
        const neto = ing - gas;
        const ingH = Math.round(ing / maxVal * 100);
        const gasH = Math.round(gas / maxVal * 100);
        const p = m.split('-');
        chartHtml += `<div class="bar-item">
            <div class="bar-value" style="font-size:.6rem;color:${neto >= 0 ? 'var(--success)' : 'var(--danger)'}">${fmtMoney(neto)}</div>
            <div style="display:flex;gap:2px;align-items:flex-end;height:120px;width:100%;">
                <div class="bar" style="height:${ingH}%;background:var(--success);flex:1;border-radius:3px 3px 0 0;"></div>
                <div class="bar" style="height:${gasH}%;background:var(--danger);flex:1;border-radius:3px 3px 0 0;"></div>
            </div>
            <div class="bar-label">${MN[parseInt(p[1])]}</div>
        </div>`;
    });
    document.getElementById('incomeChart').innerHTML = chartHtml;
}

render();
</script>
