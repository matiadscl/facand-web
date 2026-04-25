<?php
/**
 * Módulo Servicios — Vista de servicios contratados por cliente
 * Tipos: suscripción (mensual), implementación (puntual), adicional
 */

$filtro_tipo = $_GET['tipo_servicio'] ?? '';
$filtro_estado = $_GET['estado_servicio'] ?? '';
$where = '1=1';
$params = [];
if ($filtro_tipo) { $where .= ' AND s.tipo = ?'; $params[] = $filtro_tipo; }
if ($filtro_estado) { $where .= ' AND s.estado = ?'; $params[] = $filtro_estado; }

$servicios = query_all("SELECT s.*, c.nombre as cliente_nombre
    FROM servicios_cliente s
    LEFT JOIN clientes c ON s.cliente_id = c.id
    WHERE $where
    ORDER BY s.estado = 'activo' DESC, s.updated_at DESC", $params);

$clientes_list = query_all('SELECT id, nombre FROM clientes WHERE tipo IN ("activo","prospecto") ORDER BY nombre');

// KPIs
$total_activos = query_scalar('SELECT COUNT(*) FROM servicios_cliente WHERE estado = "activo"') ?? 0;
$ingreso_suscripciones = query_scalar('SELECT COALESCE(SUM(monto),0) FROM servicios_cliente WHERE estado = "activo" AND tipo = "suscripcion"') ?? 0;
$total_implementaciones = query_scalar('SELECT COUNT(*) FROM servicios_cliente WHERE tipo = "implementacion"') ?? 0;
$total_servicios = query_scalar('SELECT COUNT(*) FROM servicios_cliente') ?? 0;

// Reporte por tipo
$por_tipo = query_all('SELECT tipo, estado, COUNT(*) as cantidad, COALESCE(SUM(monto),0) as total_monto FROM servicios_cliente GROUP BY tipo, estado ORDER BY tipo, estado');
?>

<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--primary)"><div class="kpi-label">Servicios Activos</div><div class="kpi-value"><?= $total_activos ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--success)"><div class="kpi-label">Ingreso Suscripciones</div><div class="kpi-value success"><?= format_money($ingreso_suscripciones) ?></div><div class="kpi-sub">/mes</div></div>
    <div class="kpi-card" style="border-left:3px solid var(--accent)"><div class="kpi-label">Implementaciones</div><div class="kpi-value"><?= $total_implementaciones ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--text-muted)"><div class="kpi-label">Total Servicios</div><div class="kpi-value"><?= $total_servicios ?></div></div>
</div>

<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=services&tipo_servicio='+this.value+'&estado_servicio=<?= $filtro_estado ?>'">
        <option value="">Todos los tipos</option>
        <option value="suscripcion" <?= $filtro_tipo === 'suscripcion' ? 'selected' : '' ?>>Suscripción</option>
        <option value="implementacion" <?= $filtro_tipo === 'implementacion' ? 'selected' : '' ?>>Implementación</option>
        <option value="adicional" <?= $filtro_tipo === 'adicional' ? 'selected' : '' ?>>Adicional</option>
    </select>
    <select class="form-select" onchange="location.href='?page=services&tipo_servicio=<?= $filtro_tipo ?>&estado_servicio='+this.value">
        <option value="">Todos los estados</option>
        <option value="activo" <?= $filtro_estado === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="pausado" <?= $filtro_estado === 'pausado' ? 'selected' : '' ?>>Pausado</option>
        <option value="finalizado" <?= $filtro_estado === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
        <option value="cancelado" <?= $filtro_estado === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
    </select>
</div>

