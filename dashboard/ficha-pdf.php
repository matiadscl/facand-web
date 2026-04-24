<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$id = (int)($_GET['id'] ?? 0);
$c = $id ? query_one('SELECT c.*, e.nombre as responsable_nombre FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id WHERE c.id = ?', [$id]) : null;
if (!$c) { echo 'Cliente no encontrado'; exit; }

$nombre = $c['nombre'];
$fecha = date('d/m/Y');
$servicios = array_filter(array_map('trim', explode(',', $c['servicios'] ?? '')));
$herramientas = array_filter(array_map('trim', explode(',', $c['herramientas'] ?? '')));
$presupuesto = array_filter(explode("\n", $c['presupuesto_ads'] ?? ''));
$planes = ['growth'=>'Plan Growth','scale'=>'Plan Scale','starter'=>'Plan Starter','meta_ads'=>'Meta Ads','google_ads'=>'Google Ads','full_ads'=>'Full Ads','full_ads_seo'=>'Full Ads + SEO'];
$planLabel = $planes[$c['plan']] ?? ($c['plan'] ?: 'Custom');
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
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 15px; }
.info-item { padding: 8px 12px; background: #f8f8f8; border-radius: 4px; }
.info-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: 600; }
.info-value { font-size: 13px; font-weight: 600; color: #1a1a1a; margin-top: 2px; }
ul { margin-left: 18px; margin-bottom: 10px; }
li { margin-bottom: 4px; font-size: 12px; }
.footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 11px; color: #888; }
.print-btn { position: fixed; top: 15px; right: 15px; background: #f97316; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; z-index: 100; }
.print-btn:hover { background: #ea580c; }
@media print { .print-btn { display: none; } body { padding: 20px; } @page { size: A4; margin: 20mm; } }
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
<div class="section">
    <div class="section-title">Información General</div>
    <div class="info-grid">
        <div class="info-item"><div class="info-label">Rubro</div><div class="info-value"><?= htmlspecialchars($c['rubro'] ?: '-') ?></div></div>
        <div class="info-item"><div class="info-label">Plan</div><div class="info-value"><?= htmlspecialchars($planLabel) ?></div></div>
        <div class="info-item"><div class="info-label">Fee Mensual</div><div class="info-value"><?= $c['fee_mensual'] > 0 ? '$' . number_format($c['fee_mensual'], 0, ',', '.') : '-' ?></div></div>
        <div class="info-item"><div class="info-label">Estado Pago</div><div class="info-value"><?= htmlspecialchars(ucfirst($c['estado_pago'] ?? 'pendiente')) ?></div></div>
        <div class="info-item"><div class="info-label">Etapa</div><div class="info-value"><?= htmlspecialchars($c['etapa'] ?: '-') ?></div></div>
        <div class="info-item"><div class="info-label">Contacto</div><div class="info-value"><?= htmlspecialchars($c['contacto_nombre'] ?: '-') ?></div></div>
        <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?= htmlspecialchars($c['email'] ?: '-') ?></div></div>
        <div class="info-item"><div class="info-label">Teléfono</div><div class="info-value"><?= htmlspecialchars($c['telefono'] ?: '-') ?></div></div>
        <div class="info-item"><div class="info-label">Responsable</div><div class="info-value"><?= htmlspecialchars($c['responsable_nombre'] ?? '-') ?></div></div>
        <div class="info-item"><div class="info-label">Tipo</div><div class="info-value"><?= htmlspecialchars(ucfirst($c['tipo'])) ?></div></div>
    </div>
</div>
<?php if (!empty($servicios)): ?>
<div class="section"><div class="section-title">Servicios Contratados</div><ul><?php foreach ($servicios as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!empty($herramientas)): ?>
<div class="section"><div class="section-title">Herramientas de Gestión</div><ul><?php foreach ($herramientas as $h): ?><li><?= htmlspecialchars($h) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!empty($presupuesto)): ?>
<div class="section"><div class="section-title">Presupuesto</div><ul><?php foreach ($presupuesto as $p): ?><li<?= stripos($p, 'total') !== false ? ' style="font-weight:700"' : '' ?>><?= htmlspecialchars($p) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($c['notas']): ?>
<div class="section"><div class="section-title">Notas</div><p style="font-size:12px;white-space:pre-line;"><?= htmlspecialchars($c['notas']) ?></p></div>
<?php endif; ?>
<div class="footer">Facand — Agencia Digital | Ficha generada el <?= $fecha ?></div>
</body>
</html>
