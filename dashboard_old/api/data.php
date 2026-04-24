<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

$accionesFinancieras = ['pagos', 'registrar_pago', 'finanzas_resumen', 'suscripcion', 'guardar_suscripcion', 'agregar_servicio_adicional', 'eliminar_servicio_adicional', 'generar_facturacion', 'actualizar_facturacion'];
if (in_array($action, $accionesFinancieras) && !$isSocio) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permisos para esta acción']);
    exit;
}

switch ($action) {

    // ── KPIs resumen ────────────────────────────────────────────────────
    case 'resumen':
        $hoy = date('Y-m-d');
        $mesActual = date('Y-m');
        echo json_encode([
            'clientes_activos'    => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'activo'"),
            'clientes_inactivos'  => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'inactivo'"),
            'proyectos_activos'   => (int) queryScalar("SELECT COUNT(*) FROM proyectos WHERE estado = 'activo'"),
            'tareas_pendientes'   => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'pendiente'"),
            'tareas_en_progreso'  => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'en_progreso'"),
            'tareas_completadas'  => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado = 'completada'"),
            'tareas_vencidas'     => (int) queryScalar("SELECT COUNT(*) FROM tareas WHERE estado != 'completada' AND fecha_limite < ? AND fecha_limite IS NOT NULL", [$hoy]),
            'total_clientes'      => (int) queryScalar("SELECT COUNT(*) FROM clientes"),
            'ingreso_mensual'     => (float) queryScalar("SELECT COALESCE(SUM(fee_mensual), 0) FROM clientes WHERE estado = 'activo' AND tipo = 'suscripcion'"),
            'pagos_pendientes'    => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'activo' AND estado_pago = 'pendiente'"),
            'pagos_vencidos'      => (int) queryScalar("SELECT COUNT(*) FROM clientes WHERE estado = 'activo' AND estado_pago = 'vencido'"),
            'notificaciones'      => (int) queryScalar("SELECT COUNT(*) FROM notificaciones WHERE leida = 0"),
        ]);
        break;

    // ── Clientes ────────────────────────────────────────────────────────
    case 'clientes':
        $estado = $_GET['estado'] ?? null;
        $sql = "SELECT * FROM clientes";
        $params = [];
        if ($estado) { $sql .= " WHERE estado = ?"; $params[] = $estado; }
        $sql .= " ORDER BY estado, nombre";
        echo json_encode(queryAll($sql, $params));
        break;

    // ── Actualizar cliente ──────────────────────────────────────────────
    case 'actualizar_cliente':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $fields = ['tipo', 'fee_mensual', 'servicios', 'responsable_id', 'contacto_nombre', 'contacto_telefono',
                    'contacto_email', 'fecha_facturacion', 'estado_pago', 'estado', 'notas', 'url_dashboard'];
        $sets = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        $params[] = $data['id'];
        execute("UPDATE clientes SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        echo json_encode(['ok' => true]);
        break;

    // ── Proyectos ───────────────────────────────────────────────────────
    case 'proyectos':
        $clienteId = $_GET['cliente_id'] ?? null;
        $estado = $_GET['estado'] ?? null;
        $sql = "SELECT p.*, c.nombre as cliente_nombre, e.nombre as responsable_nombre
                FROM proyectos p JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN equipo e ON p.responsable_id = e.id WHERE 1=1";
        $params = [];
        if ($clienteId) { $sql .= " AND p.cliente_id = ?"; $params[] = $clienteId; }
        if ($estado) { $sql .= " AND p.estado = ?"; $params[] = $estado; }
        $sql .= " ORDER BY p.prioridad DESC, p.created_at DESC";
        echo json_encode(queryAll($sql, $params));
        break;

    // ── Tareas ──────────────────────────────────────────────────────────
    case 'tareas':
        $clienteId = $_GET['cliente_id'] ?? null;
        $asignado = $_GET['asignado_a'] ?? null;
        $estado = $_GET['estado'] ?? null;
        $proyectoId = $_GET['proyecto_id'] ?? null;
        $sql = "SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre,
                       e.nombre as asignado_nombre
                FROM tareas t JOIN clientes c ON t.cliente_id = c.id
                LEFT JOIN proyectos p ON t.proyecto_id = p.id
                LEFT JOIN equipo e ON t.asignado_a = e.id WHERE 1=1";
        $params = [];
        if ($clienteId) { $sql .= " AND t.cliente_id = ?"; $params[] = $clienteId; }
        if ($asignado) { $sql .= " AND t.asignado_a = ?"; $params[] = $asignado; }
        if ($estado) { $sql .= " AND t.estado = ?"; $params[] = $estado; }
        if ($proyectoId) { $sql .= " AND t.proyecto_id = ?"; $params[] = (int) $proyectoId; }
        $sql .= " ORDER BY CASE t.prioridad WHEN 'critica' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END, t.fecha_limite";
        echo json_encode(queryAll($sql, $params));
        break;

    // ── Crear tarea ─────────────────────────────────────────────────────
    case 'crear_tarea':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute(
            "INSERT INTO tareas (cliente_id, titulo, descripcion, proyecto_id, asignado_a, creado_por, prioridad, fecha_limite)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$data['cliente_id'], $data['titulo'], $data['descripcion'] ?? null,
             $data['proyecto_id'] ?? null, $data['asignado_a'] ?? null,
             $currentUser, $data['prioridad'] ?? 'media', $data['fecha_limite'] ?? null]
        );
        execute("INSERT INTO actividad (agente, accion, detalle, cliente_id, usuario_id) VALUES ('dashboard', 'tarea_creada', ?, ?, ?)",
            [$data['titulo'], $data['cliente_id'], $currentUser]);
        echo json_encode(['ok' => true, 'id' => getDB()->lastInsertRowID()]);
        break;

    // ── Actualizar tarea ────────────────────────────────────────────────
    case 'actualizar_tarea':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $sets = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];
        foreach (['estado', 'asignado_a', 'prioridad', 'fecha_limite', 'titulo', 'descripcion', 'proyecto_id'] as $field) {
            if (array_key_exists($field, $data)) { $sets[] = "$field = ?"; $params[] = $data[$field]; }
        }
        if (isset($data['estado']) && $data['estado'] === 'completada') {
            $sets[] = "completado_at = CURRENT_TIMESTAMP";
        }
        $params[] = (int) $data['id'];
        execute("UPDATE tareas SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        echo json_encode(['ok' => true]);
        break;

    // ── Crear proyecto ──────────────────────────────────────────────────
    case 'crear_proyecto':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute(
            "INSERT INTO proyectos (cliente_id, nombre, descripcion, responsable_id, fecha_inicio, fecha_limite, prioridad)
             VALUES (?, ?, ?, ?, date('now'), ?, ?)",
            [$data['cliente_id'], $data['nombre'], $data['descripcion'] ?? null,
             $data['responsable_id'] ?? null, $data['fecha_limite'] ?? null,
             $data['prioridad'] ?? 'media']
        );
        execute("INSERT INTO actividad (agente, accion, detalle, cliente_id, usuario_id) VALUES ('dashboard', 'proyecto_creado', ?, ?, ?)",
            [$data['nombre'], $data['cliente_id'], $currentUser]);
        echo json_encode(['ok' => true, 'id' => getDB()->lastInsertRowID()]);
        break;

    // ── Pagos ───────────────────────────────────────────────────────────
    case 'pagos':
        $clienteId = $_GET['cliente_id'] ?? null;
        $sql = "SELECT p.*, c.nombre as cliente_nombre FROM pagos p JOIN clientes c ON p.cliente_id = c.id WHERE 1=1";
        $params = [];
        if ($clienteId) { $sql .= " AND p.cliente_id = ?"; $params[] = $clienteId; }
        $sql .= " ORDER BY p.fecha DESC LIMIT 100";
        echo json_encode(queryAll($sql, $params));
        break;

    case 'registrar_pago':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute("INSERT INTO pagos (cliente_id, monto, fecha, metodo, detalle) VALUES (?, ?, ?, ?, ?)",
            [$data['cliente_id'], $data['monto'], $data['fecha'], $data['metodo'] ?? null, $data['detalle'] ?? null]);
        execute("UPDATE clientes SET estado_pago = 'pagado' WHERE id = ?", [$data['cliente_id']]);
        execute("INSERT INTO actividad (agente, accion, detalle, cliente_id, usuario_id) VALUES ('dashboard', 'pago_registrado', ?, ?, ?)",
            ['$' . number_format((float)$data['monto'], 0, '', '.'), $data['cliente_id'], $currentUser]);
        echo json_encode(['ok' => true]);
        break;

    // ── Equipo ──────────────────────────────────────────────────────────
    case 'equipo':
        echo json_encode(queryAll("SELECT * FROM equipo WHERE activo = 1"));
        break;

    // ── Actividad ───────────────────────────────────────────────────────
    case 'actividad':
        $limite = (int) ($_GET['limite'] ?? 30);
        echo json_encode(queryAll("SELECT * FROM actividad ORDER BY created_at DESC LIMIT ?", [$limite]));
        break;

    // ── Notificaciones ──────────────────────────────────────────────────
    case 'notificaciones':
        echo json_encode(queryAll("SELECT * FROM notificaciones ORDER BY created_at DESC LIMIT 50"));
        break;

    case 'marcar_leida':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id'])) {
            execute("UPDATE notificaciones SET leida = 1 WHERE id = ?", [(int)$data['id']]);
        } else {
            execute("UPDATE notificaciones SET leida = 1");
        }
        echo json_encode(['ok' => true]);
        break;

    // ── Calendario ──────────────────────────────────────────────────────
    case 'calendario':
        $mes = $_GET['mes'] ?? date('Y-m');
        $inicio = $mes . '-01';
        $fin = date('Y-m-t', strtotime($inicio));

        $eventos = [];
        // Tareas con fecha límite
        $tareasCal = queryAll(
            "SELECT t.id, t.titulo, t.fecha_limite as fecha, t.estado, t.prioridad, c.nombre as cliente, 'tarea' as tipo_evento
             FROM tareas t JOIN clientes c ON t.cliente_id = c.id
             WHERE t.fecha_limite BETWEEN ? AND ?", [$inicio, $fin]);
        $eventos = array_merge($eventos, $tareasCal);

        // Proyectos con fecha límite
        $proyectosCal = queryAll(
            "SELECT p.id, p.nombre as titulo, p.fecha_limite as fecha, p.estado, p.prioridad, c.nombre as cliente, 'proyecto' as tipo_evento
             FROM proyectos p JOIN clientes c ON p.cliente_id = c.id
             WHERE p.fecha_limite BETWEEN ? AND ?", [$inicio, $fin]);
        $eventos = array_merge($eventos, $proyectosCal);

        // Facturaciones del mes
        $diaActual = (int)date('d');
        $clientesFact = queryAll(
            "SELECT id, nombre as titulo, fecha_facturacion, estado_pago, fee_mensual, 'facturacion' as tipo_evento
             FROM clientes WHERE estado = 'activo' AND fecha_facturacion IS NOT NULL AND fecha_facturacion > 0");
        foreach ($clientesFact as &$cf) {
            $dia = min((int)$cf['fecha_facturacion'], (int)date('t', strtotime($inicio)));
            $cf['fecha'] = $mes . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
            $cf['cliente'] = $cf['titulo'];
            $cf['titulo'] = 'Facturación: ' . $cf['titulo'];
        }
        $eventos = array_merge($eventos, $clientesFact);

        usort($eventos, fn($a, $b) => strcmp($a['fecha'] ?? '', $b['fecha'] ?? ''));
        echo json_encode($eventos);
        break;

    // ── Finanzas resumen (ahora con facturación) ─────────────────────────
    case 'finanzas_resumen':
        $periodo = $_GET['periodo'] ?? date('Y-m');

        // Total a facturar = fees + servicios adicionales
        $totalFees = (float) queryScalar(
            "SELECT COALESCE(SUM(s.fee_mensual), 0) FROM suscripciones s
             JOIN clientes c ON s.cliente_id = c.id WHERE s.activa = 1 AND c.estado = 'activo'");
        $totalAdicionales = (float) queryScalar(
            "SELECT COALESCE(SUM(sa.monto), 0) FROM servicios_adicionales sa
             JOIN clientes c ON sa.cliente_id = c.id WHERE sa.activo = 1 AND sa.tipo = 'recurrente' AND c.estado = 'activo'");
        $totalFacturar = $totalFees + $totalAdicionales;

        // Lo efectivamente facturado este periodo
        $totalFacturado = (float) queryScalar(
            "SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ?", [$periodo]);
        $facturadoPendiente = (float) queryScalar(
            "SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado = 'pendiente'", [$periodo]);
        $facturadoEmitido = (float) queryScalar(
            "SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado IN ('emitida','enviada')", [$periodo]);
        $facturadoPagado = (float) queryScalar(
            "SELECT COALESCE(SUM(monto), 0) FROM facturacion WHERE periodo = ? AND estado = 'pagada'", [$periodo]);

        // Pagos efectivos del periodo
        $cobradoMes = (float) queryScalar(
            "SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE strftime('%Y-%m', fecha) = ?", [$periodo]);

        // Implementaciones pendientes
        $implPendiente = (float) queryScalar(
            "SELECT COALESCE(SUM(implementacion_monto - implementacion_pagado), 0) FROM suscripciones
             WHERE implementacion_estado != 'pagada' AND implementacion_monto > 0 AND activa = 1");

        // Desglose por cliente
        $desglose = queryAll(
            "SELECT c.id, c.nombre, c.tipo, c.estado_pago,
                    COALESCE(s.fee_mensual, 0) as fee_mensual,
                    COALESCE(s.implementacion_monto, 0) as impl_monto,
                    s.implementacion_estado as impl_estado,
                    COALESCE(s.implementacion_pagado, 0) as impl_pagado,
                    COALESCE((SELECT SUM(sa.monto) FROM servicios_adicionales sa WHERE sa.cliente_id = c.id AND sa.activo = 1 AND sa.tipo = 'recurrente'), 0) as adicionales,
                    COALESCE((SELECT SUM(f.monto) FROM facturacion f WHERE f.cliente_id = c.id AND f.periodo = ?), 0) as facturado_mes,
                    COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.cliente_id = c.id AND strftime('%Y-%m', p.fecha) = ?), 0) as cobrado_mes
             FROM clientes c
             LEFT JOIN suscripciones s ON s.cliente_id = c.id AND s.activa = 1
             WHERE c.estado = 'activo'
             ORDER BY COALESCE(s.fee_mensual, 0) DESC", [$periodo, $periodo]);

        // Líneas de facturación del periodo
        $facturacion = queryAll(
            "SELECT f.*, c.nombre as cliente_nombre FROM facturacion f
             JOIN clientes c ON f.cliente_id = c.id WHERE f.periodo = ? ORDER BY f.created_at DESC", [$periodo]);

        echo json_encode([
            'periodo' => $periodo,
            'total_a_facturar' => $totalFacturar,
            'total_fees' => $totalFees,
            'total_adicionales' => $totalAdicionales,
            'total_facturado' => $totalFacturado,
            'facturado_pendiente' => $facturadoPendiente,
            'facturado_emitido' => $facturadoEmitido,
            'facturado_pagado' => $facturadoPagado,
            'cobrado_mes' => $cobradoMes,
            'impl_pendiente' => $implPendiente,
            'desglose' => $desglose,
            'facturacion' => $facturacion,
        ]);
        break;

    // ── Suscripciones ───────────────────────────────────────────────────
    case 'suscripcion':
        $clienteId = $_GET['cliente_id'] ?? null;
        if (!$clienteId) { echo json_encode(null); break; }
        $sub = queryOne("SELECT * FROM suscripciones WHERE cliente_id = ? AND activa = 1", [$clienteId]);
        $adicionales = queryAll("SELECT * FROM servicios_adicionales WHERE cliente_id = ? ORDER BY activo DESC, nombre", [$clienteId]);
        echo json_encode(['suscripcion' => $sub, 'adicionales' => $adicionales]);
        break;

    case 'guardar_suscripcion':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $cid = $data['cliente_id'];

        // Desactivar anteriores
        execute("UPDATE suscripciones SET activa = 0 WHERE cliente_id = ?", [$cid]);

        execute("INSERT INTO suscripciones (cliente_id, fee_mensual, implementacion_monto, implementacion_estado, implementacion_pagado, fecha_inicio, fecha_fin, ciclo_facturacion, dia_facturacion, notas)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $cid,
            (int)($data['fee_mensual'] ?? 0),
            (int)($data['implementacion_monto'] ?? 0),
            $data['implementacion_estado'] ?? 'pendiente',
            (int)($data['implementacion_pagado'] ?? 0),
            $data['fecha_inicio'] ?? null,
            $data['fecha_fin'] ?? null,
            $data['ciclo_facturacion'] ?? 'mensual',
            $data['dia_facturacion'] ? (int)$data['dia_facturacion'] : null,
            $data['notas'] ?? null,
        ]);

        // Sincronizar fee en clientes
        execute("UPDATE clientes SET fee_mensual = ?, fecha_facturacion = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [(int)($data['fee_mensual'] ?? 0), $data['dia_facturacion'] ? (int)$data['dia_facturacion'] : null, $cid]);

        execute("INSERT INTO actividad (agente, accion, detalle, cliente_id, usuario_id) VALUES ('dashboard', 'suscripcion_actualizada', ?, ?, ?)",
            ['Fee: $' . number_format((int)($data['fee_mensual'] ?? 0), 0, '', '.'), $cid, $currentUser]);
        echo json_encode(['ok' => true]);
        break;

    // ── Servicios adicionales ───────────────────────────────────────────
    case 'agregar_servicio_adicional':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute("INSERT INTO servicios_adicionales (cliente_id, nombre, monto, tipo) VALUES (?, ?, ?, ?)",
            [$data['cliente_id'], $data['nombre'], (int)$data['monto'], $data['tipo'] ?? 'recurrente']);
        echo json_encode(['ok' => true, 'id' => getDB()->lastInsertRowID()]);
        break;

    case 'eliminar_servicio_adicional':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute("UPDATE servicios_adicionales SET activo = 0 WHERE id = ?", [(int)$data['id']]);
        echo json_encode(['ok' => true]);
        break;

    // ── Facturación ─────────────────────────────────────────────────────
    case 'generar_facturacion':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $periodo = $data['periodo'] ?? date('Y-m');

        // Obtener suscripciones activas
        $subs = queryAll("SELECT s.*, c.nombre FROM suscripciones s JOIN clientes c ON s.cliente_id = c.id WHERE s.activa = 1 AND c.estado = 'activo' AND s.fee_mensual > 0");
        $count = 0;
        foreach ($subs as $s) {
            // Verificar si ya existe facturación para este periodo/cliente
            $existe = queryScalar("SELECT COUNT(*) FROM facturacion WHERE cliente_id = ? AND periodo = ? AND concepto = 'Fee mensual'",
                [$s['cliente_id'], $periodo]);
            if (!$existe) {
                execute("INSERT INTO facturacion (cliente_id, periodo, concepto, monto, estado) VALUES (?, ?, 'Fee mensual', ?, 'pendiente')",
                    [$s['cliente_id'], $periodo, $s['fee_mensual']]);
                $count++;
            }
        }

        // Servicios adicionales recurrentes
        $adds = queryAll("SELECT sa.*, c.nombre FROM servicios_adicionales sa JOIN clientes c ON sa.cliente_id = c.id WHERE sa.activo = 1 AND sa.tipo = 'recurrente' AND c.estado = 'activo'");
        foreach ($adds as $a) {
            $existe = queryScalar("SELECT COUNT(*) FROM facturacion WHERE cliente_id = ? AND periodo = ? AND concepto = ?",
                [$a['cliente_id'], $periodo, $a['nombre']]);
            if (!$existe) {
                execute("INSERT INTO facturacion (cliente_id, periodo, concepto, monto, estado) VALUES (?, ?, ?, ?, 'pendiente')",
                    [$a['cliente_id'], $periodo, $a['nombre'], $a['monto']]);
                $count++;
            }
        }

        execute("INSERT INTO actividad (agente, accion, detalle, usuario_id) VALUES ('dashboard', 'facturacion_generada', ?, ?)",
            ["Periodo $periodo: $count líneas generadas", $currentUser]);
        echo json_encode(['ok' => true, 'lineas' => $count]);
        break;

    case 'actualizar_facturacion':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $sets = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];
        foreach (['estado', 'numero_factura', 'fecha_emision', 'notas'] as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = ?"; $params[] = $data[$f]; }
        }
        $params[] = (int)$data['id'];
        execute("UPDATE facturacion SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        echo json_encode(['ok' => true]);
        break;

    // ── Admin: permisos ────────────────────────────────────────────────
    case 'toggle_permiso':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Solo admin']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = $data['user_id'];
        $seccion = $data['seccion'];
        $actual = (int) queryScalar("SELECT permitido FROM permisos WHERE user_id = ? AND seccion = ?", [$userId, $seccion]);
        $nuevo = $actual ? 0 : 1;
        execute("UPDATE permisos SET permitido = ? WHERE user_id = ? AND seccion = ?", [$nuevo, $userId, $seccion]);
        echo json_encode(['ok' => true, 'permitido' => $nuevo]);
        break;

    // ── Herramientas ─────────────────────────────────────────────────
    case 'toggle_herramienta':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)$data['id'];
        $estadoActual = $data['estado_actual'] ?? 'pendiente';
        $ciclo = ['pendiente' => 'configurado', 'configurado' => 'no_aplica', 'no_aplica' => 'pendiente'];
        $nuevo = $ciclo[$estadoActual] ?? 'pendiente';
        execute("UPDATE herramientas_cliente SET estado = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$nuevo, $id]);
        echo json_encode(['ok' => true, 'nuevo_estado' => $nuevo]);
        break;

    case 'update_herr_notas':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        execute("UPDATE herramientas_cliente SET notas = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$data['notas'], (int)$data['id']]);
        echo json_encode(['ok' => true]);
        break;

    case 'ficha_cliente':
        require_once __DIR__ . '/../includes/ficha-parser.php';
        require_once __DIR__ . '/../includes/catalogo.php';
        $slug = $_GET['slug'] ?? '';
        $ficha = $slug ? parseClienteFicha($slug) : null;
        if (!$ficha) {
            echo json_encode(['error' => 'Cliente no encontrado']);
            break;
        }
        $planMap = getClientesPlanMap();
        $catalogo = getCatalogo();
        $clientePlan = $planMap[$slug] ?? null;
        $planData = null;
        if ($clientePlan && $clientePlan['plan'] && isset($catalogo[$clientePlan['plan']])) {
            $planData = $catalogo[$clientePlan['plan']];
        }
        echo json_encode([
            'ok' => true,
            'ficha' => $ficha,
            'plan' => $planData,
            'plan_key' => $clientePlan['plan'] ?? null,
            'precio_custom' => $clientePlan['precio_custom'] ?? null,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}
