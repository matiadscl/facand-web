<?php
/**
 * Módulo Servicios + Presupuestos — Vista unificada
 * Pestaña 1: Servicios activos contratados por cliente
 * Pestaña 2: Presupuestos enviados a clientes
 */

// Auto-crear tabla presupuestos si no existe
$db = new SQLite3(__DIR__ . '/../data/dashboard.db');
$db->exec('CREATE TABLE IF NOT EXISTS presupuestos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id INTEGER NOT NULL,
    nombre TEXT NOT NULL,
    servicios_detalle TEXT DEFAULT "",
    monto_total INTEGER DEFAULT 0,
    estado TEXT DEFAULT "borrador",
    fecha_emision TEXT,
    fecha_validez TEXT,
    notas TEXT DEFAULT "",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
)');
$db->close();

$tab_activo = $_GET['tab'] ?? 'servicios';

$filtro_tipo    = $_GET['tipo_servicio'] ?? '';
$filtro_estado  = $_GET['estado_servicio'] ?? '';
$filtro_pres    = $_GET['estado_presupuesto'] ?? '';

// Mes seleccionado (default: mes actual)
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

// ---- Servicios (vigentes en el mes seleccionado por fechas) ----
// Un servicio es vigente en un mes si: fecha_inicio <= último día Y (fecha_fin IS NULL O fecha_fin >= primer día)
// El estado actual no importa para vigencia histórica (un servicio finalizado en mayo estuvo vigente en abril)
$vigente_where = "s.fecha_inicio <= '$ultimo_dia' AND (s.fecha_fin IS NULL OR s.fecha_fin >= '$primer_dia')";
$where = $vigente_where;
$params = [];
if ($filtro_tipo)   { $where .= ' AND s.tipo = ?';   $params[] = $filtro_tipo; }
if ($filtro_estado) { $where .= ' AND s.estado = ?'; $params[] = $filtro_estado; }

