<?php
/**
 * Módulo Proyectos — Gestión de proyectos vinculados a clientes
 * Interconexión: clientes, tareas, equipo
 */

$filtro_estado = $_GET['estado'] ?? '';
$filtro_cliente = $_GET['cliente'] ?? '';

$where = ['1=1'];
$params = [];
if ($filtro_estado) { $where[] = 'p.estado = ?'; $params[] = $filtro_estado; }
if ($filtro_cliente) { $where[] = 'p.cliente_id = ?'; $params[] = $filtro_cliente; }

$proyectos = query_all('SELECT p.*, c.nombre as cliente_nombre, e.nombre as responsable_nombre,
    (SELECT COUNT(*) FROM tareas WHERE proyecto_id = p.id) as total_tareas,
    (SELECT COUNT(*) FROM tareas WHERE proyecto_id = p.id AND estado = "completada") as tareas_completadas
    FROM proyectos p
    LEFT JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN equipo e ON p.responsable_id = e.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY CASE p.prioridad WHEN "critica" THEN 1 WHEN "alta" THEN 2 WHEN "media" THEN 3 ELSE 4 END, p.updated_at DESC', $params);

$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

$total = count($proyectos);
$activos = count(array_filter($proyectos, fn($p) => $p['estado'] === 'activo'));
$atrasados = count(array_filter($proyectos, fn($p) => $p['estado'] === 'activo' && $p['fecha_limite'] && strtotime($p['fecha_limite']) < time()));
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Proyectos</div>
        <div class="kpi-value"><?= $total ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Activos</div>
        <div class="kpi-value success"><?= $activos ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Atrasados</div>
        <div class="kpi-value <?= $atrasados > 0 ? 'danger' : '' ?>"><?= $atrasados ?></div>
    </div>
</div>

<!-- Filtros -->
<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=projects&estado='+this.value+'&cliente=<?= safe($filtro_cliente) ?>'">
        <option value="">Todos los estados</option>
        <option value="activo" <?= $filtro_estado === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="pausado" <?= $filtro_estado === 'pausado' ? 'selected' : '' ?>>Pausado</option>
        <option value="completado" <?= $filtro_estado === 'completado' ? 'selected' : '' ?>>Completado</option>
        <option value="cancelado" <?= $filtro_estado === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
    </select>
    <select class="form-select" onchange="location.href='?page=projects&estado=<?= safe($filtro_estado) ?>&cliente='+this.value">
        <option value="">Todos los clientes</option>
        <?php foreach ($clientes_list as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= $filtro_cliente == $cl['id'] ? 'selected' : '' ?>><?= safe($cl['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Proyectos</span>
        <?php if (can_edit($current_user['id'], 'projects')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewProject()">+ Nuevo Proyecto</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Proyecto</th>
                <th>Cliente</th>
                <th>Responsable</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Progreso</th>
                <th>Fecha Límite</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($proyectos as $p):
                $progreso = $p['total_tareas'] > 0 ? round($p['tareas_completadas'] / $p['total_tareas'] * 100) : 0;
                $atrasado = $p['estado'] === 'activo' && $p['fecha_limite'] && strtotime($p['fecha_limite']) < time();
            ?>
            <tr>
                <td><strong><?= safe($p['nombre']) ?></strong></td>
                <td><?= safe($p['cliente_nombre']) ?></td>
                <td><?= safe($p['responsable_nombre'] ?? 'Sin asignar') ?></td>
                <td><span class="badge <?= status_class($p['prioridad']) ?>"><?= safe(ucfirst($p['prioridad'])) ?></span></td>
                <td><span class="badge <?= status_class($p['estado']) ?>"><?= safe(ucfirst($p['estado'])) ?></span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div class="progress-bar" style="width:80px;">
                            <div class="progress-fill <?= $progreso >= 100 ? 'success' : ($atrasado ? 'danger' : '') ?>" style="width:<?= $progreso ?>%"></div>
                        </div>
                        <span style="font-size:.75rem;"><?= $progreso ?>%</span>
                    </div>
                </td>
                <td style="<?= $atrasado ? 'color:var(--danger)' : '' ?>"><?= format_date($p['fecha_limite']) ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'projects')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="editProject(<?= $p['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($proyectos)): ?>
            <tr><td colspan="8" class="empty-state">No hay proyectos</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const clientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const equipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;

function openNewProject() {
    const body = `<form id="frmProject" class="form-grid">
        ${formField('nombre', 'Nombre del Proyecto', 'text', '', {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: clientesList})}
        ${formField('responsable_id', 'Responsable', 'select', '', {options: equipoList})}
        ${formField('prioridad', 'Prioridad', 'select', 'media', {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_inicio', 'Fecha Inicio', 'date', new Date().toISOString().split('T')[0])}
        ${formField('fecha_limite', 'Fecha Límite', 'date')}
        ${formField('descripcion', 'Descripción', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nuevo Proyecto', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveProject()">Guardar</button>`);
}

async function saveProject() {
    const res = await API.post('create_project', getFormData('frmProject'));
    if (res) { toast('Proyecto creado'); refreshPage(); }
}

async function editProject(id) {
    const res = await API.get('get_project', { id });
    if (!res) return;
    const p = res.data;
    const estados = {activo:'Activo', pausado:'Pausado', completado:'Completado', cancelado:'Cancelado'};
    const body = `<form id="frmProject" class="form-grid">
        <input type="hidden" name="id" value="${p.id}">
        ${formField('nombre', 'Nombre', 'text', p.nombre, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', p.cliente_id, {options: clientesList})}
        ${formField('responsable_id', 'Responsable', 'select', p.responsable_id || '', {options: equipoList})}
        ${formField('estado', 'Estado', 'select', p.estado, {options: estados})}
        ${formField('prioridad', 'Prioridad', 'select', p.prioridad, {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_limite', 'Fecha Límite', 'date', p.fecha_limite || '')}
        ${formField('descripcion', 'Descripción', 'textarea', p.descripcion, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Proyecto', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateProject()">Actualizar</button>`);
}

async function updateProject() {
    const res = await API.post('update_project', getFormData('frmProject'));
    if (res) { toast('Proyecto actualizado'); refreshPage(); }
}
</script>
