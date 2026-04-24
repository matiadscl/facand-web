<?php
/**
 * Guard de autenticación — incluir al inicio de cada página protegida
 * Redirige a login.php si no hay sesión activa
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/functions.php';

// Limpieza automática de actividad antigua (1x por día)
cleanup_activity();

// Cargar datos del usuario actual en variable global
$current_user = query_one('SELECT * FROM usuarios WHERE id = ?', [$_SESSION['user_id']]);
if (!$current_user || !$current_user['activo']) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: text/html; charset=UTF-8');
