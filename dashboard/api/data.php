<?php
/**
 * API CRUD — Endpoints para todas las entidades del dashboard
 * Validación, sanitización, y lógica de interconexión automática
 * Los triggers SQLite manejan la automatización entre módulos
 */
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

// CSRF para POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
        exit;
    }
}

/**
 * Sanitiza un string de input
 * @param string $key Nombre del campo POST
 * @return string
 */
function input(string $key): string {
    return htmlspecialchars(trim($_POST[$key] ?? $_GET[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Obtiene un int de input
 * @param string $key
 * @return int
 */
function input_int(string $key): int {
    return (int)($_POST[$key] ?? $_GET[$key] ?? 0);
}

/**
 * Obtiene un int nullable de input
 * @param string $key
 * @return int|null
 */
function input_int_null(string $key): ?int {
    $val = $_POST[$key] ?? $_GET[$key] ?? '';
    return $val !== '' ? (int)$val : null;
}

/**
 * Responde con JSON exitoso
 * @param mixed $data Datos a incluir
 */
function respond($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

/**
 * Responde con error
 * @param string $msg Mensaje de error
 */
function fail(string $msg): void {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ============================================================
// ROUTER DE ACCIONES
// ============================================================

switch ($action) {

    // ---- CRM ----
    case 'get_client':
        $id = input_int('id');
        $client = query_one('SELECT c.*, e.nombre as responsable_nombre,
            (SELECT COUNT(*) FROM proyectos WHERE cliente_id = c.id AND estado = "activo") as proyectos_activos,
            (SELECT COUNT(*) FROM tareas WHERE cliente_id = c.id AND estado IN ("pendiente","en_progreso")) as tareas_pendientes,
            (SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE cliente_id = c.id AND estado IN ("pendiente","parcial")) as deuda_pendiente
            FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id WHERE c.id = ?', [$id]);
        $client ? respond($client) : fail('Cliente no encontrado');
        break;

    case 'create_client':
        if (!can_edit($user_id, 'crm')) fail('Sin permiso');
        $nombre = input('nombre');
        if (empty($nombre)) fail('El nombre es obligatorio');
        db_execute('INSERT INTO clientes (nombre, rut, email, telefono, direccion, contacto_nombre, contacto_cargo, tipo, etapa_pipeline, rubro, plan, fee_mensual, servicios, herramientas, presupuesto_ads, etapa, estado_pago, url_dashboard, responsable_id, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nombre, input('rut'), input('email'), input('telefono'), input('direccion'), input('contacto_nombre'), input('contacto_cargo'), input('tipo') ?: 'prospecto', input('etapa_pipeline') ?: 'lead', input('rubro'), input('plan'), input_int('fee_mensual'), input('servicios'), input('herramientas'), input('presupuesto_ads'), input('etapa'), input('estado_pago') ?: 'pendiente', input('url_dashboard'), input_int_null('responsable_id'), input('notas')]);
        log_activity('crm', "Cliente creado: $nombre", last_id());
        respond(['id' => last_id()]);
        break;

    case 'update_client':
        if (!can_edit($user_id, 'crm')) fail('Sin permiso');
        $id = input_int('id');
        db_execute('UPDATE clientes SET nombre=?, rut=?, email=?, telefono=?, direccion=?, contacto_nombre=?, contacto_cargo=?, tipo=?, etapa_pipeline=?, rubro=?, plan=?, fee_mensual=?, servicios=?, herramientas=?, presupuesto_ads=?, etapa=?, estado_pago=?, url_dashboard=?, responsable_id=?, notas=?, updated_at=datetime("now") WHERE id=?',
            [input('nombre'), input('rut'), input('email'), input('telefono'), input('direccion'), input('contacto_nombre'), input('contacto_cargo'), input('tipo'), input('etapa_pipeline'), input('rubro'), input('plan'), input_int('fee_mensual'), input('servicios'), input('herramientas'), input('presupuesto_ads'), input('etapa'), input('estado_pago'), input('url_dashboard'), input_int_null('responsable_id'), input('notas'), $id]);
        log_activity('crm', 'Cliente actualizado: ' . input('nombre'), $id);
        respond();
        break;

    // ---- PROYECTOS ----
    case 'get_project':
        $p = query_one('SELECT * FROM proyectos WHERE id = ?', [input_int('id')]);
        $p ? respond($p) : fail('Proyecto no encontrado');
        break;

    case 'create_project':
        if (!can_edit($user_id, 'projects')) fail('Sin permiso');
        $nombre = input('nombre');
        $cliente_id = input_int('cliente_id');
        if (empty($nombre) || !$cliente_id) fail('Nombre y cliente son obligatorios');
        db_execute('INSERT INTO proyectos (cliente_id, nombre, descripcion, responsable_id, prioridad, fecha_inicio, fecha_limite) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$cliente_id, $nombre, input('descripcion'), input_int_null('responsable_id'), input('prioridad') ?: 'media', input('fecha_inicio') ?: date('Y-m-d'), input('fecha_limite') ?: null]);
        log_activity('projects', "Proyecto creado: $nombre", $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_project':
        if (!can_edit($user_id, 'projects')) fail('Sin permiso');
        $id = input_int('id');
        $estado = input('estado');
        db_execute('UPDATE proyectos SET nombre=?, cliente_id=?, descripcion=?, responsable_id=?, estado=?, prioridad=?, fecha_limite=?, completado_at=CASE WHEN ?="completado" THEN datetime("now") ELSE completado_at END, updated_at=datetime("now") WHERE id=?',
            [input('nombre'), input_int('cliente_id'), input('descripcion'), input_int_null('responsable_id'), $estado, input('prioridad'), input('fecha_limite') ?: null, $estado, $id]);
        log_activity('projects', 'Proyecto actualizado: ' . input('nombre'), input_int('cliente_id'));
        respond();
        break;

    // ---- TAREAS ----
    case 'get_task':
        $t = query_one('SELECT * FROM tareas WHERE id = ?', [input_int('id')]);
        $t ? respond($t) : fail('Tarea no encontrada');
        break;

    case 'create_task':
        if (!can_edit($user_id, 'tasks')) fail('Sin permiso');
        $titulo = input('titulo');
        $cliente_id = input_int('cliente_id');
        if (empty($titulo) || !$cliente_id) fail('Título y cliente son obligatorios');
        db_execute('INSERT INTO tareas (proyecto_id, cliente_id, titulo, descripcion, asignado_a, creado_por, prioridad, fecha_limite) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [input_int_null('proyecto_id'), $cliente_id, $titulo, input('descripcion'), input_int_null('asignado_a'), $user_id, input('prioridad') ?: 'media', input('fecha_limite') ?: null]);
        log_activity('tasks', "Tarea creada: $titulo", $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_task':
        if (!can_edit($user_id, 'tasks')) fail('Sin permiso');
        $id = input_int('id');
        $estado = input('estado');
        // Si solo viene id + estado, actualización rápida
        if (!empty($estado) && empty(input('titulo'))) {
            db_execute('UPDATE tareas SET estado=?, completado_at=CASE WHEN ?="completada" THEN datetime("now") ELSE completado_at END, updated_at=datetime("now") WHERE id=?',
                [$estado, $estado, $id]);
        } else {
            db_execute('UPDATE tareas SET titulo=?, proyecto_id=?, cliente_id=?, descripcion=?, asignado_a=?, estado=?, prioridad=?, fecha_limite=?, completado_at=CASE WHEN ?="completada" THEN datetime("now") ELSE completado_at END, updated_at=datetime("now") WHERE id=?',
                [input('titulo'), input_int_null('proyecto_id'), input_int('cliente_id'), input('descripcion'), input_int_null('asignado_a'), $estado, input('prioridad'), input('fecha_limite') ?: null, $estado, $id]);
        }
        log_activity('tasks', "Tarea actualizada (ID: $id)");
        respond();
        break;

    // ---- FACTURACIÓN ----
    case 'get_invoice':
        $f = query_one('SELECT * FROM facturas WHERE id = ?', [input_int('id')]);
        $f ? respond($f) : fail('Factura no encontrada');
        break;

    case 'create_invoice':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        $numero = input('numero');
        $cliente_id = input_int('cliente_id');
        $concepto = input('concepto');
        if (empty($numero) || !$cliente_id || empty($concepto)) fail('Número, cliente y concepto son obligatorios');
        $monto = input_int('monto');
        $impuesto = input_int('impuesto');
        $total = $monto + $impuesto;
        db_execute('INSERT INTO facturas (numero, cliente_id, proyecto_id, concepto, detalle, monto, impuesto, total, estado, fecha_emision, fecha_vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$numero, $cliente_id, input_int_null('proyecto_id'), $concepto, input('detalle'), $monto, $impuesto, $total, input('estado') ?: 'emitida', input('fecha_emision') ?: date('Y-m-d'), input('fecha_vencimiento') ?: null]);
        log_activity('billing', "Factura creada: $numero por " . format_money($total), $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_invoice':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        $id = input_int('id');
        $estado = input('estado');
        if ($estado && in_array($estado, ['emitida', 'anulada'])) {
            // Cambio de estado (emitir o anular) — triggers SQLite se encargan del resto
            db_execute('UPDATE facturas SET estado=?, updated_at=datetime("now") WHERE id=?', [$estado, $id]);
            log_activity('billing', "Factura ID:$id → $estado");
        } else {
            $monto = input_int('monto');
            $impuesto = input_int('impuesto');
            $total = $monto + $impuesto;
            db_execute('UPDATE facturas SET numero=?, cliente_id=?, proyecto_id=?, concepto=?, detalle=?, monto=?, impuesto=?, total=?, fecha_vencimiento=?, updated_at=datetime("now") WHERE id=?',
                [input('numero'), input_int('cliente_id'), input_int_null('proyecto_id'), input('concepto'), input('detalle'), $monto, $impuesto, $total, input('fecha_vencimiento') ?: null, $id]);
            log_activity('billing', 'Factura actualizada: ' . input('numero'));
        }
        respond();
        break;

    // ---- CUENTAS POR COBRAR ----
    case 'create_payment':
        if (!can_edit($user_id, 'receivables')) fail('Sin permiso');
        $cuenta_id = input_int('cuenta_cobrar_id');
        $monto = input_int('monto');
        if (!$cuenta_id || $monto <= 0) fail('Cuenta y monto son obligatorios');
        // Verificar que la cuenta existe y no está pagada
        $cuenta = query_one('SELECT * FROM cuentas_cobrar WHERE id = ?', [$cuenta_id]);
        if (!$cuenta) fail('Cuenta no encontrada');
        if ($cuenta['estado'] === 'pagado') fail('Esta cuenta ya está pagada');
        if ($monto > $cuenta['monto_pendiente']) fail('El monto excede lo pendiente');
        // Insertar abono — los triggers SQLite actualizan: cuenta_cobrar, factura, finanzas
        db_execute('INSERT INTO abonos (cuenta_cobrar_id, monto, metodo_pago, referencia, nota, fecha) VALUES (?, ?, ?, ?, ?, ?)',
            [$cuenta_id, $monto, input('metodo_pago') ?: 'transferencia', input('referencia'), input('nota'), input('fecha') ?: date('Y-m-d')]);
        log_activity('receivables', "Pago registrado: " . format_money($monto) . " en cuenta #$cuenta_id", $cuenta['cliente_id']);
        respond();
        break;

    case 'get_payments':
        $cuenta_id = input_int('cuenta_cobrar_id');
        $payments = query_all('SELECT * FROM abonos WHERE cuenta_cobrar_id = ? ORDER BY fecha DESC', [$cuenta_id]);
        respond($payments);
        break;

    // ---- FINANZAS ----
    case 'create_finance':
        if (!can_edit($user_id, 'finance')) fail('Sin permiso');
        $tipo = input('tipo');
        $descripcion = input('descripcion');
        $monto = input_int('monto');
        if (empty($tipo) || empty($descripcion) || $monto <= 0) fail('Tipo, descripción y monto son obligatorios');
        db_execute('INSERT INTO finanzas (tipo, categoria, descripcion, monto, cliente_id, fecha) VALUES (?, ?, ?, ?, ?, ?)',
            [$tipo, input('categoria') ?: 'general', $descripcion, $monto, input_int_null('cliente_id'), input('fecha') ?: date('Y-m-d')]);
        log_activity('finance', ucfirst($tipo) . " registrado: " . format_money($monto));
        respond(['id' => last_id()]);
        break;

    // ---- MARKETING ----
    case 'get_campaign':
        $ca = query_one('SELECT * FROM campanas WHERE id = ?', [input_int('id')]);
        $ca ? respond($ca) : fail('Campaña no encontrada');
        break;

    case 'create_campaign':
        if (!can_edit($user_id, 'marketing')) fail('Sin permiso');
        $nombre = input('nombre');
        $cliente_id = input_int('cliente_id');
        if (empty($nombre) || !$cliente_id) fail('Nombre y cliente son obligatorios');
        db_execute('INSERT INTO campanas (cliente_id, nombre, plataforma, estado, presupuesto, fecha_inicio, fecha_fin, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$cliente_id, $nombre, input('plataforma') ?: 'otro', input('estado') ?: 'activa', input_int('presupuesto'), input('fecha_inicio') ?: null, input('fecha_fin') ?: null, input('notas')]);
        log_activity('marketing', "Campaña creada: $nombre", $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_campaign':
        if (!can_edit($user_id, 'marketing')) fail('Sin permiso');
        $id = input_int('id');
        db_execute('UPDATE campanas SET nombre=?, cliente_id=?, plataforma=?, estado=?, presupuesto=?, gasto_actual=?, impresiones=?, clics=?, conversiones=?, fecha_inicio=?, fecha_fin=?, notas=?, updated_at=datetime("now") WHERE id=?',
            [input('nombre'), input_int('cliente_id'), input('plataforma'), input('estado'), input_int('presupuesto'), input_int('gasto_actual'), input_int('impresiones'), input_int('clics'), input_int('conversiones'), input('fecha_inicio') ?: null, input('fecha_fin') ?: null, input('notas'), $id]);
        log_activity('marketing', 'Campaña actualizada: ' . input('nombre'));
        respond();
        break;

    // ---- EQUIPO ----
    case 'get_member':
        $e = query_one('SELECT * FROM equipo WHERE id = ?', [input_int('id')]);
        $e ? respond($e) : fail('Miembro no encontrado');
        break;

    case 'create_member':
        if (!can_edit($user_id, 'team')) fail('Sin permiso');
        $nombre = input('nombre');
        if (empty($nombre)) fail('El nombre es obligatorio');
        db_execute('INSERT INTO equipo (nombre, cargo, email) VALUES (?, ?, ?)',
            [$nombre, input('cargo'), input('email')]);
        log_activity('team', "Miembro agregado: $nombre");
        respond(['id' => last_id()]);
        break;

    case 'update_member':
        if (!can_edit($user_id, 'team')) fail('Sin permiso');
        $id = input_int('id');
        db_execute('UPDATE equipo SET nombre=?, cargo=?, email=?, activo=? WHERE id=?',
            [input('nombre'), input('cargo'), input('email'), input_int('activo'), $id]);
        log_activity('team', 'Miembro actualizado: ' . input('nombre'));
        respond();
        break;

    // ---- ADMIN ----
    case 'get_user':
        if ($_SESSION['user_role'] !== 'admin') fail('Sin permiso');
        $u = query_one('SELECT id, username, nombre, email, role, activo, last_login FROM usuarios WHERE id = ?', [input_int('id')]);
        $u ? respond($u) : fail('Usuario no encontrado');
        break;

    case 'create_user':
        if ($_SESSION['user_role'] !== 'admin') fail('Sin permiso');
        $username = input('username');
        $nombre = input('nombre');
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($nombre) || empty($password)) fail('Usuario, nombre y contraseña son obligatorios');
        if (strlen($password) < 6) fail('La contraseña debe tener al menos 6 caracteres');
        $existing = query_one('SELECT id FROM usuarios WHERE username = ?', [$username]);
        if ($existing) fail('El usuario ya existe');
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db_execute('INSERT INTO usuarios (username, password_hash, nombre, email, role) VALUES (?, ?, ?, ?, ?)',
            [$username, $hash, $nombre, input('email'), input('role') ?: 'user']);
        log_activity('admin', "Usuario creado: $username");
        respond(['id' => last_id()]);
        break;

    case 'update_user':
        if ($_SESSION['user_role'] !== 'admin') fail('Sin permiso');
        $id = input_int('id');
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            if (strlen($password) < 6) fail('La contraseña debe tener al menos 6 caracteres');
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db_execute('UPDATE usuarios SET username=?, nombre=?, email=?, role=?, activo=?, password_hash=?, updated_at=datetime("now") WHERE id=?',
                [input('username'), input('nombre'), input('email'), input('role'), input_int('activo'), $hash, $id]);
        } else {
            db_execute('UPDATE usuarios SET username=?, nombre=?, email=?, role=?, activo=?, updated_at=datetime("now") WHERE id=?',
                [input('username'), input('nombre'), input('email'), input('role'), input_int('activo'), $id]);
        }
        log_activity('admin', 'Usuario actualizado: ' . input('username'));
        respond();
        break;

    case 'get_permissions':
        if ($_SESSION['user_role'] !== 'admin') fail('Sin permiso');
        respond(get_all_permissions(input_int('user_id')));
        break;

    case 'save_permissions':
        if ($_SESSION['user_role'] !== 'admin') fail('Sin permiso');
        $target_user_id = input_int('user_id');
        $perms = json_decode($_POST['permissions'] ?? '{}', true);
        if (!$perms) fail('Permisos inválidos');
        // Borrar permisos anteriores y re-insertar
        db_execute('DELETE FROM permisos WHERE usuario_id = ?', [$target_user_id]);
        foreach ($perms as $modulo => $p) {
            db_execute('INSERT INTO permisos (usuario_id, modulo, puede_ver, puede_editar) VALUES (?, ?, ?, ?)',
                [$target_user_id, $modulo, (int)($p['ver'] ?? 0), (int)($p['editar'] ?? 0)]);
        }
        log_activity('admin', "Permisos actualizados para usuario ID:$target_user_id");
        respond();
        break;

    // ---- FACTURACIÓN MENSUAL AUTOMÁTICA ----
    case 'generate_monthly_billing':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        $mes_actual = date('Y-m');
        $clientes_activos = query_all('SELECT id, nombre, fee_mensual FROM clientes WHERE tipo = "activo" AND fee_mensual > 0 AND estado_pago != "canje"');
        $created = 0;
        $skipped = 0;
        // Obtener último número de factura
        $last_num = query_scalar('SELECT numero FROM facturas ORDER BY id DESC LIMIT 1') ?? 'F-0000';
        $next_int = intval(preg_replace('/\D/', '', $last_num)) + 1;

        foreach ($clientes_activos as $cli) {
            // Verificar si ya existe factura este mes para este cliente
            $exists = query_scalar('SELECT COUNT(*) FROM facturas WHERE cliente_id = ? AND strftime("%Y-%m", fecha_emision) = ? AND estado != "anulada"', [$cli['id'], $mes_actual]);
            if ($exists > 0) {
                $skipped++;
                continue;
            }
            $numero = 'F-' . str_pad($next_int, 4, '0', STR_PAD_LEFT);
            $monto = $cli['fee_mensual'];
            $impuesto = round($monto * 0.19);
            $total = $monto + $impuesto;
            $concepto = 'Fee mensual ' . date('F Y') . ' — ' . $cli['nombre'];
            $vencimiento = date('Y-m-d', strtotime('+30 days'));

            db_execute('INSERT INTO facturas (numero, cliente_id, concepto, monto, impuesto, total, estado, fecha_emision, fecha_vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$numero, $cli['id'], $concepto, $monto, $impuesto, $total, 'emitida', date('Y-m-d'), $vencimiento]);
            $next_int++;
            $created++;
        }
        log_activity('billing', "Facturación mensual generada: $created facturas creadas, $skipped omitidas");
        respond(['created' => $created, 'skipped' => $skipped]);
        break;

    default:
        fail('Acción no reconocida: ' . $action);
}
