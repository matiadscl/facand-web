<?php
/**
 * Módulo Conciliación — Cruce Mercado Pago vs Facturas
 * Importación directa desde API Mercado Pago
 */

// Movimientos MP importados
$mp_movs = query_all("SELECT id, tipo, categoria, subcategoria, descripcion, monto, fecha, notas, created_at
    FROM finanzas WHERE origen = 'mercadopago' ORDER BY fecha DESC, id DESC") ?: [];

$total_ingresos_mp = query_scalar("SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE origen = 'mercadopago' AND tipo = 'ingreso'") ?? 0;
$total_gastos_mp = query_scalar("SELECT COALESCE(SUM(monto),0) FROM finanzas WHERE origen = 'mercadopago' AND tipo = 'gasto'") ?? 0;
$total_movs = count($mp_movs);
$ultima_sync = query_scalar("SELECT MAX(created_at) FROM finanzas WHERE origen = 'mercadopago'");
?>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Movimientos MP</div>
        <div class="kpi-value"><?= $total_movs ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Ingresos MP</div>
        <div class="kpi-value success"><?= format_money($total_ingresos_mp) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Gastos MP</div>
        <div class="kpi-value danger"><?= format_money($total_gastos_mp) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Última sincronización</div>
        <div class="kpi-value" style="font-size:.85rem;"><?= $ultima_sync ? date('d/m/Y H:i', strtotime($ultima_sync)) : 'Nunca' ?></div>
    </div>
</div>

<!-- Botón importar -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Mercado Pago</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" id="btnImportMP" onclick="importMercadoPago()">
                Importar desde Mercado Pago
            </button>
        </div>
    </div>
    <div id="importStatus" style="display:none;padding:16px;text-align:center;"></div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab active" data-tab="mp_todos">Todos</button>
    <button class="tab" data-tab="mp_ingresos">Ingresos</button>
    <button class="tab" data-tab="mp_gastos">Gastos</button>
</div>

<!-- Tabla todos -->
<div class="tab-content active" data-tab-content="mp_todos">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Movimientos Mercado Pago (<?= $total_movs ?>)</span>
        </div>
        <?php if (empty($mp_movs)): ?>
            <div style="padding:40px;text-align:center;color:var(--text-muted);">
                <div style="font-size:1.5rem;margin-bottom:8px;">Sin movimientos</div>
                <p style="font-size:.85rem;">Haz clic en "Importar desde Mercado Pago" para traer los datos.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead><tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Método</th>
                        <th style="text-align:right">Monto</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($mp_movs as $m): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                            <td><span class="badge <?= $m['tipo'] === 'ingreso' ? 'badge-success' : 'badge-danger' ?>"><?= ucfirst($m['tipo']) ?></span></td>
                            <td><?= htmlspecialchars($m['descripcion']) ?></td>
                            <td style="font-size:.8rem;color:var(--text-muted);"><?= htmlspecialchars($m['subcategoria']) ?></td>
                            <td style="text-align:right;font-weight:600;color:<?= $m['tipo'] === 'ingreso' ? 'var(--success)' : 'var(--danger)' ?>">
                                <?= ($m['tipo'] === 'gasto' ? '-' : '+') . format_money($m['monto']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabla ingresos -->
<div class="tab-content" data-tab-content="mp_ingresos">
    <div class="table-container">
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Fecha</th><th>Descripción</th><th>Método</th><th style="text-align:right">Monto</th></tr></thead>
                <tbody>
                <?php foreach ($mp_movs as $m): if ($m['tipo'] !== 'ingreso') continue; ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                        <td><?= htmlspecialchars($m['descripcion']) ?></td>
                        <td style="font-size:.8rem;color:var(--text-muted);"><?= htmlspecialchars($m['subcategoria']) ?></td>
                        <td style="text-align:right;font-weight:600;color:var(--success)">+<?= format_money($m['monto']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tabla gastos -->
<div class="tab-content" data-tab-content="mp_gastos">
    <div class="table-container">
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Fecha</th><th>Descripción</th><th>Método</th><th style="text-align:right">Monto</th></tr></thead>
                <tbody>
                <?php foreach ($mp_movs as $m): if ($m['tipo'] !== 'gasto') continue; ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                        <td><?= htmlspecialchars($m['descripcion']) ?></td>
                        <td style="font-size:.8rem;color:var(--text-muted);"><?= htmlspecialchars($m['subcategoria']) ?></td>
                        <td style="text-align:right;font-weight:600;color:var(--danger)">-<?= format_money($m['monto']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
async function importMercadoPago() {
    const btn = document.getElementById('btnImportMP');
    const status = document.getElementById('importStatus');

    btn.disabled = true;
    btn.textContent = 'Importando...';
    status.style.display = 'block';
    status.innerHTML = '<span style="color:var(--text-muted)">Conectando con Mercado Pago...</span>';

    try {
        const res = await API.post('import_mercadopago', {});
        if (res.ok) {
            const d = res.data;
            status.innerHTML = `<span style="color:var(--success);font-weight:600">${d.imported} movimientos importados</span>` +
                (d.skipped > 0 ? `<span style="color:var(--text-muted);margin-left:12px">(${d.skipped} omitidos por duplicado o rechazados)</span>` : '');
            if (d.imported > 0) {
                setTimeout(() => location.reload(), 1500);
            }
        } else {
            status.innerHTML = `<span style="color:var(--danger)">${res.error || 'Error al importar'}</span>`;
        }
    } catch (e) {
        status.innerHTML = `<span style="color:var(--danger)">Error de conexión: ${e.message}</span>`;
    }

    btn.disabled = false;
    btn.textContent = 'Importar desde Mercado Pago';
}
</script>
