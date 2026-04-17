<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$hoy = date('Y-m-d');
$mesActual = date('Y-m');

// ── Data ────────────────────────────────────────────────────────────────────
$resumen = [
    'clientes_activos'   => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'activo'"),
    'proyectos_activos'  => (int) queryScalar("SELECT COUNT(*) FROM proyectos WHERE estado = 'activo'"),
    'tareas_pendientes'  => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'pendiente'"),
    'tareas_en_progreso' => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'en_progreso'"),
    'tareas_completadas' => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'completada'"),
    'tareas_vencidas'    => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado != 'completada' AND fecha_limite < ? AND fecha_limite IS NOT NULL", [$hoy]),
    'total_clientes'     => (int) queryScalar("SELECT COUNT(*) FROM clientes"),
    'ingreso_mensual'    => (float) queryScalar("SELECT COALESCE(SUM(fee_mensual), 0) FROM clientes WHERE estado = 'activo' AND tipo = 'suscripcion'"),
    'pagos_pendientes'   => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'activo' AND estado_pago IN ('pendiente','vencido')"),
];

$clientes = queryAll("SELECT * FROM clientes ORDER BY estado, nombre");
$clientesActivos = array_filter($clientes, fn($c) => $c['estado'] === 'activo');
$proyectos = queryAll("SELECT p.*, c.nombre as cliente_nombre, e.nombre as responsable_nombre FROM proyectos p JOIN clientes c ON p.cliente_id = c.id LEFT JOIN equipo e ON p.responsable_id = e.id ORDER BY p.created_at DESC");
$tareas = queryAll("SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre, e.nombre as asignado_nombre FROM tareas t JOIN clientes c ON t.cliente_id = c.id LEFT JOIN proyectos p ON t.proyecto_id = p.id LEFT JOIN equipo e ON t.asignado_a = e.id ORDER BY CASE t.prioridad WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, t.fecha_limite");
$equipo = queryAll("SELECT * FROM equipo WHERE activo = 1");
$actividad = queryAll("SELECT * FROM actividad ORDER BY created_at DESC LIMIT 20");

$cargaEquipo = queryAll("SELECT e.id, e.nombre,
    COUNT(CASE WHEN t.estado = 'pendiente' THEN 1 END) as pendientes,
    COUNT(CASE WHEN t.estado = 'en_progreso' THEN 1 END) as en_progreso,
    COUNT(CASE WHEN t.estado = 'completada' THEN 1 END) as completadas
    FROM equipo e LEFT JOIN tareas t ON e.id = t.asignado_a
    WHERE e.activo = 1 GROUP BY e.id");

// Alertas
$tareasVencidas = queryAll("SELECT t.*, c.nombre as cliente_nombre FROM tareas t JOIN clientes c ON t.cliente_id = c.id WHERE t.estado != 'completada' AND t.fecha_limite < ? AND t.fecha_limite IS NOT NULL ORDER BY t.fecha_limite", [$hoy]);
$pagosVencidos = queryAll("SELECT * FROM clientes WHERE estado = 'activo' AND estado_pago = 'vencido'");
$pagosPendientes = queryAll("SELECT * FROM clientes WHERE estado = 'activo' AND estado_pago = 'pendiente' AND fee_mensual > 0");

// Próximos 7 días
$en7dias = date('Y-m-d', strtotime('+7 days'));
$proximosEventos = queryAll("SELECT t.titulo, t.fecha_limite as fecha, c.nombre as cliente, 'tarea' as tipo FROM tareas t JOIN clientes c ON t.cliente_id = c.id WHERE t.estado != 'completada' AND t.fecha_limite BETWEEN ? AND ? ORDER BY t.fecha_limite LIMIT 10", [$hoy, $en7dias]);
$proximosProyectos = queryAll("SELECT p.nombre as titulo, p.fecha_limite as fecha, c.nombre as cliente, 'proyecto' as tipo FROM proyectos p JOIN clientes c ON p.cliente_id = c.id WHERE p.estado = 'activo' AND p.fecha_limite BETWEEN ? AND ? ORDER BY p.fecha_limite LIMIT 5", [$hoy, $en7dias]);
$proximos = array_merge($proximosEventos, $proximosProyectos);
usort($proximos, fn($a, $b) => strcmp($a['fecha'] ?? '', $b['fecha'] ?? ''));

// Finanzas (con suscripciones)
$totalFees = (float) queryScalar("SELECT COALESCE(SUM(s.fee_mensual), 0) FROM suscripciones s JOIN clientes c ON s.cliente_id = c.id WHERE s.activa = 1 AND c.estado = 'activo'");
$totalAdicionales = (float) queryScalar("SELECT COALESCE(SUM(sa.monto), 0) FROM servicios_adicionales sa JOIN clientes c ON sa.cliente_id = c.id WHERE sa.activo = 1 AND sa.tipo = 'recurrente' AND c.estado = 'activo'");
$totalFacturar = $totalFees + $totalAdicionales;
$totalFacturado = (float) queryScalar("SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ?", [$mesActual]);
$facturaPendiente = (float) queryScalar("SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado = 'pendiente'", [$mesActual]);
$facturaEmitida = (float) queryScalar("SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado IN ('emitida','enviada')", [$mesActual]);
$facturaPagada = (float) queryScalar("SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado = 'pagada'", [$mesActual]);
$cobradoMes = (float) queryScalar("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE strftime('%Y-%m', fecha) = ?", [$mesActual]);
$pendienteCobro = max(0, $totalFacturar - $cobradoMes);
$implPendiente = (float) queryScalar("SELECT COALESCE(SUM(implementacion_monto - implementacion_pagado), 0) FROM suscripciones WHERE implementacion_estado != 'pagada' AND implementacion_monto > 0 AND activa = 1");

$desgloseFinanzas = queryAll("SELECT c.id, c.nombre, c.tipo, c.estado_pago,
    COALESCE(s.fee_mensual, 0) as fee_mensual,
    COALESCE(s.implementacion_monto, 0) as impl_monto, s.implementacion_estado as impl_estado,
    COALESCE(s.implementacion_pagado, 0) as impl_pagado,
    COALESCE((SELECT SUM(sa.monto) FROM servicios_adicionales sa WHERE sa.cliente_id = c.id AND sa.activo = 1 AND sa.tipo = 'recurrente'), 0) as adicionales,
    COALESCE((SELECT SUM(f.monto) FROM facturacion f WHERE f.cliente_id = c.id AND f.periodo = ?), 0) as facturado_mes,
    COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cliente_id = c.id AND strftime('%Y-%m', p.fecha) = ?), 0) as cobrado_mes
    FROM clientes c LEFT JOIN suscripciones s ON s.cliente_id = c.id AND s.activa = 1
    WHERE c.estado = 'activo' ORDER BY COALESCE(s.fee_mensual, 0) DESC", [$mesActual, $mesActual]);

