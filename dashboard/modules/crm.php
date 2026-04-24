<?php
/**
 * Módulo CRM — Gestión completa de clientes
 */

$clientes = query_all('SELECT c.*, e.nombre as responsable_nombre,
    (SELECT COUNT(*) FROM tareas WHERE cliente_id = c.id AND estado IN ("pendiente","en_progreso")) as tareas_pendientes
    FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id ORDER BY c.nombre');

$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');

$activos  = count(array_filter($clientes, fn($c) => $c['tipo'] === 'activo'));
$fee_total = array_sum(array_map(fn($c) => $c['tipo'] === 'activo' && $c['estado_pago'] !== 'canje' ? $c['fee_mensual'] : 0, $clientes));
$vencidos = count(array_filter($clientes, fn($c) => $c['estado_pago'] === 'vencido'));

$planes_labels = [
    'growth' => 'Growth', 'scale' => 'Scale', 'starter' => 'Starter',
    'meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads',
    'full_ads' => 'Full Ads', 'full_ads_seo' => 'Full Ads + SEO',
];
?>

<div class="kpi-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="kpi-card" style="border-left:3px solid var(--accent)">
        <div class="kpi-label">Clientes Activos</div>
        <div class="kpi-value"><?= $activos ?></div>
        <div class="kpi-sub">de <?= count($clientes) ?> totales</div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--success)">
        <div class="kpi-label">Fee Mensual Total</div>
        <div class="kpi-value success"><?= format_money($fee_total) ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid <?= $vencidos > 0 ? 'var(--danger)' : 'var(--success)' ?>">
        <div class="kpi-label">Pagos Vencidos</div>
        <div class="kpi-value <?= $vencidos > 0 ? 'danger' : '' ?>"><?= $vencidos ?></div>
    </div>
    <div class="kpi-card" style="border-left:3px solid var(--text-muted)">
        <div class="kpi-label">Tareas Abiertas</div>
        <div class="kpi-value"><?= array_sum(array_column($clientes, 'tareas_pendientes')) ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Clientes</span>
        <div class="table-actions">
            <select class="form-select" style="font-size:.8rem;padding:5px 10px;" onchange="filterCRM(this.value)" id="filterEstado">
                <option value="">Todos</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
                <option value="prospecto">Prospectos</option>
            </select>
            <?php if (can_edit($current_user['id'], 'crm')): ?>
                <button class="btn btn-primary btn-sm" onclick="openNewClient()">+ Nuevo Cliente</button>
            <?php endif; ?>
        </div>
    </div>
    <table id="tablaClientes">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Plan</th>
                <th>Fee Mensual</th>
                <th>Etapa</th>
                <th>Pago</th>
                <th>Servicios</th>
                <th>Responsable</th>
                <th style="text-align:right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($clientes as $c): ?>
            <tr data-tipo="<?= safe($c['tipo']) ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;border-radius:8px;background:rgba(249,115,22,.12);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--accent);font-size:.85rem;flex-shrink:0;"><?= strtoupper(mb_substr($c['nombre'], 0, 2)) ?></div>
                        <div>
                            <strong style="font-size:.9rem;"><?= safe($c['nombre']) ?></strong>
                            <div style="font-size:.72rem;color:var(--text-muted);"><?= safe($c['rubro'] ?: '-') ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($c['plan']): ?>
                        <span class="badge status-info"><?= safe($planes_labels[$c['plan']] ?? $c['plan']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.8rem">Custom</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;<?= $c['fee_mensual'] > 0 ? 'color:var(--success)' : '' ?>"><?= $c['fee_mensual'] > 0 ? format_money($c['fee_mensual']) : '-' ?></td>
                <td><span style="font-size:.78rem;"><?= safe($c['etapa'] ?: '-') ?></span></td>
                <td>
                    <span class="badge <?= $c['estado_pago'] === 'pagado' ? 'status-success' : ($c['estado_pago'] === 'vencido' ? 'status-danger' : ($c['estado_pago'] === 'canje' ? 'status-muted' : 'status-warning')) ?>"><?= ucfirst($c['estado_pago']) ?></span>
                </td>
                <td>
                    <?php
                    $svcs = array_filter(array_map('trim', explode(',', $c['servicios'])));
                    $shown = array_slice($svcs, 0, 2);
                    $rest = count($svcs) - 2;
                    foreach ($shown as $s): ?>
                        <span style="display:inline-block;font-size:.68rem;padding:2px 6px;border-radius:4px;background:rgba(255,255,255,.06);border:1px solid var(--border);margin:1px 2px;"><?= safe($s) ?></span>
                    <?php endforeach;
                    if ($rest > 0): ?>
                        <span style="font-size:.68rem;color:var(--accent);">+<?= $rest ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem;"><?= safe($c['responsable_nombre'] ?? '-') ?></td>
                <td style="text-align:right;white-space:nowrap;">
                    <button class="btn btn-secondary btn-sm" onclick="openClientDetail(<?= $c['id'] ?>)" title="Ver ficha completa">Ver</button>
                    <?php if (can_edit($current_user['id'], 'crm')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="editClient(<?= $c['id'] ?>)" title="Editar">Editar</button>
                    <?php endif; ?>
                    <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=<?= $c['id'] ?>','_blank')" title="Ficha PDF">PDF</button>
                    <button class="btn btn-secondary btn-sm" onclick="window.open('servicio-pdf.php?id=<?= $c['id'] ?>','_blank')" title="Documento de servicio">Servicio</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const crmEquipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;
const planesOptions = {'':'Sin plan','growth':'Growth','scale':'Scale','starter':'Starter','meta_ads':'Meta Ads','google_ads':'Google Ads','full_ads':'Full Ads','full_ads_seo':'Full Ads + SEO','custom':'Custom'};

function filterCRM(tipo) {
    document.querySelectorAll('#tablaClientes tbody tr').forEach(tr => {
        tr.style.display = (!tipo || tr.dataset.tipo === tipo) ? '' : 'none';
    });
}

function openNewClient() {
    const body = `<form id="frmClient" class="form-grid">
        ${formField('nombre', 'Nombre / Razón Social', 'text', '', {required: true})}
        ${formField('rubro', 'Rubro', 'text')}
        ${formField('contacto_nombre', 'Contacto Principal', 'text')}
        ${formField('email', 'Email', 'email')}
        ${formField('telefono', 'Teléfono', 'text')}
        ${formField('plan', 'Plan', 'select', '', {options: planesOptions})}
        ${formField('fee_mensual', 'Fee Mensual ($)', 'number')}
        ${formField('estado_pago', 'Estado Pago', 'select', 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('tipo', 'Tipo', 'select', 'activo', {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo'}})}
        ${formField('responsable_id', 'Responsable', 'select', '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('servicios', 'Servicios contratados', 'textarea', '', {fullWidth: true})}
        ${formField('herramientas', 'Herramientas', 'text', '', {fullWidth: true})}
        ${formField('presupuesto_ads', 'Presupuesto Ads', 'text')}
        ${formField('etapa', 'Etapa operativa', 'text')}
        ${formField('url_dashboard', 'URL Dashboard', 'text')}
        ${formField('notas', 'Notas', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nuevo Cliente', body, `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button><button class="btn btn-primary" onclick="saveClient()">Guardar</button>`);
}

async function saveClient() {
    const data = getFormData('frmClient');
    if (!data.nombre) { toast('El nombre es obligatorio', 'error'); return; }
    const res = await API.post('create_client', data);
    if (res) { toast('Cliente creado'); refreshPage(); }
}

async function openClientDetail(id) {
    const res = await API.get('get_client', { id });
    if (!res) return;
    const c = res.data;
    const ep = c.estado_pago === 'pagado' ? 'status-success' : (c.estado_pago === 'vencido' ? 'status-danger' : (c.estado_pago === 'canje' ? 'status-muted' : 'status-warning'));

    let serviciosHtml = '-';
    if (c.servicios) {
        serviciosHtml = c.servicios.split(',').map(s => s.trim()).filter(s => s).map(s =>
            `<span style="display:inline-block;font-size:.78rem;padding:3px 8px;border-radius:4px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);margin:2px;">${escHtml(s)}</span>`
        ).join('');
    }

    let herrHtml = '-';
    if (c.herramientas) {
        herrHtml = c.herramientas.split(',').map(s => s.trim()).filter(s => s).map(s =>
            `<span style="display:inline-block;font-size:.78rem;padding:3px 8px;border-radius:4px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.2);margin:2px;">${escHtml(s)}</span>`
        ).join('');
    }

    const body = `
    <div style="display:grid;gap:16px;">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <div style="background:var(--bg);padding:12px;border-radius:8px;">
                <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Plan</div>
                <div style="font-size:1rem;font-weight:600;margin-top:2px;">${escHtml(c.plan || 'Custom')}</div>
            </div>
            <div style="background:var(--bg);padding:12px;border-radius:8px;">
                <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Fee Mensual</div>
                <div style="font-size:1rem;font-weight:600;color:var(--success);margin-top:2px;">${c.fee_mensual ? fmtMoney(c.fee_mensual) : '$0'}</div>
            </div>
            <div style="background:var(--bg);padding:12px;border-radius:8px;">
                <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Estado Pago</div>
                <div style="margin-top:4px;"><span class="badge ${ep}">${escHtml(c.estado_pago || 'pendiente')}</span></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div><span style="font-size:.75rem;color:var(--text-muted)">Rubro:</span> ${escHtml(c.rubro || '-')}</div>
            <div><span style="font-size:.75rem;color:var(--text-muted)">Etapa:</span> ${escHtml(c.etapa || '-')}</div>
            <div><span style="font-size:.75rem;color:var(--text-muted)">Contacto:</span> ${escHtml(c.contacto_nombre || '-')}</div>
            <div><span style="font-size:.75rem;color:var(--text-muted)">Email:</span> ${escHtml(c.email || '-')}</div>
            <div><span style="font-size:.75rem;color:var(--text-muted)">Teléfono:</span> ${escHtml(c.telefono || '-')}</div>
            <div><span style="font-size:.75rem;color:var(--text-muted)">Responsable:</span> ${escHtml(c.responsable_nombre || '-')}</div>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:12px;">
            <div style="font-size:.8rem;font-weight:600;margin-bottom:6px;">Servicios contratados</div>
            <div>${serviciosHtml}</div>
        </div>

        <div>
            <div style="font-size:.8rem;font-weight:600;margin-bottom:6px;">Herramientas</div>
            <div>${herrHtml}</div>
        </div>

        ${c.presupuesto_ads ? `<div><div style="font-size:.8rem;font-weight:600;margin-bottom:6px;">Presupuesto Ads</div><div style="font-size:.85rem;white-space:pre-line;">${escHtml(c.presupuesto_ads)}</div></div>` : ''}
        ${c.url_dashboard ? `<div><div style="font-size:.8rem;font-weight:600;margin-bottom:6px;">Dashboard</div><a href="${escHtml(c.url_dashboard)}" target="_blank" style="font-size:.85rem;">${escHtml(c.url_dashboard)}</a></div>` : ''}
        ${c.notas ? `<div style="border-top:1px solid var(--border);padding-top:12px;"><div style="font-size:.8rem;font-weight:600;margin-bottom:4px;">Notas</div><div style="font-size:.85rem;color:var(--text-muted);white-space:pre-line;">${escHtml(c.notas)}</div></div>` : ''}
    </div>`;

    const footer = `
        <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=${c.id}','_blank')">Ficha PDF</button>
        <button class="btn btn-secondary btn-sm" onclick="window.open('servicio-pdf.php?id=${c.id}','_blank')">Doc. Servicio</button>
        ${APP.canEdit ? `<button class="btn btn-primary btn-sm" onclick="Modal.close();editClient(${c.id})">Editar</button>` : ''}
        <button class="btn btn-secondary" onclick="Modal.close()">Cerrar</button>`;
    Modal.open(c.nombre, body, footer);
}

async function editClient(id) {
    const res = await API.get('get_client', { id });
    if (!res) return;
    const c = res.data;
    const body = `<form id="frmClient" class="form-grid">
        <input type="hidden" name="id" value="${c.id}">
        ${formField('nombre', 'Nombre', 'text', c.nombre, {required: true})}
        ${formField('rubro', 'Rubro', 'text', c.rubro)}
        ${formField('contacto_nombre', 'Contacto Principal', 'text', c.contacto_nombre)}
        ${formField('email', 'Email', 'email', c.email)}
        ${formField('telefono', 'Teléfono', 'text', c.telefono)}
        ${formField('plan', 'Plan', 'select', c.plan || '', {options: planesOptions})}
        ${formField('fee_mensual', 'Fee Mensual ($)', 'number', c.fee_mensual)}
        ${formField('estado_pago', 'Estado Pago', 'select', c.estado_pago || 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('tipo', 'Tipo', 'select', c.tipo, {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo', cerrado:'Cerrado'}})}
        ${formField('responsable_id', 'Responsable', 'select', c.responsable_id || '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('servicios', 'Servicios (separados por coma)', 'textarea', c.servicios, {fullWidth: true})}
        ${formField('herramientas', 'Herramientas (separadas por coma)', 'text', c.herramientas, {fullWidth: true})}
        ${formField('presupuesto_ads', 'Presupuesto Ads', 'text', c.presupuesto_ads)}
        ${formField('etapa', 'Etapa operativa', 'text', c.etapa)}
        ${formField('url_dashboard', 'URL Dashboard', 'text', c.url_dashboard)}
        ${formField('notas', 'Notas', 'textarea', c.notas, {fullWidth: true})}
    </form>`;
    Modal.open('Editar: ' + c.nombre, body, `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button><button class="btn btn-primary" onclick="updateClient()">Guardar</button>`);
}

async function updateClient() {
    const data = getFormData('frmClient');
    const res = await API.post('update_client', data);
    if (res) { toast('Cliente actualizado'); refreshPage(); }
}
</script>
