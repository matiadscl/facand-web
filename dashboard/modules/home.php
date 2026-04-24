<?php
/**
 * Módulo Home — Resumen general, KPIs cruzados, alertas, actividad reciente
 * Agrega datos de todos los módulos para dar una vista panorámica
 */

// KPIs agregados — Facand
$total_clientes    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo"') ?? 0;
$fee_mensual_total = query_scalar('SELECT COALESCE(SUM(fee_mensual),0) FROM clientes WHERE tipo = "activo" AND estado_pago != "canje"') ?? 0;
$pagos_pendientes  = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND estado_pago IN ("pendiente","vencido")') ?? 0;
$pagos_vencidos    = query_scalar('SELECT COUNT(*) FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido"') ?? 0;
$tareas_pendientes = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso")') ?? 0;
$tareas_atrasadas  = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado IN ("pendiente","en_progreso") AND fecha_limite < date("now") AND fecha_limite IS NOT NULL') ?? 0;

// Financieros
$ingresos_mes = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso" AND strftime("%Y-%m", fecha) = strftime("%Y-%m", "now")') ?? 0;
$gastos_mes   = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "gasto" AND strftime("%Y-%m", fecha) = strftime("%Y-%m", "now")') ?? 0;

// Cuentas por cobrar
$por_cobrar_total    = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial")') ?? 0;
$cuentas_vencidas    = query_scalar('SELECT COUNT(*) FROM cuentas_cobrar WHERE estado IN ("pendiente","parcial") AND fecha_vencimiento < date("now") AND fecha_vencimiento IS NOT NULL') ?? 0;

// Facturación mes actual
$facturado_mes = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = strftime("%Y-%m", "now") AND estado != "anulada"') ?? 0;

// Alertas
$alertas = [];

// Tareas atrasadas
$tareas_atr = query_all('SELECT t.titulo, t.fecha_limite, c.nombre as cliente FROM tareas t LEFT JOIN clientes c ON t.cliente_id = c.id WHERE t.estado IN ("pendiente","en_progreso") AND t.fecha_limite < date("now") AND t.fecha_limite IS NOT NULL ORDER BY t.fecha_limite LIMIT 5');
foreach ($tareas_atr as $ta) {
    $dias = days_overdue($ta['fecha_limite']);
    $alertas[] = ['tipo' => 'danger', 'texto' => "Tarea \"{$ta['titulo']}\" ({$ta['cliente']}) — {$dias} días de atraso"];
}

// Cuentas por cobrar vencidas
$cuentas_venc = query_all('SELECT cc.monto_pendiente, cc.fecha_vencimiento, c.nombre as cliente FROM cuentas_cobrar cc JOIN clientes c ON cc.cliente_id = c.id WHERE cc.estado IN ("pendiente","parcial") AND cc.fecha_vencimiento < date("now") AND cc.fecha_vencimiento IS NOT NULL ORDER BY cc.fecha_vencimiento LIMIT 5');
foreach ($cuentas_venc as $cv) {
    $dias = days_overdue($cv['fecha_vencimiento']);
    $alertas[] = ['tipo' => 'danger', 'texto' => "Cobro vencido: {$cv['cliente']} — " . format_money($cv['monto_pendiente']) . " ({$dias} días)"];
}

// Clientes con pago vencido
$clientes_vencidos = query_all('SELECT nombre, fee_mensual FROM clientes WHERE tipo = "activo" AND estado_pago = "vencido" ORDER BY nombre LIMIT 10');
foreach ($clientes_vencidos as $cv) {
    $alertas[] = ['tipo' => 'danger', 'texto' => "Pago vencido: {$cv['nombre']} — " . format_money($cv['fee_mensual'])];
}

// Proyectos próximos a vencer (7 días)
$proyectos_prox = query_all('SELECT p.nombre, p.fecha_limite, c.nombre as cliente FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id WHERE p.estado = "activo" AND p.fecha_limite BETWEEN date("now") AND date("now", "+7 days") ORDER BY p.fecha_limite LIMIT 5');
foreach ($proyectos_prox as $pp) {
    $alertas[] = ['tipo' => 'warning', 'texto' => "Proyecto \"{$pp['nombre']}\" ({$pp['cliente']}) vence el " . format_date($pp['fecha_limite'])];
}

// Actividad reciente
$actividad = query_all('SELECT a.*, u.nombre as usuario FROM actividad a LEFT JOIN usuarios u ON a.usuario_id = u.id ORDER BY a.created_at DESC LIMIT 15');

