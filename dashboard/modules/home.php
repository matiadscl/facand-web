<?php
/**
 * Módulo Home — Vista panorámica
 */

$total_clientes    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo"') ?? 0;
$fee_mensual_total = query_scalar('SELECT COALESCE(SUM(fee_mensual),0) FROM clientes WHERE tipo = "activo" AND estado_pago != "canje"') ?? 0;
$pagos_vencidos    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido"') ?? 0;
$tareas_pendientes = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso")') ?? 0;
$tareas_atrasadas  = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso") AND fecha_limite < date("now") AND fecha_limite IS NOT NULL') ?? 0;
$cobrado_mes       = query_scalar('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE strftime("%Y-%m", fecha) = strftime("%Y-%m", "now")') ?? 0;
$por_cobrar        = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial")') ?? 0;
$clientes_nuevos   = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND strftime("%Y-%m", created_at) = strftime("%Y-%m", "now")') ?? 0;
$clientes_pausados = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "inactivo"') ?? 0;
$clientes_perdidos = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "cerrado"') ?? 0;

// Chart: facturación últimos 6 meses
$fact_6m = array_reverse(query_all("SELECT strftime('%Y-%m', fecha_emision) as mes, SUM(total) as total FROM facturas WHERE estado != 'anulada' GROUP BY mes ORDER BY mes DESC LIMIT 6"));
// Chart: clientes por etapa
$por_etapa = query_all("SELECT etapa_pipeline, COUNT(*) as total FROM clientes WHERE tipo IN ('activo','prospecto') AND etapa_pipeline != '' GROUP BY etapa_pipeline ORDER BY total DESC");

// Alertas
$alertas = [];
foreach (query_all('SELECT nombre, fee_mensual FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido" ORDER BY fee_mensual DESC LIMIT 5') as $cv) {
    $alertas[] = ['tipo' => 'danger', 'texto' => "Pago vencido: <strong>{$cv['nombre']}</strong> — " . format_money($cv['fee_mensual'])];
}
foreach (query_all('SELECT t.titulo, t.fecha_limite, c.nombre as cliente FROM tareas t LEFT JOIN clientes c ON t.cliente_id = c.id WHERE t.estado IN ("pendiente","en_progreso") AND t.fecha_limite < date("now") AND t.fecha_limite IS NOT NULL ORDER BY t.fecha_limite LIMIT 5') as $ta) {
    $alertas[] = ['tipo' => 'warning', 'texto' => "Tarea atrasada: <strong>{$ta['titulo']}</strong> ({$ta['cliente']}) — " . days_overdue($ta['fecha_limite']) . " días"];
}

$clientes_recientes = query_all('SELECT nombre, plan, fee_mensual, etapa, estado_pago FROM clientes WHERE tipo = "activo" ORDER BY updated_at DESC LIMIT 8');
$actividad = query_all('SELECT a.*, u.nombre as usuario FROM actividad a LEFT JOIN usuarios u ON a.usuario_id = u.id ORDER BY a.created_at DESC LIMIT 10');
$meses_es = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
?>

