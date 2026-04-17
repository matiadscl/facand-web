<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user_id'];
$currentNombre = $_SESSION['user_nombre'];
$currentRol = $_SESSION['user_rol'];
