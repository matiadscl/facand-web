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

// Suprimir warnings para garantizar JSON limpio
error_reporting(E_ERROR);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

// CSRF para POST (excepto uploads que usan FormData con archivos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'extract_pdf_data') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página e intenta de nuevo.']);
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
        db_execute('INSERT INTO facturas (numero, cliente_id, proyecto_id, concepto, detalle, monto, impuesto, total, estado, fecha_emision, fecha_vencimiento, periodo_servicio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$numero, $cliente_id, input_int_null('proyecto_id'), $concepto, input('detalle'), $monto, $impuesto, $total, input('estado') ?: 'emitida', input('fecha_emision') ?: date('Y-m-d'), input('fecha_vencimiento') ?: null, input('periodo_servicio') ?: '']);
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
            db_execute('UPDATE facturas SET numero=?, cliente_id=?, proyecto_id=?, concepto=?, detalle=?, monto=?, impuesto=?, total=?, fecha_vencimiento=?, periodo_servicio=?, updated_at=datetime("now") WHERE id=?',
                [input('numero'), input_int('cliente_id'), input_int_null('proyecto_id'), input('concepto'), input('detalle'), $monto, $impuesto, $total, input('fecha_vencimiento') ?: null, input('periodo_servicio') ?: '', $id]);
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
        if (!can_edit($user_id, 'finance') && !can_edit($user_id, 'movements')) fail('Sin permiso');
        $tipo = input('tipo');
        $descripcion = input('descripcion');
        $monto = input_int('monto');
        if (empty($tipo) || empty($descripcion) || $monto <= 0) fail('Tipo, descripción y monto son obligatorios');
        $fecha = input('fecha') ?: date('Y-m-d');
        db_execute('INSERT INTO finanzas (tipo, categoria, subcategoria, descripcion, monto, cliente_id, fecha, fecha_contable, origen, notas, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))',
            [$tipo, input('categoria') ?: 'general', input('subcategoria') ?: '', $descripcion, $monto, input_int_null('cliente_id'), $fecha, input('fecha_contable') ?: $fecha, input('origen') ?: 'manual', input('notas') ?: '']);
        log_activity('finance', ucfirst($tipo) . " registrado: " . format_money($monto));
        respond(['id' => last_id()]);
        break;

    case 'save_budget':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $mes = input('mes');
        $cat = input('categoria');
        $monto_plan = input_int('monto_plan');
        if (!$mes || !$cat) fail('Mes y categoría obligatorios');
        db_execute('INSERT OR REPLACE INTO presupuesto (mes, categoria, monto_plan) VALUES (?, ?, ?)', [$mes, $cat, $monto_plan]);
        respond();
        break;

    case 'get_budget_items':
        $items = query_all('SELECT * FROM budget_items WHERE activo = 1 ORDER BY categoria, orden, nombre');
        respond($items);
        break;

    case 'create_budget_item':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $cat = input('categoria');
        $nombre = input('nombre');
        $tipo = input('tipo_costo') ?: 'fijo';
        $valor = input_int('valor_default');
        if (!$cat || !$nombre) fail('Categoría y nombre obligatorios');
        $orden = query_scalar('SELECT COALESCE(MAX(orden),0)+1 FROM budget_items WHERE categoria = ?', [$cat]) ?? 1;
        db_execute('INSERT INTO budget_items (categoria, nombre, tipo_costo, valor_default, orden) VALUES (?, ?, ?, ?, ?)',
            [$cat, $nombre, $tipo, $valor, $orden]);
        respond(['id' => last_id()]);
        break;

    case 'update_budget_item':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $id = input_int('id');
        db_execute('UPDATE budget_items SET nombre=?, tipo_costo=?, valor_default=? WHERE id=?',
            [input('nombre'), input('tipo_costo'), input_int('valor_default'), $id]);
        respond();
        break;

    case 'delete_budget_item':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        db_execute('UPDATE budget_items SET activo = 0 WHERE id = ?', [input_int('id')]);
        db_execute('DELETE FROM budget_values WHERE item_id = ?', [input_int('id')]);
        respond();
        break;

    case 'save_budget_value':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $item_id = input_int('item_id');
        $mes = input('mes');
        $valor = input_int('valor');
        db_execute('INSERT OR REPLACE INTO budget_values (item_id, mes, valor) VALUES (?, ?, ?)', [$item_id, $mes, $valor]);
        respond();
        break;

    case 'save_budget_bulk':
        if (!can_edit($user_id, 'budget') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $data = json_decode($_POST['data'] ?? '[]', true);
        $saved = 0;
        foreach ($data as $d) {
            db_execute('INSERT OR REPLACE INTO budget_values (item_id, mes, valor) VALUES (?, ?, ?)',
                [(int)$d['item_id'], $d['mes'], (int)$d['valor']]);
            $saved++;
        }
        respond(['saved' => $saved]);
        break;

    // ---- CUENTA CORRIENTE CLIENTE ----
    case 'get_saldo_cliente':
        $cliente_id = input_int('cliente_id');
        $cargos = query_scalar("SELECT COALESCE(SUM(monto),0) FROM cuenta_corriente WHERE cliente_id = ? AND tipo IN ('factura','gasto','ajuste')", [$cliente_id]) ?? 0;
        $pagos = query_scalar("SELECT COALESCE(SUM(monto),0) FROM cuenta_corriente WHERE cliente_id = ? AND tipo = 'pago'", [$cliente_id]) ?? 0;
        $saldo = $cargos - $pagos; // negativo = a favor
        respond(['cargos' => $cargos, 'pagos' => $pagos, 'saldo' => $saldo]);
        break;

    case 'get_cta_corriente':
        $cliente_id = input_int('cliente_id');
        $movs = query_all('SELECT * FROM cuenta_corriente WHERE cliente_id = ? ORDER BY fecha DESC, id DESC', [$cliente_id]);
        respond($movs);
        break;

    case 'create_cc_movimiento':
        if (!can_edit($user_id, 'cta_corriente') && !can_edit($user_id, 'finance')) fail('Sin permiso');
        $cliente_id = input_int('cliente_id');
        $tipo = input('tipo');
        $desc = input('descripcion');
        $monto = input_int('monto');
        $fecha = input('fecha') ?: date('Y-m-d');
        if (!$cliente_id || !$tipo || !$desc || !$monto) fail('Todos los campos son obligatorios');
        db_execute('INSERT INTO cuenta_corriente (cliente_id, tipo, descripcion, monto, fecha) VALUES (?, ?, ?, ?, ?)',
            [$cliente_id, $tipo, $desc, $monto, $fecha]);
        // Si es un gasto contra cuenta cliente, registrar en finanzas como gasto
        if ($tipo === 'gasto') {
            db_execute('INSERT INTO finanzas (tipo, categoria, descripcion, monto, cliente_id, fecha, origen, created_at) VALUES ("gasto", "Gastos clientes", ?, ?, ?, ?, "manual", datetime("now"))',
                [$desc, $monto, $cliente_id, $fecha]);
        }
        log_activity('finance', "Cta corriente: $tipo $desc " . format_money($monto), $cliente_id);
        respond(['id' => last_id()]);
        break;

    // ---- MARKETING ----
    case 'get_campaign':
        $ca = query_one('SELECT * FROM campanas WHERE id = ?', [input_int('id')]);
        $ca ? respond($ca) : fail('Campaña no encontrada');
        break;

    // ---- SERVICIOS ----
    case 'get_service':
        $id = input_int('id');
        $service = query_one('SELECT * FROM servicios_cliente WHERE id = ?', [$id]);
        if (!$service) fail('Servicio no encontrado');
        respond($service);
        break;

    case 'create_service':
        if (!can_edit($user_id, 'services')) fail('Sin permiso');
        $nombre = input('nombre');
        $cliente_id = input_int('cliente_id');
        if (empty($nombre) || !$cliente_id) fail('Nombre y cliente son obligatorios');
        db_execute('INSERT INTO servicios_cliente (cliente_id, nombre, tipo, monto, estado, fecha_inicio, fecha_fin, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$cliente_id, $nombre, input('tipo') ?: 'suscripcion', input_int('monto'), input('estado') ?: 'activo', input('fecha_inicio') ?: null, input('fecha_fin') ?: null, input('notas')]);
        log_activity('services', "Servicio creado: $nombre", $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_service':
        if (!can_edit($user_id, 'services')) fail('Sin permiso');
        $id = input_int('id');
        $estado = input('estado');
        $fecha_pausa = input('fecha_pausa') ?: null;
        $fecha_reanudacion = input('fecha_reanudacion') ?: null;

        // Si cambia a pausado y no tiene fecha_pausa, usar hoy
        if ($estado === 'pausado' && !$fecha_pausa) $fecha_pausa = date('Y-m-d');
        // Si cambia a activo y tenía pausa sin reanudacion, cerrar la pausa
        if ($estado === 'activo') {
            $prev = query_one('SELECT estado, fecha_pausa FROM servicios_cliente WHERE id = ?', [$id]);
            if ($prev && $prev['fecha_pausa'] && !$fecha_reanudacion) $fecha_reanudacion = date('Y-m-d');
        }

        db_execute('UPDATE servicios_cliente SET cliente_id=?, nombre=?, tipo=?, monto=?, estado=?, fecha_inicio=?, fecha_fin=?, fecha_pausa=?, fecha_reanudacion=?, notas=?, updated_at=datetime("now") WHERE id=?',
            [input_int('cliente_id'), input('nombre'), input('tipo'), input_int('monto'), $estado, input('fecha_inicio') ?: null, input('fecha_fin') ?: null, $fecha_pausa, $fecha_reanudacion, input('notas'), $id]);
        log_activity('services', 'Servicio actualizado: ' . input('nombre'));
        respond();
        break;

    // ---- INTERACCIONES ----
    case 'get_interactions':
        $cliente_id = input_int('cliente_id');
        $interactions = query_all('SELECT i.*, e.nombre as responsable_nombre FROM interacciones i LEFT JOIN equipo e ON i.responsable_id = e.id WHERE i.cliente_id = ? ORDER BY i.fecha DESC LIMIT 50', [$cliente_id]);
        respond($interactions);
        break;

    case 'create_interaction':
        if (!can_edit($user_id, 'crm')) fail('Sin permiso');
        $cliente_id = input_int('cliente_id');
        $contenido = input('contenido');
        if (!$cliente_id || empty($contenido)) fail('Cliente y contenido son obligatorios');
        db_execute('INSERT INTO interacciones (cliente_id, tipo, contenido, resultado, responsable_id, fecha) VALUES (?, ?, ?, ?, ?, ?)',
            [$cliente_id, input('tipo') ?: 'nota', $contenido, input('resultado'), input_int_null('responsable_id'), input('fecha') ?: date('Y-m-d H:i:s')]);
        log_activity('crm', "Interacción registrada: " . substr($contenido, 0, 50), $cliente_id);
        respond(['id' => last_id()]);
        break;

    // ---- MARKETING ----
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

    // ---- SUBIR FACTURA + CREAR AUTOMÁTICAMENTE ----
    case 'upload_and_create_invoice':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');

        $cliente_id = input_int('cliente_id');
        if (!$cliente_id) fail('Cliente obligatorio');

        $numero = input('numero');
        $concepto = input('concepto');
        $monto = input_int('monto');
        $impuesto = 0; // Facturas exentas de IVA
        $total = $monto;
        $fecha_emision = input('fecha_emision') ?: date('Y-m-d');
        $fecha_venc = date('Y-m-d', strtotime($fecha_emision . ' +30 days'));
        $estado_pago = input('estado_pago'); // 'pendiente' o 'pagada'
        $servicios = input('servicios');
        $notas = input('notas');

        if (empty($numero)) {
            $last = query_scalar('SELECT numero FROM facturas ORDER BY id DESC LIMIT 1') ?? 'F-0000';
            $numero = 'F-' . str_pad(intval(preg_replace('/\D/', '', $last)) + 1, 4, '0', STR_PAD_LEFT);
        }
        if (empty($concepto)) $concepto = 'Factura ' . $numero;

        $periodo = input('periodo_servicio') ?: '';

        // Crear factura como emitida (trigger genera CxC)
        db_execute('INSERT INTO facturas (numero, cliente_id, concepto, detalle, monto, impuesto, total, estado, fecha_emision, fecha_vencimiento, periodo_servicio) VALUES (?, ?, ?, ?, ?, ?, ?, "emitida", ?, ?, ?)',
            [$numero, $cliente_id, $concepto, ($servicios ? "Servicios: $servicios" : '') . ($notas ? "\n$notas" : ''), $monto, $impuesto, $total, $fecha_emision, $fecha_venc, $periodo]);
        $factura_id = last_id();

        // Si ya pagada → registrar abono (trigger actualiza CxC + finanzas)
        $pagada = false;
        if ($estado_pago === 'pagada' && $total > 0) {
            $cxc = query_one('SELECT id FROM cuentas_cobrar WHERE factura_id = ?', [$factura_id]);
            if ($cxc) {
                $metodo = input('metodo_pago') ?: 'transferencia';
                $fecha_pago = input('fecha_pago') ?: date('Y-m-d');
                db_execute("INSERT INTO abonos (cuenta_cobrar_id, monto, metodo_pago, referencia, fecha, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
                    [$cxc['id'], $total, $metodo, $numero, $fecha_pago]);
                $pagada = true;
            }
        }

        // Subir archivos adjuntos
        $uploaded = 0;
        $upload_dir = __DIR__ . '/../uploads/facturas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        if (!empty($_FILES['archivos'])) {
            $files = $_FILES['archivos'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                if ($error !== UPLOAD_ERR_OK) continue;
                $safe_name = date('Ymd_His') . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                $dest = $upload_dir . $safe_name;
                if (move_uploaded_file($tmp, $dest)) {
                    db_execute("INSERT INTO archivos_factura (factura_id, cliente_id, nombre_archivo, ruta, servicio, notas, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
                        [$factura_id, $cliente_id, $name, 'uploads/facturas/' . $safe_name, $servicios, $notas]);
                    $uploaded++;
                }
            }
        }

        // Guardar mapeo razón social si viene
        $razon_social = input('razon_social');
        if ($razon_social) {
            db_execute('INSERT OR REPLACE INTO rut_cliente_map (razon_social, rut, cliente_id) VALUES (?, ?, ?)',
                [$razon_social, input('rut_factura'), $cliente_id]);
        }

        log_activity('billing', "Factura $numero creada" . ($pagada ? ' (pagada)' : '') . " — " . format_money($total), $cliente_id);
        respond(['numero' => $numero, 'factura_id' => $factura_id, 'pagada' => $pagada, 'archivos' => $uploaded]);
        break;

    // ---- EXTRAER DATOS DE PDF ----
    case 'extract_pdf_data':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        if (empty($_FILES['pdf'])) {
            respond(['monto' => 0, 'razon_social' => '', 'rut' => '', 'numero_factura' => '', 'fecha' => '', 'concepto' => '', 'cliente_sugerido' => null, 'texto_preview' => 'No se recibio archivo. FILES keys: ' . implode(',', array_keys($_FILES))]);
            break;
        }
        if ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            respond(['monto' => 0, 'razon_social' => '', 'rut' => '', 'numero_factura' => '', 'fecha' => '', 'concepto' => '', 'cliente_sugerido' => null, 'texto_preview' => 'Error upload: ' . $_FILES['pdf']['error']]);
            break;
        }

        $tmp = $_FILES['pdf']['tmp_name'];
        $text = '';

        // Extraer texto de streams comprimidos con PHP puro
        if (strlen(trim($text)) < 10) {
            $raw = file_get_contents($tmp);
            $text = '';
            $offset = 0;
            while (preg_match('/FlateDecode.*?stream\r?\n/s', $raw, $sm, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $sm[0][1] + strlen($sm[0][0]);
                $end = strpos($raw, 'endstream', $start);
                if ($end === false) break;
                $chunk = substr($raw, $start, $end - $start);
                $decoded = @gzuncompress($chunk);
                if ($decoded === false) {
                    for ($skip = 0; $skip < 3; $skip++) {
                        $decoded = @gzinflate(substr($chunk, $skip));
                        if ($decoded !== false) break;
                    }
                }
                if ($decoded !== false && strlen($decoded) > 20) {
                    // Solo procesar si tiene keywords de factura
                    if (preg_match('/(?:FACTURA|TOTAL|SE|R\.U\.T|EXENT|Fecha)/i', $decoded)) {
                        // Extraer texto de operadores PDF: (texto)Tj y [(texto)]TJ
                        // NO decodificar \( \) antes — usar el formato raw del PDF
                        if (preg_match_all('/\(([^)]*(?:\\\\.[^)]*)*)\)\s*Tj/i', $decoded, $ptexts)) {
                            foreach ($ptexts[1] as $fragment) {
                                // Decodificar octales dentro del fragmento
                                $fragment = preg_replace_callback('/\\\\(\d{3})/', fn($m) => chr(octdec($m[1])), $fragment);
                                $fragment = str_replace(['\\(', '\\)'], ['(', ')'], $fragment);
                                if (strlen(trim($fragment)) > 0) {
                                    $text .= trim($fragment) . "\n";
                                }
                            }
                        }
                    }
                }
                $offset = $end;
            }
        }

        // Si aún no hay texto, responder vacío (no fallar)
        if (strlen(trim($text)) < 5) {
            respond(['monto' => 0, 'razon_social' => '', 'rut' => '', 'numero_factura' => '', 'fecha' => '', 'concepto' => '', 'cliente_sugerido' => null, 'texto_preview' => 'No se pudo extraer texto del PDF']);
            break;
        }

        // ---- PARSER DE FACTURA CHILENA (SII) ----

        // Extraer TOTAL
        $monto = 0;
        if (preg_match('/TOTAL\s*\$\s*([\d.,]+)/i', $text, $m)) {
            $monto = (int)str_replace(['.', ','], ['', ''], $m[1]);
        } elseif (preg_match('/EXENTO\s*\$\s*([\d.,]+)/i', $text, $m)) {
            $monto = (int)str_replace(['.', ','], ['', ''], $m[1]);
        }

        // Extraer razón social del cliente — en línea siguiente a SEÑOR(ES):
        $razon_social = '';
        $lines = explode("\n", $text);
        for ($li = 0; $li < count($lines); $li++) {
            if (preg_match('/SE.{0,3}OR/i', $lines[$li]) && isset($lines[$li + 1])) {
                $next = trim($lines[$li + 1]);
                if ($next && !preg_match('/^R\.U\.T|^GIRO|^DIREC/i', $next)) {
                    $razon_social = $next;
                    break;
                }
            }
        }

        // Extraer RUTs — buscar todos los patrones XX.XXX.XXX-X
        $rut = '';
        if (preg_match_all('/(\d{1,2}[\.\d]{4,10}-\s*[\dkK])/i', $text, $allRuts)) {
            $cleaned = array_map(fn($r) => str_replace(' ', '', $r), $allRuts[1]);
            $unique = array_unique($cleaned);
            // El RUT del cliente es el que NO es el emisor (78.373.125-8 = Facand)
            foreach ($unique as $r) {
                if (strpos($r, '78.373.125') === false) {
                    $rut = $r;
                    break;
                }
            }
        }

        // Extraer N° factura — buscar línea que tenga solo N + algo + dígitos
        $numero_factura = '';
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if (preg_match('/^N.{0,3}(\d+)$/i', $ln)) {
                preg_match('/(\d+)/', $ln, $nm);
                $numero_factura = $nm[1] ?? '';
                break;
            }
        }

        // Extraer fecha emisión
        $fecha = '';
        $meses_map = ['enero'=>'01','febrero'=>'02','marzo'=>'03','abril'=>'04','mayo'=>'05','junio'=>'06','julio'=>'07','agosto'=>'08','septiembre'=>'09','octubre'=>'10','noviembre'=>'11','diciembre'=>'12'];
        // Buscar patrón "XX de MES del YYYY" en cualquier línea
        if (preg_match('/(\d{1,2})\s+de\s+([A-Za-z]+)\s+(?:del?\s+)?(\d{4})/i', $text, $m)) {
            $mes = $meses_map[strtolower($m[2])] ?? '01';
            $fecha = $m[3] . '-' . $mes . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        } elseif (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $text, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $fecha = "$year-" . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        $concepto = '';

        // Buscar match de razón social con clientes conocidos
        $cliente_sugerido = null;
        if ($razon_social) {
            $map = query_one('SELECT cliente_id FROM rut_cliente_map WHERE razon_social = ? OR razon_social LIKE ?',
                [$razon_social, '%' . substr($razon_social, 0, 20) . '%']);
            if ($map) $cliente_sugerido = (int)$map['cliente_id'];
        }
        if (!$cliente_sugerido && $rut) {
            $map = query_one('SELECT cliente_id FROM rut_cliente_map WHERE rut = ?', [$rut]);
            if ($map) $cliente_sugerido = (int)$map['cliente_id'];
        }

        respond([
            'monto' => $monto,
            'razon_social' => $razon_social,
            'rut' => $rut,
            'numero_factura' => $numero_factura,
            'fecha' => $fecha,
            'concepto' => $concepto,
            'cliente_sugerido' => $cliente_sugerido,
            'texto_preview' => mb_substr($text, 0, 2000),
        ]);
        break;

    // ---- ARCHIVOS DE FACTURA (legacy) ----
    case 'upload_invoices':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        $cliente_id = input_int('cliente_id');
        if (!$cliente_id) fail('Cliente obligatorio');
        $factura_id = input_int_null('factura_id');
        $servicio = input('servicio');
        $notas = input('notas');

        if (empty($_FILES['archivos']) && empty($_FILES['archivos_'])) fail('No se recibieron archivos');
        $files = $_FILES['archivos'] ?? $_FILES['archivos_'];
        $upload_dir = __DIR__ . '/../uploads/facturas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $uploaded = 0;
        $count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

            if ($error !== UPLOAD_ERR_OK) continue;
            if ($size > 10 * 1024 * 1024) continue; // max 10MB

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'xml'])) continue;

            $safe_name = date('Ymd_His') . '_' . $uploaded . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            $dest = $upload_dir . $safe_name;

            if (move_uploaded_file($tmp, $dest)) {
                db_execute("INSERT INTO archivos_factura (factura_id, cliente_id, nombre_archivo, ruta, servicio, notas, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
                    [$factura_id, $cliente_id, $name, 'uploads/facturas/' . $safe_name, $servicio, $notas]);
                $uploaded++;
            }
        }

        if ($uploaded === 0) fail('No se pudo subir ningun archivo');
        log_activity('billing', "Subidos $uploaded archivos para cliente ID:$cliente_id", $cliente_id);
        respond(['uploaded' => $uploaded]);
        break;

    case 'get_invoice_files':
        $cliente_id = input_int('cliente_id');
        $factura_id = input_int('factura_id');
        $where = '1=1';
        $params = [];
        if ($cliente_id) { $where .= ' AND af.cliente_id = ?'; $params[] = $cliente_id; }
        if ($factura_id) { $where .= ' AND af.factura_id = ?'; $params[] = $factura_id; }
        $files = query_all("SELECT af.*, c.nombre as cliente_nombre, f.numero as factura_numero
            FROM archivos_factura af
            LEFT JOIN clientes c ON af.cliente_id = c.id
            LEFT JOIN facturas f ON af.factura_id = f.id
            WHERE $where ORDER BY af.created_at DESC", $params);
        respond($files);
        break;

    case 'delete_invoice_file':
        if (!can_edit($user_id, 'billing')) fail('Sin permiso');
        $id = input_int('id');
        $file = query_one('SELECT ruta FROM archivos_factura WHERE id = ?', [$id]);
        if ($file) {
            $path = __DIR__ . '/../' . $file['ruta'];
            if (file_exists($path)) unlink($path);
            db_execute('DELETE FROM archivos_factura WHERE id = ?', [$id]);
            respond();
        } else {
            fail('Archivo no encontrado');
        }
        break;

    // ---- PRESUPUESTOS ----
    case 'get_presupuesto':
        $p = query_one('SELECT * FROM presupuestos WHERE id = ?', [input_int('id')]);
        $p ? respond($p) : fail('Presupuesto no encontrado');
        break;

    case 'create_presupuesto':
        if (!can_edit($user_id, 'services')) fail('Sin permiso');
        $nombre = input('nombre');
        $cliente_id = input_int('cliente_id');
        if (empty($nombre) || !$cliente_id) fail('Nombre y cliente son obligatorios');
        db_execute('INSERT INTO presupuestos (cliente_id, nombre, servicios_detalle, monto_total, estado, fecha_emision, fecha_validez, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$cliente_id, $nombre, input('servicios_detalle'), input_int('monto_total'), input('estado') ?: 'borrador', input('fecha_emision') ?: date('Y-m-d'), input('fecha_validez') ?: null, input('notas')]);
        log_activity('services', "Presupuesto creado: $nombre", $cliente_id);
        respond(['id' => last_id()]);
        break;

    case 'update_presupuesto':
        if (!can_edit($user_id, 'services')) fail('Sin permiso');
        $id = input_int('id');
        db_execute('UPDATE presupuestos SET cliente_id=?, nombre=?, servicios_detalle=?, monto_total=?, estado=?, fecha_emision=?, fecha_validez=?, notas=? WHERE id=?',
            [input_int('cliente_id'), input('nombre'), input('servicios_detalle'), input_int('monto_total'), input('estado'), input('fecha_emision') ?: null, input('fecha_validez') ?: null, input('notas'), $id]);
        log_activity('services', 'Presupuesto actualizado: ' . input('nombre'));
        respond();
        break;

    // ---- MERCADO PAGO ----
    case 'import_mercadopago':
        if (!can_edit($user_id, 'finance') && !can_edit($user_id, 'conciliation')) fail('Sin permiso');

        $cred_file = __DIR__ . '/../../../../.credentials/facand_mercadopago.env';
        if (!file_exists($cred_file)) fail('Credenciales de Mercado Pago no configuradas');

        $token = '';
        foreach (file($cred_file) as $line) {
            if (str_starts_with(trim($line), 'MP_ACCESS_TOKEN=')) {
                $token = trim(substr(trim($line), strlen('MP_ACCESS_TOKEN=')));
            }
        }
        if (!$token) fail('Access Token no encontrado');

        // Obtener último movimiento MP importado para no duplicar
        $last_date = query_scalar("SELECT MAX(fecha) FROM finanzas WHERE origen = 'mercadopago'");

        $all_results = [];
        $offset_mp = 0;
        $limit_mp = 100;

        do {
            $params = http_build_query([
                'sort' => 'date_created',
                'criteria' => 'asc',
                'limit' => $limit_mp,
                'offset' => $offset_mp,
            ]);
            $url = "https://api.mercadopago.com/v1/payments/search?$params";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
                CURLOPT_TIMEOUT => 30,
            ]);
            $resp = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) fail("Error de Mercado Pago (HTTP $http_code)");

            $data = json_decode($resp, true);
            if (!$data || !isset($data['results'])) fail('Respuesta inválida de Mercado Pago');

            $all_results = array_merge($all_results, $data['results']);
            $offset_mp += $limit_mp;
            $total = $data['paging']['total'] ?? 0;
        } while ($offset_mp < $total);

        $imported = 0;
        $skipped = 0;
        $movements = [];

        foreach ($all_results as $p) {
            if ($p['status'] !== 'approved') { $skipped++; continue; }

            $fecha = substr($p['date_created'] ?? '', 0, 10);
            $desc = $p['description'] ?? 'Sin descripción';
            $monto = (int)($p['transaction_amount'] ?? 0);
            $op_type = $p['operation_type'] ?? '';
            $method = $p['payment_method_id'] ?? '';
            $mp_id = (string)($p['id'] ?? '');

            // Determinar tipo: account_fund = ingreso, regular_payment = gasto
            $tipo = ($op_type === 'account_fund') ? 'ingreso' : 'gasto';
            $categoria = ($tipo === 'ingreso') ? 'Transferencia MP' : 'Servicios digitales';

            // Evitar duplicados por mp_id en notas
            $exists = query_scalar("SELECT COUNT(*) FROM finanzas WHERE origen = 'mercadopago' AND notas LIKE ?", ["%mp_id:$mp_id%"]);
            if ($exists > 0) { $skipped++; continue; }

            db_execute('INSERT INTO finanzas (tipo, categoria, subcategoria, descripcion, monto, cliente_id, fecha, fecha_contable, origen, notas, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, "mercadopago", ?, datetime("now"))',
                [$tipo, $categoria, $method, $desc, $monto, $fecha, $fecha, "mp_id:$mp_id|method:$method|op:$op_type"]);

            $movements[] = [
                'fecha' => $fecha,
                'tipo' => $tipo,
                'descripcion' => $desc,
                'monto' => $monto,
                'metodo' => $method,
                'mp_id' => $mp_id,
            ];
            $imported++;
        }

        log_activity('conciliation', "Importación Mercado Pago: $imported nuevos, $skipped omitidos");
        respond(['imported' => $imported, 'skipped' => $skipped, 'total_api' => count($all_results), 'movements' => $movements]);
        break;

    case 'get_mp_movements':
        $movs = query_all("SELECT id, tipo, categoria, subcategoria, descripcion, monto, fecha, notas, created_at FROM finanzas WHERE origen = 'mercadopago' ORDER BY fecha DESC, id DESC");
        respond($movs ?: []);
        break;

    default:
        fail('Acción no reconocida: ' . $action);
}
