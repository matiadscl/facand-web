<?php
/**
 * Módulo CRM — Gestión de clientes y pipeline de ventas
 * Entidad central: todo el sistema se vincula a clientes
 */

$clientes = query_all('SELECT c.*, e.nombre as responsable_nombre,
    (SELECT COUNT(*) FROM proyectos WHERE cliente_id = c.id AND estado = "activo") as proyectos_activos,
    (SELECT COUNT(*) FROM tareas WHERE cliente_id = c.id AND estado IN ("pendiente","en_progreso")) as tareas_pendientes,
    (SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE cliente_id = c.id AND estado IN ("pendiente","parcial")) as deuda_pendiente
    FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id ORDER BY c.updated_at DESC');

// Lista de equipo para selector de responsable
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

// Datos para pipeline — etapas de agencia
$pipeline_stages = [
    'lead'            => 'Lead',
    'contactado'      => 'Contactado',
    'propuesta'       => 'Propuesta',
    'negociacion'     => 'Negociación',
    'onboarding'      => 'Onboarding',
    'activo'          => 'Activo',
    'cerrado_perdido' => 'Cerrado Perdido',
];

$pipeline_data = [];
foreach ($pipeline_stages as $key => $label) {
    $pipeline_data[$key] = query_all('SELECT * FROM clientes WHERE etapa_pipeline = ? ORDER BY updated_at DESC', [$key]);
}

// KPIs CRM
$total = count($clientes);
$activos = count(array_filter($clientes, fn($c) => $c['tipo'] === 'activo'));
$prospectos = count(array_filter($clientes, fn($c) => $c['tipo'] === 'prospecto'));
$deuda_total = array_sum(array_column($clientes, 'deuda_pendiente'));
?>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Clientes</div>
        <div class="kpi-value"><?= $total ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Activos</div>
        <div class="kpi-value success"><?= $activos ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Prospectos</div>
        <div class="kpi-value"><?= $prospectos ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Deuda Total Clientes</div>
        <div class="kpi-value <?= $deuda_total > 0 ? 'warning' : '' ?>"><?= format_money($deuda_total) ?></div>
    </div>
</div>

<!-- Tabs: Pipeline / Lista -->
<div class="tabs">
    <button class="tab active" data-tab="pipeline">Pipeline</button>
    <button class="tab" data-tab="lista">Lista Completa</button>
</div>

<!-- Pipeline View -->
<div class="tab-content active" data-tab-content="pipeline">
    <div class="pipeline">
        <?php foreach ($pipeline_stages as $key => $label): ?>
            <div class="pipeline-stage">
                <div class="pipeline-stage-header">
                    <span class="pipeline-stage-name"><?= $label ?></span>
                    <span class="pipeline-stage-count"><?= count($pipeline_data[$key]) ?></span>
                </div>
                <?php foreach ($pipeline_data[$key] as $cli): ?>
                    <div class="pipeline-card" onclick="openClientDetail(<?= $cli['id'] ?>)">
                        <div class="pipeline-card-name"><?= safe($cli['nombre']) ?></div>
                        <div class="pipeline-card-sub"><?= safe($cli['plan'] ?: ($cli['contacto_nombre'] ?: $cli['email'])) ?></div>
                        <?php if ($cli['estado_pago'] === 'vencido'): ?>
                            <span class="badge" style="background:var(--danger);color:#fff;font-size:.65rem;margin-top:4px;">Pago vencido</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($pipeline_data[$key])): ?>
                    <div style="font-size:.75rem; color:var(--text-muted); text-align:center; padding:20px 0;">Sin clientes</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Lista View -->
