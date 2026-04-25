<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$c = $id ? query_one(
    'SELECT c.*, e.nombre as responsable_nombre FROM clientes c LEFT JOIN equipo e ON c.responsable_id = e.id WHERE c.id = ?',
    [$id]
) : null;
if (!$c) { echo 'Cliente no encontrado'; exit; }

// Servicios desde servicios_cliente
$servicios_db = query_all(
    'SELECT nombre, tipo, monto, estado, notas FROM servicios_cliente WHERE cliente_id = ? AND estado = "activo" ORDER BY nombre',
    [$id]
);

// Si no hay servicios_cliente, usar el campo servicios (texto separado por comas)
$servicios_texto = array_filter(array_map('trim', explode(',', $c['servicios'] ?? '')));
$herramientas   = array_filter(array_map('trim', explode(',', $c['herramientas'] ?? '')));
$presupuesto    = array_filter(explode("\n", $c['presupuesto_ads'] ?? ''));

$planes = [
    'growth'       => 'Plan Growth',
    'scale'        => 'Plan Scale',
    'starter'      => 'Plan Starter',
    'meta_ads'     => 'Meta Ads',
    'google_ads'   => 'Google Ads',
    'full_ads'     => 'Full Ads',
    'full_ads_seo' => 'Full Ads + SEO',
];
$planLabel   = $c['plan'] ? ($planes[$c['plan']] ?? $c['plan']) : 'Custom';
$isCustom    = empty($c['plan']);
$fecha       = date('d/m/Y');
$nombre      = $c['nombre'];
$feeFormato  = $c['fee_mensual'] > 0 ? '$' . number_format((int)$c['fee_mensual'], 0, ',', '.') . '/mes' : '—';
$adsFormato  = '';
if (!empty($presupuesto)) {
    $adsFormato = implode(' · ', $presupuesto);
}

// Logo en base64 si existe (para impresión offline)
$logoPath = __DIR__ . '/assets/img/logo.png';
$logoSrc  = '';
if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoMime = mime_content_type($logoPath);
    $logoSrc  = 'data:' . $logoMime . ';base64,' . $logoData;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ficha de Cliente — <?= htmlspecialchars($nombre) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1a1a2e;
    background: #fff;
    font-size: 13px;
    line-height: 1.65;
}

.page-wrap {
    max-width: 860px;
    margin: 0 auto;
    padding: 40px 48px;
}

/* ---- Botón de impresión (solo pantalla) ---- */
.print-btn {
    position: fixed;
    top: 18px;
    right: 18px;
    background: #41d77e;
    color: #fff;
    border: none;
    padding: 10px 22px;
    border-radius: 7px;
    font-weight: 700;
    cursor: pointer;
    font-size: 13px;
    z-index: 200;
    letter-spacing: .3px;
    box-shadow: 0 4px 14px rgba(65,215,126,.35);
    transition: background .15s;
}
.print-btn:hover { background: #2fc46e; }

/* ---- Header ---- */
.doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 22px;
    border-bottom: 3px solid #41d77e;
    margin-bottom: 28px;
}