$facturacionMes = queryAll("SELECT f.*, c.nombre as cliente_nombre FROM facturacion f JOIN clientes c ON f.cliente_id = c.id WHERE f.periodo = ? ORDER BY f.cliente_id, f.concepto", [$mesActual]);
$historialPagos = queryAll("SELECT p.*, c.nombre as cliente_nombre FROM pagos p JOIN clientes c ON p.cliente_id = c.id ORDER BY p.fecha DESC LIMIT 20");

// Suscripciones para clientes
$suscripciones = queryAll("SELECT s.*, c.nombre as cliente_nombre FROM suscripciones s JOIN clientes c ON s.cliente_id = c.id WHERE s.activa = 1");
$suscripcionesMap = [];
foreach ($suscripciones as $s) { $suscripcionesMap[$s['cliente_id']] = $s; }
$serviciosAdicionalesAll = queryAll("SELECT * FROM servicios_adicionales WHERE activo = 1 ORDER BY cliente_id, nombre");
$saMap = [];
foreach ($serviciosAdicionalesAll as $sa) { $saMap[$sa['cliente_id']][] = $sa; }

$agentes = [
    ['id' => 'orquestador', 'icon' => '🎯', 'nombre' => 'Orquestador', 'desc' => 'Rutea al agente correcto', 'status' => 'online'],
    ['id' => 'operaciones', 'icon' => '📋', 'nombre' => 'Operaciones', 'desc' => 'Proyectos, tareas, entregas', 'status' => 'online'],
    ['id' => 'marketing', 'icon' => '📣', 'nombre' => 'Marketing', 'desc' => 'Ads, SEO, contenido, analytics', 'status' => 'building'],
    ['id' => 'finanzas', 'icon' => '💰', 'nombre' => 'Finanzas', 'desc' => 'Cobros, presupuestos, flujo de caja', 'status' => 'building'],
    ['id' => 'estrategia', 'icon' => '🧭', 'nombre' => 'Estrategia', 'desc' => 'Planificación, OKRs, propuestas', 'status' => 'building'],
    ['id' => 'comunicacion', 'icon' => '💬', 'nombre' => 'Comunicación', 'desc' => 'CRM, leads, seguimiento', 'status' => 'building'],
    ['id' => 'datos', 'icon' => '📊', 'nombre' => 'Datos', 'desc' => 'Dashboards, reportes, alertas', 'status' => 'building'],
];

