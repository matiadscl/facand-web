<?php
/**
 * Install Wizard — Setup inicial del dashboard
 * Crea la base de datos, tablas y usuario admin
 * Se ejecuta una sola vez; luego redirige al login
 */

$db_path = __DIR__ . '/data/dashboard.db';

// Si ya existe la DB, redirigir
if (file_exists($db_path)) {
    header('Location: login.php');
    exit;
}

$error = '';
$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $app_name  = trim($_POST['app_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $primary   = $_POST['primary_color'] ?? '#1e3a5f';
    $accent    = $_POST['accent_color'] ?? '#f97316';

    if (empty($app_name) || empty($username) || empty($password)) {
        $error = 'Todos los campos son obligatorios';
        $step = 1;
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
        $step = 1;
    } elseif ($password !== $password2) {
        $error = 'Las contraseñas no coinciden';
        $step = 1;
    } else {
        // Crear base de datos con schema completo
        $db = new SQLite3($db_path);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $schema = <<<'SQL'

        -- ============================================================
        -- USUARIOS Y PERMISOS
        -- ============================================================
        CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            nombre TEXT NOT NULL,
            email TEXT DEFAULT '',
            role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin','user')),
            activo INTEGER NOT NULL DEFAULT 1,
            last_login TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        );

        CREATE TABLE permisos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            modulo TEXT NOT NULL,
            puede_ver INTEGER NOT NULL DEFAULT 0,
            puede_editar INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE(usuario_id, modulo)
        );

        -- ============================================================
        -- CRM — Clientes (entidad central, todo se vincula aquí)
        -- ============================================================
        CREATE TABLE clientes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            rut TEXT DEFAULT '',
            email TEXT DEFAULT '',
            telefono TEXT DEFAULT '',
            direccion TEXT DEFAULT '',
            contacto_nombre TEXT DEFAULT '',
            contacto_cargo TEXT DEFAULT '',
            tipo TEXT NOT NULL DEFAULT 'prospecto' CHECK(tipo IN ('prospecto','activo','inactivo','cerrado')),
            etapa_pipeline TEXT NOT NULL DEFAULT 'lead' CHECK(etapa_pipeline IN ('lead','contactado','propuesta','negociacion','onboarding','activo','cerrado_ganado','cerrado_perdido')),
            -- Campos Facand
            rubro TEXT DEFAULT '',
            plan TEXT DEFAULT '',
            fee_mensual INTEGER DEFAULT 0,
            servicios TEXT DEFAULT '',
            herramientas TEXT DEFAULT '',
            presupuesto_ads TEXT DEFAULT '',
            etapa TEXT DEFAULT '',
            estado_pago TEXT DEFAULT 'pendiente' CHECK(estado_pago IN ('pendiente','pagado','vencido','canje')),
            url_dashboard TEXT DEFAULT '',
            responsable_id INTEGER,
            notas TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (responsable_id) REFERENCES equipo(id) ON DELETE SET NULL
        );

        -- ============================================================
        -- PROYECTOS — vinculados a clientes
        -- ============================================================
        CREATE TABLE proyectos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            descripcion TEXT DEFAULT '',
            responsable_id INTEGER,
            estado TEXT NOT NULL DEFAULT 'activo' CHECK(estado IN ('activo','pausado','completado','cancelado')),
            prioridad TEXT NOT NULL DEFAULT 'media' CHECK(prioridad IN ('critica','alta','media','baja')),
            fecha_inicio TEXT DEFAULT (date('now')),
            fecha_limite TEXT,
            completado_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (responsable_id) REFERENCES equipo(id) ON DELETE SET NULL
        );

        -- ============================================================
        -- TAREAS — vinculadas a proyectos y clientes
        -- ============================================================
        CREATE TABLE tareas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proyecto_id INTEGER,
            cliente_id INTEGER NOT NULL,
            titulo TEXT NOT NULL,
            descripcion TEXT DEFAULT '',
            asignado_a INTEGER,
            creado_por INTEGER,
            estado TEXT NOT NULL DEFAULT 'pendiente' CHECK(estado IN ('pendiente','en_progreso','completada','cancelada')),
            prioridad TEXT NOT NULL DEFAULT 'media' CHECK(prioridad IN ('critica','alta','media','baja')),
            fecha_limite TEXT,
            completado_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE SET NULL,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (asignado_a) REFERENCES equipo(id) ON DELETE SET NULL
        );

        -- ============================================================
        -- EQUIPO — miembros del equipo
        -- ============================================================
        CREATE TABLE equipo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            cargo TEXT DEFAULT '',
            email TEXT DEFAULT '',
            activo INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        );

        -- ============================================================
        -- FACTURACIÓN — facturas emitidas a clientes
        -- Al crear una factura se genera automáticamente una cuenta por cobrar
        -- ============================================================
        CREATE TABLE facturas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            proyecto_id INTEGER,
            numero TEXT UNIQUE NOT NULL,
            concepto TEXT NOT NULL,
            detalle TEXT DEFAULT '',
            monto INTEGER NOT NULL DEFAULT 0,
            impuesto INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0,
            estado TEXT NOT NULL DEFAULT 'emitida' CHECK(estado IN ('borrador','emitida','pagada','anulada')),
            fecha_emision TEXT DEFAULT (date('now')),
            fecha_vencimiento TEXT,
            periodo_servicio TEXT DEFAULT '',
            pagado_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE SET NULL
        );

        -- ============================================================
        -- CUENTAS POR COBRAR — generadas automáticamente desde facturas
        -- Se actualizan con abonos parciales o pago total
        -- Al completarse, registra ingreso en finanzas
        -- ============================================================
        CREATE TABLE cuentas_cobrar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            factura_id INTEGER NOT NULL,
            cliente_id INTEGER NOT NULL,
            monto_original INTEGER NOT NULL DEFAULT 0,
            monto_pagado INTEGER NOT NULL DEFAULT 0,
            monto_pendiente INTEGER NOT NULL DEFAULT 0,
            estado TEXT NOT NULL DEFAULT 'pendiente' CHECK(estado IN ('pendiente','parcial','pagado','vencido','anulado')),
            fecha_vencimiento TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE,
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
        );

        -- Abonos/pagos parciales a cuentas por cobrar
        CREATE TABLE abonos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cuenta_cobrar_id INTEGER NOT NULL,
            monto INTEGER NOT NULL,
            metodo_pago TEXT DEFAULT 'transferencia' CHECK(metodo_pago IN ('transferencia','efectivo','cheque','tarjeta','otro')),
            referencia TEXT DEFAULT '',
            nota TEXT DEFAULT '',
            fecha TEXT DEFAULT (date('now')),
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (cuenta_cobrar_id) REFERENCES cuentas_cobrar(id) ON DELETE CASCADE
        );

        -- ============================================================
        -- FINANZAS — movimientos de ingreso y gasto
        -- Los ingresos se generan automáticamente al cobrar facturas
        -- Los gastos se registran manualmente
        -- ============================================================
        CREATE TABLE finanzas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL CHECK(tipo IN ('ingreso','gasto')),
            categoria TEXT NOT NULL DEFAULT 'general',
            descripcion TEXT NOT NULL,
            monto INTEGER NOT NULL DEFAULT 0,
            cliente_id INTEGER,
            factura_id INTEGER,
            fecha TEXT DEFAULT (date('now')),
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
            FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE SET NULL
        );

        CREATE TABLE categorias_finanzas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT UNIQUE NOT NULL,
            tipo TEXT NOT NULL CHECK(tipo IN ('ingreso','gasto','ambos')),
            activa INTEGER NOT NULL DEFAULT 1
        );

        -- ============================================================
        -- SERVICIOS POR CLIENTE — servicios contratados individualmente
        -- ============================================================
        CREATE TABLE servicios_cliente (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'suscripcion' CHECK(tipo IN ('suscripcion','proyecto','addon','otro')),
            monto INTEGER NOT NULL DEFAULT 0,
            estado TEXT NOT NULL DEFAULT 'activo' CHECK(estado IN ('activo','pausado','cancelado')),
            fecha_inicio TEXT,
            fecha_fin TEXT,
            notas TEXT DEFAULT '',
            created_at TEXT DEFAULT (CURRENT_TIMESTAMP),
            updated_at TEXT DEFAULT (CURRENT_TIMESTAMP),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
        );

        -- ============================================================
        -- INTERACCIONES CRM — llamadas, emails, reuniones, notas
        -- ============================================================
        CREATE TABLE interacciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'nota' CHECK(tipo IN ('llamada','email','reunion','whatsapp','nota')),
            contenido TEXT NOT NULL,
            resultado TEXT DEFAULT '',
            responsable_id INTEGER,
            fecha TEXT DEFAULT (CURRENT_TIMESTAMP),
            created_at TEXT DEFAULT (CURRENT_TIMESTAMP),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (responsable_id) REFERENCES equipo(id) ON DELETE SET NULL
        );

        -- ============================================================
        -- MARKETING — campañas vinculadas a clientes
        -- ============================================================
        CREATE TABLE campanas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            plataforma TEXT NOT NULL DEFAULT 'otro' CHECK(plataforma IN ('meta','google','email','otro')),
            estado TEXT NOT NULL DEFAULT 'borrador' CHECK(estado IN ('borrador','activa','pausada','finalizada')),
            presupuesto INTEGER NOT NULL DEFAULT 0,
            gasto_actual INTEGER NOT NULL DEFAULT 0,
            fecha_inicio TEXT,
            fecha_fin TEXT,
            impresiones INTEGER DEFAULT 0,
            clics INTEGER DEFAULT 0,
            conversiones INTEGER DEFAULT 0,
            notas TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
        );

        -- ============================================================
        -- ACTIVIDAD — log de auditoría de todo el sistema
        -- ============================================================
        CREATE TABLE actividad (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            modulo TEXT NOT NULL,
            accion TEXT NOT NULL,
            cliente_id INTEGER,
            usuario_id INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );

        -- ============================================================
        -- CATEGORÍAS FINANCIERAS POR DEFECTO
        -- ============================================================
        INSERT INTO categorias_finanzas (nombre, tipo) VALUES
            ('Servicios profesionales', 'ingreso'),
            ('Suscripciones', 'ingreso'),
            ('Proyectos', 'ingreso'),
            ('Otros ingresos', 'ingreso'),
            ('Sueldos', 'gasto'),
            ('Software y herramientas', 'gasto'),
            ('Publicidad', 'gasto'),
            ('Arriendo', 'gasto'),
            ('Servicios básicos', 'gasto'),
            ('Impuestos', 'gasto'),
            ('Otros gastos', 'gasto');

        -- ============================================================
        -- TRIGGERS DE AUTOMATIZACIÓN
        -- ============================================================

        -- Trigger: Al crear factura emitida → crear cuenta por cobrar automáticamente
        CREATE TRIGGER auto_crear_cuenta_cobrar
        AFTER INSERT ON facturas
        WHEN NEW.estado = 'emitida'
        BEGIN
            INSERT INTO cuentas_cobrar (factura_id, cliente_id, monto_original, monto_pendiente, fecha_vencimiento)
            VALUES (NEW.id, NEW.cliente_id, NEW.total, NEW.total, NEW.fecha_vencimiento);
        END;

        -- Trigger: Al cambiar factura a emitida → crear cuenta por cobrar si no existe
        CREATE TRIGGER auto_crear_cuenta_cobrar_update
        AFTER UPDATE ON facturas
        WHEN NEW.estado = 'emitida' AND OLD.estado = 'borrador'
        BEGIN
            INSERT OR IGNORE INTO cuentas_cobrar (factura_id, cliente_id, monto_original, monto_pendiente, fecha_vencimiento)
            VALUES (NEW.id, NEW.cliente_id, NEW.total, NEW.total, NEW.fecha_vencimiento);
        END;

        -- Trigger: Al registrar abono → actualizar cuenta por cobrar
        CREATE TRIGGER auto_actualizar_cuenta_cobrar
        AFTER INSERT ON abonos
        BEGIN
            UPDATE cuentas_cobrar SET
                monto_pagado = monto_pagado + NEW.monto,
                monto_pendiente = monto_original - (monto_pagado + NEW.monto),
                estado = CASE
                    WHEN (monto_pagado + NEW.monto) >= monto_original THEN 'pagado'
                    ELSE 'parcial'
                END,
                updated_at = datetime('now')
            WHERE id = NEW.cuenta_cobrar_id;

            -- Si la cuenta quedó pagada, marcar factura como pagada
            UPDATE facturas SET
                estado = 'pagada',
                pagado_at = datetime('now'),
                updated_at = datetime('now')
            WHERE id = (SELECT factura_id FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id)
            AND (SELECT monto_pagado + NEW.monto FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id)
                >= (SELECT monto_original FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id);

            -- Registrar ingreso en finanzas
            INSERT INTO finanzas (tipo, categoria, descripcion, monto, cliente_id, factura_id, fecha)
            VALUES (
                'ingreso',
                'Servicios profesionales',
                'Pago factura #' || (SELECT numero FROM facturas WHERE id = (SELECT factura_id FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id)),
                NEW.monto,
                (SELECT cliente_id FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id),
                (SELECT factura_id FROM cuentas_cobrar WHERE id = NEW.cuenta_cobrar_id),
                NEW.fecha
            );
        END;

        -- Trigger: Al anular factura → anular cuenta por cobrar
        CREATE TRIGGER auto_anular_cuenta_cobrar
        AFTER UPDATE ON facturas
        WHEN NEW.estado = 'anulada' AND OLD.estado != 'anulada'
        BEGIN
            UPDATE cuentas_cobrar SET estado = 'anulado', updated_at = datetime('now')
            WHERE factura_id = NEW.id;
        END;

        -- Trigger: Al completar proyecto → completar tareas pendientes
        CREATE TRIGGER auto_completar_tareas_proyecto
        AFTER UPDATE ON proyectos
        WHEN NEW.estado = 'completado' AND OLD.estado != 'completado'
        BEGIN
            UPDATE tareas SET
                estado = 'completada',
                completado_at = datetime('now'),
                updated_at = datetime('now')
            WHERE proyecto_id = NEW.id AND estado IN ('pendiente', 'en_progreso');
        END;

        -- Trigger: Al cerrar cliente como ganado → activar cliente
        CREATE TRIGGER auto_activar_cliente
        AFTER UPDATE ON clientes
        WHEN NEW.etapa_pipeline = 'cerrado_ganado' AND OLD.etapa_pipeline != 'cerrado_ganado'
        BEGIN
            UPDATE clientes SET tipo = 'activo', updated_at = datetime('now') WHERE id = NEW.id;
        END;

        -- ============================================================
        -- ÍNDICES DE PERFORMANCE
        -- ============================================================
        CREATE INDEX idx_clientes_tipo ON clientes(tipo);
        CREATE INDEX idx_clientes_pipeline ON clientes(etapa_pipeline);
        CREATE INDEX idx_proyectos_cliente ON proyectos(cliente_id);
        CREATE INDEX idx_proyectos_estado ON proyectos(estado);
        CREATE INDEX idx_proyectos_responsable ON proyectos(responsable_id);
        CREATE INDEX idx_tareas_cliente ON tareas(cliente_id);
        CREATE INDEX idx_tareas_proyecto ON tareas(proyecto_id);
        CREATE INDEX idx_tareas_asignado ON tareas(asignado_a);
        CREATE INDEX idx_tareas_estado ON tareas(estado);
        CREATE INDEX idx_tareas_prioridad ON tareas(prioridad);
        CREATE INDEX idx_facturas_cliente ON facturas(cliente_id);
        CREATE INDEX idx_facturas_estado ON facturas(estado);
        CREATE INDEX idx_facturas_fecha ON facturas(fecha_emision);
        CREATE INDEX idx_cuentas_cobrar_cliente ON cuentas_cobrar(cliente_id);
        CREATE INDEX idx_cuentas_cobrar_estado ON cuentas_cobrar(estado);
        CREATE INDEX idx_cuentas_cobrar_vencimiento ON cuentas_cobrar(fecha_vencimiento);
        CREATE INDEX idx_abonos_cuenta ON abonos(cuenta_cobrar_id);
        CREATE INDEX idx_finanzas_tipo ON finanzas(tipo);
        CREATE INDEX idx_finanzas_fecha ON finanzas(fecha);
        CREATE INDEX idx_finanzas_cliente ON finanzas(cliente_id);
        CREATE INDEX idx_campanas_cliente ON campanas(cliente_id);
        CREATE INDEX idx_campanas_estado ON campanas(estado);
        CREATE INDEX idx_actividad_fecha ON actividad(created_at);
        CREATE INDEX idx_actividad_modulo ON actividad(modulo);
        CREATE INDEX idx_permisos_usuario ON permisos(usuario_id);
        CREATE INDEX idx_servicios_cliente ON servicios_cliente(cliente_id);
        CREATE INDEX idx_servicios_estado ON servicios_cliente(estado);
        CREATE INDEX idx_interacciones_cliente ON interacciones(cliente_id);
        CREATE INDEX idx_interacciones_fecha ON interacciones(fecha);

