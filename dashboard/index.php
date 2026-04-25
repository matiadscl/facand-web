<?php
/**
 * Router principal — SPA-like con sidebar y carga dinámica de módulos
 * Incluye auth guard, permisos, y layout responsive
 */
require_once __DIR__ . '/includes/auth.php';

$app = require __DIR__ . '/config/app.php';
$all_modules = require __DIR__ . '/config/modules.php';
$user_modules = get_user_modules($current_user['id']);

// Módulo activo (default: home)
$page = $_GET['page'] ?? 'home';
if (!in_array($page, $user_modules) || !isset($all_modules[$page])) {
    $page = 'home';
}
$module_info = $all_modules[$page];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= safe($module_info['nombre']) ?> — <?= safe($app['name']) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?= filemtime(__DIR__ . '/css/styles.css') ?>">
    <style>
        :root {
            --primary: <?= $app['primary'] ?>;
            --accent: <?= $app['accent'] ?>;
            --bg: <?= $app['bg'] ?>;
            --surface: <?= $app['surface'] ?>;
            --text: <?= $app['text'] ?>;
            --text-muted: #d3d3d3;
            --border: #222222;
            --success: #41d77e;
            --warning: #eab308;
            --danger: #ef4444;
        }
    </style>
    <script>
        const APP = {
            csrf: '<?= csrf_token() ?>',
            userId: <?= $current_user['id'] ?>,
            userRole: '<?= $current_user['role'] ?>',
            canEdit: <?= can_edit($current_user['id'], $page) ? 'true' : 'false' ?>,
            currentPage: '<?= $page ?>',
            currency: '<?= $app['currency'] ?>'
        };
    </script>
    <script src="js/main.js?v=<?= filemtime(__DIR__ . '/js/main.js') ?>"></script>
</head>
<body>
    <!-- Overlay para mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header" style="display:flex;align-items:center;justify-content:center;background:#000;padding:16px;">
            <img src="assets/img/logo.webp" alt="Facand" style="height:36px;width:auto;">
        </div>
        <nav class="sidebar-nav">
            <?php
            // Pre-agrupar módulos con parent en dropdown
            $finanzas_children = [];
            foreach ($all_modules as $slug => $mod) {
                if (isset($mod['parent']) && $mod['parent'] === 'finanzas' && in_array($slug, $user_modules)) {
                    $finanzas_children[$slug] = $mod;
                }
            }
            $finanzas_active = isset($all_modules[$page]['parent']) && $all_modules[$page]['parent'] === 'finanzas';

            foreach ($all_modules as $slug => $mod):
                if (!in_array($slug, $user_modules)) continue;
                if (isset($mod['parent'])) continue;
            ?>
                <a href="?page=<?= $slug ?>" class="nav-item <?= $page === $slug ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $mod['icono'] ?></span>
                    <span class="nav-label"><?= safe($mod['nombre']) ?></span>
                </a>
            <?php if ($slug === 'tasks' && !empty($finanzas_children)): ?>
                <div class="nav-group <?= $finanzas_active ? 'open' : '' ?>">
                    <div class="nav-item nav-group-toggle <?= $finanzas_active ? 'active' : '' ?>" onclick="this.parentElement.classList.toggle('open')">
                        <span class="nav-icon">&#128200;</span>
                        <span class="nav-label">Finanzas</span>
                        <span class="nav-arrow">&#9656;</span>
                    </div>
                    <div class="nav-group-items">
                        <?php foreach ($finanzas_children as $cslug => $cmod): ?>
                            <a href="?page=<?= $cslug ?>" class="nav-item nav-child <?= $page === $cslug ? 'active' : '' ?>">
                                <span class="nav-icon" style="font-size:.7rem;"><?= $cmod['icono'] ?></span>
                                <span class="nav-label"><?= safe($cmod['nombre']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <span class="user-name"><?= safe($current_user['nombre']) ?></span>
                <span class="user-role"><?= $current_user['role'] === 'admin' ? 'Administrador' : 'Usuario' ?></span>
            </div>
            <a href="logout.php" class="btn-logout" title="Cerrar sesión">&#10148;</a>
        </div>
    </aside>

    <!-- Main content -->
    <main class="main-content">
        <header class="topbar">
            <button class="btn-menu" id="btnMenu" title="Menú">&#9776;</button>
            <h1 class="page-title"><?= safe($module_info['nombre']) ?></h1>
            <div class="topbar-right">
                <span class="current-date"><?= date('d/m/Y') ?></span>
            </div>
        </header>

        <div class="content-area" id="contentArea">
            <?php
            $module_file = __DIR__ . '/' . $module_info['archivo'];
            if (file_exists($module_file)) {
                include $module_file;
            } else {
                echo '<div class="empty-state"><p>Módulo en construcción</p></div>';
            }
            ?>
        </div>
    </main>

    <!-- Modal genérico -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal" id="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle"></h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>

    <!-- Toast notifications -->
    <div class="toast-container" id="toastContainer"></div>

</body>
</html>
