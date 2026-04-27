<?php
/**
 * Módulo Tareas — Vista Kanban agrupada por estado
 * Columnas: Pendientes | En Progreso | Completadas (últimas 20)
 * Sección colapsable: Eliminadas
 */

$filtro_estado   = $_GET['estado'] ?? '';
$filtro_asignado = $_GET['asignado'] ?? '';
$filtro_cliente  = $_GET['cliente'] ?? '';

// Vincular usuario actual con su equipo_id (mismos IDs)
$mi_equipo_id = $current_user['id'];

$where  = ["t.estado != 'eliminada'"];
$params = [];

// Usuarios no-admin solo ven tareas de clientes de su cartera o asignadas a ellos
if ($current_user['role'] !== 'admin') {
    $where[] = '(c.responsable_id = ? OR t.asignado_a = ?)';
    $params[] = $mi_equipo_id;
    $params[] = $mi_equipo_id;
}

if ($filtro_estado)   { $where[] = 't.estado = ?';     $params[] = $filtro_estado; }
if ($filtro_asignado) { $where[] = 't.asignado_a = ?';  $params[] = $filtro_asignado; }
if ($filtro_cliente)  { $where[] = 't.cliente_id = ?';   $params[] = $filtro_cliente; }

$base_query = 'FROM tareas t
    LEFT JOIN clientes c ON t.cliente_id = c.id
    LEFT JOIN proyectos p ON t.proyecto_id = p.id
    LEFT JOIN equipo e ON t.asignado_a = e.id';

