<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/catalogo.php';

$id = (int)($_GET['id'] ?? 0);
$c = $id ? query_one('SELECT * FROM clientes WHERE id = ?', [$id]) : null;
if (!$c) { echo 'Cliente no encontrado'; exit; }

$catalogo = getCatalogo();
$planKey = $c['plan'] ?: null;
$plan = ($planKey && isset($catalogo[$planKey])) ? $catalogo[$planKey] : null;
$nombre = $c['nombre'];
$fecha = date('d/m/Y');
$fee = $c['fee_mensual'] > 0 ? '$' . number_format($c['fee_mensual'], 0, ',', '.') . '/mes' : '';
$servicios = array_filter(array_map('trim', explode(',', $c['servicios'] ?? '')));
$herramientas = array_filter(array_map('trim', explode(',', $c['herramientas'] ?? '')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Alcances — <?= htmlspecialchars($nombre) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1a1a1a; background: #fff; padding: 30px 40px; max-width: 900px; margin: 0 auto; font-size: 13px; line-height: 1.6; }
.header { border-bottom: 3px solid #f97316; padding-bottom: 20px; margin-bottom: 30px; }
.header-top { display: flex; justify-content: space-between; align-items: flex-start; }
.header h1 { font-size: 32px; font-weight: 800; color: #f97316; letter-spacing: 2px; }
.header-right { text-align: right; }
.header-right p { font-size: 12px; color: #666; }
.client-banner { margin-top: 15px; background: #1a1a1a; color: #fff; padding: 16px 22px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
.client-banner h2 { font-size: 20px; font-weight: 700; }
.plan-badge { background: #f97316; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 700; }
.section { margin-bottom: 25px; }
.section-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #f97316; border-bottom: 2px solid #f97316; padding-bottom: 6px; margin-bottom: 12px; }
.intro { font-size: 13px; color: #444; margin-bottom: 20px; line-height: 1.7; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.check-list { list-style: none; padding: 0; }
.check-list li { padding: 6px 0; padding-left: 24px; position: relative; font-size: 12px; border-bottom: 1px solid #f5f5f5; }
.check-list li::before { content: '✓'; position: absolute; left: 0; color: #10b981; font-weight: 700; font-size: 14px; }
.cross-list { list-style: none; padding: 0; }
.cross-list li { padding: 6px 0; padding-left: 24px; position: relative; font-size: 12px; color: #666; border-bottom: 1px solid #f5f5f5; }
.cross-list li::before { content: '✕'; position: absolute; left: 0; color: #ef4444; font-weight: 700; font-size: 13px; }
.resp-list { list-style: none; padding: 0; }
.resp-list li { padding: 6px 0; padding-left: 24px; position: relative; font-size: 12px; border-bottom: 1px solid #f5f5f5; }
.resp-list li::before { content: '→'; position: absolute; left: 0; color: #f97316; font-weight: 700; }
.tool-tags { display: flex; flex-wrap: wrap; gap: 6px; }
.tool-tag { display: inline-block; padding: 4px 12px; background: #f0f0f0; border-radius: 4px; font-size: 11px; font-weight: 600; color: #444; }
.note-box { background: #fffbea; border-left: 4px solid #f59e0b; padding: 14px 18px; margin: 20px 0; font-size: 12px; line-height: 1.7; }
.conditions { background: #f8f8f8; border-radius: 6px; padding: 18px; margin-top: 20px; }
.conditions h3 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 10px; }
.conditions ul { margin-left: 16px; }
.conditions li { font-size: 11px; color: #666; margin-bottom: 4px; }
.footer { margin-top: 40px; padding-top: 15px; border-top: 2px solid #f97316; text-align: center; font-size: 11px; color: #888; }
.print-btn { position: fixed; top: 15px; right: 15px; background: #f97316; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px; z-index: 100; }
.print-btn:hover { background: #ea580c; }
@media print { .print-btn { display: none; } body { padding: 20px; } @page { size: A4; margin: 20mm; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">Descargar PDF</button>
<div class="header">
    <div class="header-top"><h1>FACAND</h1><div class="header-right"><p>Documento de Alcances del Servicio</p><p><?= $fecha ?></p></div></div>
    <div class="client-banner">
        <h2><?= htmlspecialchars($nombre) ?></h2>
        <div style="text-align:right">
            <?php if ($plan): ?><span class="plan-badge"><?= htmlspecialchars($plan['nombre']) ?></span><?php endif; ?>
            <?php if ($fee): ?><div style="font-size:12px;color:#ccc;margin-top:4px"><?= htmlspecialchars($fee) ?></div><?php endif; ?>
        </div>
    </div>
</div>
<p class="intro">Estimado/a cliente, a continuación se detallan los alcances del servicio contratado con Facand.</p>

<?php if ($plan): ?>
<div class="section"><div class="section-title">Qué incluye su servicio</div><ul class="check-list"><?php foreach ($plan['incluye'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul></div>
<div class="grid-2">
    <div class="section"><div class="section-title">No incluido</div><ul class="cross-list"><?php foreach ($plan['no_incluye'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul></div>
    <div class="section"><div class="section-title">Herramientas</div><p style="font-size:12px;color:#666;margin-bottom:10px">Configuradas y gestionadas por Facand. Cuentas propiedad del cliente.</p><div class="tool-tags"><?php foreach ($plan['herramientas'] as $h): ?><span class="tool-tag"><?= htmlspecialchars($h) ?></span><?php endforeach; ?></div></div>
</div>
<div class="section"><div class="section-title">Responsabilidades del cliente</div><ul class="resp-list"><?php foreach ($plan['responsabilidades'] as $r): ?><li><?= htmlspecialchars($r) ?></li><?php endforeach; ?></ul></div>
<?php else: ?>
<div class="section"><div class="section-title">Servicios contratados</div>
    <?php if (!empty($servicios)): ?><ul class="check-list"><?php foreach ($servicios as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p style="color:#888">Servicios por definir — proyecto personalizado.</p><?php endif; ?>
</div>
<?php if (!empty($herramientas)): ?>
<div class="section"><div class="section-title">Herramientas</div><div class="tool-tags"><?php foreach ($herramientas as $h): ?><span class="tool-tag"><?= htmlspecialchars($h) ?></span><?php endforeach; ?></div></div>
<?php endif; ?>
<?php endif; ?>

<div class="note-box"><strong>Material audiovisual:</strong> Todo el material fotográfico y de video es responsabilidad exclusiva del cliente. Facand utiliza el material entregado para la creación de anuncios, creatividades y contenido web.</div>
<div class="conditions"><h3>Condiciones generales</h3><ul>
    <li>Horario de soporte: Lunes a viernes, 9:00 a 18:00 hrs (Chile continental).</li>
    <li>Cambios adicionales a los incluidos se cotizan por separado.</li>
    <li>Facturación mensual, dentro de los primeros 5 días del mes.</li>
    <li>Presupuesto publicitario (inversión en ads) es independiente del fee de servicio.</li>
    <li>Sin contratos de permanencia. Cancela cuando quieras.</li>
    <li>Las cuentas publicitarias, GA4, GTM y Search Console son propiedad del cliente.</li>
    <li>Accesos deben ser entregados dentro de los primeros 5 días hábiles del inicio del servicio.</li>
</ul></div>
<div class="footer"><strong>FACAND</strong> — Agencia Digital<br>Documento generado el <?= $fecha ?></div>
</body>
</html>
