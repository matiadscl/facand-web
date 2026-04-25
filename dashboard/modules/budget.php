<?php
/**
 * Módulo Presupuesto — EERR proyectado anual
 * Estructura: Ingresos → CdV (fijo/variable) → Margen Bruto → GAV (fijo/variable) → EBITDA → No Op → Utilidad Neta
 * Items fijos se replican automáticamente. Variables = % de ingresos.
 */

$anio = $_GET['anio'] ?? date('Y');
$MN = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
$meses = [];
foreach ($MN as $num => $label) $meses[] = "$anio-$num";
$mes_actual = date('Y-m');

// Items del presupuesto
$items = query_all('SELECT * FROM budget_items WHERE activo = 1 ORDER BY categoria, orden, nombre') ?: [];

// Valores por item/mes
$values_raw = query_all("SELECT item_id, mes, valor FROM budget_values WHERE mes LIKE ?", ["$anio-%"]) ?: [];
$values = []; // [item_id][mes] = valor
foreach ($values_raw as $v) $values[$v['item_id']][$v['mes']] = $v['valor'];

// Gastos reales por mes (para comparación)
$reales = query_all("SELECT strftime('%Y-%m', fecha) as mes, tipo, SUM(monto) as total FROM finanzas WHERE strftime('%Y', fecha) = ? GROUP BY mes, tipo", [$anio]) ?: [];
$real_ing = []; $real_gas = [];
foreach ($reales as $r) {
    if ($r['tipo'] === 'ingreso') $real_ing[$r['mes']] = $r['total'];
    else $real_gas[$r['mes']] = $r['total'];
}

$categorias_orden = ['Ingresos', 'Costo de Ventas', 'GAV', 'No Operacionales'];
?>