// Tareas activas (no eliminadas)
$tareas = query_all("SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre, e.nombre as asignado_nombre
    $base_query
    WHERE " . implode(' AND ', $where) . '
    ORDER BY CASE t.prioridad WHEN "critica" THEN 1 WHEN "alta" THEN 2 WHEN "media" THEN 3 ELSE 4 END,
    t.fecha_limite ASC NULLS LAST', $params);

// Tareas eliminadas (misma visibilidad por cartera)
$elim_where = ["t.estado = 'eliminada'"];
$elim_params = [];
if ($current_user['role'] !== 'admin') {
    $elim_where[] = '(c.responsable_id = ? OR t.asignado_a = ?)';
    $elim_params[] = $mi_equipo_id;
    $elim_params[] = $mi_equipo_id;
}
$col_eliminada = query_all("SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre, e.nombre as asignado_nombre
    $base_query
    WHERE " . implode(' AND ', $elim_where) . '
    ORDER BY t.updated_at DESC', $elim_params);

if ($current_user['role'] !== 'admin') {
    $clientes_list = query_all('SELECT id, nombre FROM clientes WHERE responsable_id = ? ORDER BY nombre', [$mi_equipo_id]);
    $proyectos_list = query_all('SELECT p.id, p.nombre, p.cliente_id FROM proyectos p JOIN clientes c ON p.cliente_id = c.id WHERE p.estado = "activo" AND c.responsable_id = ? ORDER BY p.nombre', [$mi_equipo_id]);
} else {
    $clientes_list  = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');
    $proyectos_list = query_all('SELECT id, nombre, cliente_id FROM proyectos WHERE estado = "activo" ORDER BY nombre');
}
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

// Separar por estado
$col_pendiente  = array_values(array_filter($tareas, fn($t) => $t['estado'] === 'pendiente'));
$col_progreso   = array_values(array_filter($tareas, fn($t) => $t['estado'] === 'en_progreso'));
$col_completada = array_slice(
    array_values(array_filter($tareas, fn($t) => $t['estado'] === 'completada')),
    0, 20
);

// KPIs — filtrados por visibilidad del usuario, excluyen eliminadas
if ($current_user['role'] !== 'admin') {
    $kpi_where = "WHERE t.estado != 'eliminada' AND (c.responsable_id = ? OR t.asignado_a = ?)";
    $kpi_params = [$mi_equipo_id, $mi_equipo_id];
    $kpi_join = 'FROM tareas t LEFT JOIN clientes c ON t.cliente_id = c.id';
    $total_all      = query_scalar("SELECT COUNT(*) $kpi_join $kpi_where", $kpi_params) ?? 0;
    $pendientes_all = query_scalar("SELECT COUNT(*) $kpi_join $kpi_where AND t.estado = 'pendiente'", $kpi_params) ?? 0;
    $progreso_all   = query_scalar("SELECT COUNT(*) $kpi_join $kpi_where AND t.estado = 'en_progreso'", $kpi_params) ?? 0;
    $completad_all  = query_scalar("SELECT COUNT(*) $kpi_join $kpi_where AND t.estado = 'completada'", $kpi_params) ?? 0;
} else {
    $total_all      = query_scalar("SELECT COUNT(*) FROM tareas WHERE estado != 'eliminada'") ?? 0;
    $pendientes_all = query_scalar("SELECT COUNT(*) FROM tareas WHERE estado = 'pendiente'") ?? 0;
    $progreso_all   = query_scalar("SELECT COUNT(*) FROM tareas WHERE estado = 'en_progreso'") ?? 0;
    $completad_all  = query_scalar("SELECT COUNT(*) FROM tareas WHERE estado = 'completada'") ?? 0;
}

$can_edit = can_edit($current_user['id'], 'tasks');

/**
 * Renderiza una tarjeta de tarea
 */
function render_card(array $t, bool $can_edit, bool $is_deleted = false): string {
    $atrasada = !$is_deleted && in_array($t['estado'], ['pendiente','en_progreso'])
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
        if ($is_deleted) {
            $btns .= '<button class="btn btn-primary btn-sm" onclick="restoreTask(' . $t['id'] . ')">Restaurar</button>';
        } else {
            if ($t['estado'] === 'pendiente') {
                $btns .= '<button class="btn btn-secondary btn-sm" onclick="quickUpdateTask(' . $t['id'] . ',\'en_progreso\')">Iniciar</button> ';
            }
            if ($t['estado'] === 'en_progreso') {
                $btns .= '<button class="btn btn-primary btn-sm" onclick="quickUpdateTask(' . $t['id'] . ',\'completada\')">Completar</button> ';
            }
            if (in_array($t['estado'], ['en_progreso','completada'])) {
                $btns .= '<button class="btn btn-secondary btn-sm" onclick="revertTask(' . $t['id'] . ',\'' . $t['estado'] . '\')">Revertir</button> ';
            }
            $btns .= '<button class="btn btn-secondary btn-sm" onclick="editTask(' . $t['id'] . ')">Editar</button> ';
            $btns .= '<button class="btn btn-secondary btn-sm" style="color:var(--danger)" onclick="deleteTask(' . $t['id'] . ',\'' . addslashes(safe($t['titulo'])) . '\')">Eliminar</button>';
        }
    }

    // Color único por cliente basado en hash del nombre
    $cliente_name = $t['cliente_nombre'] ?? '';
    $hue = abs(crc32($cliente_name)) % 360;
    $cliente_bg = "hsla({$hue},60%,75%,0.15)";
    $cliente_color = "hsl({$hue},50%,65%)";
    $cliente_border = "hsla({$hue},50%,65%,0.3)";
    $iniciales = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(explode(' ', $cliente_name), 0, 2)));

    $opacity = $is_deleted ? 'opacity:.6;' : '';

    return '
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px;' . $opacity . '">
        <div style="font-weight:700;font-size:.9rem;margin-bottom:8px;">' . safe($t['titulo']) . '</div>
        <div style="display:inline-flex;align-items:center;gap:6px;background:' . $cliente_bg . ';border:1px solid ' . $cliente_border . ';border-radius:6px;padding:3px 10px 3px 6px;margin-bottom:6px;">
            <span style="width:22px;height:22px;border-radius:5px;background:' . $cliente_color . ';color:#fff;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;">' . $iniciales . '</span>
            <span style="font-size:.78rem;font-weight:600;color:' . $cliente_color . ';">' . safe($cliente_name) . '</span>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:6px;">Asignado: ' . safe($t['asignado_nombre'] ?? 'Sin asignar') . '</div>
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
    <select class="form-select" onchange="taskFilter('estado',this.value)">
        <option value="">Todos los estados</option>
        <option value="pendiente"  <?= $filtro_estado === 'pendiente'  ? 'selected' : '' ?>>Pendiente</option>
        <option value="en_progreso"<?= $filtro_estado === 'en_progreso'? 'selected' : '' ?>>En Progreso</option>
        <option value="completada" <?= $filtro_estado === 'completada' ? 'selected' : '' ?>>Completada</option>
    </select>
    <select class="form-select" onchange="taskFilter('cliente',this.value)">
        <option value="">Todos los clientes</option>
        <?php foreach ($clientes_list as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= $filtro_cliente == $cl['id'] ? 'selected' : '' ?>><?= safe($cl['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="form-select" onchange="taskFilter('asignado',this.value)">
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
        <div style="background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;color:var(--warning);">Pendientes</span>
            <span style="background:rgba(234,179,8,.15);color:var(--warning);border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= count($col_pendiente) ?></span>
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
        <div style="background:rgba(24,139,246,.08);border:1px solid rgba(24,139,246,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;color:var(--accent);">En Progreso</span>
            <span style="background:rgba(24,139,246,.15);color:var(--accent);border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= count($col_progreso) ?></span>
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
        <div style="background:rgba(65,215,126,.08);border:1px solid rgba(65,215,126,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:.9rem;color:var(--success);">Completadas</span>
            <span style="background:rgba(65,215,126,.15);color:var(--success);border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= $completad_all ?><?= $completad_all > 20 ? ' (20 recientes)' : '' ?></span>
        </div>
        <?php if (empty($col_completada)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:20px 0;">Sin tareas completadas</div>
        <?php endif; ?>
        <?php foreach ($col_completada as $t): ?>
            <?= render_card($t, $can_edit) ?>
        <?php endforeach; ?>
    </div>

</div>

<?php if (!empty($col_eliminada)): ?>
<!-- Sección Eliminadas (colapsable) -->
<div style="margin-top:24px;">
    <div onclick="document.getElementById('deletedSection').classList.toggle('hidden')" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:700;font-size:.9rem;color:var(--text-muted);">Eliminadas</span>
        <span style="display:flex;align-items:center;gap:8px;">
            <span style="background:var(--border);color:var(--text-muted);border-radius:20px;padding:2px 10px;font-size:.8rem;font-weight:700;"><?= count($col_eliminada) ?></span>
            <span style="color:var(--text-muted);font-size:.75rem;" id="deletedArrow">&#9660;</span>
        </span>
    </div>
    <div id="deletedSection" class="hidden" style="margin-top:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
        <?php foreach ($col_eliminada as $t): ?>
            <?= render_card($t, $can_edit, true) ?>
        <?php endforeach; ?>
    </div>
</div>
<style>.hidden { display:none !important; }</style>
<?php endif; ?>

<script>
const tClientesList  = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;
const tProyectosList = <?= json_encode(array_map(fn($p) => ['nombre' => $p['nombre'], 'cliente_id' => $p['cliente_id']], $proyectos_list)) ?>;
const tProyectosMap  = <?= json_encode(array_column($proyectos_list, 'nombre', 'id')) ?>;
const tEquipoList    = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;

function openNewTask() {
    const body = `<form id="frmTask" class="form-grid">
        ${formField('titulo', 'Título', 'text', '', {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: tClientesList})}
        ${formField('asignado_a', 'Asignado a', 'select', '', {options: tEquipoList})}
        ${formField('prioridad', 'Prioridad', 'select', 'media', {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_limite', 'Fecha Límite', 'date')}
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

async function deleteTask(id, titulo) {
    if (!confirm('¿Eliminar tarea "' + titulo + '"?')) return;
    const res = await API.post('update_task', {id, estado: 'eliminada'});
    if (res) { toast('Tarea eliminada'); refreshPage(); }
}

async function restoreTask(id) {
    const res = await API.post('update_task', {id, estado: 'pendiente'});
    if (res) { toast('Tarea restaurada'); refreshPage(); }
}

async function editTask(id) {
    const res = await API.get('get_task', { id });
    if (!res) return;
    const t = res.data;
    const gestiones = t.gestiones || [];

    let gestionesHtml = '';
    if (gestiones.length > 0) {
        gestionesHtml = gestiones.map(g => {
            const fecha = new Date(g.created_at).toLocaleString('es-CL', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
            return `<div style="padding:8px 12px;background:var(--bg);border-radius:8px;margin-bottom:6px;border-left:3px solid var(--accent);">
                <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:2px;"><strong>${escHtml(g.usuario_nombre || 'Usuario')}</strong> · ${fecha}</div>
                <div style="font-size:.82rem;">${escHtml(g.contenido)}</div>
            </div>`;
        }).join('');
    } else {
        gestionesHtml = '<div style="color:var(--text-muted);font-size:.82rem;padding:8px 0;">Sin gestiones registradas.</div>';
    }

    const body = `<form id="frmTask" class="form-grid">
        <input type="hidden" name="id" value="${t.id}">
        ${formField('titulo', 'Título', 'text', t.titulo, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', t.cliente_id, {options: tClientesList})}
        ${formField('asignado_a', 'Asignado a', 'select', t.asignado_a || '', {options: tEquipoList})}
        ${formField('estado', 'Estado', 'select', t.estado, {options: {pendiente:'Pendiente', en_progreso:'En Progreso', completada:'Completada', cancelada:'Cancelada'}})}
        ${formField('prioridad', 'Prioridad', 'select', t.prioridad, {options: {critica:'Crítica', alta:'Alta', media:'Media', baja:'Baja'}})}
        ${formField('fecha_limite', 'Fecha Límite', 'date', t.fecha_limite || '')}
    </form>
    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
        <div style="font-weight:700;font-size:.85rem;margin-bottom:10px;">Historial de Gestiones</div>
        <div id="gestionesList" style="max-height:200px;overflow-y:auto;margin-bottom:12px;">${gestionesHtml}</div>
        <div style="display:flex;gap:8px;">
            <input type="text" id="nuevaGestion" class="form-input" style="flex:1;font-size:.82rem;" placeholder="Registrar gestión..." onkeydown="if(event.key==='Enter'){event.preventDefault();addGestion(${t.id});}">
            <button type="button" class="btn btn-primary btn-sm" onclick="addGestion(${t.id})">Agregar</button>
        </div>
    </div>`;
    Modal.open('Editar Tarea', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateTask()">Actualizar</button>`);
}

async function addGestion(tareaId) {
    const input = document.getElementById('nuevaGestion');
    const contenido = input.value.trim();
    if (!contenido) return;
    const res = await API.post('create_gestion', { tarea_id: tareaId, contenido });
    if (res && res.ok) {
        input.value = '';
        // Recargar gestiones
        const taskRes = await API.get('get_task', { id: tareaId });
        if (taskRes && taskRes.data) {
            const gestiones = taskRes.data.gestiones || [];
            const list = document.getElementById('gestionesList');
            if (gestiones.length > 0) {
                list.innerHTML = gestiones.map(g => {
                    const fecha = new Date(g.created_at).toLocaleString('es-CL', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
                    return `<div style="padding:8px 12px;background:var(--bg);border-radius:8px;margin-bottom:6px;border-left:3px solid var(--accent);">
                        <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:2px;"><strong>${escHtml(g.usuario_nombre || 'Usuario')}</strong> · ${fecha}</div>
                        <div style="font-size:.82rem;">${escHtml(g.contenido)}</div>
                    </div>`;
                }).join('');
            }
            list.scrollTop = 0;
        }
        toast('Gestión registrada');
    }
}

async function updateTask() {
    const res = await API.post('update_task', getFormData('frmTask'));
    if (res) { toast('Tarea actualizada'); refreshPage(); }
}

function taskFilter(key, val) {
    const url = new URL(window.location);
    url.searchParams.set('page', 'tasks');
    url.searchParams.set(key, val);
    location.href = url.toString();
}
</script>
