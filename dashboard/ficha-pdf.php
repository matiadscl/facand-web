<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ficha-parser.php';

$slug = $_GET['cliente'] ?? '';
$ficha = $slug ? parseClienteFicha($slug) : null;
if (!$ficha) { echo 'Cliente no encontrado'; exit; }
$nombre = $ficha['nombre'] ?: $slug;
$fecha = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ficha — <?= htmlspecialchars($nombre) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1a1a1a; background: #fff; padding: 30px 40px; max-width: 900px; margin: 0 auto; font-size: 13px; line-height: 1.6; }
.header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #f97316; padding-bottom: 15px; margin-bottom: 25px; }
.header h1 { font-size: 28px; font-weight: 800; color: #f97316; letter-spacing: 1px; }
.header-right { text-align: right; }
.header-right h2 { font-size: 18px; font-weight: 700; }
.header-right p { font-size: 12px; color: #666; }
.section { margin-bottom: 20px; }
.section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #f97316; border-bottom: 1px solid #eee; padding-bottom: 6px; margin-bottom: 10px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
th { background: #2a2a2a; color: #fff; text-align: left; padding: 8px 12px; font-size: 11px; letter-spacing: 0.5px; text-transform: uppercase; }
td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 12px; }
tr:nth-child(even) td { background: #fafafa; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 15px; }
.info-item { padding: 8px 12px; background: #f8f8f8; border-radius: 4px; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: 600; }
.info-value { font-size: 13px; font-weight: 600; color: #1a1a1a; margin-top: 2px; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge-warn { background: #fff3cd; color: #856404; }
.badge-ok { background: #d4edda; color: #155724; }
.badge-danger { background: #f8d7da; color: #721c24; }
ul { margin-left: 18px; margin-bottom: 10px; }
li { margin-bottom: 4px; font-size: 12px; }
li.done { color: #888; text-decoration: line-through; }
.footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 11px; color: #888; }
.print-btn { position: fixed; top: 15px; right: 15px; background: #f97316; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; z-index: 100; }
.print-btn:hover { background: #ea580c; }
@media print {
  .print-btn { display: none; }
  body { padding: 20px; }
  @page { size: A4; margin: 20mm; }
}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">Descargar PDF</button>

<div class="header">
    <h1>FACAND</h1>
    <div class="header-right">
        <h2><?= htmlspecialchars($nombre) ?></h2>
        <p>Ficha de Cliente — <?= $fecha ?></p>
    </div>
</div>

<!-- Info General -->
<div class="section">
    <div class="section-title">Información General</div>
    <div class="info-grid">
        <?php foreach ($ficha['info'] as $key => $val): if (in_array($key, ['Detalle', ''])) continue; ?>
        <div class="info-item">
            <div class="info-label"><?= htmlspecialchars($key) ?></div>
            <div class="info-value"><?= htmlspecialchars($val) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($ficha['etapa']): ?>
        <div class="info-item">
            <div class="info-label">Etapa</div>
            <div class="info-value"><?= htmlspecialchars($ficha['etapa']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($ficha['estado_pagos']): ?>
        <div class="info-item">
            <div class="info-label">Estado de Pagos</div>
            <div class="info-value"><?= htmlspecialchars(strip_tags(str_replace(['**', '*'], '', $ficha['estado_pagos']))) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Servicios -->
<?php if (!empty($ficha['servicios'])): ?>
<div class="section">
    <div class="section-title">Servicios Contratados</div>
    <ul>
        <?php foreach ($ficha['servicios'] as $s): ?>
        <li><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Herramientas -->
<?php if (!empty($ficha['herramientas'])): ?>
<div class="section">
    <div class="section-title">Herramientas de Gestión</div>
    <ul>
        <?php foreach ($ficha['herramientas'] as $h): ?>
        <li><?= htmlspecialchars($h) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Presupuesto -->
<?php if (!empty($ficha['presupuesto'])): ?>
<div class="section">
    <div class="section-title">Presupuesto</div>
    <ul>
        <?php foreach ($ficha['presupuesto'] as $p): ?>
        <li<?= stripos($p, 'total') !== false ? ' style="font-weight:700"' : '' ?>><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Equipo -->
<?php if (!empty($ficha['equipo'])): ?>
<div class="section">
    <div class="section-title">Equipo Asignado</div>
    <table>
        <thead><tr><th>Persona</th><th>Rol</th><th>Acceso</th></tr></thead>
        <tbody>
        <?php foreach ($ficha['equipo'] as $e): ?>
        <tr>
            <td><strong><?= htmlspecialchars($e['persona']) ?></strong></td>
            <td><?= htmlspecialchars($e['rol']) ?></td>
            <td><?= htmlspecialchars($e['acceso']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Pendientes -->
<?php if (!empty($ficha['pendientes']) || !empty($ficha['pendientes_done'])): ?>
<div class="section">
    <div class="section-title">Pendientes</div>
    <ul>
        <?php foreach ($ficha['pendientes'] as $p): ?>
        <li><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
        <?php foreach ($ficha['pendientes_done'] as $p): ?>
        <li class="done"><?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="footer">
    Facand — Agencia Digital | Documento interno — Generado el <?= $fecha ?>
</div>
</body>
</html>