$servicios = query_all("SELECT s.*, c.nombre as cliente_nombre,
    CASE
        WHEN s.fecha_pausa IS NOT NULL AND s.fecha_pausa <= '$ultimo_dia'
             AND (s.fecha_reanudacion IS NULL OR s.fecha_reanudacion > '$ultimo_dia') THEN 'pausado'
        WHEN s.estado = 'cancelado' THEN 'cancelado'
        ELSE 'activo'
    END as estado_mes
    FROM servicios_cliente s
    LEFT JOIN clientes c ON s.cliente_id = c.id
    WHERE $where
    ORDER BY s.estado = 'activo' DESC, s.updated_at DESC", $params);

// ---- Presupuestos ----
$where_pres = '1=1';
$params_pres = [];
if ($filtro_pres) { $where_pres .= ' AND p.estado = ?'; $params_pres[] = $filtro_pres; }

$presupuestos = query_all("SELECT p.*, c.nombre as cliente_nombre
    FROM presupuestos p
    LEFT JOIN clientes c ON p.cliente_id = c.id
    WHERE $where_pres
    ORDER BY p.created_at DESC", $params_pres);

$clientes_list = query_all('SELECT id, nombre FROM clientes WHERE tipo IN ("activo","prospecto") ORDER BY nombre');

// KPIs servicios — vigencia por fechas, no por estado actual
$mes_where = "fecha_inicio <= '$ultimo_dia' AND (fecha_fin IS NULL OR fecha_fin >= '$primer_dia')";
$total_activos     = query_scalar("SELECT COUNT(*) FROM servicios_cliente WHERE $mes_where AND estado != 'cancelado'") ?? 0;
$ingreso_total     = query_scalar("SELECT COALESCE(SUM(monto),0) FROM servicios_cliente WHERE $mes_where AND estado != 'cancelado'") ?? 0;
$total_pausados    = query_scalar("SELECT COUNT(*) FROM servicios_cliente WHERE estado = 'pausado' AND $mes_where") ?? 0;
$ingreso_pausado   = query_scalar("SELECT COALESCE(SUM(monto),0) FROM servicios_cliente WHERE estado = 'pausado' AND $mes_where") ?? 0;

// KPIs presupuestos
$pres_enviados  = query_scalar('SELECT COUNT(*) FROM presupuestos WHERE estado = "enviado"') ?? 0;
$pres_aceptados = query_scalar('SELECT COUNT(*) FROM presupuestos WHERE estado = "aceptado"') ?? 0;
$pres_monto     = query_scalar('SELECT COALESCE(SUM(monto_total),0) FROM presupuestos WHERE estado = "aceptado"') ?? 0;

// Reporte por tipo
$por_tipo = query_all('SELECT tipo, estado, COUNT(*) as cantidad, COALESCE(SUM(monto),0) as total_monto FROM servicios_cliente GROUP BY tipo, estado ORDER BY tipo, estado');

// Facturación proyectada por mes (próximos 3 meses)
// Suscripciones: recurrentes cada mes (si fecha_inicio <= mes)
// Implementaciones: solo en su mes de inicio (puntuales)
$meses_proyeccion = [];
for ($i = 0; $i < 3; $i++) {
    $mes = date('Y-m', strtotime("+$i months"));
    $mes_fin = date('Y-m-t', strtotime("+$i months"));
    $mes_ini = $mes . '-01';
    $mes_label = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][(int)date('m', strtotime("+$i months")) - 1] . ' ' . date('Y', strtotime("+$i months"));

    // Suscripciones activas o que inician ese mes o antes
    $subs = query_scalar("SELECT COALESCE(SUM(monto),0) FROM servicios_cliente WHERE tipo = 'suscripcion' AND estado IN ('activo','pausado') AND (fecha_inicio IS NULL OR fecha_inicio <= '$mes_fin')", []) ?? 0;
    // Implementaciones: solo si su fecha_inicio cae en este mes
    $impl = query_scalar("SELECT COALESCE(SUM(monto),0) FROM servicios_cliente WHERE tipo IN ('implementacion','adicional') AND estado = 'activo' AND fecha_inicio >= '$mes_ini' AND fecha_inicio <= '$mes_fin'", []) ?? 0;

    // Desglose por cliente para el modal
    $desglose = query_all("SELECT s.nombre as servicio, s.tipo, s.monto, c.nombre as cliente
        FROM servicios_cliente s JOIN clientes c ON s.cliente_id = c.id
        WHERE s.estado IN ('activo','pausado')
        AND ((s.tipo = 'suscripcion' AND (s.fecha_inicio IS NULL OR s.fecha_inicio <= '$mes_fin'))
          OR (s.tipo IN ('implementacion','adicional') AND s.fecha_inicio >= '$mes_ini' AND s.fecha_inicio <= '$mes_fin'))
        ORDER BY s.tipo DESC, s.monto DESC", []);

    $meses_proyeccion[] = ['mes' => $mes, 'label' => $mes_label, 'monto' => $subs + $impl, 'subs' => $subs, 'impl' => $impl, 'desglose' => $desglose];
}
?>

<!-- Selector de mes -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=services&tab=<?= $tab_activo ?>&mes=<?= date('Y-m', strtotime("$mes_sel-01 -1 month")) ?>" class="btn btn-secondary btn-sm">← Anterior</a>
    <select class="form-select" style="font-size:.9rem;font-weight:600;min-width:180px;" onchange="location.href='?page=services&tab=<?= $tab_activo ?>&mes='+this.value">
        <?php foreach ($meses_selector as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $mv === $mes_sel ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=services&tab=<?= $tab_activo ?>&mes=<?= date('Y-m', strtotime("$mes_sel-01 +1 month")) ?>" class="btn btn-secondary btn-sm">Siguiente →</a>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="border-left:3px solid var(--primary)"><div class="kpi-label">Servicios Activos</div><div class="kpi-value"><?= $total_activos ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--success)"><div class="kpi-label">Ingreso Total Activo</div><div class="kpi-value success"><?= format_money($ingreso_total) ?></div></div>
    <div class="kpi-card" style="border-left:3px solid var(--warning)"><div class="kpi-label">Próximos (pausados)</div><div class="kpi-value"><?= $total_pausados ?></div><div class="kpi-sub"><?= format_money($ingreso_pausado) ?> al activarse</div></div>
    <div class="kpi-card" style="border-left:3px solid var(--text-muted)"><div class="kpi-label">Presup. Aceptados</div><div class="kpi-value success"><?= $pres_aceptados ?></div><div class="kpi-sub"><?= format_money($pres_monto) ?></div></div>
</div>

<!-- Proyección por mes (click para desglose) -->
<div style="display:flex;gap:14px;margin-bottom:20px;">
    <?php foreach ($meses_proyeccion as $idx => $mp): ?>
    <div style="flex:1;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;cursor:pointer;transition:border-color .15s;" onclick="showDesglose(<?= $idx ?>)" title="Click para ver desglose">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;"><?= $mp['label'] ?></div>
        <div style="font-size:1.3rem;font-weight:700;color:var(--success);margin-top:4px;"><?= format_money($mp['monto']) ?></div>
        <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;">
            <?php if ($mp['impl'] > 0): ?>
                <?= format_money($mp['subs']) ?> suscr. + <?= format_money($mp['impl']) ?> impl.
            <?php else: ?>
                <?= format_money($mp['subs']) ?> suscripciones
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
const desgloseData = <?= json_encode(array_map(fn($m) => ['label' => $m['label'], 'monto' => $m['monto'], 'desglose' => $m['desglose']], $meses_proyeccion)) ?>;

function showDesglose(idx) {
    const m = desgloseData[idx];
    let html = '<table style="width:100%;font-size:.85rem;border-collapse:collapse;">';
    html += '<thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.75rem;">Cliente</th><th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.75rem;">Servicio</th><th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.75rem;">Tipo</th><th style="text-align:right;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.75rem;">Monto</th></tr></thead><tbody>';
    m.desglose.forEach(d => {
        const tipoLabels = {suscripcion:'Suscripción', implementacion:'Implementación', adicional:'Adicional'};
        const tipoColors = {suscripcion:'var(--accent)', implementacion:'#facc15', adicional:'#38bdf8'};
        const tipo = `<span style="color:${tipoColors[d.tipo] || 'var(--text-muted)'};font-size:.72rem;">${tipoLabels[d.tipo] || d.tipo}</span>`;
        html += `<tr><td style="padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05);">${escHtml(d.cliente)}</td><td style="padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05);">${escHtml(d.servicio)}</td><td style="padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05);">${tipo}</td><td style="padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05);text-align:right;font-weight:600;">${fmtMoney(d.monto)}</td></tr>`;
    });
    html += `</tbody><tfoot><tr><td colspan="3" style="padding:8px;font-weight:700;border-top:2px solid var(--border);">Total</td><td style="padding:8px;font-weight:700;text-align:right;border-top:2px solid var(--border);color:var(--success);">${fmtMoney(m.monto)}</td></tr></tfoot></table>`;
    Modal.open('Desglose ' + m.label, html, '<button class="btn btn-secondary" onclick="Modal.close()">Cerrar</button>');
}
</script>

<!-- Tabs -->
<div style="display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid var(--border);">
    <a href="?page=services&tab=servicios&tipo_servicio=<?= $filtro_tipo ?>&estado_servicio=<?= $filtro_estado ?>"
       style="padding:8px 20px;font-weight:600;font-size:.9rem;text-decoration:none;border-radius:6px 6px 0 0;
              <?= $tab_activo !== 'presupuestos' ? 'background:var(--primary);color:#fff;' : 'color:var(--text-muted);' ?>">
        Servicios Activos
    </a>
    <a href="?page=services&tab=presupuestos&estado_presupuesto=<?= $filtro_pres ?>"
       style="padding:8px 20px;font-weight:600;font-size:.9rem;text-decoration:none;border-radius:6px 6px 0 0;
              <?= $tab_activo === 'presupuestos' ? 'background:var(--primary);color:#fff;' : 'color:var(--text-muted);' ?>">
        Presupuestos
    </a>
</div>

<?php if ($tab_activo !== 'presupuestos'): ?>
<!-- ========== TAB SERVICIOS ACTIVOS ========== -->
<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=services&tab=servicios&tipo_servicio='+this.value+'&estado_servicio=<?= $filtro_estado ?>'">
        <option value="">Todos los tipos</option>
        <option value="suscripcion"   <?= $filtro_tipo === 'suscripcion'   ? 'selected' : '' ?>>Suscripción</option>
        <option value="implementacion"<?= $filtro_tipo === 'implementacion' ? 'selected' : '' ?>>Implementación</option>
        <option value="adicional"     <?= $filtro_tipo === 'adicional'      ? 'selected' : '' ?>>Adicional</option>
    </select>
    <select class="form-select" onchange="location.href='?page=services&tab=servicios&tipo_servicio=<?= $filtro_tipo ?>&estado_servicio='+this.value">
        <option value="">Todos los estados</option>
        <option value="activo"     <?= $filtro_estado === 'activo'     ? 'selected' : '' ?>>Activo</option>
        <option value="pausado"    <?= $filtro_estado === 'pausado'    ? 'selected' : '' ?>>Pausado</option>
        <option value="finalizado" <?= $filtro_estado === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
        <option value="cancelado"  <?= $filtro_estado === 'cancelado'  ? 'selected' : '' ?>>Cancelado</option>
    </select>
</div>

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
                    <?php $est = $s['estado_mes']; ?>
                    <td><span class="badge <?= $est === 'activo' ? 'status-success' : ($est === 'pausado' ? 'status-warning' : ($est === 'cancelado' ? 'status-danger' : 'status-muted')) ?>"><?= ucfirst($est) ?></span></td>
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

<!-- Resumen por tipo (debajo de la tabla) -->
<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:16px;">
    <?php
    $tipos_resumen = [];
    foreach ($por_tipo as $pt) { $tipos_resumen[$pt['tipo']][] = $pt; }
    foreach ($tipos_resumen as $tipo => $estados): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 18px;flex:1;min-width:200px;">
        <div style="font-weight:600;margin-bottom:6px;"><?= $tipo === 'suscripcion' ? 'Suscripciones' : ($tipo === 'implementacion' ? 'Implementaciones' : 'Adicionales') ?></div>
        <?php foreach ($estados as $e): ?>
        <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:.82rem;">
            <span><span class="badge <?= $e['estado'] === 'activo' ? 'status-success' : ($e['estado'] === 'pausado' ? 'status-warning' : 'status-muted') ?>"><?= ucfirst($e['estado']) ?></span> <?= $e['cantidad'] ?></span>
            <span style="font-weight:600"><?= format_money($e['total_monto']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ========== TAB PRESUPUESTOS ========== -->
<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=services&tab=presupuestos&estado_presupuesto='+this.value">
        <option value="">Todos los estados</option>
        <option value="borrador"  <?= $filtro_pres === 'borrador'  ? 'selected' : '' ?>>Borrador</option>
        <option value="enviado"   <?= $filtro_pres === 'enviado'   ? 'selected' : '' ?>>Enviado</option>
        <option value="aceptado"  <?= $filtro_pres === 'aceptado'  ? 'selected' : '' ?>>Aceptado</option>
        <option value="rechazado" <?= $filtro_pres === 'rechazado' ? 'selected' : '' ?>>Rechazado</option>
    </select>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Presupuestos</span>
        <?php if (can_edit($current_user['id'], 'services')): ?>
            <button class="btn btn-primary btn-sm" onclick="openNewPresupuesto()">+ Nuevo Presupuesto</button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Cliente</th>
                <th>Servicios</th>
                <th>Monto Total</th>
                <th>Estado</th>
                <th>Emisión</th>
                <th>Válido hasta</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($presupuestos as $pr):
            $badge_pres = match($pr['estado']) {
                'aceptado'  => 'status-success',
                'enviado'   => 'status-info',
                'rechazado' => 'status-danger',
                default     => 'status-muted',
            };
            $vencido = $pr['estado'] === 'enviado' && $pr['fecha_validez'] && strtotime($pr['fecha_validez']) < time();
        ?>
            <tr>
                <td><strong><?= safe($pr['nombre']) ?></strong></td>
                <td><?= safe($pr['cliente_nombre']) ?></td>
                <td style="font-size:.8rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= safe($pr['servicios_detalle']) ?></td>
                <td style="font-weight:600"><?= format_money($pr['monto_total']) ?></td>
                <td><span class="badge <?= $badge_pres ?>"><?= ucfirst($pr['estado']) ?></span></td>
                <td style="font-size:.82rem"><?= $pr['fecha_emision'] ? format_date($pr['fecha_emision']) : '-' ?></td>
                <td style="font-size:.82rem;<?= $vencido ? 'color:var(--danger);font-weight:600' : '' ?>"><?= $pr['fecha_validez'] ? format_date($pr['fecha_validez']) : '-' ?></td>
                <td>
                    <?php if (can_edit($current_user['id'], 'services')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="editPresupuesto(<?= $pr['id'] ?>)">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($presupuestos)): ?>
            <tr><td colspan="8" class="empty-state">No hay presupuestos. Usa "+ Nuevo Presupuesto" para crear uno.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const sClientesList = <?= json_encode(array_column($clientes_list, 'nombre', 'id')) ?>;

// ---- Servicios ----
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
        <div class="form-group full-width" style="border-top:1px solid var(--border);padding-top:12px;margin-top:4px;">
            <label class="form-label" style="font-size:.75rem;color:var(--text-muted);">Período de pausa (opcional)</label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group"><label class="form-label">Pausado desde</label><input type="date" name="fecha_pausa" class="form-input" value="${s.fecha_pausa || ''}"></div>
            <div class="form-group"><label class="form-label">Reanuda en</label><input type="date" name="fecha_reanudacion" class="form-input" value="${s.fecha_reanudacion || ''}"></div>
        </div>
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

// ---- Presupuestos ----
function openNewPresupuesto() {
    const today = new Date().toISOString().split('T')[0];
    const validity = new Date(Date.now() + 30*24*60*60*1000).toISOString().split('T')[0];
    const body = `<form id="frmPresupuesto" class="form-grid">
        ${formField('cliente_id', 'Cliente', 'select', '', {required: true, options: sClientesList})}
        ${formField('nombre', 'Nombre / título del presupuesto', 'text', '', {required: true, fullWidth: true})}
        ${formField('servicios_detalle', 'Servicios incluidos', 'textarea', '', {fullWidth: true})}
        ${formField('monto_total', 'Monto total ($)', 'number', '', {required: true})}
        ${formField('estado', 'Estado', 'select', 'borrador', {options: {borrador:'Borrador', enviado:'Enviado', aceptado:'Aceptado', rechazado:'Rechazado'}})}
        ${formField('fecha_emision', 'Fecha de emisión', 'date', today)}
        ${formField('fecha_validez', 'Válido hasta', 'date', validity)}
        ${formField('notas', 'Notas internas', 'textarea', '', {fullWidth: true})}
    </form>`;
    Modal.open('Nuevo Presupuesto', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="savePresupuesto()">Guardar</button>`);
}

async function savePresupuesto() {
    const data = getFormData('frmPresupuesto');
    const res = await API.post('create_presupuesto', data);
    if (res) { toast('Presupuesto creado'); refreshPage(); }
}

async function editPresupuesto(id) {
    const res = await API.get('get_presupuesto', { id });
    if (!res) return;
    const p = res.data;
    const body = `<form id="frmPresupuesto" class="form-grid">
        <input type="hidden" name="id" value="${p.id}">
        ${formField('cliente_id', 'Cliente', 'select', p.cliente_id, {options: sClientesList})}
        ${formField('nombre', 'Nombre / título del presupuesto', 'text', p.nombre, {fullWidth: true})}
        ${formField('servicios_detalle', 'Servicios incluidos', 'textarea', p.servicios_detalle, {fullWidth: true})}
        ${formField('monto_total', 'Monto total ($)', 'number', p.monto_total)}
        ${formField('estado', 'Estado', 'select', p.estado, {options: {borrador:'Borrador', enviado:'Enviado', aceptado:'Aceptado', rechazado:'Rechazado'}})}
        ${formField('fecha_emision', 'Fecha de emisión', 'date', p.fecha_emision || '')}
        ${formField('fecha_validez', 'Válido hasta', 'date', p.fecha_validez || '')}
        ${formField('notas', 'Notas internas', 'textarea', p.notas, {fullWidth: true})}
    </form>`;
    Modal.open('Editar Presupuesto', body,
        `<button class="btn btn-secondary" onclick="Modal.close()">Cancelar</button>
         <button class="btn btn-primary" onclick="updatePresupuesto()">Actualizar</button>`);
}

async function updatePresupuesto() {
    const data = getFormData('frmPresupuesto');
    const res = await API.post('update_presupuesto', data);
    if (res) { toast('Presupuesto actualizado'); refreshPage(); }
}
</script>
