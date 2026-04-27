<?php
/**
 * Módulo Stack de Clientes — Checklist de herramientas habilitadas por cliente
 * Usuarios normales ven solo sus clientes asignados, admin ve todos
 */

$mi_equipo_id = $current_user['id'];

if ($current_user['role'] === 'admin') {
    $clientes = query_all("SELECT id, nombre FROM clientes WHERE tipo = 'activo' ORDER BY nombre") ?: [];
} else {
    $clientes = query_all("SELECT id, nombre FROM clientes WHERE tipo = 'activo' AND responsable_id = ? ORDER BY nombre", [$mi_equipo_id]) ?: [];
}

$stack_items = query_all("SELECT * FROM stack_items WHERE activo = 1 ORDER BY orden") ?: [];
$categorias_stack = [];
foreach ($stack_items as $si) {
    $categorias_stack[$si['categoria']][] = $si;
}
$cat_order = array_keys($categorias_stack);
$total_items = count($stack_items);

// Cargar estado de todos los clientes visibles
$cliente_ids = array_column($clientes, 'id');
$stack_data = []; // [cliente_id][item_id] = {completado, notas}
if ($cliente_ids) {
    $placeholders = implode(',', array_fill(0, count($cliente_ids), '?'));
    $rows = query_all("SELECT cliente_id, item_id, completado, notas FROM stack_cliente WHERE cliente_id IN ($placeholders)", $cliente_ids) ?: [];
    foreach ($rows as $r) {
        $stack_data[$r['cliente_id']][$r['item_id']] = $r;
    }
}

// Calcular % por cliente
$avances = [];
foreach ($clientes as $cl) {
    $done = 0;
    foreach ($stack_items as $si) {
        if (!empty($stack_data[$cl['id']][$si['id']]['completado'])) $done++;
    }
    $avances[$cl['id']] = $total_items > 0 ? round($done / $total_items * 100) : 0;
}

$can_edit_stack = can_edit($current_user['id'], 'stack') || can_edit($current_user['id'], 'crm') || $current_user['role'] === 'admin';
$cliente_sel = $_GET['cliente'] ?? '';
?>

<style>
.stack-card { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:12px; cursor:pointer; transition:border-color .2s; }
.stack-card:hover { border-color:var(--accent); }
.stack-bar { height:8px; background:var(--border); border-radius:4px; overflow:hidden; margin-top:8px; }
.stack-bar-fill { height:100%; border-radius:4px; transition:width .3s; }
.stack-cat { font-weight:700; font-size:.82rem; padding:10px 0 6px; border-bottom:1px solid var(--border); margin-bottom:8px; color:var(--accent); }
.stack-item { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,.03); }
.stack-item:last-child { border-bottom:none; }
.stack-check { width:20px; height:20px; border-radius:5px; border:2px solid var(--border); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; flex-shrink:0; }
.stack-check.done { background:var(--success); border-color:var(--success); color:#fff; }
.stack-check:hover { border-color:var(--accent); }
.stack-name { font-size:.85rem; flex:1; }
.stack-name.done { color:var(--text-muted); text-decoration:line-through; }
.stack-nota { font-size:.7rem; color:var(--text-muted); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:pointer; }
.stack-nota:hover { color:var(--accent); }
</style>

<?php if (!$cliente_sel): ?>
<!-- Vista resumen: todos los clientes -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="kpi-card">
        <div class="kpi-label">Clientes</div>
        <div class="kpi-value"><?= count($clientes) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Items del Stack</div>
        <div class="kpi-value"><?= $total_items ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Promedio Avance</div>
        <div class="kpi-value"><?= count($avances) > 0 ? round(array_sum($avances) / count($avances)) : 0 ?>%</div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Stack por Cliente</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:16px;">
        <?php foreach ($clientes as $cl):
            $pct = $avances[$cl['id']];
            $done_count = 0;
            foreach ($stack_items as $si) {
                if (!empty($stack_data[$cl['id']][$si['id']]['completado'])) $done_count++;
            }
            $bar_color = $pct >= 80 ? 'var(--success)' : ($pct >= 40 ? 'var(--accent)' : 'var(--warning)');

            // Iniciales
            $iniciales = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(explode(' ', $cl['nombre']), 0, 2)));
            $hue = abs(crc32($cl['nombre'])) % 360;
        ?>
        <a href="?page=stack&cliente=<?= $cl['id'] ?>" class="stack-card" style="text-decoration:none;color:inherit;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <span style="width:32px;height:32px;border-radius:8px;background:hsl(<?= $hue ?>,50%,65%);color:#fff;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;"><?= $iniciales ?></span>
                <div>
                    <div style="font-weight:700;font-size:.9rem;"><?= safe($cl['nombre']) ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted);"><?= $done_count ?> / <?= $total_items ?> completados</div>
                </div>
                <div style="margin-left:auto;font-weight:700;font-size:1.1rem;color:<?= $bar_color ?>;"><?= $pct ?>%</div>
            </div>
            <div class="stack-bar">
                <div class="stack-bar-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>;"></div>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($clientes)): ?>
            <div style="color:var(--text-muted);padding:20px;text-align:center;">Sin clientes asignados.</div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Vista detalle: checklist de un cliente -->
