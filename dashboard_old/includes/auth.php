<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user_id'];
$currentNombre = $_SESSION['user_nombre'];
$currentCargo = $_SESSION['user_cargo'] ?? '';
$currentRol = $_SESSION['user_rol'] ?? 'equipo';
$isAdmin = $currentRol === 'admin';
$isSocio = in_array($currentRol, ['admin', 'socio']);
