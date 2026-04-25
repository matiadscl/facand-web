<?php
/**
 * Módulo Home — Vista panorámica con KPIs, alertas y actividad
 */
?>
<style>
@media (max-width: 768px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
</style>
<?php

$total_clientes    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo"') ?? 0;
$fee_mensual_total = query_scalar('SELECT COALESCE(SUM(fee_mensual),0) FROM clientes WHERE tipo = "activo" AND estado_pago != "canje"') ?? 0;
$pagos_pendientes  = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND estado_pago IN ("pendiente","vencido") AND fee_mensual > 0') ?? 0;
$pagos_vencidos    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido"') ?? 0;
$tareas_pendientes = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso")') ?? 0;
$tareas_atrasadas  = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso") AND fecha_limite < date("now") AND fecha_limite IS NOT NULL') ?? 0;
$facturado_mes     = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = strftime("%Y-%m", "now") AND estado != "anulada"') ?? 0;
$cobrado_mes       = query_scalar('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE strftime("%Y-%m", fecha) = strftime("%Y-%m", "now")') ?? 0;
$por_cobrar        = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial")') ?? 0;
$proyectos_activos = query_scalar('SELECT COUNT(*) FROM proyectos WHERE estado = "activo"') ?? 0;
$clientes_nuevos   = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND strftime("%Y-%m", created_at) = strftime("%Y-%m", "now")') ?? 0;
$clientes_pausados = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "inactivo"') ?? 0;
$clientes_perdidos = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "cerrado"') ?? 0;

// Chart data: facturación últimos 6 meses
$fact_6meses_raw = query_all("SELECT strftime('%Y-%m', fecha_emision) as mes, SUM(total) as total FROM facturas WHERE estado != 'anulada' GROUP BY mes ORDER BY mes DESC LIMIT 6");
$fact_6meses = array_reverse($fact_6meses_raw);

// Chart data: clientes por etapa pipeline
$clientes_por_etapa = query_all("SELECT etapa_pipeline, COUNT(*) as total FROM clientes WHERE tipo IN ('activo','prospecto') AND etapa_pipeline IS NOT NULL AND etapa_pipeline != '' GROUP BY etapa_pipeline ORDER BY total DESC");

// Alertas
$alertas = [];
$clientes_vencidos = query_all('SELECT nombre, fee_mensual FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido" ORDER BY fee_mensual DESC LIMIT 10');
foreach ($clientes_vencidos as $cv) {
    $alertas[] = ['tipo' => 'danger', 'icono' => '!', 'texto' => "Pago vencido: <strong>{$cv['nombre']}</strong> — " . format_money($cv['fee_mensual'])];
}
$tareas_atr = query_all('SELECT t.titulo, t.fecha_limite, c.nombre as cliente FROM tareas t LEFT JOIN clientes c ON t.cliente_id = c.id WHERE t.estado IN ("pendiente","en_progreso") AND t.fecha_limite < date("now") AND t.fecha_limite IS NOT NULL ORDER BY t.fecha_limite LIMIT 5');
foreach ($tareas_atr as $ta) {
    $dias = days_overdue($ta['fecha_limite']);
    $alertas[] = ['tipo' => 'warning', 'icono' => '!', 'texto' => "Tarea atrasada: <strong>{$ta['titulo']}</strong> ({$ta['cliente']}) — {$dias} días"];
}
$cuentas_venc = query_all('SELECT cc.monto_pendiente, cc.fecha_vencimiento, c.nombre as cliente FROM cuentas_cobrar cc JOIN clientes c ON cc.cliente_id = c.id WHERE cc.estado IN ("pendiente","parcial") AND cc.fecha_vencimiento < date("now") AND cc.fecha_vencimiento IS NOT NULL ORDER BY cc.fecha_vencimiento LIMIT 5');
foreach ($cuentas_venc as $cv) {
    $dias = days_overdue($cv['fecha_vencimiento']);
    $alertas[] = ['tipo' => 'danger', 'icono' => '$', 'texto' => "CxC vencida: <strong>{$cv['cliente']}</strong> — " . format_money($cv['monto_pendiente']) . " ({$dias} días)"];
}

// Clientes recientes
$clientes_recientes = query_all('SELECT nombre, plan, fee_mensual, etapa, estado_pago FROM clientes WHERE tipo = "activo" ORDER BY updated_at DESC LIMIT 8');

// Actividad
$actividad = query_all('SELECT a.*, u.nombre as usuario FROM actividad a LEFT JOIN usuarios u ON a.usuario_id = u.id ORDER BY a.created_at DESC LIMIT 10');

$meses_es = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
?>

<!-- KPI Row 1 — 5 cards principales -->
<div class="kpi-grid" style="grid-template-columns: repeat(5, 1fr);">
    <a href="?page=billing" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--accent);cursor:pointer;">
            <div class="kpi-label">Facturación Proyectada</div>
            <div class="kpi-value" style="color: var(--accent);"><?= format_money($fee_mensual_total) ?></div>
            <div class="kpi-sub"><?= $total_clientes ?> clientes activos</div>
        </div>
    </a>
    <a href="?page=receivables" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--success);cursor:pointer;">
            <div class="kpi-label">Cobrado Este Mes</div>
            <div class="kpi-value success"><?= format_money($cobrado_mes) ?></div>
            <div class="kpi-sub">Facturado: <?= format_money($facturado_mes) ?></div>
        </div>
    </a>
    <a href="?page=receivables" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid <?= $por_cobrar > 0 ? 'var(--warning)' : 'var(--success)' ?>;cursor:pointer;">
            <div class="kpi-label">Por Cobrar</div>
            <div class="kpi-value <?= $por_cobrar > 0 ? 'warning' : 'success' ?>"><?= format_money($por_cobrar) ?></div>
            <div class="kpi-sub"><?= $pagos_pendientes ?> pendientes<?= $pagos_vencidos > 0 ? " · <span style='color:var(--danger)'>{$pagos_vencidos} vencidos</span>" : '' ?></div>
        </div>
    </a>
    <a href="?page=crm" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--text-muted);cursor:pointer;">
            <div class="kpi-label">Clientes Activos</div>
            <div class="kpi-value"><?= $total_clientes ?></div>
            <div class="kpi-sub"><?= $proyectos_activos ?> proyectos activos</div>
        </div>
    </a>
    <a href="?page=crm" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--accent);cursor:pointer;">
            <div class="kpi-label">Clientes Nuevos Este Mes</div>
            <div class="kpi-value" style="color:var(--accent);"><?= $clientes_nuevos ?></div>
            <div class="kpi-sub">incorporados en <?= date('M Y') ?></div>
        </div>
    </a>
