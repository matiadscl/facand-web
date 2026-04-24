<?php
/**
 * Login — autenticación con bcrypt
 * Redirige a install.php si no hay DB
 */
session_start();

$db_path = __DIR__ . '/data/dashboard.db';
if (!file_exists($db_path)) {
    header('Location: install.php');
    exit;
}

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

$error = '';
$installed = isset($_GET['installed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Ingresa usuario y contraseña';
    } else {
        $user = query_one('SELECT * FROM usuarios WHERE username = ? AND activo = 1', [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['nombre'];
            db_execute('UPDATE usuarios SET last_login = datetime("now") WHERE id = ?', [$user['id']]);
            log_activity('auth', 'Login: ' . $user['username'], null, $user['id']);
            header('Location: index.php');
            exit;
        } else {
            $error = 'Credenciales incorrectas';
        }
    }
}

$app = require __DIR__ . '/config/app.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars($app['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: <?= $app['bg'] ?>;
            color: <?= $app['text'] ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: <?= $app['surface'] ?>;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
        }
        .logo-area { text-align: center; margin-bottom: 32px; }
        .logo-area h1 { font-size: 1.4rem; margin-top: 12px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: .85rem; color: #94a3b8; margin-bottom: 6px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            background: <?= $app['bg'] ?>;
            border: 1px solid #334155;
            border-radius: 8px;
            color: <?= $app['text'] ?>;
            font-size: .95rem;
            outline: none;
            transition: border-color .2s;
        }
        input:focus { border-color: <?= $app['accent'] ?>; }
        .error { background: #7f1d1d; color: #fca5a5; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: .85rem; }
        .success { background: #14532d; color: #86efac; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: .85rem; }
        button {
            width: 100%;
            padding: 12px;
            background: <?= $app['accent'] ?>;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        button:hover { opacity: .9; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <h1><?= htmlspecialchars($app['name']) ?></h1>
        </div>

        <?php if ($installed): ?>
            <div class="success">Dashboard instalado correctamente. Inicia sesión con tu usuario admin.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" name="username" placeholder="Tu usuario" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="Tu contraseña" required>
            </div>
            <button type="submit">Iniciar Sesión</button>
        </form>
    </div>
</body>
</html>
