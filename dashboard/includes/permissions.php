<?php
/**
 * Sistema de permisos por módulo
 * Admin tiene acceso total. Usuarios normales solo a módulos asignados.
 */

/**
 * Verifica si el usuario puede ver un módulo
 * @param int $user_id ID del usuario
 * @param string $modulo Slug del módulo
 * @return bool
 */
function can_view(int $user_id, string $modulo): bool {
    $user = query_one('SELECT role FROM usuarios WHERE id = ?', [$user_id]);
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    if ($modulo === 'admin') return false;

    $perm = query_one(
        'SELECT puede_ver FROM permisos WHERE usuario_id = ? AND modulo = ?',
        [$user_id, $modulo]
    );
    return $perm && $perm['puede_ver'];
}

/**
 * Verifica si el usuario puede editar en un módulo
 * @param int $user_id ID del usuario
 * @param string $modulo Slug del módulo
 * @return bool
 */
function can_edit(int $user_id, string $modulo): bool {
    $user = query_one('SELECT role FROM usuarios WHERE id = ?', [$user_id]);
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    if ($modulo === 'admin') return false;

    $perm = query_one(
        'SELECT puede_editar FROM permisos WHERE usuario_id = ? AND modulo = ?',
        [$user_id, $modulo]
    );
    return $perm && $perm['puede_editar'];
}

/**
 * Retorna los módulos accesibles para un usuario
 * @param int $user_id ID del usuario
 * @return array Lista de slugs de módulos con acceso
 */
function get_user_modules(int $user_id): array {
    $user = query_one('SELECT role FROM usuarios WHERE id = ?', [$user_id]);
    if (!$user) return [];

    $all_modules = require __DIR__ . '/../config/modules.php';

    if ($user['role'] === 'admin') {
        return array_keys($all_modules);
    }

    $perms = query_all(
        'SELECT modulo FROM permisos WHERE usuario_id = ? AND puede_ver = 1',
        [$user_id]
    );
    $allowed = array_column($perms, 'modulo');
    // Home siempre visible
    if (!in_array('home', $allowed)) {
        array_unshift($allowed, 'home');
    }
    return $allowed;
}

/**
 * Retorna permisos completos de un usuario (para admin panel)
 * @param int $user_id
 * @return array [modulo => [puede_ver, puede_editar]]
 */
function get_all_permissions(int $user_id): array {
    $perms = query_all('SELECT modulo, puede_ver, puede_editar FROM permisos WHERE usuario_id = ?', [$user_id]);
    $result = [];
    foreach ($perms as $p) {
        $result[$p['modulo']] = ['ver' => $p['puede_ver'], 'editar' => $p['puede_editar']];
    }
    return $result;
}
