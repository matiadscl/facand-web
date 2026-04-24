<?php
/**
 * Wrapper SQLite3 — conexión y helpers para queries
 * Usa prepared statements para prevenir SQL injection
 */

/** Retorna la instancia singleton de SQLite3 */
function get_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db_path = __DIR__ . '/../data/dashboard.db';
        $db = new SQLite3($db_path);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

/**
 * Ejecuta query y retorna todos los resultados como array asociativo
 * @param string $sql Query SQL con placeholders ?
 * @param array $params Parámetros para bind
 * @return array
 */
function query_all(string $sql, array $params = []): array {
    $stmt = get_db()->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Ejecuta query y retorna la primera fila como array asociativo
 * @param string $sql Query SQL
 * @param array $params Parámetros para bind
 * @return array|null
 */
function query_one(string $sql, array $params = []): ?array {
    $rows = query_all($sql, $params);
    return $rows[0] ?? null;
}

/**
 * Ejecuta query y retorna un valor escalar
 * @param string $sql Query SQL
 * @param array $params Parámetros para bind
 * @return mixed
 */
function query_scalar(string $sql, array $params = []) {
    $row = query_one($sql, $params);
    return $row ? reset($row) : null;
}

/**
 * Ejecuta una sentencia INSERT/UPDATE/DELETE
 * @param string $sql Query SQL
 * @param array $params Parámetros para bind
 * @return int Filas afectadas
 */
function db_execute(string $sql, array $params = []): int {
    $stmt = get_db()->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->execute();
    return get_db()->changes();
}

/**
 * Retorna el último ID insertado
 * @return int
 */
function last_id(): int {
    return get_db()->lastInsertRowID();
}

/**
 * Limpia registros de actividad con más de 90 días
 * Se ejecuta automáticamente una vez al día
 */
function cleanup_activity(): void {
    $flag = sys_get_temp_dir() . '/dashboard_cleanup_' . md5(__DIR__) . '_' . date('Y-m-d');
    if (file_exists($flag)) return;
    db_execute('DELETE FROM actividad WHERE created_at < datetime("now", "-90 days")');
    file_put_contents($flag, '1');
}

/**
 * Registra una acción en la tabla de actividad
 * @param string $modulo Módulo que genera la acción
 * @param string $accion Descripción de la acción
 * @param int|null $cliente_id Cliente relacionado
 * @param int|null $usuario_id Usuario que ejecuta
 */
function log_activity(string $modulo, string $accion, ?int $cliente_id = null, ?int $usuario_id = null): void {
    if ($usuario_id === null && isset($_SESSION['user_id'])) {
        $usuario_id = (int)$_SESSION['user_id'];
    }
    db_execute(
        'INSERT INTO actividad (modulo, accion, cliente_id, usuario_id, created_at) VALUES (?, ?, ?, ?, datetime("now"))',
        [$modulo, $accion, $cliente_id, $usuario_id]
    );
}