function formatMoney(float $n): string { return '$' . number_format($n, 0, ',', '.'); }
$meses = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
$diasSemana = ['Monday'=>'Lun','Tuesday'=>'Mar','Wednesday'=>'Mié','Thursday'=>'Jue','Friday'=>'Vie','Saturday'=>'Sáb','Sunday'=>'Dom'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facand — Dashboard</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
<div class="layout">
    <!-- ═══ Sidebar ═══ -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(249,115,22,0.15);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--accent);font-size:1.1rem">F</div>
            <div>
                <h2>Facand</h2>
                <span class="sidebar-subtitle">Agencia Digital</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">General</div>
            <a class="nav-item active" data-page="inicio" onclick="showPage('inicio')">
                <span class="nav-icon">🏠</span> Inicio
            </a>
            <a class="nav-item" data-page="agentes" onclick="showPage('agentes')">
                <span class="nav-icon">🤖</span> Agentes
                <span class="nav-badge">2/7</span>
            </a>

            <div class="nav-section">Operaciones</div>
            <a class="nav-item" data-page="proyectos" onclick="showPage('proyectos')">
                <span class="nav-icon">📁</span> Proyectos
                <?php if ($resumen['proyectos_activos'] > 0): ?>
                    <span class="nav-badge"><?= $resumen['proyectos_activos'] ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" data-page="tareas" onclick="showPage('tareas')">
                <span class="nav-icon">✅</span> Tareas
                <?php if ($resumen['tareas_pendientes'] + $resumen['tareas_en_progreso'] > 0): ?>
                    <span class="nav-badge"><?= $resumen['tareas_pendientes'] + $resumen['tareas_en_progreso'] ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" data-page="clientes" onclick="showPage('clientes')">
                <span class="nav-icon">👥</span> Clientes
                <span class="nav-badge"><?= $resumen['clientes_activos'] ?></span>
            </a>

            <div class="nav-section">Gestión</div>
            <a class="nav-item" data-page="finanzas" onclick="showPage('finanzas')">
                <span class="nav-icon">💰</span> Finanzas
                <?php if ($resumen['pagos_pendientes'] > 0): ?>
                    <span class="nav-badge" style="background:rgba(239,68,68,0.15);color:var(--danger)"><?= $resumen['pagos_pendientes'] ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" data-page="calendario" onclick="showPage('calendario')">
                <span class="nav-icon">📅</span> Calendario
            </a>
            <a class="nav-item" data-page="reportes" onclick="showPage('reportes')">
                <span class="nav-icon">📊</span> Reportes
            </a>

            <div class="nav-section">Sistema</div>
            <a class="nav-item" data-page="equipo" onclick="showPage('equipo')">
                <span class="nav-icon">👤</span> Equipo
            </a>
            <a class="nav-item" data-page="actividad" onclick="showPage('actividad')">
                <span class="nav-icon">📜</span> Actividad
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-avatar"><?= strtoupper(substr($currentNombre, 0, 1)) ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars($currentNombre) ?></div>
                    <div class="sidebar-user-role"><?= htmlspecialchars($currentRol) ?></div>
                </div>
            </div>
            <a class="nav-item logout" href="logout.php">
                <span class="nav-icon">🚪</span> Cerrar sesión
            </a>
        </div>
    </aside>

    <!-- ═══ Main Content ═══ -->
    <main class="main-content">

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- INICIO                                                          -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-inicio">
            <div class="page-header">
                <div>
                    <h1>Bienvenido, <?= htmlspecialchars(explode(' ', $currentNombre)[0]) ?></h1>
                    <span class="page-subtitle"><?= $diasSemana[date('l')] ?> <?= date('j') ?> de <?= $meses[date('F')] ?>, <?= date('Y') ?></span>
                </div>
            </div>

            <!-- Alertas -->
            <?php if (!empty($tareasVencidas) || !empty($pagosVencidos)): ?>
            <div style="margin-bottom: 1.5rem;">
                <?php foreach ($pagosVencidos as $pv): ?>
                    <div class="alert-card alert-danger">
                        <div class="alert-card-icon">🔴</div>
                        <div class="alert-card-text">
                            <strong>Pago vencido: <?= htmlspecialchars($pv['nombre']) ?></strong>
                            <p><?= formatMoney((float)$pv['fee_mensual']) ?> pendiente de cobro</p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php foreach (array_slice($tareasVencidas, 0, 3) as $tv): ?>
                    <div class="alert-card alert-warning">
                        <div class="alert-card-icon">⚠️</div>
                        <div class="alert-card-text">
                            <strong>Tarea vencida: <?= htmlspecialchars($tv['titulo']) ?></strong>
                            <p><?= htmlspecialchars($tv['cliente_nombre']) ?> — venció <?= $tv['fecha_limite'] ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($tareasVencidas) > 3): ?>
                    <div class="alert-card alert-warning">
                        <div class="alert-card-icon">📋</div>
                        <div class="alert-card-text"><strong>+<?= count($tareasVencidas) - 3 ?> tareas vencidas más</strong></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- KPIs -->
            <div class="kpi-grid">
                <div class="kpi-card kpi-highlight">
                    <div class="kpi-label">A facturar / mes</div>
                    <div class="kpi-value"><?= formatMoney($totalFacturar) ?></div>
                    <div class="kpi-detail"><?= formatMoney($cobradoMes) ?> cobrado</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Clientes activos</div>
                    <div class="kpi-value"><?= $resumen['clientes_activos'] ?></div>
                    <div class="kpi-detail">de <?= $resumen['total_clientes'] ?> totales</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Tareas pendientes</div>
                    <div class="kpi-value"><?= $resumen['tareas_pendientes'] ?></div>
                    <?php if ($resumen['tareas_vencidas'] > 0): ?>
                        <div class="kpi-detail" style="color:var(--danger)"><?= $resumen['tareas_vencidas'] ?> vencidas</div>
                    <?php endif; ?>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">En progreso</div>
                    <div class="kpi-value"><?= $resumen['tareas_en_progreso'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Proyectos activos</div>
                    <div class="kpi-value"><?= $resumen['proyectos_activos'] ?></div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <!-- Equipo -->
                <div class="section">
                    <div class="section-header"><h2>Equipo</h2></div>
                    <div class="team-grid" style="grid-template-columns: 1fr;">
                        <?php foreach ($cargaEquipo as $m): ?>
                        <div class="team-card">
                            <div class="team-avatar <?= htmlspecialchars($m['id']) ?>"><?= strtoupper(substr($m['nombre'], 0, 1)) ?></div>
                            <div class="team-info">
                                <h3><?= htmlspecialchars($m['nombre']) ?></h3>
                                <div class="team-role"><?= $m['pendientes'] ?> pend. · <?= $m['en_progreso'] ?> en prog.</div>
                            </div>
                            <div class="team-stats">
                                <div class="stat-num"><?= $m['completadas'] ?></div>
                                <div class="stat-label">hechas</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Próximos 7 días -->
                <div class="section">
                    <div class="section-header"><h2>Próximos 7 días</h2></div>
                    <?php if (empty($proximos)): ?>
                        <div class="empty-state"><p>Sin eventos próximos</p></div>
                    <?php else: ?>
                        <div class="mini-events">
                            <?php foreach ($proximos as $ev): ?>
                            <div class="mini-event">
                                <div class="mini-event-date"><?= date('d/m', strtotime($ev['fecha'])) ?></div>
                                <div class="mini-event-text"><?= htmlspecialchars($ev['titulo']) ?></div>
                                <span class="badge badge-<?= $ev['tipo'] === 'tarea' ? 'en-progreso' : 'nuevo' ?> mini-event-badge"><?= $ev['tipo'] ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagos pendientes rápidos -->
            <?php if (!empty($pagosPendientes)): ?>
            <div class="section" style="margin-top:1.5rem">
                <div class="section-header"><h2>Cobros pendientes</h2></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Cliente</th><th>Fee</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($pagosPendientes as $pp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pp['nombre']) ?></strong></td>
                                <td><?= formatMoney((float)$pp['fee_mensual']) ?></td>
                                <td><span class="badge badge-pendiente">pendiente</span></td>
                                <td><button class="btn btn-sm btn-primary" onclick="registrarPagoRapido('<?= $pp['id'] ?>', <?= (int)$pp['fee_mensual'] ?>)">Registrar pago</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- CLIENTES                                                        -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-clientes" style="display:none">
            <div class="page-header">
                <h1>Clientes</h1>
                <div class="header-controls">
                    <select id="filter-clientes-estado" onchange="filterClientes()">
                        <option value="">Todos</option>
                        <option value="activo">Activos</option>
                        <option value="inactivo">Inactivos</option>
                    </select>
                </div>
            </div>

            <!-- Tabla principal -->
            <div class="section">
                <div class="table-responsive">
                    <table class="data-table" id="tabla-clientes">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Servicios</th>
                                <th>Implementación</th>
                                <th>Fee mensual</th>
                                <th>Adicionales</th>
                                <th>Total/mes</th>
                                <th>Estado pago</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $cl):
                                $sub = $suscripcionesMap[$cl['id']] ?? null;
                                $adds = $saMap[$cl['id']] ?? [];
                                $feeMensual = $sub ? (int)$sub['fee_mensual'] : (int)$cl['fee_mensual'];
                                $totalAdds = array_sum(array_column($adds, 'monto'));
                                $totalMes = $feeMensual + $totalAdds;
                                $implMonto = $sub ? (int)$sub['implementacion_monto'] : 0;
                                $implEstado = $sub['implementacion_estado'] ?? null;
                                $implPagado = $sub ? (int)$sub['implementacion_pagado'] : 0;
                            ?>
                            <tr data-estado="<?= $cl['estado'] ?>">
                                <td>
                                    <strong><?= htmlspecialchars($cl['nombre']) ?></strong>
                                    <?php if ($cl['notas']): ?><br><span style="font-size:0.72rem;color:var(--text-muted)"><?= htmlspecialchars(substr($cl['notas'], 0, 50)) ?></span><?php endif; ?>
                                </td>
                                <td><span class="badge <?= $cl['tipo'] === 'suscripcion' ? 'badge-en-progreso' : 'badge-nuevo' ?>"><?= $cl['tipo'] === 'suscripcion' ? 'Suscripción' : 'Puntual' ?></span></td>
                                <td>
                                    <div class="client-services">
                                        <?php foreach (explode(',', $cl['servicios'] ?? '') as $s): $s = trim($s); if ($s): ?>
                                            <span class="service-tag"><?= htmlspecialchars($s) ?></span>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <?php if ($implMonto > 0): ?>
                                        <?= formatMoney($implMonto) ?>
                                        <br><span class="badge badge-<?= $implEstado === 'pagada' ? 'completada' : ($implEstado === 'parcial' ? 'pendiente' : 'critica') ?>" style="font-size:0.65rem"><?= $implEstado ?><?= $implEstado === 'parcial' ? ' (' . formatMoney($implPagado) . ')' : '' ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td class="text-right"><?= $feeMensual > 0 ? formatMoney($feeMensual) : '-' ?></td>
                                <td class="text-right">
                                    <?php if ($totalAdds > 0): ?>
                                        <?= formatMoney($totalAdds) ?>
                                        <br><span style="font-size:0.68rem;color:var(--text-muted)"><?= count($adds) ?> servicio<?= count($adds) > 1 ? 's' : '' ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td class="text-right" style="font-weight:600;color:<?= $totalMes > 0 ? 'var(--success)' : 'var(--text-muted)' ?>">
                                    <?= $totalMes > 0 ? formatMoney($totalMes) : '-' ?>
                                </td>
                                <td>
                                    <?php $epClass = match($cl['estado_pago']) { 'pagado' => 'badge-completada', 'vencido' => 'badge-critica', default => 'badge-pendiente' }; ?>
                                    <span class="badge <?= $epClass ?>"><?= $cl['estado_pago'] ?? 'pendiente' ?></span>
                                </td>
                                <td>
                                    <?php if ($cl['contacto_nombre']): ?>
                                        <span style="font-size:0.82rem"><?= htmlspecialchars($cl['contacto_nombre']) ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td><span class="badge badge-<?= $cl['estado'] ?>"><?= $cl['estado'] ?></span></td>
                                <td>
                                    <button class="btn-icon" onclick="editCliente('<?= $cl['id'] ?>')" title="Editar cliente">✏️</button>
                                    <button class="btn-icon" onclick="editSuscripcion('<?= $cl['id'] ?>', '<?= htmlspecialchars($cl['nombre']) ?>')" title="Suscripción">💳</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- FINANZAS                                                        -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-finanzas" style="display:none">
            <div class="page-header">
                <h1>Finanzas</h1>
                <span class="page-subtitle"><?= $meses[date('F')] ?> <?= date('Y') ?></span>
            </div>

            <!-- KPIs financieros -->
            <div class="finance-grid">
                <div class="finance-card positive">
                    <div class="fc-label">A facturar / mes</div>
                    <div class="fc-value" style="color:var(--success)"><?= formatMoney($totalFacturar) ?></div>
                    <div class="fc-detail">Fees <?= formatMoney($totalFees) ?> + Adicionales <?= formatMoney($totalAdicionales) ?></div>
                </div>
                <div class="finance-card <?= $totalFacturado > 0 ? 'positive' : 'warning' ?>">
                    <div class="fc-label">Facturado este mes</div>
                    <div class="fc-value"><?= formatMoney($totalFacturado) ?></div>
                    <div class="fc-detail"><?= $totalFacturar > 0 ? round($totalFacturado / $totalFacturar * 100) : 0 ?>% del total</div>
                </div>
                <div class="finance-card <?= $cobradoMes > 0 ? 'positive' : '' ?>">
                    <div class="fc-label">Cobrado (pagos)</div>
                    <div class="fc-value" style="color:var(--success)"><?= formatMoney($cobradoMes) ?></div>
                    <div class="fc-detail"><?= $totalFacturado > 0 ? round($cobradoMes / $totalFacturado * 100) : 0 ?>% de lo facturado</div>
                </div>
                <div class="finance-card <?= $pendienteCobro > 0 ? 'warning' : 'positive' ?>">
                    <div class="fc-label">Pendiente de cobro</div>
                    <div class="fc-value" style="color:var(--warning)"><?= formatMoney($pendienteCobro) ?></div>
                    <div class="fc-detail"><?= $resumen['pagos_pendientes'] ?> clientes</div>
                </div>
                <?php if ($implPendiente > 0): ?>
                <div class="finance-card negative">
                    <div class="fc-label">Implementaciones pend.</div>
                    <div class="fc-value" style="color:var(--danger)"><?= formatMoney($implPendiente) ?></div>
                    <div class="fc-detail">Setup/onboarding no cobrado</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Barra de progreso visual -->
            <div class="section" style="margin-bottom:1.5rem">
                <div class="section-header"><h2>Flujo del mes</h2></div>
                <div style="display:flex;gap:0.3rem;height:28px;border-radius:6px;overflow:hidden;background:var(--bg-dark)">
                    <?php if ($totalFacturar > 0):
                        $pctCobrado = min(100, $cobradoMes / $totalFacturar * 100);
                        $pctFacturado = min(100 - $pctCobrado, max(0, ($totalFacturado - $cobradoMes) / $totalFacturar * 100));
                        $pctPendiente = max(0, 100 - $pctCobrado - $pctFacturado);
                    ?>
                        <div style="width:<?= $pctCobrado ?>%;background:var(--success);display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:600;color:#fff"><?= $pctCobrado > 8 ? 'Cobrado' : '' ?></div>
                        <div style="width:<?= $pctFacturado ?>%;background:var(--info);display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:600;color:#fff"><?= $pctFacturado > 10 ? 'Facturado' : '' ?></div>
                        <div style="width:<?= $pctPendiente ?>%;background:var(--bg-input);display:flex;align-items:center;justify-content:center;font-size:0.7rem;color:var(--text-muted)"><?= $pctPendiente > 10 ? 'Sin facturar' : '' ?></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:1.5rem;margin-top:0.6rem;font-size:0.75rem">
                    <span style="color:var(--success)">● Cobrado</span>
                    <span style="color:var(--info)">● Facturado (no cobrado)</span>
                    <span style="color:var(--text-muted)">● Sin facturar</span>
                </div>
            </div>

            <!-- Facturación del mes -->
            <div class="section">
                <div class="section-header">
                    <h2>Facturación — <?= $meses[date('F')] ?></h2>
                    <div class="section-actions">
                        <button class="btn btn-sm btn-secondary" onclick="generarFacturacion()">Generar facturación del mes</button>
                        <button class="btn btn-sm btn-primary" onclick="openModal('pago')">+ Registrar pago</button>
                    </div>
                </div>
                <?php if (empty($facturacionMes)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📄</div>
                        <h3>Sin facturación generada</h3>
                        <p>Haz clic en "Generar facturación del mes" para crear las líneas automáticamente</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Cliente</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>N° Factura</th><th>Fecha emisión</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($facturacionMes as $f): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($f['cliente_nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($f['concepto']) ?></td>
                                <td class="text-right"><?= formatMoney((float)$f['monto']) ?></td>
                                <td>
                                    <?php $fc = match($f['estado']) {
                                        'pagada' => 'badge-completada', 'emitida' => 'badge-en-progreso',
                                        'enviada' => 'badge-en-progreso', default => 'badge-pendiente'
                                    }; ?>
                                    <span class="badge <?= $fc ?>"><?= $f['estado'] ?></span>
                                </td>
                                <td><?= htmlspecialchars($f['numero_factura'] ?? '-') ?></td>
                                <td><?= $f['fecha_emision'] ?? '-' ?></td>
                                <td>
                                    <select class="btn-icon" style="background:var(--bg-input);color:var(--text-primary);border:1px solid var(--border);border-radius:4px;padding:0.2rem;font-size:0.75rem;cursor:pointer" onchange="updateFacturacion(<?= $f['id'] ?>, this.value)">
                                        <option value="pendiente" <?= $f['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                        <option value="emitida" <?= $f['estado'] === 'emitida' ? 'selected' : '' ?>>Emitida</option>
                                        <option value="enviada" <?= $f['estado'] === 'enviada' ? 'selected' : '' ?>>Enviada</option>
                                        <option value="pagada" <?= $f['estado'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Desglose por cliente -->
            <div class="section">
                <div class="section-header"><h2>Desglose por cliente</h2></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Cliente</th><th>Fee</th><th>Adicionales</th><th>Total/mes</th><th>Facturado</th><th>Cobrado</th><th>Diferencia</th><th>Implementación</th></tr></thead>
                        <tbody>
                        <?php
                        $sumFee = 0; $sumAdd = 0; $sumTotal = 0; $sumFact = 0; $sumCob = 0;
                        foreach ($desgloseFinanzas as $df):
                            $fee = (float)$df['fee_mensual'];
                            $add = (float)$df['adicionales'];
                            $tot = $fee + $add;
                            $fact = (float)$df['facturado_mes'];
                            $cob = (float)$df['cobrado_mes'];
                            $diff = $tot - $cob;
                            $sumFee += $fee; $sumAdd += $add; $sumTotal += $tot; $sumFact += $fact; $sumCob += $cob;
                            if ($tot == 0 && $fact == 0 && $cob == 0 && (float)$df['impl_monto'] == 0) continue;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($df['nombre']) ?></strong></td>
                                <td class="text-right"><?= $fee > 0 ? formatMoney($fee) : '-' ?></td>
                                <td class="text-right"><?= $add > 0 ? formatMoney($add) : '-' ?></td>
                                <td class="text-right" style="font-weight:600"><?= $tot > 0 ? formatMoney($tot) : '-' ?></td>
                                <td class="text-right" style="color:var(--info)"><?= $fact > 0 ? formatMoney($fact) : '-' ?></td>
                                <td class="text-right" style="color:var(--success)"><?= $cob > 0 ? formatMoney($cob) : '-' ?></td>
                                <td class="text-right" style="color:<?= $diff > 0 ? 'var(--warning)' : 'var(--success)' ?>;font-weight:600"><?= $diff > 0 ? formatMoney($diff) : ($tot > 0 ? '✓' : '-') ?></td>
                                <td class="text-right">
                                    <?php if ((float)$df['impl_monto'] > 0): ?>
                                        <?= formatMoney((float)$df['impl_monto']) ?>
                                        <span class="badge badge-<?= $df['impl_estado'] === 'pagada' ? 'completada' : 'pendiente' ?>" style="font-size:0.6rem"><?= $df['impl_estado'] ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--accent)">
                                <td><strong>TOTAL</strong></td>
                                <td class="text-right" style="font-weight:600"><?= formatMoney($sumFee) ?></td>
                                <td class="text-right" style="font-weight:600"><?= formatMoney($sumAdd) ?></td>
                                <td class="text-right" style="font-weight:700;color:var(--accent)"><?= formatMoney($sumTotal) ?></td>
                                <td class="text-right" style="font-weight:600;color:var(--info)"><?= formatMoney($sumFact) ?></td>
                                <td class="text-right" style="font-weight:600;color:var(--success)"><?= formatMoney($sumCob) ?></td>
                                <td class="text-right" style="font-weight:700;color:var(--warning)"><?= formatMoney(max(0, $sumTotal - $sumCob)) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Historial de pagos -->
            <div class="section">
                <div class="section-header"><h2>Últimos pagos recibidos</h2></div>
                <?php if (empty($historialPagos)): ?>
                    <div class="empty-state"><p>Sin pagos registrados</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Fecha</th><th>Cliente</th><th>Monto</th><th>Método</th><th>Detalle</th></tr></thead>
                        <tbody>
                        <?php foreach ($historialPagos as $hp): ?>
                            <tr>
                                <td><?= $hp['fecha'] ?></td>
                                <td><?= htmlspecialchars($hp['cliente_nombre']) ?></td>
                                <td class="text-right" style="color:var(--success)"><?= formatMoney((float)$hp['monto']) ?></td>
                                <td><?= htmlspecialchars($hp['metodo'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($hp['detalle'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- TAREAS (con filtros + Kanban)                                   -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-tareas" style="display:none">
            <div class="page-header">
                <h1>Tareas</h1>
                <div class="header-controls">
                    <button class="btn btn-primary btn-sm" onclick="openModal('tarea')">+ Nueva tarea</button>
                </div>
            </div>

            <div class="filters-bar">
                <select id="filter-t-cliente" onchange="filterTareas()">
                    <option value="">Todos los clientes</option>
                    <?php foreach ($clientesActivos as $cl): ?>
                        <option value="<?= htmlspecialchars($cl['nombre']) ?>"><?= htmlspecialchars($cl['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-t-asignado" onchange="filterTareas()">
                    <option value="">Todos</option>
                    <?php foreach ($equipo as $m): ?>
                        <option value="<?= htmlspecialchars($m['nombre']) ?>"><?= htmlspecialchars($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-t-prioridad" onchange="filterTareas()">
                    <option value="">Toda prioridad</option>
                    <option value="critica">Crítica</option>
                    <option value="alta">Alta</option>
                    <option value="media">Media</option>
                    <option value="baja">Baja</option>
                </select>
                <div class="view-toggle">
                    <button class="active" onclick="setTareasView('table', this)">Tabla</button>
                    <button onclick="setTareasView('kanban', this)">Kanban</button>
                </div>
            </div>

            <!-- Vista tabla -->
            <div id="tareas-table-view">
                <?php if (empty($tareas)): ?>
                    <div class="empty-state"><div class="empty-state-icon">✅</div><h3>Sin tareas</h3></div>
                <?php else: ?>
                <div class="section">
                    <div class="table-responsive">
                        <table class="data-table" id="tabla-tareas">
                            <thead><tr><th>Tarea</th><th>Cliente</th><th>Proyecto</th><th>Asignado</th><th>Prioridad</th><th>Límite</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($tareas as $t):
                                $vencida = $t['estado'] !== 'completada' && $t['fecha_limite'] && $t['fecha_limite'] < $hoy;
                            ?>
                                <tr class="tarea-row" data-cliente="<?= htmlspecialchars($t['cliente_nombre']) ?>" data-asignado="<?= htmlspecialchars($t['asignado_nombre'] ?? '') ?>" data-prioridad="<?= $t['prioridad'] ?>" data-estado="<?= $t['estado'] ?>">
                                    <td><span class="priority-dot <?= $t['prioridad'] ?>"></span><?= htmlspecialchars($t['titulo']) ?></td>
                                    <td><?= htmlspecialchars($t['cliente_nombre']) ?></td>
                                    <td><?= htmlspecialchars($t['proyecto_nombre'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($t['asignado_nombre'] ?? '-') ?></td>
                                    <td><span class="badge badge-<?= $t['prioridad'] ?>"><?= $t['prioridad'] ?></span></td>
                                    <td style="<?= $vencida ? 'color:var(--danger);font-weight:600' : '' ?>"><?= $t['fecha_limite'] ?? '-' ?></td>
                                    <td><span class="badge badge-<?= str_replace('_', '-', $t['estado']) ?>"><?= $t['estado'] ?></span></td>
                                    <td>
                                        <?php if ($t['estado'] === 'pendiente'): ?>
                                            <button class="btn-icon" onclick="updateTarea(<?= $t['id'] ?>, 'en_progreso')" title="Iniciar">▶</button>
                                        <?php elseif ($t['estado'] === 'en_progreso'): ?>
                                            <button class="btn-icon" onclick="updateTarea(<?= $t['id'] ?>, 'completada')" title="Completar">✓</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Vista Kanban -->
            <div id="tareas-kanban-view" style="display:none">
                <div class="kanban-board">
                    <?php
                    $kanbanCols = ['pendiente' => 'Pendiente', 'en_progreso' => 'En Progreso', 'completada' => 'Completada'];
                    foreach ($kanbanCols as $estado => $label):
                        $tareasCol = array_filter($tareas, fn($t) => $t['estado'] === $estado);
                    ?>
                    <div class="kanban-column" data-estado="<?= $estado ?>" ondragover="event.preventDefault(); this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="dropTarea(event, '<?= $estado ?>')">
                        <div class="kanban-column-header">
                            <span class="kanban-column-title"><?= $label ?></span>
                            <span class="kanban-column-count"><?= count($tareasCol) ?></span>
                        </div>
                        <?php foreach ($tareasCol as $t): ?>
                        <div class="kanban-card" draggable="true" ondragstart="dragTarea(event, <?= $t['id'] ?>)" data-id="<?= $t['id'] ?>">
                            <div class="kanban-card-title"><span class="priority-dot <?= $t['prioridad'] ?>"></span><?= htmlspecialchars($t['titulo']) ?></div>
                            <div class="kanban-card-meta">
                                <span><?= htmlspecialchars($t['cliente_nombre']) ?></span>
                                <?php if ($t['asignado_nombre']): ?><span>· <?= htmlspecialchars($t['asignado_nombre']) ?></span><?php endif; ?>
                                <?php if ($t['fecha_limite']): ?><span>· <?= $t['fecha_limite'] ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- CALENDARIO                                                      -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-calendario" style="display:none">
            <div class="page-header"><h1>Calendario</h1></div>
            <div class="calendar-nav">
                <button onclick="changeMonth(-1)">◀</button>
                <h2 id="calendar-title"></h2>
                <button onclick="changeMonth(1)">▶</button>
                <button onclick="changeMonth(0)" class="btn btn-sm btn-secondary" style="margin-left:auto">Hoy</button>
            </div>
            <div class="calendar-grid" id="calendar-grid"></div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- REPORTES                                                        -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-reportes" style="display:none">
            <div class="page-header">
                <h1>Reportes por Cliente</h1>
                <span class="page-subtitle">Acceso directo a dashboards de clientes</span>
            </div>
            <div class="agents-grid">
                <?php foreach ($clientes as $cl): if ($cl['estado'] !== 'activo') continue; ?>
                <div class="agent-card">
                    <div class="agent-card-header">
                        <div class="agent-icon operaciones" style="font-size:0.9rem;font-weight:700"><?= strtoupper(substr($cl['nombre'], 0, 2)) ?></div>
                        <div>
                            <div class="agent-card-title"><?= htmlspecialchars($cl['nombre']) ?></div>
                            <div class="agent-card-status"><span style="font-size:0.75rem;color:var(--text-muted)"><?= htmlspecialchars($cl['rubro'] ?? '') ?></span></div>
                        </div>
                    </div>
                    <div class="agent-card-desc">
                        <div class="client-services" style="margin-bottom:0.5rem">
                            <?php foreach (explode(',', $cl['servicios'] ?? '') as $s): $s = trim($s); if ($s): ?>
                                <span class="service-tag"><?= htmlspecialchars($s) ?></span>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <?php if ($cl['url_dashboard']): ?>
                        <a href="<?= htmlspecialchars($cl['url_dashboard']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="width:100%;text-align:center">Abrir Dashboard</a>
                    <?php else: ?>
                        <span style="font-size:0.78rem;color:var(--text-muted)">Sin dashboard configurado</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- AGENTES / PROYECTOS / EQUIPO / ACTIVIDAD (sin cambios grandes) -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="page" id="page-agentes" style="display:none">
            <div class="page-header"><h1>Agentes</h1><span class="page-subtitle">Sistema de agentes especializados por departamento</span></div>
            <div class="agents-grid">
                <?php foreach ($agentes as $ag): ?>
                <div class="agent-card">
                    <div class="agent-card-header">
                        <div class="agent-icon <?= $ag['id'] ?>"><?= $ag['icon'] ?></div>
                        <div>
                            <div class="agent-card-title"><?= $ag['nombre'] ?></div>
                            <div class="agent-card-status"><span class="status-dot <?= $ag['status'] ?>"></span><?= $ag['status'] === 'online' ? 'Activo' : 'En construcción' ?></div>
                        </div>
                    </div>
                    <div class="agent-card-desc"><?= $ag['desc'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="page" id="page-proyectos" style="display:none">
            <div class="page-header"><h1>Proyectos</h1>
                <div class="header-controls"><button class="btn btn-primary btn-sm" onclick="openModal('proyecto')">+ Nuevo proyecto</button></div>
            </div>
            <?php if (empty($proyectos)): ?>
                <div class="empty-state"><div class="empty-state-icon">📁</div><h3>Sin proyectos</h3></div>
            <?php else: ?>
            <div class="section"><div class="table-responsive"><table class="data-table">
                <thead><tr><th>Proyecto</th><th>Cliente</th><th>Responsable</th><th>Prioridad</th><th>Estado</th><th>Fecha límite</th></tr></thead>
                <tbody>
                <?php foreach ($proyectos as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                        <td><?= htmlspecialchars($p['cliente_nombre']) ?></td>
                        <td><?= htmlspecialchars($p['responsable_nombre'] ?? '-') ?></td>
                        <td><span class="badge badge-<?= $p['prioridad'] ?>"><?= $p['prioridad'] ?></span></td>
                        <td><span class="badge badge-<?= $p['estado'] ?>"><?= $p['estado'] ?></span></td>
                        <td><?= $p['fecha_limite'] ?? '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
            <?php endif; ?>
        </div>

        <div class="page" id="page-equipo" style="display:none">
            <div class="page-header"><h1>Equipo Facand</h1></div>
            <div class="team-grid" style="margin-bottom:2rem">
                <?php foreach ($equipo as $m): ?>
                <div class="team-card">
                    <div class="team-avatar <?= htmlspecialchars($m['id']) ?>"><?= strtoupper(substr($m['nombre'], 0, 1)) ?></div>
                    <div class="team-info">
                        <h3><?= htmlspecialchars($m['nombre']) ?></h3>
                        <div class="team-role"><?= htmlspecialchars($m['rol']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.3rem">Acceso: <?= htmlspecialchars($m['permisos']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="page" id="page-actividad" style="display:none">
            <div class="page-header"><h1>Log de Actividad</h1></div>
            <div class="section">
                <?php if (empty($actividad)): ?>
                    <div class="empty-state"><div class="empty-state-icon">📜</div><h3>Sin actividad</h3></div>
                <?php else: ?>
                <div class="activity-feed">
                    <?php foreach ($actividad as $act): ?>
                    <div class="activity-item">
                        <div class="activity-icon <?= str_contains($act['accion'], 'tarea') ? 'tarea' : (str_contains($act['accion'], 'pago') ? 'tarea' : 'proyecto') ?>">
                            <?= str_contains($act['accion'], 'tarea') ? '✅' : (str_contains($act['accion'], 'pago') ? '💰' : (str_contains($act['accion'], 'ruteo') ? '🔀' : '📁')) ?>
                        </div>
                        <div class="activity-text">
                            <strong><?= htmlspecialchars($act['agente']) ?></strong> — <?= htmlspecialchars($act['accion']) ?>
                            <?php if ($act['detalle']): ?><br><span style="color:var(--text-secondary)"><?= htmlspecialchars($act['detalle']) ?></span><?php endif; ?>
                            <?php if ($act['cliente_id']): ?><br><span style="color:var(--text-muted);font-size:0.75rem">Cliente: <?= htmlspecialchars($act['cliente_id']) ?></span><?php endif; ?>
                        </div>
                        <div class="activity-time"><?= $act['created_at'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- ═══ Modal: Nueva Tarea ═══ -->
<div class="modal-overlay" id="modal-tarea">
    <div class="modal">
        <h2>Nueva Tarea</h2>
        <form id="form-tarea" onsubmit="return submitTarea(event)">
            <div class="form-group"><label>Título</label><input type="text" name="titulo" required></div>
            <div class="form-group"><label>Cliente</label>
                <select name="cliente_id" required><option value="">Seleccionar...</option>
                <?php foreach ($clientes as $cl): ?><option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Asignar a</label>
                    <select name="asignado_a"><option value="">Sin asignar</option>
                    <?php foreach ($equipo as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Prioridad</label>
                    <select name="prioridad"><option value="baja">Baja</option><option value="media" selected>Media</option><option value="alta">Alta</option><option value="critica">Crítica</option></select>
                </div>
            </div>
            <div class="form-group"><label>Fecha límite</label><input type="date" name="fecha_limite"></div>
            <div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="3"></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('tarea')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear tarea</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Nuevo Proyecto ═══ -->
<div class="modal-overlay" id="modal-proyecto">
    <div class="modal">
        <h2>Nuevo Proyecto</h2>
        <form id="form-proyecto" onsubmit="return submitProyecto(event)">
            <div class="form-group"><label>Nombre</label><input type="text" name="nombre" required></div>
            <div class="form-group"><label>Cliente</label>
                <select name="cliente_id" required><option value="">Seleccionar...</option>
                <?php foreach ($clientes as $cl): ?><option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Responsable</label>
                    <select name="responsable_id"><option value="">Sin asignar</option>
                    <?php foreach ($equipo as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Prioridad</label>
                    <select name="prioridad"><option value="media" selected>Media</option><option value="alta">Alta</option><option value="critica">Crítica</option></select>
                </div>
            </div>
            <div class="form-group"><label>Fecha límite</label><input type="date" name="fecha_limite"></div>
            <div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="3"></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('proyecto')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear proyecto</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Registrar Pago ═══ -->
<div class="modal-overlay" id="modal-pago">
    <div class="modal">
        <h2>Registrar Pago</h2>
        <form id="form-pago" onsubmit="return submitPago(event)">
            <div class="form-group"><label>Cliente</label>
                <select name="cliente_id" id="pago-cliente" required><option value="">Seleccionar...</option>
                <?php foreach ($clientesActivos as $cl): ?><option value="<?= $cl['id'] ?>" data-fee="<?= (int)$cl['fee_mensual'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Monto ($)</label><input type="number" name="monto" id="pago-monto" required></div>
                <div class="form-group"><label>Fecha</label><input type="date" name="fecha" value="<?= $hoy ?>" required></div>
            </div>
            <div class="form-group"><label>Método</label>
                <select name="metodo"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option></select>
            </div>
            <div class="form-group"><label>Detalle (opcional)</label><input type="text" name="detalle"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('pago')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Registrar</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Editar Cliente ═══ -->
<div class="modal-overlay" id="modal-cliente">
    <div class="modal">
        <h2>Editar Cliente</h2>
        <form id="form-cliente" onsubmit="return submitCliente(event)">
            <input type="hidden" name="id" id="edit-cliente-id">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Tipo</label>
                    <select name="tipo" id="edit-tipo"><option value="suscripcion">Suscripción</option><option value="servicio_puntual">Servicio puntual</option></select>
                </div>
                <div class="form-group"><label>Estado</label>
                    <select name="estado" id="edit-estado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Fee mensual ($)</label><input type="number" name="fee_mensual" id="edit-fee"></div>
                <div class="form-group"><label>Día facturación (1-28)</label><input type="number" name="fecha_facturacion" id="edit-facturacion" min="1" max="28"></div>
            </div>
            <div class="form-group"><label>Servicios (separados por coma)</label><input type="text" name="servicios" id="edit-servicios"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Contacto nombre</label><input type="text" name="contacto_nombre" id="edit-contacto"></div>
                <div class="form-group"><label>Email</label><input type="email" name="contacto_email" id="edit-email"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Teléfono</label><input type="text" name="contacto_telefono" id="edit-telefono"></div>
                <div class="form-group"><label>Estado pago</label>
                    <select name="estado_pago" id="edit-estado-pago"><option value="pendiente">Pendiente</option><option value="pagado">Pagado</option><option value="vencido">Vencido</option></select>
                </div>
            </div>
            <div class="form-group"><label>URL Dashboard</label><input type="url" name="url_dashboard" id="edit-url-dashboard"></div>
            <div class="form-group"><label>Notas</label><textarea name="notas" id="edit-notas" rows="2"></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cliente')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Suscripción ═══ -->
<div class="modal-overlay" id="modal-suscripcion">
    <div class="modal" style="max-width:600px">
        <h2 id="sub-modal-title">Suscripción</h2>
        <form id="form-suscripcion" onsubmit="return submitSuscripcion(event)">
            <input type="hidden" name="cliente_id" id="sub-cliente-id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Fee mensual ($)</label><input type="number" name="fee_mensual" id="sub-fee"></div>
                <div class="form-group"><label>Ciclo facturación</label>
                    <select name="ciclo_facturacion" id="sub-ciclo">
                        <option value="mensual">Mensual</option>
                        <option value="trimestral">Trimestral</option>
                        <option value="semestral">Semestral</option>
                        <option value="anual">Anual</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem">
                <div class="form-group"><label>Día facturación</label><input type="number" name="dia_facturacion" id="sub-dia" min="1" max="28"></div>
                <div class="form-group"><label>Fecha inicio</label><input type="date" name="fecha_inicio" id="sub-inicio"></div>
                <div class="form-group"><label>Fecha fin</label><input type="date" name="fecha_fin" id="sub-fin"></div>
            </div>

            <div style="border-top:1px solid var(--border);margin:1rem 0;padding-top:1rem">
                <h3 style="font-size:0.9rem;margin-bottom:0.75rem;color:var(--text-secondary)">Implementación / Setup</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem">
                    <div class="form-group"><label>Monto total ($)</label><input type="number" name="implementacion_monto" id="sub-impl-monto"></div>
                    <div class="form-group"><label>Pagado ($)</label><input type="number" name="implementacion_pagado" id="sub-impl-pagado"></div>
                    <div class="form-group"><label>Estado</label>
                        <select name="implementacion_estado" id="sub-impl-estado">
                            <option value="pendiente">Pendiente</option>
                            <option value="parcial">Parcial</option>
                            <option value="pagada">Pagada</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="border-top:1px solid var(--border);margin:1rem 0;padding-top:1rem">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem">
                    <h3 style="font-size:0.9rem;color:var(--text-secondary)">Servicios adicionales</h3>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addServicioAdicional()">+ Agregar</button>
                </div>
                <div id="sub-adicionales-list"></div>
                <div id="sub-add-form" style="display:none;margin-top:0.75rem">
                    <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:0.5rem;align-items:end">
                        <div class="form-group" style="margin:0"><label>Servicio</label><input type="text" id="add-srv-nombre" placeholder="Ej: Mantenimiento web"></div>
                        <div class="form-group" style="margin:0"><label>Monto ($)</label><input type="number" id="add-srv-monto" placeholder="50000"></div>
                        <div class="form-group" style="margin:0"><label>Tipo</label>
                            <select id="add-srv-tipo"><option value="recurrente">Recurrente</option><option value="unico">Único</option></select>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="saveServicioAdicional()" style="margin-bottom:0.1rem">✓</button>
                    </div>
                </div>
            </div>

            <div class="form-group"><label>Notas</label><textarea name="notas" id="sub-notas" rows="2"></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('suscripcion')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar suscripción</button>
            </div>
        </form>
    </div>
</div>

<script>
// Data de clientes para edición
const clientesData = <?= json_encode(array_map(fn($c) => [
    'id' => $c['id'], 'tipo' => $c['tipo'], 'estado' => $c['estado'],
    'fee_mensual' => $c['fee_mensual'], 'servicios' => $c['servicios'],
    'contacto_nombre' => $c['contacto_nombre'], 'contacto_email' => $c['contacto_email'],
    'contacto_telefono' => $c['contacto_telefono'], 'fecha_facturacion' => $c['fecha_facturacion'],
    'estado_pago' => $c['estado_pago'], 'notas' => $c['notas'], 'url_dashboard' => $c['url_dashboard']
], $clientes)) ?>;
</script>
<script src="js/main.js"></script>
</body>
</html>
