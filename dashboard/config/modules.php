<?php
/**
 * Registro de módulos disponibles
 * Cada módulo: slug => [nombre, icono, archivo, descripción]
 * El orden define el orden en el sidebar
 */
return [
    'home'        => ['nombre' => 'Inicio',            'icono' => '&#9776;',  'archivo' => 'modules/home.php',        'descripcion' => 'Resumen general y KPIs'],
    'crm'         => ['nombre' => 'Clientes',           'icono' => '&#128101;','archivo' => 'modules/crm.php',         'descripcion' => 'Gestión de clientes y pipeline'],
    'services'    => ['nombre' => 'Servicios',          'icono' => '&#128188;','archivo' => 'modules/services.php',    'descripcion' => 'Servicios, presupuestos y cotizaciones'],
    'tasks'       => ['nombre' => 'Tareas',             'icono' => '&#9745;',  'archivo' => 'modules/tasks.php',       'descripcion' => 'Gestión de tareas y asignaciones'],
    'billing'     => ['nombre' => 'Facturación',        'icono' => '&#128179;','archivo' => 'modules/billing.php',     'descripcion' => 'Emisión y control de facturas',        'parent' => 'finanzas'],
    'receivables' => ['nombre' => 'Cuentas por Cobrar', 'icono' => '&#128176;','archivo' => 'modules/receivables.php', 'descripcion' => 'Seguimiento de cobros pendientes',    'parent' => 'finanzas'],
    'cta_corriente'=> ['nombre' => 'Cta Corriente Externa','icono' => '&#128179;','archivo' => 'modules/cta_corriente.php','descripcion' => 'Cuenta corriente externa: saldos y abonos', 'parent' => 'finanzas'],
    'movements'   => ['nombre' => 'Movimientos',        'icono' => '&#128203;','archivo' => 'modules/movements.php',   'descripcion' => 'Ingresos, gastos y registro completo', 'parent' => 'finanzas'],
    'cartolas'    => ['nombre' => 'Cartolas',            'icono' => '&#127974;','archivo' => 'modules/cartolas.php',    'descripcion' => 'Importar cartolas bancarias',          'parent' => 'finanzas'],
    'conciliation'=> ['nombre' => 'Conciliación',       'icono' => '&#128256;','archivo' => 'modules/conciliation.php','descripcion' => 'Cruce banco vs facturas y SII',        'parent' => 'finanzas'],
    'results'     => ['nombre' => 'Resultados',          'icono' => '&#128200;','archivo' => 'modules/results.php',    'descripcion' => 'Estado de resultados simplificado',    'parent' => 'finanzas'],
    'budget'      => ['nombre' => 'Presupuesto',         'icono' => '&#128202;','archivo' => 'modules/budget.php',     'descripcion' => 'Presupuesto vs real por categoría',    'parent' => 'finanzas'],
    'categorias'  => ['nombre' => 'Categorías EERR',    'icono' => '&#128209;','archivo' => 'modules/categorias.php', 'descripcion' => 'Administrar categorías del EERR',      'parent' => 'finanzas'],
    'stack'       => ['nombre' => 'Stack Clientes',      'icono' => '&#128295;','archivo' => 'modules/stack.php',       'descripcion' => 'Herramientas habilitadas por cliente'],
    'marketing'   => ['nombre' => 'Marketing',          'icono' => '&#128226;','archivo' => 'modules/marketing.php',   'descripcion' => 'Campañas y métricas'],
    'team'        => ['nombre' => 'Equipo',             'icono' => '&#128100;','archivo' => 'modules/team.php',        'descripcion' => 'Miembros y carga de trabajo'],
    'reports'     => ['nombre' => 'Reportes',           'icono' => '&#128202;','archivo' => 'modules/reports.php',     'descripcion' => 'Reportes exportables'],
    'admin'       => ['nombre' => 'Administración',     'icono' => '&#9881;',  'archivo' => 'modules/admin.php',       'descripcion' => 'Usuarios, roles y configuración'],
];
