<?php
/**
 * Módulo Reportes — Reportes cruzados exportables
 * Agrega datos de todos los módulos para generar reportes
 */

$mes = $_GET['mes'] ?? date('Y-m');
$meses_nombres = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes_label = ($meses_nombres[substr($mes, 5, 2)] ?? '') . ' ' . substr($mes, 0, 4);

// Datos para reporte mensual
$r_clientes_nuevos = query_scalar('SELECT COUNT(*) FROM clientes WHERE strftime("%Y-%m", created_at) = ?', [$mes]) ?? 0;
$r_proyectos_creados = query_scalar('SELECT COUNT(*) FROM proyectos WHERE strftime("%Y-%m", created_at) = ?', [$mes]) ?? 0;
$r_tareas_completadas = query_scalar('SELECT COUNT(*) FROM tareas WHERE estado = "completada" AND strftime("%Y-%m", completado_at) = ?', [$mes]) ?? 0;
$r_facturas_emitidas = query_scalar('SELECT COUNT(*) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = ? AND estado != "anulada"', [$mes]) ?? 0;
$r_monto_facturado = query_scalar('SELECT COALESCE(SUM(total),0) FROM facturas WHERE strftime("%Y-%m", fecha_emision) = ? AND estado != "anulada"', [$mes]) ?? 0;
$r_monto_cobrado = query_scalar('SELECT COALESCE(SUM(monto),0) FROM abonos WHERE strftime("%Y-%m", fecha) = ?', [$mes]) ?? 0;
$r_ingresos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "ingreso" AND strftime("%Y-%m", fecha) = ?', [$mes]) ?? 0;
$r_gastos = query_scalar('SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE tipo = "gasto" AND strftime("%Y-%m", fecha) = ?', [$mes]) ?? 0;

// Top clientes por facturación del mes
$top_clientes = query_all('SELECT c.nombre, SUM(f.total) as total FROM facturas f JOIN clientes c ON f.cliente_id = c.id WHERE strftime("%Y-%m", f.fecha_emision) = ? AND f.estado != "anulada" GROUP BY f.cliente_id ORDER BY total DESC LIMIT 10', [$mes]);

// Rendimiento equipo
$equipo_report = query_all('SELECT e.nombre,
    (SELECT COUNT(*) FROM tareas WHERE asignado_a = e.id AND estado = "completada" AND strftime("%Y-%m", completado_at) = ?) as completadas,
    (SELECT COUNT(*) FROM tareas WHERE asignado_a = e.id AND estado IN ("pendiente","en_progreso")) as pendientes
    FROM equipo e WHERE e.activo = 1 ORDER BY completadas DESC', [$mes]);
?>

<style>
@media print {
  body, .content { background: #fff !important; color: #000 !important; }
  .sidebar, .topbar, .btn, .no-print { display: none !important; }
  .kpi-grid { grid-template-columns: repeat(4, 1fr) !important; }
  .kpi-card { background: #f8f8f8 !important; color: #000 !important; border: 1px solid #ddd !important; }
  .data-table th { background: #eee !important; color: #000 !important; }
  .data-table td { color: #000 !important; border-bottom: 1px solid #ddd !important; }
}
</style>

<div class="filters-bar">
    <select class="form-select" onchange="location.href='?page=reports&mes='+this.value">
        <?php for ($i = 0; $i < 12; $i++):
            $m = date('Y-m', strtotime("-$i months"));
            $ml = ($meses_nombres[substr($m, 5, 2)] ?? '') . ' ' . substr($m, 0, 4);
        ?>
            <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endfor; ?>
    </select>
    <button class="btn btn-secondary btn-sm" onclick="window.print()">Imprimir / PDF</button>
</div>

<div class="chart-container" style="margin-bottom:24px;">
    <h2 style="font-size:1.2rem; margin-bottom:4px;">Reporte Mensual — <?= $mes_label ?></h2>
    <p style="font-size:.8rem; color:var(--text-muted);">Generado el <?= date('d/m/Y H:i') ?></p>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Clientes Nuevos</div>
        <div class="kpi-value"><?= $r_clientes_nuevos ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Proyectos Creados</div>
        <div class="kpi-value"><?= $r_proyectos_creados ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Tareas Completadas</div>
        <div class="kpi-value success"><?= $r_tareas_completadas ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Facturas Emitidas</div>
        <div class="kpi-value"><?= $r_facturas_emitidas ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Facturado</div>
        <div class="kpi-value"><?= format_money($r_monto_facturado) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Cobrado</div>
        <div class="kpi-value success"><?= format_money($r_monto_cobrado) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Resultado Neto</div>
        <?php $neto = $r_ingresos - $r_gastos; ?>
        <div class="kpi-value <?= $neto >= 0 ? 'success' : 'danger' ?>"><?= format_money($neto) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Tasa de Cobro</div>
        <div class="kpi-value"><?= $r_monto_facturado > 0 ? round($r_monto_cobrado / $r_monto_facturado * 100) : 0 ?>%</div>
    </div>
</div>

<div class="grid-2">
    <!-- Top Clientes -->
    <div class="table-container">
        <div class="table-header"><span class="table-title">Top Clientes por Facturación</span></div>
        <table>
            <thead><tr><th>Cliente</th><th>Total Facturado</th></tr></thead>
            <tbody>
                <?php foreach ($top_clientes as $tc): ?>
                <tr>
                    <td><?= safe($tc['nombre']) ?></td>
                    <td><strong><?= format_money($tc['total']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($top_clientes)): ?>
                <tr><td colspan="2" class="empty-state">Sin facturación</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Rendimiento Equipo -->
    <div class="table-container">
        <div class="table-header"><span class="table-title">Rendimiento Equipo</span></div>
        <table>
            <thead><tr><th>Miembro</th><th>Completadas</th><th>Pendientes</th></tr></thead>
            <tbody>
                <?php foreach ($equipo_report as $er): ?>
                <tr>
                    <td><?= safe($er['nombre']) ?></td>
                    <td style="color:var(--success)"><?= $er['completadas'] ?></td>
                    <td><?= $er['pendientes'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($equipo_report)): ?>
                <tr><td colspan="3" class="empty-state">Sin datos</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