<div class="tab-content" data-tab-content="lista">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Clientes</span>
            <?php if (can_edit($current_user['id'], 'crm')): ?>
                <button class="btn btn-primary btn-sm" onclick="openNewClient()">+ Nuevo Cliente</button>
            <?php endif; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Plan</th>
                    <th>Fee Mensual</th>
                    <th>Etapa</th>
                    <th>Estado Pago</th>
                    <th>Responsable</th>
                    <th>Tareas</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                <tr>
                    <td><strong><?= safe($c['nombre']) ?></strong><br><span style="font-size:.75rem;color:var(--text-muted)"><?= safe($c['rubro']) ?></span></td>
                    <td><?= safe($c['plan'] ?: '-') ?></td>
                    <td><?= $c['fee_mensual'] > 0 ? format_money($c['fee_mensual']) : '-' ?></td>
                    <td><span class="badge"><?= safe($c['etapa'] ?: $c['etapa_pipeline']) ?></span></td>
                    <td><span class="badge <?= $c['estado_pago'] === 'pagado' ? 'status-success' : ($c['estado_pago'] === 'vencido' ? 'status-danger' : 'status-warning') ?>"><?= safe(ucfirst($c['estado_pago'])) ?></span></td>
                    <td><?= safe($c['responsable_nombre'] ?? '-') ?></td>
                    <td><?= $c['tareas_pendientes'] ?></td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="openClientDetail(<?= $c['id'] ?>)">Ver</button>
                        <?php if (can_edit($current_user['id'], 'crm')): ?>
                            <button class="btn btn-secondary btn-sm" onclick="editClient(<?= $c['id'] ?>)">Editar</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($clientes)): ?>
                <tr><td colspan="8" class="empty-state">No hay clientes registrados</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/** Abre formulario para nuevo cliente */
const crmEquipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;

function openNewClient() {
    const body = `<form id="frmClient" class="form-grid">
        ${formField('nombre', 'Nombre / Razón Social', 'text', '', {required: true})}
        ${formField('rut', 'RUT', 'text')}
        ${formField('rubro', 'Rubro', 'text')}
        ${formField('email', 'Email', 'email')}
        ${formField('telefono', 'Teléfono', 'text')}
        ${formField('contacto_nombre', 'Nombre Contacto', 'text')}
        ${formField('contacto_cargo', 'Cargo Contacto', 'text')}
        ${formField('tipo', 'Tipo', 'select', 'prospecto', {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo'}})}
        ${formField('etapa_pipeline', 'Etapa Pipeline', 'select', 'lead', {options: {lead:'Lead', contactado:'Contactado', propuesta:'Propuesta', negociacion:'Negociación', onboarding:'Onboarding', activo:'Activo', cerrado_perdido:'Cerrado Perdido'}})}
        ${formField('plan', 'Plan', 'select', '', {options: {'':'Sin plan', basico:'Básico', estandar:'Estándar', premium:'Premium', custom:'Custom'}})}
        ${formField('fee_mensual', 'Fee Mensual ($)', 'number')}
        ${formField('servicios', 'Servicios', 'text', '', {fullWidth: true})}
        ${formField('herramientas', 'Herramientas', 'text', '', {fullWidth: true})}
        ${formField('presupuesto_ads', 'Presupuesto Ads', 'text')}
        ${formField('etapa', 'Etapa Actual', 'text')}
        ${formField('estado_pago', 'Estado Pago', 'select', 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('responsable_id', 'Responsable', 'select', '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('url_dashboard', 'URL Dashboard', 'text')}
        ${formField('direccion', 'Dirección', 'text', '', {fullWidth: true})}
        ${formField('notas', 'Notas', 'textarea', '', {fullWidth: true})}
    </form>`;
    const footer = `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
        <button class="btn btn-primary" onclick="saveClient()">Guardar</button>`;
    Modal.open('Nuevo Cliente', body, footer);
}

/** Guarda nuevo cliente via API */
async function saveClient() {
    const data = getFormData('frmClient');
    const res = await API.post('create_client', data);
    if (res) {
        toast('Cliente creado correctamente');
        refreshPage();
    }
}

