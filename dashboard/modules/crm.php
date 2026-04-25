<?php
/**
 * Módulo CRM — Clientes Facand
 */
// Mes seleccionado
$meses_es = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes_sel = $_GET['mes'] ?? date('Y-m');
$mes_num = substr($mes_sel, 5, 2);
$mes_anio = substr($mes_sel, 0, 4);
$mes_label = ($meses_es[$mes_num] ?? $mes_num) . ' ' . $mes_anio;
$primer_dia = "$mes_sel-01";
$ultimo_dia = date('Y-m-t', strtotime($primer_dia));
$meses_selector = [];
for ($i = -6; $i <= 2; $i++) {
    $m = date('Y-m', strtotime("$i months"));
    $meses_selector[$m] = $meses_es[substr($m, 5)] . ' ' . substr($m, 0, 4);
}

// Servicios vigentes en el mes para subqueries
$sv_where = "AND sc.fecha_inicio <= '$ultimo_dia' AND (sc.fecha_fin IS NULL OR sc.fecha_fin >= '$primer_dia')";

$clientes = query_all("SELECT c.*, e.nombre as responsable_nombre,
    (SELECT COUNT(*) FROM tareas WHERE cliente_id = c.id AND estado IN ('pendiente','en_progreso')) as tareas_pendientes,
    (SELECT COALESCE(SUM(monto),0) FROM servicios_cliente sc WHERE sc.cliente_id = c.id AND sc.tipo = 'suscripcion' AND sc.estado = 'activo' $sv_where) as total_suscripcion,
    (SELECT COALESCE(SUM(monto),0) FROM servicios_cliente sc WHERE sc.cliente_id = c.id AND sc.tipo IN ('implementacion','adicional') AND sc.estado = 'activo' $sv_where) as total_implementacion,
    (SELECT COUNT(*) FROM servicios_cliente sc WHERE sc.cliente_id = c.id AND sc.estado = 'activo' $sv_where) as servicios_activos
    FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id
    WHERE c.created_at <= '$ultimo_dia'
    ORDER BY c.nombre");
$equipo_list = query_all('SELECT id, nombre FROM equipo WHERE activo = 1 ORDER BY nombre');
$activos = count(array_filter($clientes, fn($c) => $c['tipo'] === 'activo'));
// Suscripción total desde servicios_cliente (no fee_mensual)
$fee_total = array_sum(array_map(fn($c) => $c['tipo'] === 'activo' ? $c['total_suscripcion'] + $c['total_implementacion'] : 0, $clientes));
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

/* Tabla de clientes */
.table-container { overflow-x:auto; border-radius:10px; border:1px solid var(--border); }
.crm-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.crm-table thead tr { background:var(--bg); border-bottom:1px solid var(--border); }
.crm-table th { padding:10px 14px; text-align:left; font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); white-space:nowrap; }
.crm-table tbody tr { border-bottom:1px solid var(--border); transition:background .12s; cursor:pointer; }
.crm-table tbody tr:last-child { border-bottom:none; }
.crm-table tbody tr:hover { background:rgba(255,255,255,.03); }
.crm-table td { padding:10px 14px; vertical-align:middle; }
.crm-table .td-nombre { font-weight:600; color:var(--text); }
.crm-table .td-nombre:hover { color:var(--accent); text-decoration:underline; }
.crm-table .td-fee { font-weight:700; color:var(--success); white-space:nowrap; }
.crm-table .td-actions { white-space:nowrap; display:flex; gap:4px; align-items:center; }
.crm-table .td-actions .btn { padding:4px 9px; font-size:.7rem; }
.badge-plan { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(56,189,248,.1); border:1px solid rgba(56,189,248,.2); color:#38bdf8; white-space:nowrap; }
.badge-pago-pagado { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.2); color:#4ade80; }
.badge-pago-vencido { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.2); color:#f87171; }
.badge-pago-pendiente { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(234,179,8,.1); border:1px solid rgba(234,179,8,.2); color:#facc15; }
.badge-pago-canje { font-size:.68rem; padding:3px 8px; border-radius:4px; background:rgba(148,163,184,.1); border:1px solid rgba(148,163,184,.2); color:#94a3b8; }
.resp-select { font-size:.75rem; background:var(--bg); border:1px solid var(--border); border-radius:5px; color:var(--text); padding:3px 6px; cursor:pointer; max-width:130px; }
.resp-select:focus { border-color:var(--accent); outline:none; }
.client-dash-link { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; color:var(--accent); padding:4px 8px; border-radius:4px; background:rgba(249,115,22,.08); border:1px solid rgba(249,115,22,.15); transition:all .15s; }
.client-dash-link:hover { background:rgba(249,115,22,.2); }

@media(max-width:768px) { .crm-kpis { grid-template-columns:repeat(2,1fr); } }

/* Tabs CRM */
.crm-tabs { display:flex; gap:4px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:0; }
.crm-tab { padding:8px 18px; font-size:.85rem; font-weight:500; background:none; border:none; border-bottom:2px solid transparent; color:var(--text-muted); cursor:pointer; transition:all .15s; margin-bottom:-1px; }
.crm-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.crm-tab:hover:not(.active) { color:var(--text); }
.crm-tab-content { display:none; }
.crm-tab-content.active { display:block; }

/* Interacciones */
.interaction-item { padding:10px 0; border-bottom:1px solid var(--border); font-size:.82rem; }
.interaction-item:last-child { border-bottom:none; }
.interaction-tipo { display:inline-block; font-size:.68rem; padding:2px 7px; border-radius:4px; background:rgba(249,115,22,.1); border:1px solid rgba(249,115,22,.2); color:var(--accent); margin-right:6px; text-transform:uppercase; letter-spacing:.3px; }
.interaction-fecha { font-size:.7rem; color:var(--text-muted); }
.interaction-contenido { margin-top:4px; color:var(--text); }
.interaction-resultado { margin-top:2px; font-size:.78rem; color:var(--text-muted); font-style:italic; }
</style>

<?php
// Pipeline: solo etapas comerciales (sin activo — los activos ya son cerrado_ganado)
$pipeline_stages = ['lead'=>'Lead','contactado'=>'Contactado','propuesta'=>'Propuesta','negociacion'=>'Negociación','onboarding'=>'Onboarding'];
$clientes_por_etapa = [];
foreach ($pipeline_stages as $key => $_) $clientes_por_etapa[$key] = [];
foreach ($clientes as $c) {
    $etapa = $c['etapa_pipeline'] ?: '';
    if (isset($clientes_por_etapa[$etapa])) {
        $clientes_por_etapa[$etapa][] = $c;
    }
    // Si la etapa no está en el pipeline (ej: cerrado_ganado), no se muestra
}
?>

<!-- Selector de mes -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=crm&mes=<?= date('Y-m', strtotime("$mes_sel-01 -1 month")) ?>" class="btn btn-secondary btn-sm">← Anterior</a>
    <select class="form-select" style="font-size:.9rem;font-weight:600;min-width:180px;" onchange="location.href='?page=crm&mes='+this.value">
        <?php foreach ($meses_selector as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $mv === $mes_sel ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=crm&mes=<?= date('Y-m', strtotime("$mes_sel-01 +1 month")) ?>" class="btn btn-secondary btn-sm">Siguiente →</a>
</div>

<div class="crm-kpis">
    <div class="crm-kpi" style="border-left:3px solid var(--accent)"><div class="crm-kpi-label">Clientes Activos</div><div class="crm-kpi-val"><?= $activos ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid var(--success)"><div class="crm-kpi-label">Facturación Proyectada</div><div class="crm-kpi-val" style="color:var(--success)"><?= format_money($fee_total) ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid <?= $vencidos ? 'var(--danger)' : 'var(--success)' ?>"><div class="crm-kpi-label">Pagos Vencidos</div><div class="crm-kpi-val <?= $vencidos ? 'danger' : '' ?>"><?= $vencidos ?></div></div>
    <div class="crm-kpi" style="border-left:3px solid var(--text-muted)"><div class="crm-kpi-label">Total Clientes</div><div class="crm-kpi-val"><?= count($clientes) ?></div></div>
</div>

<div class="crm-tabs">
    <button class="crm-tab active" onclick="switchCrmTab('cards', this)">Lista de Clientes</button>
    <button class="crm-tab" onclick="switchCrmTab('pipeline', this)">Pipeline</button>
</div>

<!-- Vista Clientes (tabla) -->
<div id="crmTabCards" class="crm-tab-content active">
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

    <div class="table-container">
        <table class="crm-table" id="crmTable">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Rubro</th>
                    <th>Servicios</th>
                    <th>Pipeline</th>
                    <th>Pago</th>
                    <th>Responsable</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="crmTableBody">
            <?php foreach ($clientes as $c): ?>
            <tr data-tipo="<?= $c['tipo'] ?>" data-nombre="<?= safe(strtolower($c['nombre'])) ?>">
                <td onclick="openClientDetail(<?= $c['id'] ?>)">
                    <span class="td-nombre"><?= safe($c['nombre']) ?></span>
                    <?php if ($c['tareas_pendientes'] > 0): ?>
                        <span style="margin-left:6px;font-size:.65rem;padding:1px 6px;border-radius:10px;background:rgba(234,179,8,.15);border:1px solid rgba(234,179,8,.25);color:#facc15"><?= $c['tareas_pendientes'] ?> tarea<?= $c['tareas_pendientes'] > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted)"><?= safe($c['rubro'] ?: '—') ?></td>
                <td>
                    <?php
                    $svcs = query_all('SELECT tipo FROM servicios_cliente WHERE cliente_id = ? AND estado = "activo" ORDER BY tipo DESC', [$c['id']]);
                    if (!empty($svcs)):
                        $tipos_unicos = array_unique(array_column($svcs, 'tipo'));
                        foreach ($tipos_unicos as $t): ?>
                            <span class="badge-plan" style="font-size:.65rem;<?= $t !== 'suscripcion' ? 'background:rgba(234,179,8,.1);border-color:rgba(234,179,8,.2);color:#facc15;' : '' ?>"><?= $t === 'suscripcion' ? 'Suscripción' : 'Custom' ?></span>
                    <?php endforeach;
                    else: ?>
                        <span style="color:var(--text-muted);font-size:.72rem">—</span>
                    <?php endif; ?>
                </td>
                <td><?php
                    $etapa_labels = ['lead'=>'Lead','contactado'=>'Contactado','propuesta'=>'Propuesta','negociacion'=>'Negociación','onboarding'=>'Onboarding','activo'=>'Activo','cerrado_ganado'=>'Cerrado ✓','cerrado_perdido'=>'Cerrado ✗'];
                    $ep = $c['etapa_pipeline'] ?? '';
                    $ep_color = in_array($ep, ['activo','cerrado_ganado']) ? 'status-success' : (in_array($ep, ['onboarding','negociacion']) ? 'status-warning' : ($ep === 'cerrado_perdido' ? 'status-danger' : 'status-info'));
                ?><span class="badge <?= $ep_color ?>" style="font-size:.65rem"><?= $etapa_labels[$ep] ?? ucfirst($ep) ?></span></td>
                <td><span class="badge-pago-<?= safe($c['estado_pago']) ?>"><?= ucfirst($c['estado_pago']) ?></span></td>
                <td onclick="event.stopPropagation()">
                    <?php if (can_edit($current_user['id'], 'crm')): ?>
                        <select class="resp-select" onchange="changeResponsable(<?= $c['id'] ?>, this.value)" title="Cambiar responsable">
                            <option value="">Sin asignar</option>
                            <?php foreach ($equipo_list as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= $c['responsable_id'] == $e['id'] ? 'selected' : '' ?>><?= safe($e['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span style="font-size:.82rem"><?= safe($c['responsable_nombre'] ?: '—') ?></span>
                    <?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()">
                    <div class="td-actions">
                        <?php if ($c['url_dashboard']): ?>
                            <a href="<?= safe($c['url_dashboard']) ?>" target="_blank" class="client-dash-link" title="Abrir dashboard del cliente">&#8599;</a>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=<?= $c['id'] ?>','_blank')" title="Ficha PDF">Ficha PDF</button>
                        <?php if (can_edit($current_user['id'], 'crm')): ?>
                            <button class="btn btn-secondary btn-sm" onclick="editClient(<?= $c['id'] ?>)" title="Editar">Editar</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Vista Pipeline (kanban) -->
<div id="crmTabPipeline" class="crm-tab-content">
    <?php
    // Filtrar solo etapas con clientes
    $etapas_con_clientes = array_filter($clientes_por_etapa, fn($cards) => !empty($cards));
    if (empty($etapas_con_clientes)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">Todos los clientes están cerrados. No hay prospectos en el pipeline.</div>
    <?php else: ?>
    <div class="pipeline" style="grid-template-columns:repeat(<?= count($etapas_con_clientes) ?>, 1fr);">
        <?php foreach ($etapas_con_clientes as $stage_key => $cards): ?>
        <div class="pipeline-stage">
            <div class="pipeline-stage-header">
                <span class="pipeline-stage-name"><?= $pipeline_stages[$stage_key] ?? ucfirst($stage_key) ?></span>
                <span class="pipeline-stage-count"><?= count($cards) ?></span>
            </div>
            <?php foreach ($cards as $c): ?>
            <div class="pipeline-card" onclick="openClientDetail(<?= $c['id'] ?>)">
                <div class="pipeline-card-name"><?= safe($c['nombre']) ?></div>
                <div class="pipeline-card-sub"><?= safe($c['rubro'] ?: 'Sin rubro') ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const crmEquipoList = <?= json_encode(array_column($equipo_list, 'nombre', 'id')) ?>;
const planesOpts = {'':'Sin plan','growth':'Growth','scale':'Scale','starter':'Starter','meta_ads':'Meta Ads','google_ads':'Google Ads','full_ads':'Full Ads','full_ads_seo':'Full Ads + SEO','custom':'Custom'};

function switchCrmTab(tab, btn) {
    document.querySelectorAll('.crm-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.crm-tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('crmTab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
}

function filterClients() {
    const q = document.getElementById('crmSearch').value.toLowerCase();
    const tipo = document.getElementById('filterTipo').value;
    document.querySelectorAll('#crmTableBody tr').forEach(row => {
        const matchNombre = !q || row.dataset.nombre.includes(q);
        const matchTipo = !tipo || row.dataset.tipo === tipo;
        row.style.display = (matchNombre && matchTipo) ? '' : 'none';
    });
}
// Init filter
filterClients();

async function changeResponsable(clienteId, responsableId) {
    await API.post('update_client', { id: clienteId, responsable_id: responsableId });
    toast('Responsable actualizado');
}

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

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <div style="font-size:.82rem;font-weight:600">Interacciones</div>
                ${APP.canEdit ? '<button class="btn btn-secondary btn-sm" onclick="toggleInteractionForm(' + c.id + ')">+ Registrar</button>' : ''}
            </div>
            <div id="interactionForm_${c.id}" style="display:none;background:var(--bg);border-radius:8px;padding:14px;margin-bottom:12px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                    <div>
                        <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px">Tipo</label>
                        <select id="iType_${c.id}" class="form-select" style="font-size:.82rem;padding:6px 10px;width:100%">
                            <option value="llamada">Llamada</option>
                            <option value="email">Email</option>
                            <option value="reunion">Reunión</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="nota" selected>Nota</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px">Resultado</label>
                        <input type="text" id="iResultado_${c.id}" class="crm-search" placeholder="Resultado..." style="width:100%;padding:6px 10px;font-size:.82rem">
                    </div>
                </div>
                <div style="margin-bottom:8px">
                    <label style="font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:4px">Contenido</label>
                    <textarea id="iContenido_${c.id}" class="crm-search" rows="3" placeholder="Descripción de la interacción..." style="width:100%;padding:8px 10px;font-size:.82rem;resize:vertical"></textarea>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                    <button class="btn btn-secondary btn-sm" onclick="toggleInteractionForm(${c.id})">Cancelar</button>
                    <button class="btn btn-primary btn-sm" onclick="saveInteraction(${c.id})">Guardar</button>
                </div>
            </div>
            <div id="interactionList_${c.id}" style="font-size:.82rem;color:var(--text-muted)">Cargando...</div>
        </div>
    </div>`;

    const footer = `
        <button class="btn btn-secondary btn-sm" onclick="window.open('ficha-pdf.php?id=${c.id}','_blank')">Ficha PDF</button>
        ${c.url_dashboard ? '<a href="' + escHtml(c.url_dashboard) + '" target="_blank" class="btn btn-secondary btn-sm">Dashboard &#8599;</a>' : ''}
        ${APP.canEdit ? '<button class="btn btn-primary btn-sm" onclick="Modal.close();editClient('+c.id+')">Editar</button>' : ''}
        <button class="btn btn-secondary" onclick="Modal.close()">Cerrar</button>`;
    Modal.open(c.nombre, body, footer);

    // Cargar interacciones de forma asíncrona
    loadInteractions(c.id);
}

async function loadInteractions(clienteId) {
    const res = await API.get('get_interactions', { cliente_id: clienteId });
    const el = document.getElementById('interactionList_' + clienteId);
    if (!el) return;
    if (!res || !res.data || res.data.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:.8rem;padding:8px 0">Sin interacciones registradas.</div>';
        return;
    }
    const tipoColors = { llamada:'rgba(56,189,248,.15)', email:'rgba(167,139,250,.15)', reunion:'rgba(52,211,153,.15)', whatsapp:'rgba(74,222,128,.15)', nota:'rgba(249,115,22,.15)' };
    el.innerHTML = res.data.map(i => `
        <div class="interaction-item">
            <div style="display:flex;align-items:center;gap:6px">
                <span class="interaction-tipo" style="background:${tipoColors[i.tipo]||'rgba(255,255,255,.05)'}">${escHtml(i.tipo)}</span>
                <span class="interaction-fecha">${escHtml((i.fecha||'').slice(0,16).replace('T',' '))}</span>
                ${i.responsable_nombre ? '<span style="font-size:.7rem;color:var(--text-muted)">— '+escHtml(i.responsable_nombre)+'</span>' : ''}
            </div>
            <div class="interaction-contenido">${escHtml(i.contenido)}</div>
            ${i.resultado ? '<div class="interaction-resultado">Resultado: '+escHtml(i.resultado)+'</div>' : ''}
        </div>`).join('');
}

function toggleInteractionForm(clienteId) {
    const f = document.getElementById('interactionForm_' + clienteId);
    if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

async function saveInteraction(clienteId) {
    const tipo = document.getElementById('iType_' + clienteId)?.value || 'nota';
    const contenido = document.getElementById('iContenido_' + clienteId)?.value?.trim();
    const resultado = document.getElementById('iResultado_' + clienteId)?.value?.trim();
    if (!contenido) { toast('El contenido es obligatorio', 'error'); return; }
    const res = await API.post('create_interaction', { cliente_id: clienteId, tipo, contenido, resultado });
    if (res) {
        toast('Interacción guardada');
        toggleInteractionForm(clienteId);
        document.getElementById('iContenido_' + clienteId).value = '';
        document.getElementById('iResultado_' + clienteId).value = '';
        loadInteractions(clienteId);
    }
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
        ${formField('fee_mensual', 'Suscripción ($) — editar en Servicios', 'number', c.fee_mensual)}
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