</div>

<!-- KPI Row 2 — 3 cards secundarias -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr);margin-top:10px;">
    <a href="?page=crm" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--text-muted);padding:10px 16px;cursor:pointer;">
            <div class="kpi-label" style="font-size:.72rem;">Clientes Pausados</div>
            <div class="kpi-value" style="font-size:1.4rem;"><?= $clientes_pausados ?></div>
        </div>
    </a>
    <a href="?page=crm" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid var(--danger);padding:10px 16px;cursor:pointer;">
            <div class="kpi-label" style="font-size:.72rem;">Clientes Perdidos</div>
            <div class="kpi-value" style="font-size:1.4rem;color:var(--danger);"><?= $clientes_perdidos ?></div>
        </div>
    </a>
    <a href="?page=tasks" style="text-decoration:none;color:inherit;">
        <div class="kpi-card" style="border-left: 3px solid <?= $tareas_atrasadas > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>;padding:10px 16px;cursor:pointer;">
            <div class="kpi-label" style="font-size:.72rem;">Tareas Atrasadas</div>
            <div class="kpi-value" style="font-size:1.4rem;<?= $tareas_atrasadas > 0 ? 'color:var(--danger);' : '' ?>"><?= $tareas_atrasadas ?></div>
            <div class="kpi-sub" style="font-size:.68rem;"><?= $tareas_pendientes ?> pendientes en total</div>
        </div>
    </a>
</div>