/** Abre detalle de un cliente */
async function openClientDetail(id) {
    const res = await API.get('get_client', { id });
    if (!res) return;
    const c = res.data;
    const estadoPagoBadge = c.estado_pago === 'pagado' ? 'status-success' : (c.estado_pago === 'vencido' ? 'status-danger' : 'status-warning');
    const body = `
        <div style="display:grid; gap:12px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div><strong>Tipo:</strong> <span class="badge ${status_class(c.tipo)}">${c.tipo}</span></div>
                <div><strong>Estado Pago:</strong> <span class="badge ${estadoPagoBadge}">${escHtml(c.estado_pago || '-')}</span></div>
                <div><strong>Plan:</strong> ${escHtml(c.plan || '-')}</div>
                <div><strong>Fee Mensual:</strong> ${c.fee_mensual ? fmtMoney(c.fee_mensual) : '-'}</div>
                <div><strong>Rubro:</strong> ${escHtml(c.rubro || '-')}</div>
                <div><strong>Etapa:</strong> ${escHtml(c.etapa || c.etapa_pipeline || '-')}</div>
            </div>
            <hr style="border-color:var(--border)">
            <div><strong>Servicios:</strong> ${escHtml(c.servicios || '-')}</div>
            <div><strong>Herramientas:</strong> ${escHtml(c.herramientas || '-')}</div>
            <div><strong>Presupuesto Ads:</strong> ${escHtml(c.presupuesto_ads || '-')}</div>
            ${c.url_dashboard ? '<div><strong>Dashboard:</strong> <a href="' + escHtml(c.url_dashboard) + '" target="_blank">' + escHtml(c.url_dashboard) + '</a></div>' : ''}
            <hr style="border-color:var(--border)">
            <div><strong>RUT:</strong> ${escHtml(c.rut || '-')}</div>
            <div><strong>Email:</strong> ${escHtml(c.email || '-')}</div>
            <div><strong>Teléfono:</strong> ${escHtml(c.telefono || '-')}</div>
            <div><strong>Contacto:</strong> ${escHtml(c.contacto_nombre || '-')} ${c.contacto_cargo ? '(' + escHtml(c.contacto_cargo) + ')' : ''}</div>
            <hr style="border-color:var(--border)">
            <div><strong>Proyectos activos:</strong> ${c.proyectos_activos}</div>
            <div><strong>Tareas pendientes:</strong> ${c.tareas_pendientes}</div>
            <div><strong>Deuda pendiente:</strong> ${fmtMoney(c.deuda_pendiente)}</div>
            ${c.notas ? '<div><strong>Notas:</strong><br>' + escHtml(c.notas) + '</div>' : ''}
        </div>`;
    Modal.open(c.nombre, body, `<button class="btn btn-secondary" onclick="Modal.close()">Cerrar</button>`);
}

/** Abre formulario para editar cliente */
async function editClient(id) {
    const res = await API.get('get_client', { id });
    if (!res) return;
    const c = res.data;
    const body = `<form id="frmClient" class="form-grid">
        <input type="hidden" name="id" value="${c.id}">
        ${formField('nombre', 'Nombre', 'text', c.nombre, {required: true})}
        ${formField('rut', 'RUT', 'text', c.rut)}
        ${formField('rubro', 'Rubro', 'text', c.rubro)}
        ${formField('email', 'Email', 'email', c.email)}
        ${formField('telefono', 'Teléfono', 'text', c.telefono)}
        ${formField('contacto_nombre', 'Nombre Contacto', 'text', c.contacto_nombre)}
        ${formField('contacto_cargo', 'Cargo Contacto', 'text', c.contacto_cargo)}
        ${formField('tipo', 'Tipo', 'select', c.tipo, {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo', cerrado:'Cerrado'}})}
        ${formField('etapa_pipeline', 'Etapa Pipeline', 'select', c.etapa_pipeline, {options: {lead:'Lead', contactado:'Contactado', propuesta:'Propuesta', negociacion:'Negociación', onboarding:'Onboarding', activo:'Activo', cerrado_perdido:'Cerrado Perdido'}})}
        ${formField('plan', 'Plan', 'select', c.plan || '', {options: {'':'Sin plan', basico:'Básico', estandar:'Estándar', premium:'Premium', custom:'Custom'}})}
        ${formField('fee_mensual', 'Fee Mensual ($)', 'number', c.fee_mensual)}
        ${formField('servicios', 'Servicios', 'text', c.servicios, {fullWidth: true})}
        ${formField('herramientas', 'Herramientas', 'text', c.herramientas, {fullWidth: true})}
        ${formField('presupuesto_ads', 'Presupuesto Ads', 'text', c.presupuesto_ads)}
        ${formField('etapa', 'Etapa Actual', 'text', c.etapa)}
        ${formField('estado_pago', 'Estado Pago', 'select', c.estado_pago || 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('responsable_id', 'Responsable', 'select', c.responsable_id || '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('url_dashboard', 'URL Dashboard', 'text', c.url_dashboard)}
        ${formField('direccion', 'Dirección', 'text', c.direccion, {fullWidth: true})}
        ${formField('notas', 'Notas', 'textarea', c.notas, {fullWidth: true})}
    </form>`;
    const footer = `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
        <button class="btn btn-primary" onclick="updateClient()">Actualizar</button>`;
    Modal.open('Editar Cliente', body, footer);
}

/** Actualiza cliente via API */
async function updateClient() {
    const data = getFormData('frmClient');
    const res = await API.post('update_client', data);
    if (res) {
        toast('Cliente actualizado');
        refreshPage();
    }
}
</script>
