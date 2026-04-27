<?php
/**
 * Módulo Categorías EERR — Administrar categorías del Estado de Resultados
 * 4 niveles: Sección > Categoría > Subcategoría > Detalle (libre en movimientos)
 */

$categorias = query_all("SELECT ce.*,
    (SELECT COUNT(*) FROM finanzas f WHERE f.seccion = ce.seccion AND f.categoria = ce.categoria AND f.subcategoria = ce.subcategoria) as movimientos
    FROM categorias_eerr ce ORDER BY ce.orden, ce.seccion, ce.categoria, ce.subcategoria") ?: [];

$secciones_orden = ['Ingresos', 'Costo de Ventas', 'GAV', 'No Operacionales'];
$sec_labels = ['Ingresos' => 'Ingresos', 'Costo de Ventas' => 'Costo de Ventas', 'GAV' => 'Gastos Adm. y Ventas', 'No Operacionales' => 'No Operacionales'];

// Agrupar por sección > categoría
$tree = [];
foreach ($categorias as $c) {
    $tree[$c['seccion']][$c['categoria']][] = $c;
}
?>

<style>
.cat-table { width:100%; font-size:.8rem; }
.cat-table th { font-size:.7rem; text-transform:uppercase; letter-spacing:.3px; color:var(--text-muted); padding:6px 10px; }
.cat-table td { padding:5px 10px; border-bottom:1px solid var(--border); }
.cat-section { background:var(--bg); font-weight:700; font-size:.85rem; }
.cat-section td { padding:10px 14px; }
.cat-group td { font-weight:600; font-size:.82rem; background:rgba(249,115,22,.03); }
.cat-row:hover { background:rgba(249,115,22,.03); }
.cat-actions { display:flex; gap:4px; }
.cat-badge { font-size:.65rem; padding:2px 6px; border-radius:4px; }
.cat-badge-used { background:rgba(56,189,248,.1); color:#38bdf8; }
.cat-badge-empty { background:var(--bg); color:var(--text-muted); }
</style>

<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Categorías EERR</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" onclick="openNewCat()">+ Nueva categoría</button>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table class="cat-table">
            <thead><tr>
                <th style="width:180px">Sección</th>
                <th style="width:180px">Categoría</th>
                <th>Subcategoría</th>
                <th style="width:70px">Tipo</th>
                <th style="width:60px">Orden</th>
                <th style="width:80px">Movs.</th>
                <th style="width:120px">Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($secciones_orden as $sec): ?>
                <tr class="cat-section">
                    <td colspan="7"><?= htmlspecialchars($sec_labels[$sec] ?? $sec) ?></td>
                </tr>
                <?php if (isset($tree[$sec])): ?>
                    <?php foreach ($tree[$sec] as $cat => $items): ?>
                        <tr class="cat-group">
                            <td></td>
                            <td colspan="6"><?= htmlspecialchars($cat) ?></td>
                        </tr>
                        <?php foreach ($items as $item): ?>
                            <tr class="cat-row" id="cat-row-<?= $item['id'] ?>">
                                <td></td>
                                <td></td>
                                <td><?= htmlspecialchars($item['subcategoria']) ?></td>
                                <td><span class="badge <?= $item['tipo'] === 'ingreso' ? 'badge-success' : 'badge-danger' ?>" style="font-size:.65rem;"><?= $item['tipo'] ?></span></td>
                                <td style="color:var(--text-muted);font-size:.75rem;"><?= $item['orden'] ?></td>
                                <td>
                                    <?php if ($item['movimientos'] > 0): ?>
                                        <span class="cat-badge cat-badge-used"><?= $item['movimientos'] ?> mov.</span>
                                    <?php else: ?>
                                        <span class="cat-badge cat-badge-empty">sin uso</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="cat-actions">
                                        <button class="btn btn-secondary btn-sm" style="font-size:.65rem;padding:2px 8px;" onclick="editCat(<?= htmlspecialchars(json_encode($item)) ?>)">Editar</button>
                                        <?php if ($item['movimientos'] == 0): ?>
                                            <button class="btn btn-secondary btn-sm" style="font-size:.65rem;padding:2px 8px;color:var(--danger);" onclick="deleteCat(<?= $item['id'] ?>, '<?= htmlspecialchars($item['subcategoria']) ?>')">Eliminar</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal crear/editar -->
<div id="catModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:12px;padding:24px;width:100%;max-width:480px;margin:20px;">
        <h3 id="catModalTitle" style="margin-bottom:16px;">Nueva categoría</h3>
        <input type="hidden" id="catId">
        <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
                <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Sección (N1)</label>
                <select class="form-select" id="catSeccion" style="width:100%;">
                    <option value="">Seleccionar...</option>
                    <option value="Ingresos">Ingresos</option>
                    <option value="Costo de Ventas">Costo de Ventas</option>
                    <option value="GAV">GAV</option>
                    <option value="No Operacionales">No Operacionales</option>
                </select>
            </div>
            <div>
                <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Categoría (N2)</label>
                <input type="text" class="form-select" id="catCategoria" style="width:100%;" placeholder="Ej: Suscripciones, Personal, Infraestructura...">
            </div>
            <div>
                <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Subcategoría (N3)</label>
                <input type="text" class="form-select" id="catSubcategoria" style="width:100%;" placeholder="Ej: Plan Google Ads, Sueldos, Hosting...">
            </div>
            <div style="display:flex;gap:12px;">
                <div style="flex:1;">
                    <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Tipo</label>
                    <select class="form-select" id="catTipo" style="width:100%;">
                        <option value="ingreso">Ingreso</option>
                        <option value="gasto">Gasto</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Orden</label>
                    <input type="number" class="form-select" id="catOrden" style="width:100%;" value="999">
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:20px;justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeCatModal()">Cancelar</button>
            <button class="btn btn-primary" id="catSaveBtn" onclick="saveCat()">Guardar</button>
        </div>
    </div>
</div>

<script>
function openNewCat() {
    document.getElementById('catModalTitle').textContent = 'Nueva categoría';
    document.getElementById('catId').value = '';
    document.getElementById('catSeccion').value = '';
    document.getElementById('catCategoria').value = '';
    document.getElementById('catSubcategoria').value = '';
    document.getElementById('catTipo').value = 'gasto';
    document.getElementById('catOrden').value = '999';
    document.getElementById('catModal').style.display = 'flex';
}

function editCat(cat) {
    document.getElementById('catModalTitle').textContent = 'Editar categoría';
    document.getElementById('catId').value = cat.id;
    document.getElementById('catSeccion').value = cat.seccion;
    document.getElementById('catCategoria').value = cat.categoria;
    document.getElementById('catSubcategoria').value = cat.subcategoria;
    document.getElementById('catTipo').value = cat.tipo;
    document.getElementById('catOrden').value = cat.orden;
    document.getElementById('catModal').style.display = 'flex';
}

function closeCatModal() {
    document.getElementById('catModal').style.display = 'none';
}

async function saveCat() {
    const id = document.getElementById('catId').value;
    const data = {
        seccion: document.getElementById('catSeccion').value,
        categoria: document.getElementById('catCategoria').value,
        subcategoria: document.getElementById('catSubcategoria').value,
        tipo: document.getElementById('catTipo').value,
        orden: document.getElementById('catOrden').value,
    };
    if (!data.seccion || !data.categoria || !data.subcategoria) {
        toast('Completa sección, categoría y subcategoría', 'error');
        return;
    }
    const action = id ? 'update_categoria_eerr' : 'create_categoria_eerr';
    if (id) data.id = id;
    const res = await API.post(action, data);
    if (res && res.ok) {
        toast(id ? 'Categoría actualizada' : 'Categoría creada');
        closeCatModal();
        location.reload();
    }
}

async function deleteCat(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '"?')) return;
    const res = await API.post('delete_categoria_eerr', { id });
    if (res && res.ok) {
        toast('Categoría eliminada');
        location.reload();
    }
}

// Cerrar modal con click fuera
document.getElementById('catModal').addEventListener('click', function(e) {
    if (e.target === this) closeCatModal();
});
</script>
