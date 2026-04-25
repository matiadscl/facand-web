<?php
/**
 * Registro de módulos disponibles
 * Cada módulo: slug => [nombre, icono, archivo, descripción]
 * El orden define el orden en el sidebar
 */
return [
    'home'        => ['nombre' => 'Inicio',            'icono' => '&#9776;',  'archivo' => 'modules/home.php',        'descripcion' => 'Resumen general y KPIs'],
    'crm'         => ['nombre' => 'Clientes',           'icono' => '&#128101;','archivo' => 'modules/crm.php',         'descripcion' => 'Gestión de clientes y pipeline'],
    'projects'    => ['nombre' => 'Proyectos',          'icono' => '&#128196;','archivo' => 'modules/projects.php',    'descripcion' => 'Gestión de proyectos'],
    'tasks'       => ['nombre' => 'Tareas',             'icono' => '&#9745;',  'archivo' => 'modules/tasks.php',       'descripcion' => 'Gestión de tareas y asignaciones'],
    'services'    => ['nombre' => 'Servicios',            'icono' => '&#128188;','archivo' => 'modules/services.php',    'descripcion' => 'Servicios contratados por cliente'],
    'billing'     => ['nombre' => 'Facturación',        'icono' => '&#128179;','archivo' => 'modules/billing.php',     'descripcion' => 'Emisión y control de facturas'],
    'receivables' => ['nombre' => 'Cuentas por Cobrar', 'icono' => '&#128176;','archivo' => 'modules/receivables.php', 'descripcion' => 'Seguimiento de cobros pendientes'],
    'finance'     => ['nombre' => 'Finanzas',           'icono' => '&#128200;','archivo' => 'modules/finance.php',     'descripcion' => 'Ingresos, gastos y flujo de caja'],
    'marketing'   => ['nombre' => 'Marketing',          'icono' => '&#128226;','archivo' => 'modules/marketing.php',   'descripcion' => 'Campañas y métricas'],
    'team'        => ['nombre' => 'Equipo',             'icono' => '&#128100;','archivo' => 'modules/team.php',        'descripcion' => 'Miembros y carga de trabajo'],
    'reports'     => ['nombre' => 'Reportes',           'icono' => '&#128202;','archivo' => 'modules/reports.php',     'descripcion' => 'Reportes exportables'],
    'admin'       => ['nombre' => 'Administración',     'icono' => '&#9881;',  'archivo' => 'modules/admin.php',       'descripcion' => 'Usuarios, roles y configuración'],
];
