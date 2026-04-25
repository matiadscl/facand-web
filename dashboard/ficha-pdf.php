<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$c = $id ? query_one('SELECT c.*, e.nombre as responsable_nombre FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id WHERE c.id = ?', [$id]) : null;
if (!$c) { echo 'Cliente no encontrado'; exit; }

$servicios_db = query_all('SELECT nombre, tipo, monto, estado, notas FROM servicios_cliente WHERE cliente_id = ? ORDER BY tipo DESC, monto DESC', [$id]);
$herramientas = array_filter(array_map('trim', explode(',', $c['herramientas'] ?? '')));
$monto_pendiente = query_scalar('SELECT COALESCE(SUM(monto_pendiente),0) FROM cuentas_cobrar WHERE cliente_id = ? AND estado IN ("pendiente","parcial","vencido")', [$id]) ?? 0;

$total_suscripcion = 0;
$total_implementacion = 0;
foreach ($servicios_db as $sv) {
    if ($sv['estado'] !== 'activo') continue;
    if ($sv['tipo'] === 'suscripcion') $total_suscripcion += $sv['monto'];
    else $total_implementacion += $sv['monto'];
}

$nombre = $c['nombre'];
$fecha = date('d/m/Y');
$folio = 50 + (int)$c['id']; // Folio único por cliente, parte desde 50

// Detectar qué tipo de servicios tiene para personalizar T&C
$tiene_ads = false;
$tiene_web = false;
$tiene_custom = false;
foreach ($servicios_db as $sv) {
    $n = strtolower($sv['nombre']);
    if (str_contains($n, 'ads') || str_contains($n, 'meta') || str_contains($n, 'google')) $tiene_ads = true;
    if (str_contains($n, 'web') || str_contains($n, 'landing') || str_contains($n, 'sitio')) $tiene_web = true;
    if (str_contains($n, 'custom') || str_contains($n, 'desarrollo') || str_contains($n, 'token')) $tiene_custom = true;
}

