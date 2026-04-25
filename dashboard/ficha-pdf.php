<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$c = $id ? query_one('SELECT c.*, e.nombre as responsable_nombre FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id WHERE c.id = ?', [$id]) : null;
if (!$c) { echo 'Cliente no encontrado'; exit; }

// Servicios desde servicios_cliente (fuente única)
$servicios_db = query_all('SELECT nombre, tipo, monto, estado, notas FROM servicios_cliente WHERE cliente_id = ? ORDER BY tipo DESC, monto DESC', [$id]);
$herramientas = array_filter(array_map('trim', explode(',', $c['herramientas'] ?? '')));

// Montos
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

// Logo en base64
$logoPath = __DIR__ . '/assets/img/logo.webp';
$logoSrc = '';
if (file_exists($logoPath)) {
    $logoSrc = 'data:image/webp;base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ficha — <?= htmlspecialchars($nombre) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DM Sans',sans-serif; color:#1a1a2e; background:#fff; font-size:13px; line-height:1.6; }
.page { max-width:820px; margin:0 auto; padding:40px 48px; }

.print-btn { position:fixed; top:16px; right:16px; background:#41d77e; color:#fff; border:none; padding:10px 24px; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; z-index:200; font-family:inherit; }
.print-btn:hover { background:#2fc46e; }

/* Header */
.header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:20px; border-bottom:3px solid #41d77e; margin-bottom:28px; }
.header-logo { display:flex; align-items:center; gap:10px; }
.header-logo img { height:40px; }
.header-logo-text { font-size:22px; font-weight:800; color:#41d77e; letter-spacing:1px; }
.header-meta { text-align:right; }
.header-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:#888; }
.header-date { font-size:11px; color:#888; margin-top:2px; }
.header-client { font-size:18px; font-weight:800; color:#1a1a2e; margin-top:6px; }

/* Sections */
.section { margin-bottom:24px; }
.section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:#41d77e; padding-bottom:6px; border-bottom:1.5px solid #e8faf0; margin-bottom:12px; }

/* Info grid */
.info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.info-box { background:#f7fffe; border:1px solid #e0f5eb; border-radius:7px; padding:10px 14px; }
.info-label { font-size:9px; text-transform:uppercase; letter-spacing:.5px; color:#999; font-weight:700; margin-bottom:2px; }
.info-value { font-size:13px; font-weight:600; color:#1a1a2e; }
.info-value.big { font-size:16px; font-weight:800; color:#41d77e; }
.info-value.danger { color:#c53030; }
.info-value.muted { color:#888; font-weight:400; }

/* Service cards */
.svc-list { display:flex; flex-direction:column; gap:10px; }
.svc-card { border:1px solid #e0f5eb; border-radius:8px; padding:14px 16px; background:#f7fffe; }
.svc-card.custom { border-left:3px solid #f59e0b; background:#fffdf7; }
.svc-card.plan { border-left:3px solid #41d77e; }
.svc-card.adicional { border-left:3px solid #3b82f6; }
.svc-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
.svc-name { font-size:13px; font-weight:700; color:#1a1a2e; }
.svc-badge { font-size:9px; padding:2px 8px; border-radius:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.svc-badge.plan { background:#e8faf0; color:#1a9b54; }
.svc-badge.custom { background:#fef3c7; color:#92400e; }
.svc-badge.adicional { background:#dbeafe; color:#1d4ed8; }
.svc-monto { font-size:14px; font-weight:800; color:#41d77e; }
.svc-notas { font-size:11.5px; color:#666; margin-top:4px; line-height:1.5; }

/* Financial */
.fin-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f0f5f2; font-size:13px; }
.fin-row:last-child { border-bottom:none; }
.fin-label { color:#555; }
.fin-value { font-weight:700; }

/* Terms */
.terms-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.terms-box { background:#f7fffe; border:1px solid #e0f5eb; border-radius:8px; padding:16px 18px; }
.terms-box h3 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#41d77e; margin-bottom:10px; }
.terms-list { list-style:none; }
.terms-list li { padding:4px 0 4px 16px; position:relative; font-size:11px; color:#444; line-height:1.5; border-bottom:1px solid #eef7f2; }
.terms-list li:last-child { border-bottom:none; }
.terms-list li::before { content:'→'; position:absolute; left:0; color:#41d77e; font-weight:700; }

/* Tools */
.tool-tags { display:flex; flex-wrap:wrap; gap:5px; }
.tool-tag { padding:3px 10px; background:#f1f5f9; border-radius:4px; font-size:11px; font-weight:600; color:#444; border:1px solid #e2e8f0; }

/* Footer */
.footer { margin-top:32px; padding-top:14px; border-top:2px solid #41d77e; display:flex; justify-content:space-between; align-items:center; }
.footer-brand { font-size:11px; color:#888; }
.footer-brand strong { color:#41d77e; font-size:13px; }

@media print {
    .print-btn { display:none; }
    .page { padding:16px 24px; max-width:100%; }
    body { font-size:11px; }
    @page { size:A4; margin:16mm 12mm; }
}
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Descargar PDF</button>

<div class="page">

    <!-- Header -->
    <div class="header">
        <div class="header-logo">
            <?php if ($logoSrc): ?>
                <img src="<?= $logoSrc ?>" alt="Facand">
            <?php endif; ?>
            <span class="header-logo-text">FACAND</span>
        </div>
        <div class="header-meta">
            <div class="header-label">Ficha de Cliente</div>
            <div class="header-date"><?= $fecha ?></div>
            <div class="header-client"><?= htmlspecialchars($nombre) ?></div>
        </div>
    </div>

    <!-- Info cliente -->
    <div class="section">
        <div class="section-title">Información del Cliente</div>
        <div class="info-grid">
            <div class="info-box"><div class="info-label">Rubro</div><div class="info-value"><?= htmlspecialchars($c['rubro'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Contacto</div><div class="info-value"><?= htmlspecialchars($c['contacto_nombre'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Responsable Facand</div><div class="info-value"><?= htmlspecialchars($c['responsable_nombre'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Email</div><div class="info-value <?= empty($c['email']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['email'] ?: '—') ?></div></div>
            <div class="info-box"><div class="info-label">Teléfono</div><div class="info-value <?= empty($c['telefono']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['telefono'] ?: '—') ?></div></div>
            <div class="info-box">
                <div class="info-label">Pendiente de pago</div>
                <div class="info-value <?= $monto_pendiente > 0 ? 'danger' : '' ?>"><?= $monto_pendiente > 0 ? '$' . number_format($monto_pendiente, 0, ',', '.') : 'Al día' ?></div>
            </div>
        </div>
    </div>

    <!-- Servicios contratados -->
    <div class="section">
        <div class="section-title">Servicios Contratados</div>
        <?php if (!empty($servicios_db)): ?>
        <div class="svc-list">
            <?php foreach ($servicios_db as $sv):
                $is_plan = str_starts_with($sv['nombre'], 'Plan ');
                $is_custom = str_starts_with($sv['nombre'], 'Custom');
                $card_class = $is_plan ? 'plan' : ($is_custom ? 'custom' : 'adicional');
                $badge_class = $card_class;
                $badge_label = $is_plan ? 'Plan' : ($is_custom ? 'Custom' : ucfirst($sv['tipo']));
                $monto_fmt = '$' . number_format($sv['monto'], 0, ',', '.');
                if ($sv['tipo'] === 'suscripcion') $monto_fmt .= '/mes';
            ?>
            <div class="svc-card <?= $card_class ?>">
                <div class="svc-header">
                    <div>
                        <span class="svc-badge <?= $badge_class ?>"><?= $badge_label ?></span>
                        <span class="svc-name"><?= htmlspecialchars($sv['nombre']) ?></span>
                    </div>
                    <span class="svc-monto"><?= $monto_fmt ?></span>
                </div>
                <?php if ($sv['notas']): ?>
                    <div class="svc-notas"><?= htmlspecialchars($sv['notas']) ?></div>
                <?php endif; ?>
                <?php if ($sv['estado'] !== 'activo'): ?>
                    <div style="margin-top:4px;font-size:10px;color:#92400e;font-weight:600;">Estado: <?= ucfirst($sv['estado']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p style="font-size:12px;color:#888;">Servicios por definir.</p>
        <?php endif; ?>
    </div>

    <!-- Resumen financiero -->
    <div class="section">
        <div class="section-title">Resumen Financiero</div>
        <div style="border:1px solid #e0f5eb;border-radius:8px;padding:4px 16px;">
            <?php if ($total_suscripcion > 0): ?>
            <div class="fin-row">
                <span class="fin-label">Suscripción mensual</span>
                <span class="fin-value" style="color:#41d77e">$<?= number_format($total_suscripcion, 0, ',', '.') ?>/mes</span>
            </div>
            <?php endif; ?>
            <?php if ($total_implementacion > 0): ?>
            <div class="fin-row">
                <span class="fin-label">Implementación / Custom</span>
                <span class="fin-value">$<?= number_format($total_implementacion, 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($c['presupuesto_ads']): ?>
            <div class="fin-row">
                <span class="fin-label">Inversión publicitaria (a cargo del cliente)</span>
                <span class="fin-value"><?= htmlspecialchars($c['presupuesto_ads']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($c['presupuesto_ads']): ?>
        <div style="margin-top:8px;padding:8px 14px;background:#fffbeb;border-radius:6px;border-left:3px solid #f59e0b;font-size:10.5px;color:#92400e;">
            <strong>Importante:</strong> La inversión publicitaria es de exclusiva responsabilidad y cargo del cliente. No está incluida en el servicio de Facand.
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($herramientas)): ?>
    <div class="section">
        <div class="section-title">Herramientas y Plataformas</div>
        <div class="tool-tags">
            <?php foreach ($herramientas as $h): ?>
                <span class="tool-tag"><?= htmlspecialchars($h) ?></span>
            <?php endforeach; ?>
        </div>
        <p style="font-size:10px;color:#888;margin-top:6px;">Las cuentas son propiedad del cliente.</p>
    </div>
    <?php endif; ?>

    <!-- Condiciones -->
    <div class="section">
        <div class="section-title">Condiciones del Servicio</div>
        <div class="terms-grid">
            <div class="terms-box">
                <h3>Responsabilidades del cliente</h3>
                <ul class="terms-list">
                    <li>La inversión publicitaria es de <strong>exclusiva responsabilidad y cargo del cliente</strong>.</li>
                    <li>Proveer <strong>material audiovisual</strong> en formatos adecuados para publicidad digital.</li>
                    <li>Entregar <strong>accesos a cuentas publicitarias</strong> y plataformas dentro de los primeros 5 días hábiles.</li>
                    <li><strong>Aprobar creatividades</strong> en plazo razonable.</li>
                    <li>Informar <strong>cambios de oferta con al menos 5 días de anticipación</strong>.</li>
                    <li>Las cuentas publicitarias son <strong>propiedad del cliente</strong>.</li>
                </ul>
            </div>
            <div class="terms-box">
                <h3>Compromisos de Facand</h3>
                <ul class="terms-list">
                    <li><strong>Gestión profesional</strong> de campañas según plan contratado.</li>
                    <li><strong>Reportes periódicos</strong> con métricas y análisis.</li>
                    <li><strong>Optimización continua</strong> para maximizar retorno.</li>
                    <li><strong>Soporte técnico</strong> en horario laboral (L-V, 9:00-18:00).</li>
                    <li>Transparencia total: cuentas a nombre del cliente al término.</li>
                    <li>Sin contratos de permanencia.</li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($c['notas']): ?>
    <div class="section">
        <div class="section-title">Observaciones</div>
        <div style="background:#f7fffe;border:1px solid #e0f5eb;border-radius:7px;padding:12px;font-size:12px;color:#444;white-space:pre-line;"><?= htmlspecialchars($c['notas']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-brand">
            <strong>FACAND</strong> — Agencia de Marketing Digital<br>
            <span style="font-size:10px;">Documento informativo — acuerdo de servicios vigente.</span>
        </div>
        <div style="font-size:10px;color:#aaa;">Generado el <?= $fecha ?></div>
    </div>

</div>
</body>
</html>