// Ingresos últimos 6 meses para gráfico
$chart_data = query_all('SELECT strftime("%Y-%m", fecha) as mes, SUM(CASE WHEN tipo = "ingreso" THEN monto ELSE 0 END) as ingresos, SUM(CASE WHEN tipo = "gasto" THEN monto ELSE 0 END) as gastos FROM finanzas WHERE fecha >= date("now", "-6 months") GROUP BY mes ORDER BY mes');
?>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Fee Mensual Total</div>
        <div class="kpi-value"><?= format_money($fee_mensual_total) ?></div>
        <div class="kpi-sub"><?= $total_clientes ?> clientes activos</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Clientes Activos</div>
        <div class="kpi-value"><?= $total_clientes ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Pagos Pendientes</div>
        <div class="kpi-value <?= $pagos_vencidos > 0 ? 'danger' : ($pagos_pendientes > 0 ? 'warning' : '') ?>"><?= $pagos_pendientes ?></div>
        <?php if ($pagos_vencidos > 0): ?>
            <div class="kpi-sub" style="color: var(--danger)"><?= $pagos_vencidos ?> vencidos</div>
        <?php endif; ?>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Tareas Pendientes</div>
        <div class="kpi-value <?= $tareas_atrasadas > 0 ? 'warning' : '' ?>"><?= $tareas_pendientes ?></div>
        <?php if ($tareas_atrasadas > 0): ?>
            <div class="kpi-sub" style="color: var(--danger)"><?= $tareas_atrasadas ?> atrasadas</div>
        <?php endif; ?>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Facturado Este Mes</div>
        <div class="kpi-value"><?= format_money($facturado_mes) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Resultado Mes</div>
        <?php $resultado = $ingresos_mes - $gastos_mes; ?>
        <div class="kpi-value <?= $resultado >= 0 ? 'success' : 'danger' ?>"><?= format_money($resultado) ?></div>
        <div class="kpi-sub">Ingresos: <?= format_money($ingresos_mes) ?> | Gastos: <?= format_money($gastos_mes) ?></div>
    </div>
</div>

<div class="grid-2">
    <!-- Alertas -->
    <div>
        <div class="section-header">
            <h3 class="section-title">Alertas</h3>
        </div>
        <?php if (empty($alertas)): ?>
            <div class="empty-state"><p>Sin alertas pendientes</p></div>
        <?php else: ?>
            <div class="alerts-list">
                <?php foreach ($alertas as $a): ?>
                    <div class="alert-item alert-<?= $a['tipo'] ?>">
                        <span class="alert-icon"><?= $a['tipo'] === 'danger' ? '&#9888;' : '&#9432;' ?></span>
                        <span class="alert-text"><?= safe($a['texto']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Actividad Reciente -->
    <div>
        <div class="section-header">
            <h3 class="section-title">Actividad Reciente</h3>
        </div>
        <?php if (empty($actividad)): ?>
            <div class="empty-state"><p>Sin actividad registrada</p></div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($actividad as $act): ?>
                    <div class="activity-item">
                        <span class="activity-dot"></span>
                        <span class="activity-text">
                            <strong><?= safe($act['usuario'] ?? 'Sistema') ?></strong>
                            <?= safe($act['accion']) ?>
                            <span style="color: var(--text-muted)">[<?= safe($act['modulo']) ?>]</span>
                        </span>
                        <span class="activity-time"><?= time_ago($act['created_at']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Gráfico Ingresos vs Gastos -->
<?php if (!empty($chart_data)): ?>
<div class="chart-container">
    <div class="chart-title">Ingresos vs Gastos — Últimos 6 Meses</div>
    <div class="bar-chart" id="financeChart">
        <?php
        $max_val = 1;
        foreach ($chart_data as $d) {
            $max_val = max($max_val, $d['ingresos'], $d['gastos']);
        }
        foreach ($chart_data as $d):
            $ing_h = $max_val > 0 ? round($d['ingresos'] / $max_val * 100) : 0;
            $gas_h = $max_val > 0 ? round($d['gastos'] / $max_val * 100) : 0;
            $mes_label = substr($d['mes'], 5);
        ?>
        <div class="bar-item">
            <div class="bar-value" style="color: var(--success)"><?= format_money($d['ingresos']) ?></div>
            <div style="display:flex; gap:3px; align-items:flex-end; height:120px; width:100%;">
                <div class="bar" style="height: <?= $ing_h ?>%; background: var(--success); flex:1;"></div>
                <div class="bar" style="height: <?= $gas_h ?>%; background: var(--danger); flex:1;"></div>
            </div>
            <div class="bar-label"><?= $mes_label ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:16px; margin-top:12px; font-size:.75rem;">
        <span style="color:var(--success)">&#9632; Ingresos</span>
        <span style="color:var(--danger)">&#9632; Gastos</span>
    </div>
</div>
<?php endif; ?>