<style>
.budget-grid { overflow-x:auto; }
.budget-grid table { width:100%; border-collapse:collapse; font-size:.68rem; table-layout:fixed; }
.budget-grid th, .budget-grid td { padding:4px 3px; border-bottom:1px solid var(--border); }
.budget-grid th { font-size:.62rem; text-transform:uppercase; letter-spacing:.3px; color:var(--text-muted); }
.budget-grid .col-cat { width:130px; position:sticky; left:0; background:var(--surface); z-index:2; }
.budget-grid .row-section { background:var(--bg); font-weight:700; font-size:.72rem; }
.budget-grid .row-section td { padding:8px 3px 4px; }
.budget-grid .row-calc { font-weight:700; border-top:2px solid var(--border); }
.budget-grid .row-calc td { padding:6px 3px; }
.budget-grid .tipo-badge { font-size:.55rem; padding:1px 4px; border-radius:3px; display:inline-block; }
.budget-grid .tipo-fijo { background:rgba(56,189,248,.1); color:#38bdf8; }
.budget-grid .tipo-variable { background:rgba(249,115,22,.1); color:var(--accent); }
.budget-grid input.cell-input { width:100%; font-size:.68rem; padding:2px 3px; text-align:right; background:var(--bg); border:1px solid transparent; border-radius:3px; color:var(--text); outline:none; }
.budget-grid input.cell-input:focus { border-color:var(--accent); }
.budget-grid .mes-actual { background:rgba(249,115,22,.04); }
.add-item-btn { font-size:.65rem; color:var(--accent); cursor:pointer; padding:2px 6px; border:1px dashed var(--border); border-radius:4px; display:inline-block; margin:2px 0; }
.add-item-btn:hover { background:rgba(249,115,22,.1); }
@media(max-width:768px) { .budget-grid .col-cat { width:90px; font-size:.6rem; } }
</style>

<!-- Header -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <a href="?page=budget&anio=<?= $anio - 1 ?>" class="btn btn-secondary btn-sm">←</a>
    <span style="font-size:1rem;font-weight:700;">Presupuesto <?= $anio ?></span>
    <a href="?page=budget&anio=<?= $anio + 1 ?>" class="btn btn-secondary btn-sm">→</a>
    <button class="btn btn-primary btn-sm" style="margin-left:auto;" id="btnSave" onclick="saveAll()">Guardar</button>
</div>

<!-- Grid principal -->
<div class="table-container">
<div class="budget-grid">
<table>
    <thead>
        <tr>
            <th class="col-cat">Item</th>
            <th style="width:40px;text-align:center;">Tipo</th>
            <?php foreach ($MN as $num => $label): $es_actual = ("$anio-$num" === $mes_actual); ?>
                <th style="text-align:right;<?= $es_actual ? 'color:var(--accent);' : '' ?>"><?= $label ?></th>
            <?php endforeach; ?>
            <th style="text-align:right;color:var(--accent);">Total</th>
        </tr>
    </thead>
    <tbody id="budgetBody">
    </tbody>
</table>
</div>
</div>

<!-- Leyenda -->
<div style="margin-top:10px;padding:10px 14px;background:var(--surface);border:1px solid var(--border);border-radius:8px;font-size:.68rem;color:var(--text-muted);display:flex;gap:14px;flex-wrap:wrap;">
    <span><span class="tipo-badge tipo-fijo">Fijo</span> Monto se repite cada mes</span>
    <span><span class="tipo-badge tipo-variable">Variable</span> % sobre ingresos del mes</span>
    <span>Edita cualquier celda — los cambios se guardan con el botón Guardar</span>
</div>

<script>
const ANIO = '<?= $anio ?>';
const MES_ACTUAL = '<?= $mes_actual ?>';
const MESES = <?= json_encode($meses) ?>;
const MN = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const CATS = ['Ingresos', 'Costo de Ventas', 'GAV', 'No Operacionales'];
const CAT_SIGNO = { 'Ingresos': 1, 'Costo de Ventas': -1, 'GAV': -1, 'No Operacionales': -1 };

let items = <?= json_encode($items) ?>;
let vals = <?= json_encode($values) ?>;
let realIng = <?= json_encode($real_ing) ?>;
let dirty = []; // [{item_id, mes, valor}]

function getVal(itemId, mes) {
    return (vals[itemId] && vals[itemId][mes]) ? vals[itemId][mes] : null;
}

function getItemVal(item, mesIdx) {
    const mes = MESES[mesIdx];
    const stored = getVal(item.id, mes);
    if (stored !== null) return stored;
    // Default: fijo = valor_default, variable = valor_default (es %)
    return item.valor_default || 0;
}

function calcIngresos(mesIdx) {
    let total = 0;
    items.filter(i => i.categoria === 'Ingresos').forEach(i => { total += getItemVal(i, mesIdx); });
    return total;
}

function calcCatTotal(cat, mesIdx) {
    const ing = calcIngresos(mesIdx);
    let total = 0;
    items.filter(i => i.categoria === cat).forEach(i => {
        if (i.tipo_costo === 'variable') {
            total += Math.round(ing * getItemVal(i, mesIdx) / 100);
        } else {
            total += getItemVal(i, mesIdx);
        }
    });
    return total;
}

function render() {
    const tbody = document.getElementById('budgetBody');
    let html = '';

    CATS.forEach(cat => {
        const catItems = items.filter(i => i.categoria === cat);
        const showTipo = (cat !== 'Ingresos'); // Ingresos no necesita tipo fijo/variable

        // Section header
        html += `<tr class="row-section"><td class="col-cat" colspan="2">${cat}</td>`;
        MESES.forEach(() => html += '<td></td>');
        html += '<td></td></tr>';

        // Items
        catItems.forEach(item => {
            html += `<tr>`;
            html += `<td class="col-cat" style="padding-left:12px;">
                ${escHtml(item.nombre)}
                <button style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:.6rem;padding:0 3px;" onclick="deleteItem(${item.id})" title="Eliminar">✕</button>
            </td>`;
            html += `<td style="text-align:center;">`;
            if (showTipo) {
                html += `<select class="tipo-badge ${item.tipo_costo === 'variable' ? 'tipo-variable' : 'tipo-fijo'}" style="border:none;cursor:pointer;font-size:.55rem;padding:1px 2px;" onchange="changeTipo(${item.id}, this.value)">
                    <option value="fijo" ${item.tipo_costo === 'fijo' ? 'selected' : ''}>Fijo</option>
                    <option value="variable" ${item.tipo_costo === 'variable' ? 'selected' : ''}>%Ing</option>
                </select>`;
            } else {
                html += `<span class="tipo-badge tipo-fijo">$</span>`;
            }
            html += `</td>`;

            let rowTotal = 0;
            MESES.forEach((mes, mi) => {
                const val = getItemVal(item, mi);
                const esActual = (mes === MES_ACTUAL);
                let display = val;

                if (item.tipo_costo === 'variable' && cat !== 'Ingresos') {
                    const calc = Math.round(calcIngresos(mi) * val / 100);
                    rowTotal += calc;
                    html += `<td style="text-align:right;${esActual ? 'background:rgba(249,115,22,.04);' : ''}">
                        <input class="cell-input" value="${val}" style="width:40px;display:inline;color:var(--accent);" onchange="onCellChange(${item.id},'${mes}',this.value)" title="${val}% = ${fmtMoney(calc)}">
                        <div style="font-size:.55rem;color:var(--text-muted);">${fmtMoney(calc)}</div>
                    </td>`;
                } else {
                    rowTotal += val;
                    html += `<td style="text-align:right;${esActual ? 'background:rgba(249,115,22,.04);' : ''}">
                        <input class="cell-input" value="${val}" onchange="onCellChange(${item.id},'${mes}',this.value)">
                    </td>`;
                }
            });
            html += `<td style="text-align:right;font-weight:600;">${fmtMoney(rowTotal)}</td>`;
            html += '</tr>';
        });

        // Add item button
        html += `<tr><td class="col-cat" style="padding-left:12px;" colspan="2">
            <span class="add-item-btn" onclick="addItem('${cat}')">+ Agregar item</span>
        </td>`;
        MESES.forEach(() => html += '<td></td>');
        html += '<td></td></tr>';

        // Calculated rows
        if (cat === 'Costo de Ventas') {
            html += calcRow('Margen Bruto', (mi) => calcIngresos(mi) - calcCatTotal('Costo de Ventas', mi));
        } else if (cat === 'GAV') {
            html += calcRow('EBITDA', (mi) => calcIngresos(mi) - calcCatTotal('Costo de Ventas', mi) - calcCatTotal('GAV', mi));
        } else if (cat === 'No Operacionales') {
            html += calcRow('Utilidad Neta', (mi) => calcIngresos(mi) - calcCatTotal('Costo de Ventas', mi) - calcCatTotal('GAV', mi) - calcCatTotal('No Operacionales', mi), true);
        }
    });

    tbody.innerHTML = html;
}

function calcRow(label, fn, bold) {
    let html = `<tr class="row-calc"><td class="col-cat" colspan="2" style="${bold ? 'font-size:.75rem;' : ''}">${label}</td>`;
    let total = 0;
    MESES.forEach((mes, mi) => {
        const v = fn(mi);
        total += v;
        const c = v >= 0 ? 'var(--success)' : 'var(--danger)';
        html += `<td style="text-align:right;color:${c};">${fmtMoney(v)}</td>`;
    });
    const tc = total >= 0 ? 'var(--success)' : 'var(--danger)';
    html += `<td style="text-align:right;color:${tc};font-weight:700;">${fmtMoney(total)}</td></tr>`;
    return html;
}

function onCellChange(itemId, mes, val) {
    const v = parseInt(val) || 0;
    if (!vals[itemId]) vals[itemId] = {};
    vals[itemId][mes] = v;
    dirty.push({ item_id: itemId, mes, valor: v });
    render(); // Recalcular líneas
}

async function changeTipo(itemId, tipo) {
    await API.post('update_budget_item', { id: itemId, nombre: items.find(i=>i.id==itemId).nombre, tipo_costo: tipo, valor_default: items.find(i=>i.id==itemId).valor_default });
    items.find(i => i.id == itemId).tipo_costo = tipo;
    render();
}

function addItem(cat) {
    const tipos = cat === 'Ingresos'
        ? ''
        : `<div style="margin-top:8px;">
            <label class="form-label">Tipo de costo</label>
            <select name="tipo_costo" class="form-select">
                <option value="fijo">Fijo (monto mensual)</option>
                <option value="variable">Variable (% de ingresos)</option>
            </select>
           </div>
           <div style="margin-top:8px;">
            ${formField('valor_default', 'Valor default (monto o %)', 'number', '')}
           </div>`;

    const body = `<form id="frmNewItem">
        <input type="hidden" name="categoria" value="${cat}">
        ${formField('nombre', 'Nombre del item', 'text', '', {required: true})}
        ${tipos}
    </form>`;
    Modal.open('Agregar item — ' + cat, body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveNewItem()">Agregar</button>`);
}

async function saveNewItem() {
    const data = getFormData('frmNewItem');
    if (!data.nombre) { toast('Ingresa un nombre', 'error'); return; }
    if (!data.tipo_costo) data.tipo_costo = 'fijo';
    const res = await API.post('create_budget_item', data);
    if (res) {
        items.push({ id: res.data.id, categoria: data.categoria, nombre: data.nombre, tipo_costo: data.tipo_costo, valor_default: parseInt(data.valor_default)||0, orden: items.length });
        toast('Item agregado');
        Modal.close();
        render();
    }
}

async function deleteItem(id) {
    if (!confirm('¿Eliminar este item del presupuesto?')) return;
    await API.post('delete_budget_item', { id });
    items = items.filter(i => i.id !== id);
    toast('Item eliminado');
    render();
}

async function saveAll() {
    if (!dirty.length) { toast('Sin cambios'); return; }
    const btn = document.getElementById('btnSave');
    btn.disabled = true; btn.textContent = 'Guardando...';

    const fd = new FormData();
    fd.append('action', 'save_budget_bulk');
    fd.append('csrf_token', APP.csrf);
    fd.append('data', JSON.stringify(dirty));
    try {
        const res = await fetch('api/data.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.ok) { toast(json.data.saved + ' celdas guardadas'); dirty = []; }
        else toast(json.error || 'Error', 'error');
    } catch(e) { toast('Error de conexión', 'error'); }
    btn.disabled = false; btn.textContent = 'Guardar';
}

render();
</script>
