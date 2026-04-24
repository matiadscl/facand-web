<?php
/**
 * Módulo Equipo — Miembros y carga de trabajo
 * Interconexión: tareas, proyectos
 */

$equipo = query_all('SELECT e.*,
    (SELECT COUNT(*) FROM tareas WHERE asignado_a = e.id AND estado = "pendiente") as tareas_pendientes,
    (SELECT COUNT(*) FROM tareas WHERE asignado_a = e.id AND estado = "en_progreso") as tareas_progreso,
    (SELECT COUNT(*) FROM tareas WHERE asignado_a = e.id AND estado = "completada" AND strftime("%Y-%m", completado_at) = strftime("%Y-%m", "now")) as tareas_completadas_mes,
    (SELECT COUNT(*) FROM proyectos WHERE responsable_id = e.id AND estado = "activo") as proyectos_activos
    FROM equipo e ORDER BY e.activo DESC, e.nombre');
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Miembros Activos</div>
        <div class="kpi-value"><?= count(array_filter($equipo, fn($e) => $e['activo'])) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Tareas en Progreso (total)</div>
        <div class="kpi-value"><?= array_sum(array_column($equipo, 'tareas_progreso')) ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Equipo</span>
        <?php if (can_edit($current_user['id'], 'team')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewMember()">+ Agregar Miembro</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Cargo</th>
                <th>Estado</th>
                <th>Proyectos</th>
                <th>Pendientes</th>
                <th>En Progreso</th>
                <th>Completadas (mes)</th>
                <th>Carga</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($equipo as $e):
                $carga_total = $e['tareas_pendientes'] + $e['tareas_progreso'];
                $carga_class = $carga_total > 8 ? 'danger' : ($carga_total > 4 ? 'warning' : 'success');
            ?>
            <tr>
                <td><strong><?= safe($e['nombre']) ?></strong></td>
                <td><?= safe($e['cargo'] ?: '-') ?></td>
                <td><span class="badge <?= $e['activo'] ? 'status-success' : 'status-muted' ?>"><?= $e['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                <td><?= $e['proyectos_activos'] ?></td>
                <td><?= $e['tareas_pendientes'] ?></td>
                <td><?= $e['tareas_progreso'] ?></td>
                <td><?= $e['tareas_completadas_mes'] ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div class="progress-bar" style="width:60px;">
                            <div class="progress-fill <?= $carga_class ?>" style="width:<?= min(100, $carga_total * 10) ?>%"></div>
                        </div>
                        <span style="font-size:.75rem;"><?= $carga_total ?></span>
                    </div>
                </td>
                <td>
                    <?php if (can_edit($current_user['id'], 'team')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="editMember(<?= $e['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($equipo)): ?>
            <tr><td colspan="9" class="empty-state">No hay miembros en el equipo</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function openNewMember() {
    const body = `<form id="frmMember" class="form-grid">
        ${formField('nombre', 'Nombre', 'text', '', {required: true})}
        ${formField('cargo', 'Cargo', 'text')}
        ${formField('email', 'Email', 'email')}
    </form>`;
    Modal.open('Nuevo Miembro', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveMember()">Guardar</button>`);
}

async function saveMember() {
    const res = await API.post('create_member', getFormData('frmMember'));
    if (res) { toast('Miembro agregado'); refreshPage(); }
}

async function editMember(id) {
    const res = await API.get('get_member', { id });
    if (!res) return;
    const e = res.data;
    const body = `<form id="frmMember" class="form-grid">
        <input type="hidden" name="id" value="${e.id}">
        ${formField('nombre', 'Nombre', 'text', e.nombre, {required: true})}
        ${formField('cargo', 'Cargo', 'text', e.cargo)}
        ${formField('email', 'Email', 'email', e.email)}
        ${formField('activo', 'Estado', 'select', e.activo, {options: {'1':'Activo', '0':'Inactivo'}})}
    </form>`;
    Modal.open('Editar Miembro', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateMember()">Actualizar</button>`);
}

async function updateMember() {
    const res = await API.post('update_member', getFormData('frmMember'));
    if (res) { toast('Miembro actualizado'); refreshPage(); }
}
</script>