<!-- Charts -->
<div class="grid-2" style="margin-top:24px;margin-bottom:24px;">
    <!-- Facturación últimos 6 meses -->
    <div class="chart-container">
        <div class="chart-title">Facturación Últimos 6 Meses</div>
        <?php if (!empty($fact_6meses)): ?>
        <div class="bar-chart">
            <?php
            $max_fact = max(array_column($fact_6meses, 'total')) ?: 1;
            foreach ($fact_6meses as $fm):
                $h = round($fm['total'] / $max_fact * 100);
                $mes_label = isset($meses_es[substr($fm['mes'], 5)]) ? $meses_es[substr($fm['mes'], 5)] : substr($fm['mes'], 5);
            ?>
            <div class="bar-item">
                <div class="bar-value"><?= format_money($fm['total']) ?></div>
                <div class="bar" style="height:<?= $h ?>%;background:var(--accent);"></div>
                <div class="bar-label"><?= $mes_label ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty-state"><p>Sin datos de facturación</p></div>
        <?php endif; ?>
    </div>

    <!-- Clientes por etapa pipeline -->
    <div class="chart-container">
        <div class="chart-title">Clientes por Etapa</div>
        <?php if (!empty($clientes_por_etapa)): ?>
        <?php
        $max_etapa = max(array_column($clientes_por_etapa, 'total')) ?: 1;
        foreach ($clientes_por_etapa as $ep):
            $w = round($ep['total'] / $max_etapa * 100);
        ?>
        <div class="bar-item" style="flex-direction:row;align-items:center;gap:10px;height:auto;margin-bottom:10px;">
            <div class="bar-label" style="width:120px;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= safe($ep['etapa_pipeline']) ?>"><?= safe($ep['etapa_pipeline']) ?></div>
            <div style="flex:1;background:var(--bg);border-radius:4px;height:18px;overflow:hidden;">
                <div style="width:<?= $w ?>%;height:100%;background:var(--accent);border-radius:4px;"></div>
            </div>
            <div class="bar-value" style="width:28px;text-align:left;"><?= $ep['total'] ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><p>Sin datos de etapas</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($alertas)): ?>
<div style="margin-bottom: 24px;">
    <?php foreach ($alertas as $a): ?>
    <div class="alert-item alert-<?= $a['tipo'] ?>" style="margin-bottom: 6px;">
        <span class="alert-icon" style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;background:<?= $a['tipo'] === 'danger' ? 'rgba(239,68,68,.2)' : 'rgba(234,179,8,.2)' ?>;color:<?= $a['tipo'] === 'danger' ? 'var(--danger)' : 'var(--warning)' ?>;"><?= $a['icono'] ?></span>
        <span class="alert-text"><?= $a['texto'] ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-2">
    <!-- Clientes recientes -->
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Clientes Activos</span>
            <a href="?page=crm" class="btn btn-secondary btn-sm">Ver todos</a>
        </div>
        <table>
            <thead><tr><th>Cliente</th><th>Plan</th><th>Fee</th><th>Pago</th></tr></thead>
            <tbody>
            <?php foreach ($clientes_recientes as $c): ?>
                <tr>
                    <td><strong><?= safe($c['nombre']) ?></strong><?= $c['etapa'] ? '<br><span style="font-size:.7rem;color:var(--text-muted)">' . safe($c['etapa']) . '</span>' : '' ?></td>
                    <td><span class="badge status-info" style="font-size:.7rem"><?= safe($c['plan'] ?: '-') ?></span></td>
                    <td style="font-weight:600;"><?= $c['fee_mensual'] > 0 ? format_money($c['fee_mensual']) : '-' ?></td>
                    <td><span class="badge <?= $c['estado_pago'] === 'pagado' ? 'status-success' : ($c['estado_pago'] === 'vencido' ? 'status-danger' : ($c['estado_pago'] === 'canje' ? 'status-muted' : 'status-warning')) ?>"><?= ucfirst($c['estado_pago']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Actividad reciente -->
    <div>
        <div class="section-header">
            <h3 class="section-title">Actividad Reciente</h3>
        </div>
        <?php if (empty($actividad)): ?>
            <div style="color:var(--text-muted); font-size:.85rem; padding:20px 0;">Sin actividad registrada. Las acciones en el dashboard se registran aqui automaticamente.</div>
        <?php else: ?>
            <div class="activity-list">
            <?php foreach ($actividad as $act): ?>
                <div class="activity-item">
                    <span class="activity-dot"></span>
                    <span class="activity-text"><strong><?= safe($act['usuario'] ?? 'Sistema') ?></strong> <?= safe($act['accion']) ?> <span style="color:var(--text-muted)">[<?= safe($act['modulo']) ?>]</span></span>
                    <span class="activity-time"><?= time_ago($act['created_at']) ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