<?php
$cliente = query_one("SELECT * FROM clientes WHERE id = ?", [(int)$cliente_sel]);
if (!$cliente) { echo '<div class="empty-state">Cliente no encontrado.</div>'; return; }
$cl_id = $cliente['id'];
$pct = $avances[$cl_id] ?? 0;
$done_count = 0;
foreach ($stack_items as $si) {
    if (!empty($stack_data[$cl_id][$si['id']]['completado'])) $done_count++;
}
$bar_color = $pct >= 80 ? 'var(--success)' : ($pct >= 40 ? 'var(--accent)' : 'var(--warning)');
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
    <a href="?page=stack" class="btn btn-secondary btn-sm">&larr; Volver</a>
    <h2 style="margin:0;font-size:1.1rem;"><?= safe($cliente['nombre']) ?></h2>
    <span style="font-weight:700;font-size:1.1rem;color:<?= $bar_color ?>;margin-left:auto;" id="stackPct"><?= $pct ?>%</span>
</div>

<div class="stack-bar" style="height:12px;margin-bottom:24px;">
    <div class="stack-bar-fill" id="stackBar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>;"></div>
</div>
<div style="font-size:.78rem;color:var(--text-muted);margin-top:-18px;margin-bottom:20px;" id="stackCount"><?= $done_count ?> de <?= $total_items ?> completados</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Stack Digital</span>
    </div>
    <div style="padding:16px;">
        <?php foreach ($categorias_stack as $cat => $items): ?>
            <div class="stack-cat"><?= safe($cat) ?></div>
            <?php foreach ($items as $si):
                $sc = $stack_data[$cl_id][$si['id']] ?? null;
                $is_done = !empty($sc['completado']);
                $nota = $sc['notas'] ?? '';
            ?>
            <div class="stack-item" data-item-id="<?= $si['id'] ?>">
                <div class="stack-check <?= $is_done ? 'done' : '' ?>"
                    <?php if ($can_edit_stack): ?>
                    onclick="toggleItem(<?= $cl_id ?>, <?= $si['id'] ?>, <?= $is_done ? 0 : 1 ?>, this)"
                    <?php endif; ?>
                >
                    <?= $is_done ? '&#10003;' : '' ?>
                </div>
                <span class="stack-name <?= $is_done ? 'done' : '' ?>"><?= safe($si['nombre']) ?></span>
                <?php if ($can_edit_stack): ?>
                <span class="stack-nota" onclick="editNota(<?= $cl_id ?>, <?= $si['id'] ?>, this)" title="<?= safe($nota) ?>">
                    <?= $nota ? safe($nota) : '+ nota' ?>
                </span>
                <?php elseif ($nota): ?>
                <span class="stack-nota"><?= safe($nota) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<script>
const totalItems = <?= $total_items ?>;

async function toggleItem(clienteId, itemId, completado, el) {
    const res = await API.post('toggle_stack_item', { cliente_id: clienteId, item_id: itemId, completado });
    if (res && res.ok) {
        if (completado) {
            el.classList.add('done');
            el.innerHTML = '&#10003;';
            el.nextElementSibling.classList.add('done');
        } else {
            el.classList.remove('done');
            el.innerHTML = '';
            el.nextElementSibling.classList.remove('done');
        }
        el.setAttribute('onclick', `toggleItem(${clienteId}, ${itemId}, ${completado ? 0 : 1}, this)`);
        updateProgress();
    }
}

function updateProgress() {
    const done = document.querySelectorAll('.stack-check.done').length;
    const pct = totalItems > 0 ? Math.round(done / totalItems * 100) : 0;
    const color = pct >= 80 ? 'var(--success)' : (pct >= 40 ? 'var(--accent)' : 'var(--warning)');
    document.getElementById('stackPct').textContent = pct + '%';
    document.getElementById('stackPct').style.color = color;
    document.getElementById('stackBar').style.width = pct + '%';
    document.getElementById('stackBar').style.background = color;
    document.getElementById('stackCount').textContent = done + ' de ' + totalItems + ' completados';
}

async function editNota(clienteId, itemId, el) {
    const current = el.textContent.trim();
    const nota = prompt('Nota para este item:', current === '+ nota' ? '' : current);
    if (nota === null) return;
    const res = await API.post('save_stack_nota', { cliente_id: clienteId, item_id: itemId, notas: nota });
    if (res && res.ok) {
        el.textContent = nota || '+ nota';
        el.title = nota;
    }
}
</script>
<?php endif; ?>