.logo-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}
.logo-img { height: 44px; width: auto; }
.logo-text {
    font-size: 24px;
    font-weight: 800;
    color: #41d77e;
    letter-spacing: 2px;
    line-height: 1;
}
.logo-sub { font-size: 10px; color: #888; letter-spacing: 1px; text-transform: uppercase; margin-top: 2px; }

.doc-meta { text-align: right; }
.doc-meta .doc-type {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #555;
}
.doc-meta .doc-date { font-size: 11px; color: #888; margin-top: 3px; }
.doc-meta .doc-client {
    font-size: 18px;
    font-weight: 800;
    color: #1a1a2e;
    margin-top: 6px;
    max-width: 340px;
}

/* ---- Secciones ---- */
.section { margin-bottom: 26px; }
.section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #41d77e;
    padding-bottom: 7px;
    border-bottom: 1.5px solid #e8faf0;
    margin-bottom: 14px;
}

/* ---- Grilla de info ---- */
.info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.info-grid-2 { grid-template-columns: repeat(2, 1fr); }

.info-box {
    background: #f7fffe;
    border: 1px solid #e0f5eb;
    border-radius: 7px;
    padding: 10px 14px;
}
.info-box.accent {
    background: #f0fdf7;
    border-color: #41d77e;
    border-left-width: 3px;
}
.info-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #999;
    font-weight: 700;
    margin-bottom: 3px;
}
.info-value {
    font-size: 13px;
    font-weight: 600;
    color: #1a1a2e;
}
.info-value.big {
    font-size: 16px;
    font-weight: 800;
    color: #41d77e;
}
.info-value.muted { color: #888; font-weight: 400; }

/* ---- Badge plan ---- */
.plan-badge {
    display: inline-block;
    background: #41d77e;
    color: #fff;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .3px;
}
.plan-badge.custom {
    background: #f0f0f0;
    color: #555;
}

/* ---- Badge estado pago ---- */
.badge-pagado  { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; background:#e8f9f0; color:#1a9b54; border:1px solid #b6edd0; }
.badge-vencido { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; background:#fef2f2; color:#c53030; border:1px solid #fca5a5; }
.badge-pendiente { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
.badge-canje   { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; }

/* ---- Servicios ---- */
.servicios-table { width: 100%; border-collapse: collapse; }
.servicios-table th {
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #999;
    padding: 6px 10px;
    background: #f7fffe;
    border-bottom: 1.5px solid #e0f5eb;
}
.servicios-table td {
    padding: 9px 10px;
    font-size: 12.5px;
    border-bottom: 1px solid #f0f5f2;
    vertical-align: top;
}
.servicios-table tr:last-child td { border-bottom: none; }
.serv-tipo {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 3px;
    background: #e8f9f0;
    color: #1a9b54;
    font-weight: 600;
    white-space: nowrap;
}

/* ---- Herramientas tags ---- */
.tool-tags { display: flex; flex-wrap: wrap; gap: 6px; }
.tool-tag {
    display: inline-block;
    padding: 4px 12px;
    background: #f1f5f9;
    border-radius: 4px;
    font-size: 11.5px;
    font-weight: 600;
    color: #444;
    border: 1px solid #e2e8f0;
}

/* ---- Sección financiera ---- */
.fin-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 9px 0;
    border-bottom: 1px solid #f0f5f2;
    font-size: 13px;
}
.fin-row:last-child { border-bottom: none; }
.fin-label { color: #555; }
.fin-value { font-weight: 700; color: #1a1a2e; }
.fin-note { font-size: 10.5px; color: #888; margin-top: 2px; }

/* ---- Condiciones ---- */
.terms-box {
    background: #f7fffe;
    border: 1px solid #e0f5eb;
    border-radius: 8px;
    padding: 18px 20px;
}
.terms-box h3 {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #41d77e;
    margin-bottom: 12px;
}
.terms-list { list-style: none; padding: 0; }
.terms-list li {
    padding: 5px 0 5px 20px;
    position: relative;
    font-size: 11.5px;
    color: #444;
    line-height: 1.55;
    border-bottom: 1px solid #eef7f2;
}
.terms-list li:last-child { border-bottom: none; }
.terms-list li::before {
    content: '→';
    position: absolute;
    left: 0;
    color: #41d77e;
    font-weight: 700;
    font-size: 12px;
}
.terms-list li strong { color: #1a1a2e; }

.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ---- Responsabilidades ---- */
.resp-check::before { content: '✓'; color: #1a9b54; }
.resp-cross::before { content: '•'; color: #41d77e; }

/* ---- Footer ---- */
.doc-footer {
    margin-top: 36px;
    padding-top: 16px;
    border-top: 2px solid #41d77e;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.footer-brand { font-size: 11px; color: #888; }
.footer-brand strong { color: #41d77e; font-size: 13px; }
.footer-date { font-size: 10px; color: #aaa; }

/* ---- Print ---- */
@media print {
    .print-btn { display: none; }
    .page-wrap { padding: 20px 28px; max-width: 100%; }
    body { font-size: 12px; }
    @page { size: A4; margin: 18mm 14mm; }
}
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Descargar PDF</button>

<div class="page-wrap">

    <!-- Header -->
    <div class="doc-header">
        <div class="logo-wrap">
            <?php if ($logoSrc): ?>
                <img class="logo-img" src="<?= $logoSrc ?>" alt="Facand">
            <?php else: ?>
                <div>
                    <div class="logo-text">FACAND</div>
                    <div class="logo-sub">Agencia Digital</div>
                </div>
            <?php endif; ?>
        </div>
        <div class="doc-meta">
            <div class="doc-type">Ficha de Cliente</div>
            <div class="doc-date">Fecha: <?= $fecha ?></div>
            <div class="doc-client"><?= htmlspecialchars($nombre) ?></div>
        </div>
    </div>

    <!-- Información del cliente -->
    <div class="section">
        <div class="section-title">Información del Cliente</div>
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">Nombre</div>
                <div class="info-value"><?= htmlspecialchars($c['nombre']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Rubro</div>
                <div class="info-value <?= empty($c['rubro']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['rubro'] ?: '—') ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Contacto</div>
                <div class="info-value <?= empty($c['contacto_nombre']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['contacto_nombre'] ?: '—') ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Email</div>
                <div class="info-value <?= empty($c['email']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['email'] ?: '—') ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Teléfono</div>
                <div class="info-value <?= empty($c['telefono']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['telefono'] ?: '—') ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Responsable Facand</div>
                <div class="info-value <?= empty($c['responsable_nombre']) ? 'muted' : '' ?>"><?= htmlspecialchars($c['responsable_nombre'] ?: '—') ?></div>
            </div>
        </div>
    </div>

    <!-- Plan -->
    <div class="section">
        <div class="section-title">Plan Contratado</div>
        <div class="info-grid" style="grid-template-columns: auto 1fr 1fr 1fr; align-items:center;">
            <div style="padding-right:8px;">
                <span class="plan-badge <?= $isCustom ? 'custom' : '' ?>"><?= htmlspecialchars($planLabel) ?></span>
            </div>
            <div class="info-box accent" style="grid-column: span 1;">
                <div class="info-label">Suscripción mensual</div>
                <div class="info-value big"><?= $feeFormato ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Estado de Pago</div>
                <div class="info-value" style="margin-top:4px">
                    <span class="badge-<?= htmlspecialchars($c['estado_pago'] ?? 'pendiente') ?>"><?= ucfirst($c['estado_pago'] ?? 'pendiente') ?></span>
                </div>
            </div>
            <div class="info-box">
                <div class="info-label">Tipo de cliente</div>
                <div class="info-value"><?= ucfirst(htmlspecialchars($c['tipo'] ?? '—')) ?></div>
            </div>
        </div>
        <?php if ($isCustom && !empty($c['servicios'])): ?>
            <div style="margin-top:10px;padding:10px 14px;background:#f7fffe;border-radius:6px;border:1px solid #e0f5eb;font-size:12px;color:#555;">
                <strong style="color:#1a1a2e;">Descripción del servicio:</strong> <?= htmlspecialchars($c['servicios']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Servicios contratados -->
    <div class="section">
        <div class="section-title">Servicios Contratados</div>
        <?php if (!empty($servicios_db)): ?>
            <table class="servicios-table">
                <thead>
                    <tr>
                        <th>Servicio</th>
                        <th>Tipo</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($servicios_db as $sv): ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars($sv['nombre']) ?></td>
                        <td><span class="serv-tipo"><?= htmlspecialchars(ucfirst($sv['tipo'])) ?></span></td>
                        <td class="info-value muted"><?= htmlspecialchars($sv['notas'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (!empty($servicios_texto)): ?>
            <table class="servicios-table">
                <thead>
                    <tr><th>Servicio</th></tr>
                </thead>
                <tbody>
                <?php foreach ($servicios_texto as $s): ?>
                    <tr><td style="font-weight:600"><?= htmlspecialchars($s) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="font-size:12px;color:#888;">Servicios por definir — proyecto personalizado.</p>
        <?php endif; ?>
    </div>

    <!-- Sección financiera -->
    <div class="section">
        <div class="section-title">Resumen Financiero</div>
        <div style="border:1px solid #e0f5eb;border-radius:8px;overflow:hidden;padding:4px 16px;">
            <div class="fin-row">
                <div>
                    <div class="fin-label">Suscripción mensual (fee de servicio)</div>
                    <div class="fin-note">Tarifa por gestión y servicios Facand</div>
                </div>
                <div class="fin-value"><?= $feeFormato ?></div>
            </div>
            <?php if (!empty($presupuesto)): ?>
            <div class="fin-row">
                <div>
                    <div class="fin-label">Inversión publicitaria (a cargo del cliente)</div>
                    <div class="fin-note">Presupuesto de anuncios — responsabilidad exclusiva del cliente</div>
                </div>
                <div class="fin-value"><?= htmlspecialchars($adsFormato) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($presupuesto)): ?>
        <div style="margin-top:8px;padding:8px 14px;background:#fffbeb;border-radius:6px;border-left:3px solid #f59e0b;font-size:11px;color:#92400e;line-height:1.6">
            <strong>Importante:</strong> La inversión publicitaria (presupuesto de anuncios en Google Ads, Meta Ads u otras plataformas) es de exclusiva responsabilidad y cargo del cliente. Este monto no está incluido en el fee de servicio de Facand.
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($herramientas)): ?>
    <!-- Herramientas -->
    <div class="section">
        <div class="section-title">Herramientas y Plataformas</div>
        <div class="tool-tags">
            <?php foreach ($herramientas as $h): ?>
                <span class="tool-tag"><?= htmlspecialchars($h) ?></span>
            <?php endforeach; ?>
        </div>
        <p style="font-size:11px;color:#888;margin-top:8px;">Configuradas y gestionadas por Facand. Las cuentas son propiedad del cliente.</p>
    </div>
    <?php endif; ?>

    <!-- Condiciones del servicio -->
    <div class="section">
        <div class="section-title">Condiciones del Servicio</div>
        <div class="two-col">
            <div>
                <div class="terms-box">
                    <h3>Responsabilidades del cliente</h3>
                    <ul class="terms-list">
                        <li>La inversión publicitaria (presupuesto de anuncios en Google Ads, Meta Ads u otras plataformas) es de <strong>exclusiva responsabilidad y cargo del cliente</strong>.</li>
                        <li>Proveer <strong>material audiovisual</strong> (fotos, videos, logos) en formatos adecuados para publicidad digital.</li>
                        <li>Entregar <strong>accesos a cuentas publicitarias</strong>, Google Analytics, Search Console y demás plataformas dentro de los primeros 5 días hábiles del inicio del servicio.</li>
                        <li><strong>Aprobar creatividades</strong> en un plazo razonable para no retrasar la publicación de campañas.</li>
                        <li>Informar <strong>cambios de oferta o producto con al menos 5 días de anticipación</strong>.</li>
                        <li>Las cuentas publicitarias, GA4, GTM y Search Console son <strong>propiedad del cliente</strong>.</li>
                    </ul>
                </div>
            </div>
            <div>
                <div class="terms-box">
                    <h3>Compromisos de Facand</h3>
                    <ul class="terms-list">
                        <li><strong>Gestión profesional de campañas</strong> digitales según el plan contratado.</li>
                        <li><strong>Reportes periódicos</strong> de rendimiento con métricas clave y análisis de resultados.</li>
                        <li><strong>Optimización continua</strong> de campañas para maximizar el retorno de la inversión.</li>
                        <li><strong>Soporte técnico</strong> en horario laboral (lunes a viernes, 9:00 a 18:00 hrs).</li>
                        <li>Transparencia total: las cuentas quedan a nombre del cliente al término del contrato.</li>
                        <li>Sin contratos de permanencia. El servicio puede cancelarse con aviso previo.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div style="margin-top:12px;padding:10px 14px;background:#f7fffe;border-radius:6px;border:1px solid #e0f5eb;font-size:11.5px;color:#555;">
            Facturación mensual dentro de los primeros 5 días del mes. Cambios adicionales fuera del plan se cotizan por separado.
        </div>
    </div>

    <?php if ($c['notas']): ?>
    <!-- Notas -->
    <div class="section">
        <div class="section-title">Observaciones</div>
        <div style="background:#f7fffe;border:1px solid #e0f5eb;border-radius:7px;padding:14px;font-size:12.5px;color:#444;white-space:pre-line;"><?= htmlspecialchars($c['notas']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="doc-footer">
        <div class="footer-brand">
            <strong>FACAND</strong> — Agencia de Marketing Digital<br>
            <span style="font-size:10px;">Este documento es de carácter informativo y representa el acuerdo de servicios vigente.</span>
        </div>
        <div class="footer-date">Generado el <?= $fecha ?></div>
    </div>

</div><!-- /page-wrap -->
</body>
</html>
