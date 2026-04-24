<?php
/**
 * Funciones helper globales del dashboard
 */

$app_config = require __DIR__ . '/../config/app.php';

/**
 * Formatea un monto en la moneda configurada
 * @param float|int $amount Monto
 * @return string Monto formateado
 */
function format_money($amount): string {
    global $app_config;
    $currency = $app_config['currency'] ?? 'CLP';
    if ($currency === 'CLP') {
        return '$' . number_format((int)$amount, 0, ',', '.');
    }
    return '$' . number_format((float)$amount, 2, ',', '.');
}

/**
 * Formatea una fecha a formato local
 * @param string|null $date Fecha en formato Y-m-d o datetime
 * @return string Fecha formateada dd/mm/yyyy
 */
function format_date(?string $date): string {
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '-';
}

/**
 * Retorna hace cuánto tiempo fue una fecha (ej: "hace 2 horas")
 * @param string $datetime Fecha con hora
 * @return string
 */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'hace un momento';
    if ($diff < 3600) return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff / 3600) . 'h';
    if ($diff < 604800) return 'hace ' . floor($diff / 86400) . 'd';
    return format_date($datetime);
}

/**
 * Sanitiza string para output HTML
 * @param string|null $str
 * @return string
 */
function safe(mixed $str): string {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Genera un token CSRF y lo guarda en sesión
 * @return string Token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF recibido
 * @param string $token Token a validar
 * @return bool
 */
function csrf_validate(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Retorna campo hidden con CSRF token para formularios
 * @return string HTML del input hidden
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Calcula días de atraso desde una fecha
 * @param string $date Fecha límite
 * @return int Días de atraso (negativo = faltan días)
 */
function days_overdue(string $date): int {
    $deadline = strtotime($date);
    $today = strtotime('today');
    return (int)floor(($today - $deadline) / 86400);
}

/**
 * Retorna clase CSS según estado de pago
 * @param string $estado Estado del documento
 * @return string Clase CSS
 */
function status_class(string $estado): string {
    return match($estado) {
        'pagado', 'completado', 'activo' => 'status-success',
        'pendiente', 'en_progreso'       => 'status-warning',
        'vencido', 'atrasado', 'critica' => 'status-danger',
        'cancelado', 'inactivo'          => 'status-muted',
        default                          => 'status-default',
    };
}

/**
 * Retorna el nombre de la app desde config
 * @return string
 */
function app_name(): string {
    global $app_config;
    return $app_config['name'] ?? 'Dashboard';
}
