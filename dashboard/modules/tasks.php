<?php
/**
 * Módulo Tareas — Gestión de tareas vinculadas a proyectos y clientes
 * Interconexión: proyectos, clientes, equipo
 */

$filtro_estado = $_GET['estado'] ?? '';
$filtro_asignado = $_GET['asignado'] ?? '';

$where = ['1=1'];
$params = [];
if ($filtro_estado) { $where[] = 't.estado = ?'; $params[] = $filtro_estado; }
if ($filtro_asignado) { $where[] = 't.asignado_a = ?'; $params[] = $filtro_asignado; }

$tareas = query_all('SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre, e.nombre as asignado_nombre
    FROM tareas t
    LEFT JOIN clientes c ON t.cliente_id = c.id
    LEFT JOIN proyectos p ON t.proyecto_id = p.id
    LEFT JOIN equipo e ON t.asignado_a = e.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY CASE t.prioridad WHEN "critica" THEN 1 WHEN "alta" THEN 2 WHEN "media" THEN 3 ELSE 4 END,
    CASE t.estado WHEN "en_progreso" THEN 1 WHEN "pendiente" THEN 2 ELSE 3 END,
    t.fecha_limite ASC NULLS LAST', $params);

$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');
$proyectos_list = query_all('SELECT id, nombre, cliente_id FROM proyectos WHERE estado = "activo" ORDER BY nombre');
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

$total = count($tareas);
$pendientes = count(array_filter($tareas, fn($t) => $t['estado'] === 'pendiente'));
$en_progreso = count(array_filter($tareas, fn($t) => $t['estado'] === 'en_progreso'));
$completadas = count(array_filter($tareas, fn($t) => $t['estado'] === 'completada'));
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total</div>
        <div class="kpi-value"><?= $total ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Pendientes</div>
        <div class="kpi-value warning"><?= $pendientes ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">En Progreso</div>
        <div class="kpi-value" style="color:var(--accent)"><?= $en_progreso ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Completadas</div>
        <div class="kpi-value success"><?= $completadas ?></div>
    </div>
</div>

<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=tasks&estado='+this.value+'&asignado=<?= safe($filtro_asignado) ?>'">
        <option value="">Todos los estados</option>
        <option value="pendiente" <?= $filtro_estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
        <option value="en_progreso" <?= $filtro_estado === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
        <option value="completada" <?= $filtro_estado === 'completada' ? 'selected' : '' ?>>Completada</option>
    </select>
    <select class="form-select" onchange="location.href='?page=tasks&estado=<?= safe($filtro_estado) ?>&asignado='+this.value">
        <option value="">Todos los miembros</option>
        <?php foreach ($equipo_list as $eq): ?>
            <option value="<?= $eq['id'] ?>" <?= $filtro_asignado == $eq['id'] ? 'selected' : '' ?>><?= safe($eq['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Tareas</span>
        <?php if (can_edit($current_user['id'], 'tasks')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewTask()">+ Nueva Tarea</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Tarea</th>
                <th>Cliente</th>
                <th>Proyecto</th>
                <th>Asignado</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Fecha Límite</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tareas as $t):
                $atrasada = in_array($t['estado'], ['pendiente','en_progreso']) && $t['fecha_limite'] && strtotime($t['fecha_limite']) < time();
            ?>
            <tr>
                <td><strong><?= safe($t['titulo']) ?></strong></td>
                <td><?= safe($t['cliente_nombre']) ?></td>
                <td><?= safe($t['proyecto_nombre'] ?? '-') ?></td>
                <td><?= safe($t['asignado_nombre'] ?? 'Sin asignar') ?></td>
                <td><span class="badge <?= status_class($t['prioridad']) ?>"><?= safe(ucfirst($t['prioridad'])) ?></span></td>
                <td><span class="badge <?= status_class($t['estado']) ?>"><?= safe(ucfirst(str_replace('_', ' ', $t['estado']))) ?></span></td>
                <td style="<?= $atrasada ? 'color:var(--danger);font-weight:600' : '' ?>"><?= format_date($t['fecha_limite']) ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'tasks')): ?>
                        <?php if ($t['estado'] === 'pendiente'): ?>
                            <button class="btn btn-secondary btn-sm" onclick="quickUpdateTask(<?= $t['id'] ?>, 'en_progreso')">Iniciar</button>
                        <?php elseif ($t['estado'] === 'en_progreso'): ?>
                            <button class="btn btn-primary btn-sm" onclick="quickUpdateTask(<?= $t['id'] ?>, 'completada')">Completar</button>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" onclick="editTask(<?= $t['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tareas)): ?>
            <tr><td colspan="8" class="empty-state">No hay tareas</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const tClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const tProyectosList = <?= json_encode(array_map(fn($p) => ['nombre' => $p['nombre'], 'cliente_id' => $p['cliente_id']], $proyectos_list)) ?>;
const tProyectosMap = <?= json_encode(array_column($proyectos_list, 'nombre', 'id')) ?>;
const tEquipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;

function openNewTask() {
    const body = `<form id="frmTask" class="form-grid">
        ${formField('titulo', 'Título', 'text', '', {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: tClientesList})}
        ${formField('proyecto_id', 'Proyecto', 'select', '', {options: tProyectosMap})}
        ${formField('asignado_a', 'Asignado a', 'select', '', {options: tEquipoList})}
        ${formField('prioridad', 'Prioridad', 'select', 'media', {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_limite', 'Fecha Límite', 'date')}
        ${formField('descripcion', 'Descripción', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nueva Tarea', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveTask()">Guardar</button>`);
}

async function saveTask() {
    const res = await API.post('create_task', getFormData('frmTask'));
    if (res) { toast('Tarea creada'); refreshPage(); }
}

async function quickUpdateTask(id, estado) {
    const res = await API.post('update_task', { id, estado });
    if (res) { toast('Tarea actualizada'); refreshPage(); }
}

async function editTask(id) {
    const res = await API.get('get_task', { id });
    if (!res) return;
    const t = res.data;
    const body = `<form id="frmTask" class="form-grid">
        <input type="hidden" name="id" value="${t.id}">
        ${formField('titulo', 'Título', 'text', t.titulo, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', t.cliente_id, {options: tClientesList})}
        ${formField('proyecto_id', 'Proyecto', 'select', t.proyecto_id || '', {options: tProyectosMap})}
        ${formField('asignado_a', 'Asignado a', 'select', t.asignado_a || '', {options: tEquipoList})}
        ${formField('estado', 'Estado', 'select', t.estado, {options: {pendiente:'Pendiente', en_progreso:'En Progreso', completada:'Completada', cancelada:'Cancelada'}})}
        ${formField('prioridad', 'Prioridad', 'select', t.prioridad, {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_limite', 'Fecha Límite', 'date', t.fecha_limite || '')}
        ${formField('descripcion', 'Descripción', 'textarea', t.descripcion, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Tarea', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateTask()">Actualizar</button>`);
}

async function updateTask() {
    const res = await API.post('update_task', getFormData('frmTask'));
    if (res) { toast('Tarea actualizada'); refreshPage(); }
}
</script>
