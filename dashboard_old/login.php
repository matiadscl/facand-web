<?php
declare(strict_types=1);
session_start();

$USERS = [
    'mati'  => ['pass' => 'Facand2026!', 'nombre' => 'Matías', 'cargo' => 'CEO / CMO', 'rol' => 'admin'],
    'fabi'  => ['pass' => 'Fabi2026!',   'nombre' => 'Fabián Astorga', 'cargo' => 'Analista Programador', 'rol' => 'socio'],
    'nico'  => ['pass' => 'Nico2026!',   'nombre' => 'Nico Ojeda', 'cargo' => 'Programador', 'rol' => 'equipo'],
];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if (isset($USERS[$user]) && $USERS[$user]['pass'] === $pass) {
        $_SESSION['user_id'] = $user;
        $_SESSION['user_nombre'] = $USERS[$user]['nombre'];
        $_SESSION['user_cargo'] = $USERS[$user]['cargo'];
        $_SESSION['user_rol'] = $USERS[$user]['rol'];
        header('Location: index.php');
        exit;
    }
    $error = 'Usuario o contraseña incorrectos';
}

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facand — Login</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="assets/logo.png" alt="Facand" onerror="this.parentElement.innerHTML='<div style=\'width:80px;height:80px;border-radius:50%;background:rgba(249,115,22,0.15);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:var(--accent)\'>F</div>'">
            </div>
            <div class="login-header">
                <h1>Facand</h1>
                <p>Agencia Digital — Panel de Control</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Usuario</label>
                    <input type="text" name="user" placeholder="mati, fabi o nico" required autofocus>
                </div>
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="pass" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Ingresar</button>
            </form>
            <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 1.5rem;">
                Facand &copy; <?= date('Y') ?>
            </div>
        </div>
    </div>
</body>
</html>
