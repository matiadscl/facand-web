<?php
/**
 * Módulo CRM — Clientes Facand
 */
$clientes = query_all('SELECT c.*, e.nombre as responsable_nombre,
    (SELECT COUNT(*) FROM tareas WHERE cliente_id = c.id AND estado IN ("pendiente","en_progreso")) as tareas_pendientes
    FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id ORDER BY c.nombre');
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');
$activos = count(array_filter($clientes, fn($c) => $c['tipo'] === 'activo'));
$fee_total = array_sum(array_map(fn($c) => $c['tipo'] === 'activo' && $c['estado_pago'] !== 'canje' ? $c['fee_mensual'] : 0, $clientes));
$vencidos = count(array_filter($clientes, fn($c) => $c['estado_pago'] === 'vencido'));
$planes_labels = ['growth'=>'Growth','scale'=>'Scale','starter'=>'Starter','meta_ads'=>'Meta Ads','google_ads'=>'Google Ads','full_ads'=>'Full Ads','full_ads_seo'=>'Full Ads + SEO'];
?>

<style>
.crm-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.crm-kpi { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:16px; }
.crm-kpi-label { font-size:.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.crm-kpi-val { font-size:1.4rem; font-weight:700; margin-top:2px; }

.crm-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.crm-search { flex:1; min-width:200px; padding:8px 14px; background:var(--bg); border:1px solid var(--border); border-radius:8px; color:var(--text); font-size:.85rem; outline:none; }
.crm-search:focus { border-color:var(--accent); }

.crm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:14px; }

.client-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:18px; cursor:pointer; transition:all .15s; position:relative; }
.client-card:hover { border-color:var(--accent); transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.2); }
.client-card-top { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.client-avatar { width:42px; height:42px; border-radius:10px; background:rgba(249,115,22,.12); display:flex; align-items:center; justify-content:center; font-weight:700; color:var(--accent); font-size:.9rem; flex-shrink:0; }
.client-name { font-size:.95rem; font-weight:600; }
.client-rubro { font-size:.72rem; color:var(--text-muted); }
.client-meta { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.client-tag { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(255,255,255,.05); border:1px solid var(--border); }
.client-tag-plan { background:rgba(56,189,248,.1); border-color:rgba(56,189,248,.2); color:#38bdf8; }
.client-tag-pago-pagado { background:rgba(34,197,94,.1); border-color:rgba(34,197,94,.2); color:#4ade80; }
.client-tag-pago-vencido { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.2); color:#f87171; }
.client-tag-pago-pendiente { background:rgba(234,179,8,.1); border-color:rgba(234,179,8,.2); color:#facc15; }
.client-tag-pago-canje { background:rgba(148,163,184,.1); border-color:rgba(148,163,184,.2); color:#94a3b8; }
.client-bottom { display:flex; justify-content:space-between; align-items:center; padding-top:10px; border-top:1px solid var(--border); }
.client-fee { font-size:1rem; font-weight:700; color:var(--success); }
.client-actions { display:flex; gap:4px; }
.client-actions .btn { padding:4px 8px; font-size:.72rem; }
.client-dash-link { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; color:var(--accent); padding:4px 8px; border-radius:4px; background:rgba(249,115,22,.08); border:1px solid rgba(249,115,22,.15); transition:all .15s; }
.client-dash-link:hover { background:rgba(249,115,22,.2); }

@media(max-width:768px) { .crm-kpis { grid-template-columns:repeat(2,1fr); } .crm-grid { grid-template-columns:1fr; } }
</style>

<div class="crm-kpis">
    <div class="crm-kpi" style="border-left:3px solid var(--accent)"><div class="crm-kpi-label">Clientes Activos</div><div class="crm-kpi-val"><?= $activos ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid var(--success)"><div class="crm-kpi-label">Suscripción</div><div class="crm-kpi-val" style="color:var(--success)"><?= format_money($fee_total) ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid <?= $vencidos ? 'var(--danger)' : 'var(--success)' ?>"><div class="crm-kpi-label">Pagos Vencidos</div><div class="crm-kpi-val <?= $vencidos ? 'danger' : '' ?>"><?= $vencidos ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid var(--text-muted)"><div class="crm-kpi-label">Total Clientes</div><div class="crm-kpi-val"><?= count($clientes) ?></div></div>
</div>

<div class="crm-toolbar">
    <input type="text" class="crm-search" id="crmSearch" placeholder="Buscar cliente..." oninput="filterClients()">
    <select class="form-select" style="font-size:.82rem;padding:6px 10px;" id="filterTipo" onchange="filterClients()">
        <option value="">Todos</option>
        <option value="activo" selected>Activos</option>
        <option value="inactivo">Inactivos</option>
        <option value="prospecto">Prospectos</option>
    </select>
    <?php if (can_edit($current_user['id'], 'crm')): ?>
        <button class="btn btn-primary btn-sm" onclick="openNewClient()">+ Nuevo Cliente</button>
    <?php endif; ?>
</div>

<div class="crm-grid" id="crmGrid">
    <?php foreach ($clientes as $c): ?>
    <div class="client-card" data-tipo="<?= $c['tipo'] ?>" data-nombre="<?= safe(strtolower($c['nombre'])) ?>" onclick="openClientDetail(<?= $c['id'] ?>)">
        <div class="client-card-top">
            <div class="client-avatar"><?= strtoupper(mb_substr($c['nombre'], 0, 2)) ?></div>
            <div>
                <div class="client-name"><?= safe($c['nombre']) ?></div>
                <div class="client-rubro"><?= safe($c['rubro'] ?: 'Sin rubro') ?></div>
            </div>
        </div>
        <div class="client-meta">
            <?php if ($c['plan']): ?>
                <span class="client-tag client-tag-plan"><?= safe($planes_labels[$c['plan']] ?? $c['plan']) ?></span>
            <?php endif; ?>
            <span class="client-tag client-tag-pago-<?= $c['estado_pago'] ?>"><?= ucfirst($c['estado_pago']) ?></span>
            <?php if ($c['etapa']): ?>
                <span class="client-tag"><?= safe($c['etapa']) ?></span>
            <?php endif; ?>
            <?php if ($c['tareas_pendientes'] > 0): ?>
                <span class="client-tag" style="color:var(--warning)"><?= $c['tareas_pendientes'] ?> tareas</span>
            <?php endif; ?>
        </div>
        <div class="client-bottom" onclick="event.stopPropagation()">
            <div class="client-fee"><?= $c['fee_mensual'] > 0 ? format_money($c['fee_mensual']) . '<span style="font-size:.7rem;font-weight:400;color:var(--text-muted)">/mes</span>' : '<span style="color:var(--text-muted);font-size:.82rem">-</span>' ?></div>
            <div class="client-actions">
                <?php if ($c['url_dashboard']): ?>
                    <a href="<?= safe($c['url_dashboard']) ?>" target="_blank" class="client-dash-link" title="Abrir dashboard del cliente">Dashboard &#8599;</a>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=<?= $c['id'] ?>','_blank')" title="Ficha PDF">PDF</button>
                <button class="btn btn-secondary btn-sm" onclick="window.open('servicio-pdf.php?id=<?= $c['id'] ?>','_blank')" title="Documento de servicio">Servicio</button>
                <?php if (can_edit($current_user['id'], 'crm')): ?>
                    <button class="btn btn-secondary btn-sm" onclick="editClient(<?= $c['id'] ?>)" title="Editar">Editar</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
const crmEquipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;
const planesOpts = {'':'Sin plan','growth':'Growth','scale':'Scale','starter':'Starter','meta_ads':'Meta Ads','google_ads':'Google Ads','full_ads':'Full Ads','full_ads_seo':'Full Ads + SEO','custom':'Custom'};

function filterClients() {
    const q = document.getElementById('crmSearch').value.toLowerCase();
    const tipo = document.getElementById('filterTipo').value;
    document.querySelectorAll('.client-card').forEach(card => {
        const matchNombre = !q || card.dataset.nombre.includes(q);
        const matchTipo = !tipo || card.dataset.tipo === tipo;
        card.style.display = (matchNombre && matchTipo) ? '' : 'none';
    });
}
// Init filter
filterClients();

async function openClientDetail(id) {
    const res = await API.get('get_client', { id });
    if (!res) return;
    const c = res.data;
    const ep = c.estado_pago === 'pagado' ? 'status-success' : (c.estado_pago === 'vencido' ? 'status-danger' : (c.estado_pago === 'canje' ? 'status-muted' : 'status-warning'));

    const mkTags = (str, color) => (str || '').split(',').map(s => s.trim()).filter(s => s).map(s =>
        `<span style="display:inline-block;font-size:.75rem;padding:3px 9px;border-radius:5px;background:rgba(${color},.08);border:1px solid rgba(${color},.18);margin:2px">${escHtml(s)}</span>`
    ).join('') || '<span style="color:var(--text-muted);font-size:.82rem">-</span>';

    const body = `
    <div style="display:grid;gap:18px;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
            <div style="background:var(--bg);padding:14px 16px;border-radius:10px;border-left:3px solid #38bdf8">
                <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Plan</div>
                <div style="font-size:1.05rem;font-weight:700;margin-top:3px">${escHtml(c.plan || 'Custom')}</div>
            </div>
            <div style="background:var(--bg);padding:14px 16px;border-radius:10px;border-left:3px solid var(--success)">
                <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Suscripción</div>
                <div style="font-size:1.05rem;font-weight:700;color:var(--success);margin-top:3px">${c.fee_mensual ? fmtMoney(c.fee_mensual) : '$0'}</div>
            </div>
            <div style="background:var(--bg);padding:14px 16px;border-radius:10px;border-left:3px solid ${c.estado_pago==='vencido'?'var(--danger)':c.estado_pago==='pagado'?'var(--success)':'var(--warning)'}">
                <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Estado Pago</div>
                <div style="margin-top:5px"><span class="badge ${ep}" style="font-size:.8rem">${escHtml(c.estado_pago || 'pendiente')}</span></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;">
            <div><span style="color:var(--text-muted)">Rubro:</span> ${escHtml(c.rubro || '-')}</div>
            <div><span style="color:var(--text-muted)">Etapa:</span> ${escHtml(c.etapa || '-')}</div>
            <div><span style="color:var(--text-muted)">Contacto:</span> ${escHtml(c.contacto_nombre || '-')}</div>
            <div><span style="color:var(--text-muted)">Responsable:</span> ${escHtml(c.responsable_nombre || '-')}</div>
            <div><span style="color:var(--text-muted)">Email:</span> ${escHtml(c.email || '-')}</div>
            <div><span style="color:var(--text-muted)">Teléfono:</span> ${escHtml(c.telefono || '-')}</div>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:.82rem;font-weight:600;margin-bottom:8px">Servicios contratados</div>
            <div>${mkTags(c.servicios, '34,197,94')}</div>
        </div>
        <div>
            <div style="font-size:.82rem;font-weight:600;margin-bottom:8px">Herramientas</div>
            <div>${mkTags(c.herramientas, '56,189,248')}</div>
        </div>
        ${c.presupuesto_ads ? '<div><div style="font-size:.82rem;font-weight:600;margin-bottom:6px">Presupuesto Ads</div><div style="font-size:.82rem;white-space:pre-line;background:var(--bg);padding:10px 14px;border-radius:8px">' + escHtml(c.presupuesto_ads) + '</div></div>' : ''}
        ${c.url_dashboard ? '<div><div style="font-size:.82rem;font-weight:600;margin-bottom:6px">Dashboard del cliente</div><a href="' + escHtml(c.url_dashboard) + '" target="_blank" class="client-dash-link" style="font-size:.82rem">Abrir dashboard &#8599;</a></div>' : ''}
        ${c.notas ? '<div style="border-top:1px solid var(--border);padding-top:12px"><div style="font-size:.82rem;font-weight:600;margin-bottom:4px">Notas</div><div style="font-size:.82rem;color:var(--text-muted);white-space:pre-line">' + escHtml(c.notas) + '</div></div>' : ''}
    </div>`;

    const footer = `
        <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=${c.id}','_blank')">Ficha PDF</button>
        <button class="btn btn-secondary btn-sm" onclick="window.open('servicio-pdf.php?id=${c.id}','_blank')">Doc. Servicio</button>
        ${c.url_dashboard ? '<a href="' + escHtml(c.url_dashboard) + '" target="_blank" class="btn btn-secondary btn-sm">Dashboard &#8599;</a>' : ''}
        ${APP.canEdit ? '<button class="btn btn-primary btn-sm" onclick="Modal.close();editClient('+c.id+')">Editar</button>' : ''}
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
        ${formField('contacto_nombre', 'Contacto', 'text', c.contacto_nombre)}
        ${formField('email', 'Email', 'email', c.email)}
        ${formField('telefono', 'Teléfono', 'text', c.telefono)}
        ${formField('plan', 'Plan', 'select', c.plan || '', {options: planesOpts})}
        ${formField('fee_mensual', 'Suscripción ($)', 'number', c.fee_mensual)}
        ${formField('estado_pago', 'Estado Pago', 'select', c.estado_pago || 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('tipo', 'Tipo', 'select', c.tipo, {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo', cerrado:'Cerrado'}})}
        ${formField('etapa_pipeline', 'Pipeline', 'select', c.etapa_pipeline, {options: {lead:'Lead', contactado:'Contactado', propuesta:'Propuesta', negociacion:'Negociación', onboarding:'Onboarding', activo:'Activo', cerrado_ganado:'Cerrado Ganado', cerrado_perdido:'Cerrado Perdido'}})}
        ${formField('responsable_id', 'Responsable', 'select', c.responsable_id || '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('etapa', 'Etapa operativa', 'text', c.etapa)}
        ${formField('servicios', 'Servicios (separados por coma)', 'textarea', c.servicios, {fullWidth: true})}
        ${formField('herramientas', 'Herramientas (separadas por coma)', 'textarea', c.herramientas, {fullWidth: true})}
        ${formField('presupuesto_ads', 'Presupuesto Ads', 'textarea', c.presupuesto_ads, {fullWidth: true})}
        ${formField('url_dashboard', 'URL Dashboard', 'text', c.url_dashboard, {fullWidth: true})}
        ${formField('notas', 'Notas', 'textarea', c.notas, {fullWidth: true})}
    </form>`;
    Modal.open('Editar: ' + c.nombre, body, `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button><button class="btn btn-primary" onclick="updateClient()">Guardar</button>`);
}

async function updateClient() {
    const data = getFormData('frmClient');
    const res = await API.post('update_client', data);
    if (res) { toast('Cliente actualizado'); refreshPage(); }
}

function openNewClient() {
    const body = `<form id="frmClient" class="form-grid">
        ${formField('nombre', 'Nombre', 'text', '', {required: true})}
        ${formField('rubro', 'Rubro', 'text')}
        ${formField('contacto_nombre', 'Contacto', 'text')}
        ${formField('email', 'Email', 'email')}
        ${formField('telefono', 'Teléfono', 'text')}
        ${formField('plan', 'Plan', 'select', '', {options: planesOpts})}
        ${formField('fee_mensual', 'Suscripción ($)', 'number')}
        ${formField('estado_pago', 'Estado Pago', 'select', 'pendiente', {options: {pendiente:'Pendiente', pagado:'Pagado', vencido:'Vencido', canje:'Canje'}})}
        ${formField('tipo', 'Tipo', 'select', 'activo', {options: {prospecto:'Prospecto', activo:'Activo', inactivo:'Inactivo'}})}
        ${formField('responsable_id', 'Responsable', 'select', '', {options: {'':'Sin asignar', ...crmEquipoList}})}
        ${formField('servicios', 'Servicios', 'textarea', '', {fullWidth: true})}
        ${formField('url_dashboard', 'URL Dashboard', 'text', '', {fullWidth: true})}
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
</script>
