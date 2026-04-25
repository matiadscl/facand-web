<?php
/**
 * Módulo Marketing — Campañas vinculadas a clientes
 * Interconexión: clientes, finanzas (gasto publicitario)
 */

$campanas = query_all('SELECT ca.*, c.nombre as cliente_nombre
    FROM campanas ca
    LEFT JOIN clientes c ON ca.cliente_id = c.id
    ORDER BY ca.updated_at DESC');

$clientes_list = query_all('SELECT id, nombre FROM clientes ORDER BY nombre');

$activas = count(array_filter($campanas, fn($c) => $c['estado'] === 'activa'));
$presupuesto_total = array_sum(array_column(array_filter($campanas, fn($c) => $c['estado'] === 'activa'), 'presupuesto'));
$gasto_total = array_sum(array_column(array_filter($campanas, fn($c) => $c['estado'] === 'activa'), 'gasto_actual'));
$total_conversiones = array_sum(array_column($campanas, 'conversiones'));
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Campañas Activas</div>
        <div class="kpi-value"><?= $activas ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Presupuesto Activo</div>
        <div class="kpi-value"><?= format_money($presupuesto_total) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Gasto Total</div>
        <div class="kpi-value warning"><?= format_money($gasto_total) ?></div>
        <div class="kpi-sub"><?= $presupuesto_total > 0 ? round($gasto_total / $presupuesto_total * 100) : 0 ?>% del presupuesto</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Conversiones Totales</div>
        <div class="kpi-value success"><?= $total_conversiones ?></div>
    </div>
</div>

<p class="text-muted" style="margin-bottom:1rem;font-size:.85rem;">💡 Los datos de campañas se ingresan manualmente. Integración con APIs de Google Ads y Meta Ads pendiente.</p>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Campañas</span>
        <?php if (can_edit($current_user['id'], 'marketing')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewCampaign()">+ Nueva Campaña</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Campaña</th>
                <th>Cliente</th>
                <th>Plataforma</th>
                <th>Estado</th>
                <th>Presupuesto</th>
                <th>Gasto</th>
                <th>Impresiones</th>
                <th>Clics</th>
                <th>Conversiones</th>
                <th>CPC</th>
                <th>CTR</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($campanas as $ca):
                $ctr = $ca['impresiones'] > 0 ? round($ca['clics'] / $ca['impresiones'] * 100, 2) : 0;
                $cpc = $ca['clics'] > 0 ? round($ca['gasto_actual'] / $ca['clics']) : 0;
            ?>
            <tr>
                <td><strong><?= safe($ca['nombre']) ?></strong></td>
                <td><?= safe($ca['cliente_nombre']) ?></td>
                <td><span class="badge status-info"><?= safe(ucfirst($ca['plataforma'])) ?></span></td>
                <td><span class="badge <?= status_class($ca['estado'] === 'activa' ? 'activo' : $ca['estado']) ?>"><?= safe(ucfirst($ca['estado'])) ?></span></td>
                <td><?= format_money($ca['presupuesto']) ?></td>
                <td>
                    <?= format_money($ca['gasto_actual']) ?>
                    <?php if ($ca['presupuesto'] > 0): ?>
                        <div class="progress-bar" style="width:60px; margin-top:4px;">
                            <div class="progress-fill <?= $ca['gasto_actual'] > $ca['presupuesto'] ? 'danger' : '' ?>" style="width:<?= min(100, round($ca['gasto_actual'] / $ca['presupuesto'] * 100)) ?>%"></div>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= number_format($ca['impresiones']) ?></td>
                <td><?= number_format($ca['clics']) ?></td>
                <td><strong><?= $ca['conversiones'] ?></strong></td>
                <td><?= format_money($cpc) ?></td>
                <td><?= $ctr ?>%</td>
                <td>
                    <?php if (can_edit($current_user['id'], 'marketing')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="editCampaign(<?= $ca['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($campanas)): ?>
            <tr><td colspan="12" class="empty-state">No hay campañas</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const mClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

function openNewCampaign() {
    const body = `<form id="frmCampaign" class="form-grid">
        ${formField('nombre', 'Nombre Campaña', 'text', '', {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: mClientesList})}
        ${formField('plataforma', 'Plataforma', 'select', 'meta', {options: {meta:'Meta Ads', google:'Google Ads', email:'Email', otro:'Otro'}})}
        ${formField('estado', 'Estado', 'select', 'activa', {options: {borrador:'Borrador', activa:'Activa', pausada:'Pausada', finalizada:'Finalizada'}})}
        ${formField('presupuesto', 'Presupuesto', 'number', '')}
        ${formField('fecha_inicio', 'Fecha Inicio', 'date', new Date().toISOString().split('T')[0])}
        ${formField('fecha_fin', 'Fecha Fin', 'date')}
        ${formField('notas', 'Notas', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nueva Campaña', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="saveCampaign()">Guardar</button>`);
}

async function saveCampaign() {
    const res = await API.post('create_campaign', getFormData('frmCampaign'));
    if (res) { toast('Campaña creada'); refreshPage(); }
}

async function editCampaign(id) {
    const res = await API.get('get_campaign', { id });
    if (!res) return;
    const ca = res.data;
    const body = `<form id="frmCampaign" class="form-grid">
        <input type="hidden" name="id" value="${ca.id}">
        ${formField('nombre', 'Nombre', 'text', ca.nombre, {required: true})}
        ${formField('cliente_id', 'Cliente', 'select', ca.cliente_id, {options: mClientesList})}
        ${formField('plataforma', 'Plataforma', 'select', ca.plataforma, {options: {meta:'Meta Ads', google:'Google Ads', email:'Email', otro:'Otro'}})}
        ${formField('estado', 'Estado', 'select', ca.estado, {options: {borrador:'Borrador', activa:'Activa', pausada:'Pausada', finalizada:'Finalizada'}})}
        ${formField('presupuesto', 'Presupuesto', 'number', ca.presupuesto)}
        ${formField('gasto_actual', 'Gasto Actual', 'number', ca.gasto_actual)}
        ${formField('impresiones', 'Impresiones', 'number', ca.impresiones)}
        ${formField('clics', 'Clics', 'number', ca.clics)}
        ${formField('conversiones', 'Conversiones', 'number', ca.conversiones)}
        ${formField('fecha_inicio', 'Fecha Inicio', 'date', ca.fecha_inicio || '')}
        ${formField('fecha_fin', 'Fecha Fin', 'date', ca.fecha_fin || '')}
        ${formField('notas', 'Notas', 'textarea', ca.notas, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Campaña', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updateCampaign()">Actualizar</button>`);
}

async function updateCampaign() {
    const res = await API.post('update_campaign', getFormData('frmCampaign'));
    if (res) { toast('Campaña actualizada'); refreshPage(); }
}
</script>