$logoPath = __DIR__ . '/assets/img/logo.webp';
$logoSrc = file_exists($logoPath) ? 'data:image/webp;base64,' . base64_encode(file_get_contents($logoPath)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ficha F-<?= $folio ?> — <?= htmlspecialchars($nombre) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DM Sans',sans-serif; color:#fff; background:#000; font-size:13px; line-height:1.65; }
.page { max-width:820px; margin:0 auto; padding:40px 48px; }

.print-btn { position:fixed; top:16px; right:16px; background:#41d77e; color:#000; border:none; padding:10px 24px; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; z-index:200; font-family:inherit; }
.print-btn:hover { background:#2fc46e; }

/* Header */
.header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:20px; border-bottom:2px solid #41d77e; margin-bottom:28px; }
.header-logo img { height:44px; }
.header-meta { text-align:right; }
.header-folio { font-size:11px; font-weight:700; color:#41d77e; letter-spacing:1px; }
.header-label { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:1.5px; margin-top:2px; }
.header-client { font-size:18px; font-weight:800; margin-top:6px; }

/* Sections */
.section { margin-bottom:24px; }
.section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:#41d77e; padding-bottom:6px; border-bottom:1px solid #222; margin-bottom:12px; }

/* Info grid */
.info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.info-box { background:#111; border:1px solid #222; border-radius:8px; padding:10px 14px; }
.info-label { font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:#888; font-weight:700; margin-bottom:2px; }
.info-val { font-size:13px; font-weight:600; }
.info-val.green { color:#41d77e; }
.info-val.red { color:#ef4444; }
.info-val.muted { color:#666; font-weight:400; }

/* Service cards */
.svc-list { display:flex; flex-direction:column; gap:10px; }
.svc-card { border:1px solid #222; border-radius:8px; padding:14px 16px; background:#111; }
.svc-card.plan { border-left:3px solid #41d77e; }
.svc-card.custom { border-left:3px solid #f59e0b; }
.svc-card.adicional { border-left:3px solid #188bf6; }
.svc-head { display:flex; justify-content:space-between; align-items:center; }
.svc-name { font-size:13px; font-weight:700; }
.svc-badge { font-size:9px; padding:2px 8px; border-radius:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.svc-badge.plan { background:rgba(65,215,126,.15); color:#41d77e; }
.svc-badge.custom { background:rgba(245,158,11,.15); color:#f59e0b; }
.svc-badge.adicional { background:rgba(24,139,246,.15); color:#188bf6; }
.svc-monto { font-size:14px; font-weight:800; color:#41d77e; }
.svc-notas { font-size:11px; color:#999; margin-top:4px; line-height:1.5; }
.svc-estado { margin-top:4px; font-size:10px; color:#f59e0b; font-weight:600; }

/* Financial */
.fin-box { background:#111; border:1px solid #222; border-radius:8px; padding:4px 16px; }
.fin-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #1a1a1a; font-size:13px; }
.fin-row:last-child { border-bottom:none; }
.fin-label { color:#999; }
.fin-value { font-weight:700; }
.fin-value.green { color:#41d77e; }
.fin-note { margin-top:8px; padding:8px 14px; background:#1a1500; border-radius:6px; border-left:3px solid #f59e0b; font-size:10.5px; color:#f59e0b; }

/* Tools */
.tool-tags { display:flex; flex-wrap:wrap; gap:5px; }
.tool-tag { padding:3px 10px; background:#111; border-radius:4px; font-size:11px; font-weight:600; color:#ccc; border:1px solid #222; }

/* Terms */
.terms-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.terms-box { background:#111; border:1px solid #222; border-radius:8px; padding:16px 18px; }
.terms-box h3 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#41d77e; margin-bottom:10px; }
.terms-list { list-style:none; }
.terms-list li { padding:4px 0 4px 16px; position:relative; font-size:11px; color:#ccc; line-height:1.5; border-bottom:1px solid #1a1a1a; }
.terms-list li:last-child { border-bottom:none; }
.terms-list li::before { content:'→'; position:absolute; left:0; color:#41d77e; font-weight:700; }
.terms-list li strong { color:#fff; }

/* Footer */
.footer { margin-top:32px; padding-top:14px; border-top:2px solid #41d77e; display:flex; justify-content:space-between; align-items:center; }
.footer-brand { font-size:11px; color:#666; }
.footer-brand strong { color:#41d77e; font-size:13px; }

@media print {
    .print-btn { display:none; }
    body { background:#fff; color:#1a1a2e; }
    .page { padding:16px 24px; }
    .info-box, .svc-card, .fin-box, .terms-box { background:#f8f8f8; border-color:#ddd; }
    .info-val, .svc-name { color:#1a1a2e; }
    .svc-notas, .fin-label, .terms-list li { color:#444; }
    .tool-tag { background:#f0f0f0; color:#333; border-color:#ddd; }
    .section-title { color:#1a9b54; border-color:#e0e0e0; }
    .header { border-color:#1a9b54; }
    .footer { border-color:#1a9b54; }
    .footer-brand { color:#888; }
    @page { size:A4; margin:16mm 12mm; }
}
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Descargar PDF</button>

<div class="page">

    <div class="header">
        <div class="header-logo">
            <?php if ($logoSrc): ?><img src="<?= $logoSrc ?>" alt="Facand"><?php endif; ?>
        </div>
        <div class="header-meta">
            <div class="header-folio">F-<?= $folio ?></div>
            <div class="header-label">Ficha de cliente</div>
            <div class="header-client"><?= htmlspecialchars($nombre) ?></div>
            <div style="font-size:11px;color:#666;margin-top:2px;"><?= $fecha ?></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Información del Cliente</div>
        <div class="info-grid">
            <div class="info-box"><div class="info-label">Rubro</div><div class="info-val"><?= htmlspecialchars($c['rubro'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Contacto</div><div class="info-val"><?= htmlspecialchars($c['contacto_nombre'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Responsable Facand</div><div class="info-val"><?= htmlspecialchars($c['responsable_nombre'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Email</div><div class="info-val <?= empty($c['email']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['email'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Teléfono</div><div class="info-val <?= empty($c['telefono']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['telefono'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Pendiente de pago</div><div class="info-val <?= $monto_pendiente > 0 ? 'red' : 'green' ?>"><?= $monto_pendiente > 0 ? '$' . number_format($monto_pendiente, 0, ',', '.') : 'Al día' ?></div></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Servicios Contratados</div>
        <?php if (!empty($servicios_db)): ?>
        <div class="svc-list">
            <?php foreach ($servicios_db as $sv):
                $is_plan = str_starts_with($sv['nombre'], 'Plan ');
                $is_custom = str_starts_with($sv['nombre'], 'Custom');
                $cls = $is_plan ? 'plan' : ($is_custom ? 'custom' : 'adicional');
                $label = $is_plan ? 'Plan' : ($is_custom ? 'Custom' : ucfirst($sv['tipo']));
                $mfmt = '$' . number_format($sv['monto'], 0, ',', '.');
                if ($sv['tipo'] === 'suscripcion') $mfmt .= '/mes';
            ?>
            <div class="svc-card <?= $cls ?>">
                <div class="svc-head">
                    <div><span class="svc-badge <?= $cls ?>"><?= $label ?></span> <span class="svc-name"><?= htmlspecialchars($sv['nombre']) ?></span></div>
                    <span class="svc-monto"><?= $mfmt ?></span>
                </div>
                <?php if ($sv['notas']): ?><div class="svc-notas"><?= htmlspecialchars($sv['notas']) ?></div><?php endif; ?>
                <?php if ($sv['estado'] !== 'activo'): ?><div class="svc-estado">Estado: <?= ucfirst($sv['estado']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p style="color:#666;">Servicios por definir.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">Resumen Financiero</div>
        <div class="fin-box">
            <?php if ($total_suscripcion > 0): ?>
            <div class="fin-row"><span class="fin-label">Suscripción mensual</span><span class="fin-value green">$<?= number_format($total_suscripcion, 0, ',', '.') ?>/mes</span></div>
            <?php endif; ?>
            <?php if ($total_implementacion > 0): ?>
            <div class="fin-row"><span class="fin-label">Implementación / Custom</span><span class="fin-value">$<?= number_format($total_implementacion, 0, ',', '.') ?></span></div>
            <?php endif; ?>
            <?php if ($c['presupuesto_ads']): ?>
            <div class="fin-row"><span class="fin-label">Inversión publicitaria (cargo del cliente)</span><span class="fin-value"><?= htmlspecialchars($c['presupuesto_ads']) ?></span></div>
            <?php endif; ?>
        </div>
        <?php if ($tiene_ads): ?>
        <div class="fin-note"><strong>Importante:</strong> La inversión publicitaria es de exclusiva responsabilidad y cargo del cliente. No está incluida en el servicio de Facand.</div>
        <?php endif; ?>
    </div>

    <?php if (!empty($herramientas)): ?>
    <div class="section">
        <div class="section-title">Herramientas</div>
        <div class="tool-tags">
            <?php foreach ($herramientas as $h): ?><span class="tool-tag"><?= htmlspecialchars($h) ?></span><?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Condiciones del Servicio</div>
        <div class="terms-grid">
            <div class="terms-box">
                <h3>Responsabilidades del cliente</h3>
                <ul class="terms-list">
                    <?php if ($tiene_ads): ?>
                    <li>La <strong>inversión publicitaria</strong> es de exclusiva responsabilidad y cargo del cliente.</li>
                    <li>Entregar <strong>accesos a cuentas publicitarias</strong> y plataformas dentro de los primeros 5 días hábiles.</li>
                    <li>Informar <strong>cambios de oferta o producto con al menos 5 días de anticipación</strong>.</li>
                    <?php endif; ?>
                    <li>Proveer <strong>material audiovisual</strong> (fotos, videos, logos) necesario para los servicios contratados.</li>
                    <?php if ($tiene_web): ?>
                    <li>Proveer <strong>contenido y textos</strong> para el sitio web dentro de los plazos acordados.</li>
                    <li>Dar <strong>feedback oportuno</strong> sobre avances y entregables.</li>
                    <?php endif; ?>
                    <?php if ($tiene_custom): ?>
                    <li>Entregar <strong>información y accesos</strong> necesarios para la ejecución del servicio custom.</li>
                    <?php endif; ?>
                    <li>Las cuentas y plataformas son <strong>propiedad del cliente</strong>.</li>
                </ul>
            </div>
            <div class="terms-box">
                <h3>Compromisos de Facand</h3>
                <ul class="terms-list">
                    <?php if ($tiene_ads): ?>
                    <li><strong>Gestión profesional de campañas</strong> con optimización continua.</li>
                    <li><strong>Reportes periódicos</strong> con métricas y análisis de resultados.</li>
                    <?php endif; ?>
                    <?php if ($tiene_web): ?>
                    <li><strong>Desarrollo y mantención</strong> del sitio web según alcance contratado.</li>
                    <li><strong>Hosting y dominio</strong> incluidos durante la vigencia del servicio.</li>
                    <?php endif; ?>
                    <?php if ($tiene_custom): ?>
                    <li><strong>Ejecución del servicio custom</strong> según alcance definido en la propuesta.</li>
                    <?php endif; ?>
                    <li><strong>Soporte técnico</strong> en horario laboral (L-V, 9:00 a 18:00 hrs).</li>
                    <li>Transparencia: cuentas a nombre del cliente al término del servicio.</li>
                    <li><strong>Sin contratos de permanencia.</strong> Cancelación con aviso previo.</li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($c['notas']): ?>
    <div class="section">
        <div class="section-title">Observaciones</div>
        <div style="background:#111;border:1px solid #222;border-radius:7px;padding:12px;font-size:12px;color:#999;white-space:pre-line;"><?= htmlspecialchars($c['notas']) ?></div>
    </div>
    <?php endif; ?>

    <div class="footer">
        <div class="footer-brand">
            <strong>FACAND</strong> — Agencia de Marketing Digital<br>
            <span style="font-size:10px;">Documento informativo — acuerdo de servicios vigente.</span>
        </div>
        <div style="font-size:10px;color:#666;">Folio F-<?= $folio ?> · <?= $fecha ?></div>
    </div>

</div>
</body>
</html>
