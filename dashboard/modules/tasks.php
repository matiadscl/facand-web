<?php
/**
 * Módulo Tareas — Vista Kanban agrupada por estado
 * Columnas: Pendientes | En Progreso | Completadas (últimas 20)
 */

$filtro_estado   = $_GET['estado'] ?? '';
$filtro_asignado = $_GET['asignado'] ?? '';

$where  = ['1=1'];
$params = [];
if ($filtro_estado)   { $where[] = 't.estado = ?';    $params[] = $filtro_estado; }
if ($filtro_asignado) { $where[] = 't.asignado_a = ?'; $params[] = $filtro_asignado; }

// Traemos todo (sin límite) para calcular KPIs correctos; limitamos completadas al renderizar
$tareas = query_all('SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre, e.nombre as asignado_nombre
    FROM tareas t
    LEFT JOIN clientes c ON t.cliente_id = c.id
    LEFT JOIN proyectos p ON t.proyecto_id = p.id
    LEFT JOIN equipo e ON t.asignado_a = e.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY CASE t.prioridad WHEN "critica" THEN 1 WHEN "alta" THEN 2 WHEN "media" THEN 3 ELSE 4 END,
    t.fecha_limite ASC NULLS LAST', $params);

$clientes_list  = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');
$proyectos_list = query_all('SELECT id, nombre, cliente_id FROM proyectos WHERE estado = "activo" ORDER BY nombre');
$equipo_list    = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

// Separar por estado
$col_pendiente  = array_values(array_filter($tareas, fn($t) => $t['estado'] === 'pendiente'));
$col_progreso   = array_values(array_filter($tareas, fn($t) => $t['estado'] === 'en_progreso'));
$col_completada = array_slice(
    array_values(array_filter($tareas, fn($t) => $t['estado'] === 'completada')),
    0, 20
);

// KPIs sobre todo el conjunto sin filtro de estado del panel (usar counts directos)
$total_all      = query_scalar('SELECT COUNT(*) FROM tareas') ?? 0;
$pendientes_all = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado = "pendiente"') ?? 0;
$progreso_all   = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado = "en_progreso"') ?? 0;
$completad_all  = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado = "completada"') ?? 0;

$can_edit = can_edit($current_user['id'], 'tasks');

/**
 * Renderiza una tarjeta de tarea
 */
function render_card(array $t, bool $can_edit): string {
    $atrasada = in_array($t['estado'], ['pendiente','en_progreso'])
        && $t['fecha_limite']
        && strtotime($t['fecha_limite']) < time();

    $prioridad_badge = match($t['prioridad']) {
        'critica' => 'status-danger',
        'alta'    => 'status-warning',
        'baja'    => 'status-muted',
        default   => 'status-info',
    };

    $fecha_html = '';
    if ($t['fecha_limite']) {
        $fecha_str  = format_date($t['fecha_limite']);
        $fecha_html = '<div style="font-size:.75rem;margin-top:4px;' . ($atrasada ? 'color:var(--danger);font-weight:700' : 'color:var(--text-muted)') . '">
            ' . ($atrasada ? '&#9888; ' : '') . $fecha_str . '
        </div>';
    }

    $btns = '';
    if ($can_edit) {
        if ($t['estado'] === 'pendiente') {
            $btns .= '<button class="btn btn-secondary btn-sm" onclick="quickUpdateTask(' . $t['id'] . ',\'en_progreso\')">Iniciar</button> ';
        }
        if ($t['estado'] === 'en_progreso') {
            $btns .= '<button class="btn btn-primary btn-sm" onclick="quickUpdateTask(' . $t['id'] . ',\'completada\')">Completar</button> ';
        }
        if (in_array($t['estado'], ['en_progreso','completada'])) {
            $btns .= '<button class="btn btn-secondary btn-sm" onclick="revertTask(' . $t['id'] . ',\'' . $t['estado'] . '\')">Revertir</button> ';
        }
        $btns .= '<button class="btn btn-secondary btn-sm" onclick="editTask(' . $t['id'] . ')">Editar</button>';
    }

    return '
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="font-weight:700;font-size:.9rem;margin-bottom:6px;">' . safe($t['titulo']) . '</div>
        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:4px;">' . safe($t['cliente_nombre'] ?? '') . '</div>
        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:6px;">Asignado: ' . safe($t['asignado_nombre'] ?? 'Sin asignar') . '</div>
        <div style="margin-bottom:6px;"><span class="badge ' . $prioridad_badge . '">' . ucfirst($t['prioridad']) . '</span></div>
        ' . $fecha_html . '
        <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:4px;">' . $btns . '</div>
    </div>';
}
?>

<div class="kpi-grid">
    <div class="kpi-card"><div class="kpi-label">Total Tareas</div><div class="kpi-value"><?= $total_all ?></div></div>
    <div class="kpi-card"><div class="kpi-label">Pendientes</div><div class="kpi-value warning"><?= $pendientes_all ?></div></div>
    <div class="kpi-card"><div class="kpi-label">En Progreso</div><div class="kpi-value" style="color:var(--accent)"><?= $progreso_all ?></div></div>
    <div class="kpi-card"><div class="kpi-label">Completadas</div><div class="kpi-value success"><?= $completad_all ?></div></div>
</div>

<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=tasks&estado='+this.value+'&asignado=<?= safe($filtro_asignado) ?>'">
        <option value="">Todos los estados</option>
        <option value="pendiente"  <?= $filtro_estado === 'pendiente'  ? 'selected' : '' ?>>Pendiente</option>
        <option value="en_progreso"<?= $filtro_estado === 'en_progreso'? 'selected' : '' ?>>En Progreso</option>
        <option value="completada" <?= $filtro_estado === 'completada' ? 'selected' : '' ?>>Completada</option>
    </select>
    <select class="form-select" onchange="location.href='?page=tasks&estado=<?= safe($filtro_estado) ?>&asignado='+this.value">
        <option value="">Todos los miembros</option>
        <?php foreach ($equipo_list as $eq): ?>
            <option value="<?= $eq['id'] ?>" <?= $filtro_asignado == $eq['id'] ? 'selected' : '' ?>><?= safe($eq['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($can_edit): ?>
        <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="openNewTask()">+ Nueva Tarea</button>
    <?php endif; ?>
</div>

<!-- Kanban: 3 columnas -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:start;">

    <!-- Pendientes -->
    <div>
        <div style="background:var(--warning-soft,#fff3cd);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;">Pendientes</span>
            <span style="background:var(--warning);color:#fff;border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= count($col_pendiente) ?></span>
        </div>
        <?php if (empty($col_pendiente)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:20px 0;">Sin tareas pendientes</div>
        <?php endif; ?>
        <?php foreach ($col_pendiente as $t): ?>
            <?= render_card($t, $can_edit) ?>
        <?php endforeach; ?>
    </div>

    <!-- En Progreso -->
    <div>
        <div style="background:var(--accent-soft,#e0f0ff);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;">En Progreso</span>
            <span style="background:var(--accent);color:#fff;border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= count($col_progreso) ?></span>
        </div>
        <?php if (empty($col_progreso)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:20px 0;">Sin tareas en progreso</div>
        <?php endif; ?>
        <?php foreach ($col_progreso as $t): ?>
            <?= render_card($t, $can_edit) ?>
        <?php endforeach; ?>
    </div>

    <!-- Completadas (últimas 20) -->
    <div>
        <div style="background:var(--success-soft,#d4edda);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;">Completadas</span>
            <span style="background:var(--success);color:#fff;border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= $completad_all ?><?= $completad_all > 20 ? ' (20 recientes)' : '' ?></span>
        </div>
        <?php if (empty($col_completada)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:20px 0;">Sin tareas completadas</div>
        <?php endif; ?>
        <?php foreach ($col_completada as $t): ?>
            <?= render_card($t, $can_edit) ?>
        <?php endforeach; ?>
    </div>

</div>

<script>
const tClientesList  = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const tProyectosList = <?= json_encode(array_map(fn($p) => ['nombre' => $p['nombre'], 'cliente_id' => $p['cliente_id']], $proyectos_list)) ?>;
const tProyectosMap  = <?= json_encode(array_column($proyectos_list, 'nombre', 'id')) ?>;
const tEquipoList    = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;

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

async function revertTask(id, currentEstado) {
    const prev = currentEstado === 'completada' ? 'en_progreso' : 'pendiente';
    const res = await API.post('update_task', {id, estado: prev});
    if (res) { toast('Tarea revertida a ' + prev.replace('_',' ')); refreshPage(); }
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
