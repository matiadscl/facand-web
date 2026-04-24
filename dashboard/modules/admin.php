<?php
/**
 * Módulo Admin — Gestión de usuarios, roles y permisos por módulo
 * Solo accesible por usuarios con role = admin
 */

if ($current_user['role'] !== 'admin') {
    echo '<div class="empty-state"><p>Acceso restringido a administradores</p></div>';
    return;
}

$usuarios = query_all('SELECT * FROM usuarios ORDER BY role DESC, nombre');
$all_modules = require __DIR__ . '/../config/modules.php';
// Excluir admin del listado de permisos asignables
$assignable_modules = array_filter($all_modules, fn($k) => $k !== 'admin', ARRAY_FILTER_USE_KEY);

$total_users = count($usuarios);
$admins = count(array_filter($usuarios, fn($u) => $u['role'] === 'admin'));
$activos = count(array_filter($usuarios, fn($u) => $u['activo']));
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Usuarios</div>
        <div class="kpi-value"><?= $total_users ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Administradores</div>
        <div class="kpi-value"><?= $admins ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Activos</div>
        <div class="kpi-value success"><?= $activos ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Usuarios</span>
        <button class="btn btn-primary btn-sm" onclick="openNewUser()">+ Nuevo Usuario</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Nombre</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Último Login</th>
                <th>Módulos</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u):
                $user_mods = get_user_modules($u['id']);
            ?>
            <tr>
                <td><strong><?= safe($u['username']) ?></strong></td>
                <td><?= safe($u['nombre']) ?></td>
                <td><?= safe($u['email'] ?: '-') ?></td>
                <td><span class="badge <?= $u['role'] === 'admin' ? 'status-info' : 'status-default' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Usuario' ?></span></td>
                <td><span class="badge <?= $u['activo'] ? 'status-success' : 'status-muted' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                <td><?= $u['last_login'] ? format_date($u['last_login']) : 'Nunca' ?></td>
                <td style="font-size:.75rem;">
                    <?php if ($u['role'] === 'admin'): ?>
                        <span style="color:var(--accent)">Todos</span>
                    <?php else: ?>
                        <?= count($user_mods) ?> módulos
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick="editUser(<?= $u['id'] ?>)">Editar</button>
                    <?php if ($u['role'] !== 'admin' || $admins > 1): ?>
                        <button class="btn btn-secondary btn-sm" onclick="managePermissions(<?= $u['id'] ?>, '<?= safe($u['nombre']) ?>')">Permisos</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Actividad del sistema -->
<?php $log = query_all('SELECT a.*, u.nombre as usuario FROM actividad a LEFT JOIN usuarios u ON a.usuario_id = u.id ORDER BY a.created_at DESC LIMIT 30'); ?>
<div class="table-container" style="margin-top:24px;">
    <div class="table-header">
        <span class="table-title">Log de Actividad (últimas 30)</span>
    </div>
    <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Módulo</th><th>Acción</th></tr></thead>
        <tbody>
            <?php foreach ($log as $l): ?>
            <tr>
                <td style="white-space:nowrap"><?= safe(substr($l['created_at'], 0, 16)) ?></td>
                <td><?= safe($l['usuario'] ?? 'Sistema') ?></td>
                <td><span class="badge status-default"><?= safe($l['modulo']) ?></span></td>
                <td><?= safe($l['accion']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const assignableModules = <?= json_encode(array_map(fn($m) => $m['nombre'], $assignable_modules)) ?>;
const moduleKeys = <?= json_encode(array_keys($assignable_modules)) ?>;

function openNewUser() {
    const body = `<form id="frmUser" class="form-grid">
        ${formField('username', 'Usuario', 'text', '', {required: true})}
        ${formField('nombre', 'Nombre Completo', 'text', '', {required: true})}
        ${formField('email', 'Email', 'email')}
        ${formField('password', 'Contraseña', 'password', '', {required: true})}
        ${formField('role', 'Rol', 'select', 'user', {options: {user:'Usuario', admin:'Administrador'}})}
    </form>
    <div style="margin-top:12px; font-size:.8rem; color:var(--text-muted);">
        Después de crear el usuario, asigna permisos con el botón "Permisos".
    </div>`;
    Modal.open('Nuevo Usuario', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveUser()">Crear Usuario</button>`);
}

async function saveUser() {
    const res = await API.post('create_user', getFormData('frmUser'));
    if (res) { toast('Usuario creado'); refreshPage(); }
}

async function editUser(id) {
    const res = await API.get('get_user', { id });
    if (!res) return;
    const u = res.data;
    const body = `<form id="frmUser" class="form-grid">
        <input type="hidden" name="id" value="${u.id}">
        ${formField('username', 'Usuario', 'text', u.username, {required: true})}
        ${formField('nombre', 'Nombre', 'text', u.nombre, {required: true})}
        ${formField('email', 'Email', 'email', u.email)}
        ${formField('password', 'Nueva Contraseña (dejar vacío para no cambiar)', 'password')}
        ${formField('role', 'Rol', 'select', u.role, {options: {user:'Usuario', admin:'Administrador'}})}
        ${formField('activo', 'Estado', 'select', u.activo, {options: {'1':'Activo', '0':'Inactivo'}})}
    </form>`;
    Modal.open('Editar Usuario', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateUser()">Actualizar</button>`);
}

async function updateUser() {
    const res = await API.post('update_user', getFormData('frmUser'));
    if (res) { toast('Usuario actualizado'); refreshPage(); }
}

async function managePermissions(userId, userName) {
    const res = await API.get('get_permissions', { user_id: userId });
    if (!res) return;
    const perms = res.data;

    let html = `<form id="frmPerms"><input type="hidden" name="user_id" value="${userId}">
        <p style="color:var(--text-muted); font-size:.85rem; margin-bottom:16px;">Configura qué módulos puede ver y editar <strong>${escHtml(userName)}</strong></p>`;

    for (let i = 0; i < moduleKeys.length; i++) {
        const key = moduleKeys[i];
        const name = assignableModules[key];
        const perm = perms[key] || {ver: 0, editar: 0};
        html += `<div class="toggle-row">
            <span class="toggle-label">${name}</span>
            <div style="display:flex; gap:12px; align-items:center;">
                <label style="font-size:.75rem; display:flex; align-items:center; gap:4px;">
                    <input type="checkbox" name="ver_${key}" ${perm.ver ? 'checked' : ''}> Ver
                </label>
                <label style="font-size:.75rem; display:flex; align-items:center; gap:4px;">
                    <input type="checkbox" name="editar_${key}" ${perm.editar ? 'checked' : ''}> Editar
                </label>
            </div>
        </div>`;
    }
    html += '</form>';

    Modal.open(`Permisos — ${userName}`, html,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="savePermissions()">Guardar Permisos</button>`);
}

async function savePermissions() {
    const form = document.getElementById('frmPerms');
    const userId = form.querySelector('[name="user_id"]').value;
    const perms = {};
    for (const key of moduleKeys) {
        perms[key] = {
            ver: form.querySelector(`[name="ver_${key}"]`)?.checked ? 1 : 0,
            editar: form.querySelector(`[name="editar_${key}"]`)?.checked ? 1 : 0
        };
    }
    const res = await API.post('save_permissions', { user_id: userId, permissions: JSON.stringify(perms) });
    if (res) { toast('Permisos actualizados'); refreshPage(); }
}
</script>