<style>
.home-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:20px; }
.home-kpi { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:18px 20px; text-decoration:none; color:inherit; transition:all .15s; cursor:pointer; }
.home-kpi:hover { border-color:var(--primary); transform:translateY(-1px); }
.home-kpi-label { font-size:.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.home-kpi-value { font-size:1.5rem; font-weight:700; }
.home-kpi-sub { font-size:.72rem; color:var(--text-muted); margin-top:3px; }
.home-kpis-sm { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:24px; }
.home-kpi-sm { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px 16px; text-decoration:none; color:inherit; transition:all .15s; cursor:pointer; }
.home-kpi-sm:hover { border-color:var(--primary); }
.home-kpi-sm .home-kpi-label { font-size:.68rem; }
.home-kpi-sm .home-kpi-value { font-size:1.2rem; }
@media (max-width:768px) { .home-kpis, .home-kpis-sm { grid-template-columns:repeat(2,1fr); } }
@media (max-width:480px) { .home-kpis { grid-template-columns:1fr; } }
</style>

<!-- KPIs principales -->
<div class="home-kpis">
    <a href="?page=billing" class="home-kpi" style="border-left:3px solid var(--primary)">
        <div class="home-kpi-label">Facturación Proyectada</div>
        <div class="home-kpi-value" style="color:var(--primary)"><?= format_money($fee_mensual_total) ?></div>
        <div class="home-kpi-sub"><?= $total_clientes ?> clientes activos</div>
    </a>
    <a href="?page=receivables" class="home-kpi" style="border-left:3px solid var(--success)">
        <div class="home-kpi-label">Cobrado Este Mes</div>
        <div class="home-kpi-value" style="color:var(--success)"><?= format_money($cobrado_mes) ?></div>
    </a>
    <a href="?page=receivables" class="home-kpi" style="border-left:3px solid <?= $por_cobrar > 0 ? 'var(--warning)' : 'var(--success)' ?>">
        <div class="home-kpi-label">Por Cobrar</div>
        <div class="home-kpi-value" style="color:<?= $por_cobrar > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= format_money($por_cobrar) ?></div>
        <div class="home-kpi-sub"><?= $pagos_vencidos > 0 ? "<span style='color:var(--danger)'>{$pagos_vencidos} vencidos</span>" : 'Sin vencidos' ?></div>
    </a>
    <a href="?page=crm" class="home-kpi" style="border-left:3px solid var(--accent)">
        <div class="home-kpi-label">Clientes Activos</div>
        <div class="home-kpi-value"><?= $total_clientes ?></div>
    </a>
</div>

<!-- KPIs secundarios -->
<div class="home-kpis-sm">
    <a href="?page=crm" class="home-kpi-sm">
        <div class="home-kpi-label">Nuevos este mes</div>
        <div class="home-kpi-value" style="color:var(--primary)"><?= $clientes_nuevos ?></div>
    </a>
    <a href="?page=crm" class="home-kpi-sm">
        <div class="home-kpi-label">Pausados</div>
        <div class="home-kpi-value"><?= $clientes_pausados ?></div>
    </a>
    <a href="?page=crm" class="home-kpi-sm">
        <div class="home-kpi-label">Perdidos</div>
        <div class="home-kpi-value" style="color:<?= $clientes_perdidos > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $clientes_perdidos ?></div>
    </a>
    <a href="?page=tasks" class="home-kpi-sm">
        <div class="home-kpi-label">Tareas atrasadas</div>
        <div class="home-kpi-value" style="color:<?= $tareas_atrasadas > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $tareas_atrasadas ?></div>
        <div class="home-kpi-sub"><?= $tareas_pendientes ?> pendientes total</div>
    </a>
</div>

<!-- Gráficos -->
<div class="grid-2">
    <div class="chart-container">
        <div class="chart-title">Facturación Últimos 6 Meses</div>
        <?php if (!empty($fact_6m)):
            $max_f = max(array_column($fact_6m, 'total')) ?: 1;
        ?>
        <div class="bar-chart">
            <?php foreach ($fact_6m as $fm):
                $h = round($fm['total'] / $max_f * 100);
                $ml = $meses_es[substr($fm['mes'], 5)] ?? substr($fm['mes'], 5);
            ?>
            <div class="bar-item">
                <div class="bar-value" style="font-size:.65rem"><?= format_money($fm['total']) ?></div>
                <div class="bar" style="height:<?= $h ?>%;background:var(--primary);"></div>
                <div class="bar-label"><?= $ml ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div style="padding:30px;text-align:center;color:var(--text-muted);font-size:.85rem;">Sin datos de facturación</div>
        <?php endif; ?>
    </div>

    <div class="chart-container">
        <div class="chart-title">Clientes por Etapa</div>
        <?php if (!empty($por_etapa)):
            $max_e = max(array_column($por_etapa, 'total')) ?: 1;
            $etapa_labels = ['lead'=>'Lead','contactado'=>'Contactado','propuesta'=>'Propuesta','negociacion'=>'Negociación','onboarding'=>'Onboarding','activo'=>'Activo','cerrado_ganado'=>'Cerrado ✓','cerrado_perdido'=>'Cerrado ✗'];
            foreach ($por_etapa as $ep):
                $w = round($ep['total'] / $max_e * 100);
                $label = $etapa_labels[$ep['etapa_pipeline']] ?? ucfirst($ep['etapa_pipeline']);
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <div style="width:100px;text-align:right;font-size:.78rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $label ?></div>
            <div style="flex:1;background:var(--border);border-radius:4px;height:20px;overflow:hidden;">
                <div style="width:<?= $w ?>%;height:100%;background:var(--primary);border-radius:4px;transition:width .5s;"></div>
            </div>
            <div style="width:24px;font-size:.8rem;font-weight:600;"><?= $ep['total'] ?></div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
            <div style="padding:30px;text-align:center;color:var(--text-muted);font-size:.85rem;">Sin datos de pipeline</div>
        <?php endif; ?>
    </div>
</div>

<!-- Alertas -->
<?php if (!empty($alertas)): ?>
<div style="margin:24px 0 16px;">
    <div class="section-header"><h3 class="section-title">Alertas</h3></div>
    <?php foreach ($alertas as $a): ?>
    <div class="alert-item alert-<?= $a['tipo'] ?>" style="margin-bottom:6px;">
        <span class="alert-icon" style="width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;background:<?= $a['tipo'] === 'danger' ? 'rgba(239,68,68,.15)' : 'rgba(234,179,8,.15)' ?>;color:<?= $a['tipo'] === 'danger' ? 'var(--danger)' : 'var(--warning)' ?>;">!</span>
        <span class="alert-text"><?= $a['texto'] ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Tabla + Actividad -->
<div class="grid-2">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Clientes Activos</span>
            <a href="?page=crm" class="btn btn-secondary btn-sm">Ver todos</a>
        </div>
        <table>
            <thead><tr><th>Cliente</th><th>Plan</th><th>Suscripción</th><th>Pago</th></tr></thead>
            <tbody>
            <?php foreach ($clientes_recientes as $c): ?>
            <tr>
                <td><strong><?= safe($c['nombre']) ?></strong></td>
                <td><span class="badge status-info" style="font-size:.7rem"><?= safe($c['plan'] ?: 'Custom') ?></span></td>
                <td style="font-weight:600"><?= $c['fee_mensual'] > 0 ? format_money($c['fee_mensual']) : '-' ?></td>
                <td><span class="badge <?= $c['estado_pago'] === 'pagado' ? 'status-success' : ($c['estado_pago'] === 'vencido' ? 'status-danger' : ($c['estado_pago'] === 'canje' ? 'status-muted' : 'status-warning')) ?>"><?= ucfirst($c['estado_pago']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <div class="section-header"><h3 class="section-title">Actividad Reciente</h3></div>
        <?php if (empty($actividad)): ?>
            <div style="color:var(--text-muted);font-size:.85rem;padding:20px 0;">Sin actividad registrada.</div>
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
