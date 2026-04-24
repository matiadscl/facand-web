<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$user = $_GET['user'] ?? '';
$token = $_GET['token'] ?? '';

// Tokens simples por usuario para autenticar el feed
$tokens = [
    'mati' => 'fcal_m4t1_2026',
    'fabi' => 'fcal_f4b1_2026',
    'nico' => 'fcal_n1c0_2026',
];

if (!isset($tokens[$user]) || $tokens[$user] !== $token) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// Mapear user a equipo.id
$equipoMap = ['mati' => 1, 'fabi' => 2, 'nico' => 3];
$equipoId = $equipoMap[$user] ?? null;

// Obtener tareas con fecha límite (asignadas al usuario o todas si es socio)
$sql = "SELECT t.*, c.nombre as cliente_nombre, p.nombre as proyecto_nombre
        FROM tareas t
        JOIN clientes c ON t.cliente_id = c.id
        LEFT JOIN proyectos p ON t.proyecto_id = p.id
        WHERE t.fecha_limite IS NOT NULL AND t.estado != 'completada'";
$params = [];
if ($equipoId && !in_array($user, ['mati', 'fabi'])) {
    $sql .= " AND t.asignado_a = ?";
    $params[] = $equipoId;
}
$tareas = queryAll($sql, $params);

// Proyectos con fecha límite
$sqlP = "SELECT p.*, c.nombre as cliente_nombre FROM proyectos p JOIN clientes c ON p.cliente_id = c.id WHERE p.fecha_limite IS NOT NULL AND p.estado = 'activo'";
$proyectos = queryAll($sqlP);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="facand-' . $user . '.ics"');
header('Cache-Control: no-cache, must-revalidate');

$uid_domain = 'facand.com';
$now = gmdate('Ymd\THis\Z');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Facand//Dashboard//ES\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:Facand - " . ucfirst($user) . "\r\n";
echo "X-WR-TIMEZONE:America/Santiago\r\n";

foreach ($tareas as $t) {
    $dtStart = str_replace('-', '', $t['fecha_limite']);
    $summary = "[Tarea] " . $t['titulo'];
    $desc = "Cliente: " . $t['cliente_nombre'];
    if ($t['proyecto_nombre']) $desc .= "\\nProyecto: " . $t['proyecto_nombre'];
    $desc .= "\\nPrioridad: " . $t['prioridad'];

    echo "BEGIN:VEVENT\r\n";
    echo "UID:tarea-{$t['id']}@{$uid_domain}\r\n";
    echo "DTSTAMP:{$now}\r\n";
    echo "DTSTART;VALUE=DATE:{$dtStart}\r\n";
    echo "SUMMARY:" . icalEscape($summary) . "\r\n";
    echo "DESCRIPTION:" . icalEscape($desc) . "\r\n";
    echo "CATEGORIES:Tarea\r\n";
    echo "BEGIN:VALARM\r\n";
    echo "TRIGGER:-PT9H\r\n";
    echo "ACTION:DISPLAY\r\n";
    echo "DESCRIPTION:Tarea vence hoy\r\n";
    echo "END:VALARM\r\n";
    echo "END:VEVENT\r\n";
}

foreach ($proyectos as $p) {
    $dtStart = str_replace('-', '', $p['fecha_limite']);
    $summary = "[Proyecto] " . $p['nombre'];
    $desc = "Cliente: " . $p['cliente_nombre'];

    echo "BEGIN:VEVENT\r\n";
    echo "UID:proyecto-{$p['id']}@{$uid_domain}\r\n";
    echo "DTSTAMP:{$now}\r\n";
    echo "DTSTART;VALUE=DATE:{$dtStart}\r\n";
    echo "SUMMARY:" . icalEscape($summary) . "\r\n";
    echo "DESCRIPTION:" . icalEscape($desc) . "\r\n";
    echo "CATEGORIES:Proyecto\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

function icalEscape(string $s): string {
    return str_replace(["\n", ",", ";"], ["\\n", "\\,", "\\;"], $s);
}