SQL;

        $db->exec($schema);

        // Crear usuario admin
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT INTO usuarios (username, password_hash, nombre, role) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $username);
        $stmt->bindValue(2, $hash);
        $stmt->bindValue(3, $app_name . ' Admin');
        $stmt->bindValue(4, 'admin');
        $stmt->execute();

        // Actualizar config con nombre y colores
        $config_content = "<?php\nreturn " . var_export([
            'name'     => $app_name,
            'logo'     => 'assets/img/logo.png',
            'primary'  => $primary,
            'accent'   => $accent,
            'bg'       => '#0f172a',
            'surface'  => '#1e293b',
            'text'     => '#e2e8f0',
            'timezone' => 'America/Santiago',
            'currency' => 'CLP',
            'locale'   => 'es_CL',
            'version'  => '1.0.0',
            'active'   => true,
        ], true) . ";\n";
        file_put_contents(__DIR__ . '/config/app.php', $config_content);

        $db->close();

        // Redirigir al login
        header('Location: login.php?installed=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .install-card {
            background: #1e293b;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
        }
        h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .subtitle { color: #94a3b8; margin-bottom: 32px; font-size: .9rem; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: .85rem; color: #94a3b8; margin-bottom: 6px; }
        input[type="text"], input[type="password"], input[type="color"] {
            width: 100%;
            padding: 10px 14px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: .95rem;
            outline: none;
            transition: border-color .2s;
        }
        input:focus { border-color: #f97316; }
        input[type="color"] { height: 44px; padding: 4px; cursor: pointer; }
        .colors-row { display: flex; gap: 12px; }
        .colors-row .form-group { flex: 1; }
        .error { background: #7f1d1d; color: #fca5a5; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: .85rem; }
        button {
            width: 100%;
            padding: 12px;
            background: #f97316;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        button:hover { background: #ea580c; }
        .step-indicator { display: flex; gap: 8px; margin-bottom: 24px; }
        .step-dot { width: 8px; height: 8px; border-radius: 50%; background: #334155; }
        .step-dot.active { background: #f97316; }
    </style>
</head>
<body>
    <div class="install-card">
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
        </div>
        <h1>Configuración Inicial</h1>
        <p class="subtitle">Configura tu dashboard en un minuto</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="step" value="2">

            <div class="form-group">
                <label>Nombre de la empresa / app</label>
                <input type="text" name="app_name" placeholder="Mi Empresa" required
                       value="<?= htmlspecialchars($_POST['app_name'] ?? '') ?>">
            </div>

            <div class="colors-row">
                <div class="form-group">
                    <label>Color principal</label>
                    <input type="color" name="primary_color" value="<?= htmlspecialchars($_POST['primary_color'] ?? '#1e3a5f') ?>">
                </div>
                <div class="form-group">
                    <label>Color acento</label>
                    <input type="color" name="accent_color" value="<?= htmlspecialchars($_POST['accent_color'] ?? '#f97316') ?>">
                </div>
            </div>

            <hr style="border-color: #334155; margin: 24px 0;">

            <div class="form-group">
                <label>Usuario administrador</label>
                <input type="text" name="username" placeholder="admin" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="Mínimo 6 caracteres" required>
            </div>

            <div class="form-group">
                <label>Confirmar contraseña</label>
                <input type="password" name="password2" placeholder="Repetir contraseña" required>
            </div>

            <button type="submit">Instalar Dashboard</button>
        </form>
    </div>
</body>
</html>