<div class="grid-2">
    <!-- Tabla de servicios -->
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Servicios Contratados</span>
            <div class="table-actions">
            <?php if (can_edit($current_user['id'], 'services')): ?>
                <button class="btn btn-primary btn-sm" onclick="openNewService()">+ Nuevo Servicio</button>
            <?php endif; ?>
            </div>
        </div>
        <table>
            <thead><tr><th>Servicio</th><th>Cliente</th><th>Tipo</th><th>Monto</th><th>Estado</th><th>Inicio</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($servicios as $s): ?>
                <tr>
                    <td><strong><?= safe($s['nombre']) ?></strong><?= $s['notas'] ? '<br><span style="font-size:.7rem;color:var(--text-muted)">' . safe(substr($s['notas'], 0, 50)) . '</span>' : '' ?></td>
                    <td><?= safe($s['cliente_nombre']) ?></td>
                    <td><span class="badge <?= $s['tipo'] === 'suscripcion' ? 'status-info' : ($s['tipo'] === 'implementacion' ? 'status-warning' : 'status-muted') ?>"><?= ucfirst($s['tipo']) ?></span></td>
                    <td style="font-weight:600"><?= format_money($s['monto']) ?><?= $s['tipo'] === 'suscripcion' ? '<span style="font-size:.7rem;color:var(--text-muted)">/mes</span>' : '' ?></td>
                    <td><span class="badge <?= $s['estado'] === 'activo' ? 'status-success' : ($s['estado'] === 'pausado' ? 'status-warning' : ($s['estado'] === 'cancelado' ? 'status-danger' : 'status-muted')) ?>"><?= ucfirst($s['estado']) ?></span></td>
                    <td style="font-size:.82rem"><?= $s['fecha_inicio'] ? format_date($s['fecha_inicio']) : '-' ?></td>
                    <td>
                        <?php if (can_edit($current_user['id'], 'services')): ?>
                            <button class="btn btn-secondary btn-sm" onclick="editService(<?= $s['id'] ?>)">Editar</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($servicios)): ?>
                <tr><td colspan="7" class="empty-state">No hay servicios registrados. Usa "+ Nuevo Servicio" para agregar.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Reporte por tipo -->
    <div>
        <div class="section-header">
            <h3 class="section-title">Resumen por Tipo</h3>
        </div>
        <?php
        $tipos_resumen = [];
        foreach ($por_tipo as $pt) {
            $tipos_resumen[$pt['tipo']][] = $pt;
        }
        ?>
        <?php foreach ($tipos_resumen as $tipo => $estados): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px;">
            <div style="font-weight:600;margin-bottom:8px;text-transform:capitalize;"><?= $tipo === 'suscripcion' ? 'Suscripciones' : ($tipo === 'implementacion' ? 'Implementaciones' : 'Adicionales') ?></div>
            <?php foreach ($estados as $e): ?>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:.85rem;">
                <span><span class="badge <?= $e['estado'] === 'activo' ? 'status-success' : ($e['estado'] === 'pausado' ? 'status-warning' : 'status-muted') ?>"><?= ucfirst($e['estado']) ?></span> <?= $e['cantidad'] ?> servicio<?= $e['cantidad'] > 1 ? 's' : '' ?></span>
                <span style="font-weight:600"><?= format_money($e['total_monto']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($tipos_resumen)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;padding:20px 0;">Sin datos. Agrega servicios para ver el resumen.</div>
        <?php endif; ?>
    </div>
</div>

<script>
const sClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

function openNewService() {
    const body = `<form id="frmService" class="form-grid">
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: sClientesList})}
        ${formField('nombre', 'Nombre del servicio', 'text', '', {required: true})}
        ${formField('tipo', 'Tipo', 'select', 'suscripcion', {options: {suscripcion:'Suscripción (mensual)', implementacion:'Implementación (puntual)', adicional:'Adicional'}})}
        ${formField('monto', 'Monto ($)', 'number', '', {required: true})}
        ${formField('estado', 'Estado', 'select', 'activo', {options: {activo:'Activo', pausado:'Pausado', finalizado:'Finalizado', cancelado:'Cancelado'}})}
        ${formField('fecha_inicio', 'Fecha inicio', 'date', new Date().toISOString().split('T')[0])}
        ${formField('fecha_fin', 'Fecha fin (opcional)', 'date')}
        ${formField('notas', 'Notas', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nuevo Servicio', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveService()">Guardar</button>`);
}

async function saveService() {
    const data = getFormData('frmService');
    const res = await API.post('create_service', data);
    if (res) { toast('Servicio creado'); refreshPage(); }
}

async function editService(id) {
    const res = await API.get('get_service', { id });
    if (!res) return;
    const s = res.data;
    const body = `<form id="frmService" class="form-grid">
        <input type="hidden" name="id" value="${s.id}">
        ${formField('cliente_id', 'Cliente', 'select', s.cliente_id, {options: sClientesList})}
        ${formField('nombre', 'Nombre del servicio', 'text', s.nombre)}
        ${formField('tipo', 'Tipo', 'select', s.tipo, {options: {suscripcion:'Suscripción (mensual)', implementacion:'Implementación (puntual)', adicional:'Adicional'}})}
        ${formField('monto', 'Monto ($)', 'number', s.monto)}
        ${formField('estado', 'Estado', 'select', s.estado, {options: {activo:'Activo', pausado:'Pausado', finalizado:'Finalizado', cancelado:'Cancelado'}})}
        ${formField('fecha_inicio', 'Fecha inicio', 'date', s.fecha_inicio || '')}
        ${formField('fecha_fin', 'Fecha fin', 'date', s.fecha_fin || '')}
        ${formField('notas', 'Notas', 'textarea', s.notas, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Servicio', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateService()">Actualizar</button>`);
}

async function updateService() {
    const data = getFormData('frmService');
    const res = await API.post('update_service', data);
    if (res) { toast('Servicio actualizado'); refreshPage(); }
}
</script>
