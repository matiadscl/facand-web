<?php
/**
 * Módulo Cartolas — Import de cartolas bancarias
 * Parser multi-banco: Santander, BCI, Banco Estado, Scotiabank, Chile, Itaú, Falabella, BICE
 * Auto-detección de formato, categorización automática, deduplicación
 * Procesamiento 100% client-side (SheetJS + pdf.js)
 */

$categorias = query_all('SELECT DISTINCT categoria FROM finanzas WHERE categoria != "" ORDER BY categoria') ?: [];
$reglas = query_all('SELECT * FROM reglas_categorizacion ORDER BY tipo, patron') ?: [];
$historial = query_all('SELECT DISTINCT descripcion, categoria, subcategoria FROM finanzas WHERE categoria != "" AND categoria != "general" AND origen = "banco" ORDER BY id DESC LIMIT 500') ?: [];
$clientes_mp = query_all('SELECT id, nombre FROM clientes ORDER BY nombre') ?: [];

$ultima_sync_mp = query_scalar("SELECT MAX(created_at) FROM finanzas WHERE origen = 'mercadopago'");
$total_mp = query_scalar("SELECT COUNT(*) FROM finanzas WHERE origen = 'mercadopago'") ?? 0;
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

<!-- Importar desde Mercado Pago -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Mercado Pago</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" id="btnImportMP" onclick="previewMercadoPago()">
                Importar desde Mercado Pago
            </button>
        </div>
    </div>
    <div id="mpStatus" style="display:none;padding:16px;text-align:center;"></div>
    <div id="mpLastSync" style="padding:8px 16px;font-size:.78rem;color:var(--text-muted);">
        <?= $ultima_sync_mp
            ? "Última sincronización: " . date('d/m/Y H:i', strtotime($ultima_sync_mp)) . " — $total_mp movimientos importados"
            : "Sin importaciones previas" ?>
    </div>
</div>

<!-- Preview MP -->
<div id="mpPreview" style="display:none;">
    <div class="table-container" style="margin-bottom:20px;">
        <div class="table-header">
            <span class="table-title">Preview Mercado Pago</span>
            <div id="mpPreviewStats" style="font-size:.78rem;display:flex;gap:16px;flex-wrap:wrap;"></div>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr>
                    <th style="width:85px">Fecha</th>
                    <th>Descripción</th>
                    <th style="text-align:right;width:100px">Monto</th>
                    <th style="width:250px">Clasificación</th>
                    <th style="width:190px">Conciliación</th>
                    <th style="width:90px">Acción</th>
                </tr></thead>
                <tbody id="mpPreviewBody"></tbody>
            </table>
        </div>
        <div style="padding:16px;display:flex;gap:8px;">
            <button class="btn btn-primary" id="btnConfirmMP" onclick="confirmMPImport()">Confirmar Importación</button>
            <button class="btn btn-secondary" onclick="cancelMPPreview()">Cancelar</button>
        </div>
    </div>
</div>

<!-- Importar Cartola Bancaria -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Importar Cartola Bancaria</span>
    </div>
    <div style="padding:20px;">
        <div id="uploadZone" style="border:2px dashed var(--border);border-radius:12px;padding:40px 20px;text-align:center;cursor:pointer;transition:border-color .2s;background:var(--bg);"
             ondragover="event.preventDefault();this.style.borderColor='var(--accent)'"
             ondragleave="this.style.borderColor='var(--border)'"
             ondrop="event.preventDefault();this.style.borderColor='var(--border)';handleFile(event.dataTransfer.files[0])"
             onclick="document.getElementById('fileInput').click()">
            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,.pdf" style="display:none" onchange="handleFile(this.files[0])">
            <div style="font-size:2rem;margin-bottom:8px;">&#128194;</div>
            <div style="font-weight:600;margin-bottom:4px;">Arrastra tu archivo o haz clic para seleccionar</div>
            <div style="font-size:.78rem;color:var(--text-muted);">Excel (.xlsx), CSV o PDF — Santander, BCI, Banco Estado, Scotiabank, Chile, Itaú, Falabella, BICE</div>
        </div>
        <div id="fileInfo" style="display:none;margin-top:12px;padding:12px;background:var(--bg);border-radius:8px;justify-content:space-between;align-items:center;">
            <span><strong id="fileName"></strong> <span id="fileSize" style="color:var(--text-muted);font-size:.8rem;"></span></span>
            <button class="btn btn-secondary btn-sm" onclick="clearFile()">Quitar</button>
        </div>
        <div id="processingMsg" style="display:none;padding:16px;text-align:center;color:var(--text-muted);">Procesando archivo...</div>
    </div>
</div>

<div id="previewSection" style="display:none;">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Vista Previa</span>
            <div id="previewStats" style="font-size:.78rem;display:flex;gap:16px;flex-wrap:wrap;"></div>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr>
                    <th>Fecha</th><th>Descripción</th><th style="text-align:right">Monto</th>
                    <th>Cuenta</th><th>Categoría</th><th>Estado</th>
                </tr></thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
        <div style="padding:16px;display:flex;gap:8px;">
            <button class="btn btn-primary" id="btnConfirm" onclick="confirmImport()">Confirmar Importación</button>
            <button class="btn btn-secondary" onclick="cancelImport()">Cancelar</button>
        </div>
    </div>
</div>

<script>
const catReglas = <?= json_encode($reglas) ?>;
const catHistorial = <?= json_encode($historial) ?>;
const catCategorias = <?= json_encode(array_column($categorias, 'categoria')) ?>;
let pendingMov = [];

function handleFile(file) {
    if (!file) return;
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(0) + ' KB';
    document.getElementById('fileInfo').style.display = 'flex';
    document.getElementById('processingMsg').style.display = 'block';
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext === 'xlsx' || ext === 'xls') parseExcel(file);
    else if (ext === 'pdf') parsePDF(file);
    else if (ext === 'csv') parseCSV(file);
    else { alert('Formato no soportado'); clearFile(); }
}

function parseExcel(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const wb = XLSX.read(e.target.result, { type:'array', cellDates:true });
            const sheetName = wb.SheetNames.find(s => s.toLowerCase().includes('cartola')) || wb.SheetNames[0];
            processRows(XLSX.utils.sheet_to_json(wb.Sheets[sheetName], { header:1, defval:'' }), file.name);
        } catch(err) { alert('Error al leer Excel: '+err.message); clearFile(); }
    };
    reader.readAsArrayBuffer(file);
}

function parsePDF(file) {
    const reader = new FileReader();
    reader.onload = async function(e) {
        try {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            const pdf = await pdfjsLib.getDocument({ data:e.target.result }).promise;
            let allText = [];
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const content = await page.getTextContent();
                const lines = {};
                content.items.forEach(item => {
                    const y = Math.round(item.transform[5]);
                    if (!lines[y]) lines[y] = [];
                    lines[y].push({ x:item.transform[4], text:item.str });
                });
                Object.keys(lines).sort((a,b)=>b-a).forEach(y => {
                    allText.push(lines[y].sort((a,b)=>a.x-b.x).map(s=>s.text).join('\t'));
                });
            }
            processRows(allText.map(l=>l.split('\t')), file.name);
        } catch(err) { alert('Error al leer PDF: '+err.message); clearFile(); }
    };
    reader.readAsArrayBuffer(file);
}

function parseCSV(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').filter(l=>l.trim());
        processRows(lines.map(l=>l.split(/[;\t]/)), file.name);
    };
    reader.readAsText(file);
}

function processRows(rows, fileName) {
    pendingMov = [];
    let headerIdx=-1, colMonto=-1, colDesc=-1, colFecha=-1;
    // Detectar banco
    const fn = fileName.toLowerCase();
    let banco = 'Banco';
    ['santander','bci','estado','bancoestado','scotiabank','chile','bch','itau','itaú','falabella','bice'].forEach(b => {
        if (fn.includes(b)) banco = b.charAt(0).toUpperCase()+b.slice(1);
    });
    if (fn.includes('estado') || fn.includes('bancoestado')) banco = 'Banco Estado';
    if (fn.includes('chile') || fn.includes('bch')) banco = 'Banco de Chile';

    // Buscar headers
    for (let i=0; i<Math.min(15,rows.length); i++) {
        const row = rows[i].map(c=>String(c).toLowerCase().trim());
        let found=0;
        row.forEach((c,j) => {
            if ((c.includes('monto')||c.includes('cargo')||c.includes('abono')||c.includes('importe'))&&!c.includes('saldo')) { colMonto=j; found++; }
            if (c.includes('descripci')||c.includes('glosa')||c.includes('detalle')||c.includes('concepto')) { colDesc=j; found++; }
            if ((c.includes('fecha')||c.match(/^dd[\/-]mm/))&&colFecha<0) { colFecha=j; found++; }
        });
        if (found>=2) { headerIdx=i; break; }
    }
    if (colMonto<0&&colDesc<0) { colMonto=0; colDesc=1; }

    rows.slice(Math.max(0,headerIdx+1)).forEach(row => {
        if (!row||row.length<2) return;
        const desc = String(row[colDesc]||'').trim();
        if (!desc||desc.length<2) return;
        if (desc.toLowerCase().includes('descripci')||desc.toLowerCase().includes('saldo')) return;
        if (desc.match(/^\d{1,2}[\/-]\d{1,2}[\/-]\d{4}$/)) return;

        let monto = parseFloat(String(row[colMonto]||'0').replace(/\./g,'').replace(',','.'));
        if (isNaN(monto)||monto===0) return;

        let fecha = '';
        if (colFecha>=0) fecha = parseDate(row[colFecha]);
        if (!fecha) {
            for (let j=0;j<Math.min(row.length,8);j++) {
                if (j===colDesc||j===colMonto) continue;
                const pd=parseDate(row[j]);
                if (pd&&pd.match(/^\d{4}-\d{2}-\d{2}$/)){fecha=pd;break;}
            }
        }
        const cat = categorize(desc, monto);
        pendingMov.push({ fecha, desc, monto, cuenta:banco, categoria:cat.categoria, subcategoria:cat.subcategoria, method:cat.method });
    });

    document.getElementById('processingMsg').style.display = 'none';
    if (pendingMov.length>0) renderPreview(banco);
    else { alert('No se encontraron movimientos válidos.'); clearFile(); }
}

function parseDate(val) {
    if (!val) return '';
    if (val instanceof Date) return val.getFullYear()+'-'+String(val.getMonth()+1).padStart(2,'0')+'-'+String(val.getDate()).padStart(2,'0');
    const s=String(val).trim();
    const iso=s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (iso) return iso[1]+'-'+iso[2].padStart(2,'0')+'-'+iso[3].padStart(2,'0');
    const dmy=s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/);
    if (dmy){const y=dmy[3].length===2?'20'+dmy[3]:dmy[3];return y+'-'+dmy[2].padStart(2,'0')+'-'+dmy[1].padStart(2,'0');}
    return '';
}

function categorize(desc, monto) {
    const dl=desc.toLowerCase();
    for (const r of catReglas) { if (r.tipo==='exact'&&dl===r.patron.toLowerCase()) return{categoria:r.categoria,subcategoria:r.subcategoria||'',method:'regla'}; }
    for (const r of catReglas) { if (r.tipo==='keyword'&&dl.includes(r.patron.toLowerCase())) return{categoria:r.categoria,subcategoria:r.subcategoria||'',method:'regla'}; }
    for (const h of catHistorial) { if (h.descripcion&&dl.includes(h.descripcion.toLowerCase().substring(0,20))) return{categoria:h.categoria,subcategoria:h.subcategoria||'',method:'historial'}; }
    return { categoria:monto>0?'Ingresos':'', subcategoria:'', method:monto>0?'auto':'pendiente' };
}

function renderPreview(banco) {
    document.getElementById('previewSection').style.display = 'block';
    const auto=pendingMov.filter(m=>m.method!=='pendiente').length;
    const pending=pendingMov.filter(m=>m.method==='pendiente').length;
    document.getElementById('previewStats').innerHTML =
        `<span>Banco: <strong>${escHtml(banco)}</strong></span>
         <span>Total: <strong>${pendingMov.length}</strong></span>
         <span style="color:var(--success)">Categorizados: <strong>${auto}</strong></span>
         <span style="color:var(--warning)">Pendientes: <strong>${pending}</strong></span>`;
    const tbody=document.getElementById('previewBody');
    tbody.innerHTML='';
    const catOpts=catCategorias.map(c=>`<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');
    pendingMov.forEach((m,i) => {
        const color=m.monto>=0?'var(--success)':'var(--danger)';
        const badge=m.method==='pendiente'?'<span class="badge status-warning" style="font-size:.65rem;">Revisar</span>':`<span class="badge status-success" style="font-size:.65rem;">${escHtml(m.method)}</span>`;
        const tr=document.createElement('tr');
        tr.innerHTML=`
            <td style="font-size:.82rem;white-space:nowrap">${escHtml(m.fecha)}</td>
            <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;" title="${escHtml(m.desc)}">${escHtml(m.desc)}</td>
            <td style="text-align:right;font-weight:600;color:${color};white-space:nowrap">${fmtMoney(m.monto)}</td>
            <td style="font-size:.82rem">${escHtml(m.cuenta)}</td>
            <td><select class="form-select" style="font-size:.78rem;padding:4px 8px;" onchange="pendingMov[${i}].categoria=this.value">
                <option value="">Sin categoría</option>${catOpts}</select></td>
            <td>${badge}</td>`;
        const sel=tr.querySelector('select');
        if (m.categoria) sel.value=m.categoria;
        tbody.appendChild(tr);
    });
}

async function confirmImport() {
    const btn=document.getElementById('btnConfirm');
    btn.disabled=true; btn.textContent='Importando...';
    let ok=0, err=0;
    for (const m of pendingMov) {
        const res=await API.post('create_finance',{
            tipo:m.monto>=0?'ingreso':'gasto',
            descripcion:m.desc,
            monto:Math.abs(Math.round(m.monto)),
            categoria:m.categoria||'Por categorizar',
            subcategoria:m.subcategoria||'',
            fecha:m.fecha||new Date().toISOString().split('T')[0],
            origen:'banco',
            notas:'Cartola '+m.cuenta
        });
        if(res) ok++; else err++;
    }
    btn.disabled=false; btn.textContent='Confirmar Importación';
    toast(`${ok} movimientos importados`+(err?`, ${err} errores`:''));
    if(ok>0){cancelImport();clearFile();}
}

function cancelImport(){pendingMov=[];document.getElementById('previewSection').style.display='none';}
function clearFile(){document.getElementById('fileInput').value='';document.getElementById('fileInfo').style.display='none';document.getElementById('processingMsg').style.display='none';}

// ---- Mercado Pago ----
const mpClientes = <?= json_encode($clientes_mp) ?>;
const mpSocios = <?= json_encode(query_all("SELECT id, nombre FROM equipo WHERE nombre IN ('Matías Hidalgo','Fabián Astorga') ORDER BY id") ?: []) ?>;
let mpPendingItems = [];
let mpCategorias = []; // se llena desde la API

async function previewMercadoPago() {
    const btn = document.getElementById('btnImportMP');
    const status = document.getElementById('mpStatus');
    btn.disabled = true;
    btn.textContent = 'Consultando...';
    status.style.display = 'block';
    status.innerHTML = '<span style="color:var(--text-muted)">Conectando con Mercado Pago...</span>';
    try {
        const body = new FormData();
        body.append('action', 'preview_mercadopago');
        body.append('csrf_token', APP.csrf);
        const raw = await fetch('api/data.php', { method: 'POST', body });
        const res = await raw.json();
        if (res && res.ok) {
            const movs = res.data.movements;
            mpCategorias = res.data.categorias || [];
            if (movs.length === 0) {
                status.innerHTML = '<span style="color:var(--success)">Todo sincronizado, no hay movimientos nuevos.</span>';
            } else {
                status.style.display = 'none';
                renderMPPreview(movs);
            }
        } else {
            status.innerHTML = `<span style="color:var(--danger)">${(res && res.error) || 'Error al consultar'}</span>`;
        }
    } catch (e) {
        status.innerHTML = `<span style="color:var(--danger)">Error: ${e.message}</span>`;
    }
    btn.disabled = false;
    btn.textContent = 'Importar desde Mercado Pago';
}

// Helpers para selectores EERR encadenados
function getSeccionesForTipo(tipo) {
    const secs = [...new Set(mpCategorias.filter(c => c.tipo === tipo).map(c => c.seccion))];
    return secs;
}
function getCategoriasForSeccion(seccion) {
    return [...new Set(mpCategorias.filter(c => c.seccion === seccion).map(c => c.categoria))];
}
function getSubcatsForCat(seccion, categoria) {
    return mpCategorias.filter(c => c.seccion === seccion && c.categoria === categoria).map(c => c.subcategoria);
}

function renderMPPreview(movs) {
    mpPendingItems = movs.map(m => ({
        ...m,
        action: m.match_existente ? 'conciliar' : (m.match_factura ? 'conciliar' : 'importar'),
        cliente_id: m.match_factura ? m.match_factura.cliente_id : '',
        match_id: m.match_existente ? m.match_existente.id : 0,
        match_factura_id: m.match_factura ? m.match_factura.factura_id : 0,
        desglose: null, // null = sin desglose, array = sub-items
    }));

    document.getElementById('mpPreview').style.display = 'block';

    const matched = mpPendingItems.filter(m => m.match_existente || m.match_factura).length;
    const autocat = mpPendingItems.filter(m => m.cat_method !== 'pendiente').length;
    document.getElementById('mpPreviewStats').innerHTML =
        `<span>Total: <strong>${movs.length}</strong></span>
         <span style="color:var(--success)">Categorizados: <strong>${autocat}</strong></span>
         <span style="color:var(--warning)">Pendientes: <strong>${movs.length - autocat}</strong></span>
         <span style="color:var(--accent)">Con match: <strong>${matched}</strong></span>`;

    rebuildMPTable();
}

function rebuildMPTable() {
    const tbody = document.getElementById('mpPreviewBody');
    tbody.innerHTML = '';
    const clienteOpts = mpClientes.map(c => `<option value="${c.id}">${escHtml(c.nombre)}</option>`).join('');

    mpPendingItems.forEach((m, i) => {
        const color = m.tipo === 'ingreso' ? 'var(--success)' : 'var(--danger)';
        const sign = m.tipo === 'ingreso' ? '+' : '-';
        const isDesglosado = m.desglose && m.desglose.length > 0;

        // Match info
        let matchHtml = '<span style="font-size:.75rem;color:var(--text-muted)">Sin match</span>';
        if (m.match_existente) {
            const ex = m.match_existente;
            matchHtml = `<div style="font-size:.75rem;background:var(--bg);padding:4px 8px;border-radius:6px;border:1px solid var(--accent);">
                <strong style="color:var(--accent)">Match</strong> #${ex.id}: ${escHtml(ex.descripcion.substring(0,25))}<br>
                ${ex.fecha} · ${escHtml(ex.categoria)}
            </div>`;
        } else if (m.match_factura) {
            const fac = m.match_factura;
            matchHtml = `<div style="font-size:.75rem;background:var(--bg);padding:4px 8px;border-radius:6px;border:1px solid var(--success);">
                <strong style="color:var(--success)">Factura ${escHtml(fac.numero)}</strong><br>
                ${escHtml(fac.cliente || '')} · $${Number(fac.total).toLocaleString('es-CL')}
            </div>`;
        }

        // Acción: solo visible sin desglose (con desglose cada sub-línea decide)
        let actionHtml = '';
        if (!isDesglosado) {
            actionHtml = `<select class="form-select" style="font-size:.75rem;padding:4px 6px;" onchange="mpPendingItems[${i}].action=this.value;rebuildMPTable()">`;
            actionHtml += `<option value="importar"${m.action==='importar'?' selected':''}>Importar</option>`;
            if (m.match_existente || m.match_factura) actionHtml += `<option value="conciliar"${m.action==='conciliar'?' selected':''}>Conciliar</option>`;
            if (m.tipo === 'ingreso') actionHtml += `<option value="abono_cc"${m.action==='abono_cc'?' selected':''}>Cta corriente ext.</option>`;
            actionHtml += `<option value="skip"${m.action==='skip'?' selected':''}>Omitir</option></select>`;
        }

        // Botón desglosar
        const desgloseBtn = isDesglosado
            ? `<button class="btn btn-secondary btn-sm" style="font-size:.65rem;margin-top:4px;" onclick="removeDesglose(${i})">Quitar desglose</button>`
            : `<button class="btn btn-secondary btn-sm" style="font-size:.65rem;margin-top:4px;" onclick="addDesglose(${i})">Desglosar</button>`;

        // Fila principal
        const isAbono = m.action === 'abono_cc';
        const tr = document.createElement('tr');
        tr.style.cssText = isDesglosado ? 'background:var(--bg);' : (isAbono ? 'background:rgba(56,189,248,.04);' : '');

        // Columna clasificación unificada
        let clasificacionHtml;
        if (isAbono && !isDesglosado) {
            if (!m.concepto_tipo) m.concepto_tipo = 'transferencia';
            clasificacionHtml = buildCceSelectors(i, null, m) + buildCceEntitySelector(i, null, m);
        } else if (isDesglosado) {
            clasificacionHtml = '<span style="font-size:.72rem;color:var(--text-muted)">Ver desglose abajo</span>';
        } else {
            clasificacionHtml = buildEerrSelectors(i, m);
            if (m.tipo === 'ingreso') {
                clasificacionHtml += `<select class="form-select" style="font-size:.7rem;padding:3px 5px;margin-top:3px;" onchange="mpPendingItems[${i}].cliente_id=this.value">
                    <option value="">Cliente (opcional)</option>${clienteOpts}</select>`;
            }
        }

        tr.innerHTML = `
            <td style="font-size:.8rem;white-space:nowrap">${escHtml(m.fecha)}</td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="${escHtml(m.descripcion)}">
                ${escHtml(m.descripcion)}
                <span class="badge ${m.cat_method==='pendiente'?'status-warning':'status-success'}" style="font-size:.6rem;margin-left:4px">${m.cat_method}</span>
                ${isDesglosado ? '<br><span class="badge" style="font-size:.6rem;background:var(--accent);color:#fff;">'+m.desglose.length+' items</span>' : ''}
            </td>
            <td style="text-align:right;font-weight:600;color:${color};white-space:nowrap">${sign}$${Number(m.monto).toLocaleString('es-CL')}</td>
            <td>${clasificacionHtml}</td>
            <td>${matchHtml}</td>
            <td>${actionHtml}<br>${desgloseBtn}</td>`;

        if (!isDesglosado && m.tipo === 'ingreso') {
            const selCli = tr.querySelector('select[onchange*="cliente_id"]');
            if (selCli && m.cliente_id) selCli.value = m.cliente_id;
        }
        tbody.appendChild(tr);

        // Filas de desglose
        if (isDesglosado) {
            m.desglose.forEach((d, j) => {
                if (!d.sub_action) d.sub_action = 'importar';
                if (!d.concepto_tipo) d.concepto_tipo = 'transferencia';
                const isSubAbono = d.sub_action === 'abono_cc';
                const subTr = document.createElement('tr');
                subTr.style.cssText = isSubAbono
                    ? 'background:rgba(56,189,248,.06);border-left:3px solid var(--accent);'
                    : 'background:rgba(249,115,22,.03);border-left:3px solid var(--accent);';
                subTr.innerHTML = `
                    <td>
                        <select class="form-select" style="font-size:.7rem;padding:3px 5px;${isSubAbono ? 'border-color:var(--accent);color:var(--accent);font-weight:600;' : ''}" onchange="mpPendingItems[${i}].desglose[${j}].sub_action=this.value;rebuildMPTable()">
                            <option value="importar"${d.sub_action==='importar'?' selected':''}>EERR</option>
                            ${m.tipo==='ingreso' ? `<option value="abono_cc"${d.sub_action==='abono_cc'?' selected':''}>Cta corriente ext.</option>` : ''}
                        </select>
                    </td>
                    <td style="font-size:.75rem;">
                        <input type="text" class="form-select" style="font-size:.72rem;padding:3px 6px;width:100%;" value="${escHtml(d.descripcion)}"
                            placeholder="Descripción" onchange="mpPendingItems[${i}].desglose[${j}].descripcion=this.value">
                    </td>
                    <td style="text-align:right;">
                        <input type="number" class="form-select" style="font-size:.72rem;padding:3px 6px;width:90px;text-align:right;" value="${d.monto}"
                            placeholder="Monto" onchange="updateDesgloseAmount(${i},${j},this.value)">
                    </td>
                    <td>${isSubAbono
                        ? buildCceSelectors(i, j, d) + buildCceEntitySelector(i, j, d)
                        : buildEerrSelectors(`${i}_${j}`, d, true) + (m.tipo === 'ingreso' ? `<select class="form-select" style="font-size:.7rem;padding:3px 5px;margin-top:3px;" onchange="mpPendingItems[${i}].desglose[${j}].cliente_id=this.value">
                            <option value="">Cliente (opcional)</option>${clienteOpts}</select>` : '')
                    }</td>
                    <td><button class="btn btn-secondary btn-sm" style="font-size:.6rem;padding:2px 6px;" onclick="removeDesgloseItem(${i},${j})">x</button></td>`;
                const selCli = subTr.querySelector('select[onchange*="cliente_id"]');
                if (selCli && d.cliente_id) selCli.value = d.cliente_id;
                tbody.appendChild(subTr);
            });

            // Fila para agregar + balance
            const addTr = document.createElement('tr');
            addTr.style.cssText = 'background:rgba(249,115,22,.03);border-left:3px solid var(--accent);';
            const desgloseTotal = m.desglose.reduce((s, d) => s + (parseInt(d.monto) || 0), 0);
            const diff = m.monto - desgloseTotal;
            const balColor = diff === 0 ? 'var(--success)' : 'var(--danger)';
            addTr.innerHTML = `
                <td></td>
                <td><span class="add-item-btn" onclick="addDesgloseItem(${i})">+ Agregar línea</span></td>
                <td style="text-align:right;font-size:.72rem;font-weight:600;color:${balColor}">
                    ${diff === 0 ? 'Cuadrado' : 'Diferencia: $' + Number(Math.abs(diff)).toLocaleString('es-CL')}
                </td>
                <td colspan="3" style="font-size:.7rem;color:var(--text-muted);">
                    Total desglose: $${Number(desgloseTotal).toLocaleString('es-CL')} de $${Number(m.monto).toLocaleString('es-CL')}
                </td>`;
            tbody.appendChild(addTr);
        }
    });
}

function addDesglose(i) {
    const m = mpPendingItems[i];
    m.desglose = [{
        descripcion: m.descripcion,
        monto: m.monto,
        seccion: m.seccion || '', categoria: m.categoria || '', subcategoria: m.subcategoria || '',
        cliente_id: m.cliente_id || '',
    }];
    m.action = 'importar';
    rebuildMPTable();
}

function removeDesglose(i) {
    mpPendingItems[i].desglose = null;
    rebuildMPTable();
}

function addDesgloseItem(i) {
    const m = mpPendingItems[i];
    const used = m.desglose.reduce((s, d) => s + (parseInt(d.monto) || 0), 0);
    const remaining = m.monto - used;
    m.desglose.push({
        descripcion: '', monto: Math.max(0, remaining),
        seccion: m.tipo === 'ingreso' ? 'Ingresos' : 'GAV',
        categoria: '', subcategoria: '', cliente_id: '',
    });
    rebuildMPTable();
}

function removeDesgloseItem(i, j) {
    mpPendingItems[i].desglose.splice(j, 1);
    if (mpPendingItems[i].desglose.length === 0) mpPendingItems[i].desglose = null;
    rebuildMPTable();
}

function updateDesgloseAmount(i, j, val) {
    mpPendingItems[i].desglose[j].monto = parseInt(val) || 0;
    rebuildMPTable();
}

// Override para selectores dentro de desglose (usa key compuesto "i_j")
function onSeccionChange(key, val) {
    mpPendingItems[key].seccion = val;
    mpPendingItems[key].categoria = '';
    mpPendingItems[key].subcategoria = '';
    rebuildMPTable();
}

function onCategoriaChange(key, val) {
    if (val === '__nueva__') {
        const nombre = prompt('Nombre de la nueva categoría:');
        if (!nombre) { rebuildMPTable(); return; }
        mpPendingItems[key].categoria = nombre;
        mpPendingItems[key].subcategoria = '';
        mpCategorias.push({ seccion: mpPendingItems[key].seccion, categoria: nombre, subcategoria: '', tipo: mpPendingItems[key].tipo, orden: 999 });
        rebuildMPTable();
    } else {
        mpPendingItems[key].categoria = val;
        mpPendingItems[key].subcategoria = '';
        rebuildMPTable();
    }
}

function onSeccionChangeSub(key, val) {
    const [i, j] = key.split('_').map(Number);
    mpPendingItems[i].desglose[j].seccion = val;
    mpPendingItems[i].desglose[j].categoria = '';
    mpPendingItems[i].desglose[j].subcategoria = '';
    rebuildMPTable();
}

function onCategoriaChangeSub(key, val) {
    const [i, j] = key.split('_').map(Number);
    if (val === '__nueva__') {
        const nombre = prompt('Nombre de la nueva categoría:');
        if (!nombre) { rebuildMPTable(); return; }
        mpPendingItems[i].desglose[j].categoria = nombre;
        mpPendingItems[i].desglose[j].subcategoria = '';
        const sec = mpPendingItems[i].desglose[j].seccion;
        mpCategorias.push({ seccion: sec, categoria: nombre, subcategoria: '', tipo: mpPendingItems[i].tipo, orden: 999 });
        rebuildMPTable();
    } else {
        mpPendingItems[i].desglose[j].categoria = val;
        mpPendingItems[i].desglose[j].subcategoria = '';
        rebuildMPTable();
    }
}

// ---- Cuenta Corriente Externa: selectores tipo + entidad ----
const cceTipos = [
    { value: 'transferencia', label: 'Transferencia entre bancos' },
    { value: 'cliente', label: 'Cliente' },
    { value: 'socio', label: 'Socio' },
    { value: 'proveedor', label: 'Proveedor' },
    { value: 'otro', label: 'Otro' },
];

function buildCceSelectors(i, j, d) {
    const isSub = j !== null;
    const tipoVal = d.concepto_tipo || 'cliente';
    const tipoOpts = cceTipos.map(t => `<option value="${t.value}"${t.value===tipoVal?' selected':''}>${t.label}</option>`).join('');
    const handler = isSub
        ? `mpPendingItems[${i}].desglose[${j}].concepto_tipo=this.value;rebuildMPTable()`
        : `mpPendingItems[${i}].concepto_tipo=this.value;rebuildMPTable()`;

    return `<div style="display:flex;flex-direction:column;gap:3px;">
        <span style="font-size:.65rem;color:var(--accent);font-weight:600;">Cta corriente externa</span>
        <select class="form-select" style="font-size:.7rem;padding:3px 5px;" onchange="${handler}">
            ${tipoOpts}</select>
    </div>`;
}

function buildCceEntitySelector(i, j, d) {
    const isSub = j !== null;
    const tipoVal = d.concepto_tipo || 'cliente';
    const entidadVal = d.concepto_entidad || d.cliente_id || '';

    const handler = isSub
        ? `mpPendingItems[${i}].desglose[${j}].concepto_entidad=this.value`
        : `mpPendingItems[${i}].concepto_entidad=this.value`;
    const handlerCli = isSub
        ? `mpPendingItems[${i}].desglose[${j}].cliente_id=this.value;mpPendingItems[${i}].desglose[${j}].concepto_entidad=this.value`
        : `mpPendingItems[${i}].cliente_id=this.value;mpPendingItems[${i}].concepto_entidad=this.value`;

    if (tipoVal === 'transferencia') {
        return `<span style="font-size:.68rem;color:var(--text-muted);display:block;margin-top:3px;">Movimiento entre cuentas propias</span>`;
    }
    if (tipoVal === 'cliente') {
        const opts = mpClientes.map(c => `<option value="${c.id}"${String(c.id)===String(entidadVal)?' selected':''}>${escHtml(c.nombre)}</option>`).join('');
        return `<select class="form-select" style="font-size:.7rem;padding:3px 5px;margin-top:3px;" onchange="${handlerCli}">
            <option value="">* Seleccionar cliente</option>${opts}</select>`;
    }
    if (tipoVal === 'socio') {
        const opts = mpSocios.map(s => `<option value="socio_${s.id}"${String('socio_'+s.id)===String(entidadVal)?' selected':''}>${escHtml(s.nombre)}</option>`).join('');
        return `<select class="form-select" style="font-size:.7rem;padding:3px 5px;margin-top:3px;" onchange="${handler}">
            <option value="">* Seleccionar socio</option>${opts}</select>`;
    }
    // proveedor u otro: input libre
    return `<input type="text" class="form-select" style="font-size:.7rem;padding:3px 5px;width:100%;margin-top:3px;"
        value="${escHtml(entidadVal)}" placeholder="Nombre..." onchange="${handler}">`;
}

function buildEerrSelectors(key, m, isSub) {
    const tipo = isSub ? (mpPendingItems[String(key).split('_')[0]]?.tipo || 'gasto') : m.tipo;
    const secciones = getSeccionesForTipo(tipo);
    const secOpts = secciones.map(s => `<option value="${escHtml(s)}"${s===m.seccion?' selected':''}>${escHtml(s)}</option>`).join('');
    const cats = m.seccion ? getCategoriasForSeccion(m.seccion) : [];
    const catOpts = cats.map(c => `<option value="${escHtml(c)}"${c===m.categoria?' selected':''}>${escHtml(c)}</option>`).join('');
    const subs = (m.seccion && m.categoria) ? getSubcatsForCat(m.seccion, m.categoria) : [];
    const isCustomCat = m.categoria && !cats.includes(m.categoria);
    const isCustomSub = m.subcategoria && !subs.includes(m.subcategoria);
    const subOpts = subs.map(s => `<option value="${escHtml(s)}"${s===m.subcategoria?' selected':''}>${escHtml(s)}</option>`).join('');

    const secHandler = isSub ? `onSeccionChangeSub('${key}',this.value)` : `onSeccionChange(${key},this.value)`;
    const catHandler = isSub ? `onCategoriaChangeSub('${key}',this.value)` : `onCategoriaChange(${key},this.value)`;
    const subHandler = isSub
        ? `onSubcategoriaChange('${key}',this.value,true)`
        : `onSubcategoriaChange(${key},this.value,false)`;

    // Si tiene valor custom, mostrar input en vez de select
    const clearCatHandler = isSub
        ? `mpPendingItems[${String(key).split('_')[0]}].desglose[${String(key).split('_')[1]}].categoria='';mpPendingItems[${String(key).split('_')[0]}].desglose[${String(key).split('_')[1]}].subcategoria='';rebuildMPTable()`
        : `mpPendingItems[${key}].categoria='';mpPendingItems[${key}].subcategoria='';rebuildMPTable()`;
    const clearSubHandler = isSub
        ? `mpPendingItems[${String(key).split('_')[0]}].desglose[${String(key).split('_')[1]}].subcategoria='';rebuildMPTable()`
        : `mpPendingItems[${key}].subcategoria='';rebuildMPTable()`;

    const catHtml = isCustomCat
        ? `<div style="display:flex;gap:2px;"><input type="text" class="form-select" style="font-size:.7rem;padding:3px 5px;flex:1;" value="${escHtml(m.categoria)}" data-role="categoria" data-idx="${key}"
            onchange="${isSub ? `mpPendingItems[${String(key).split('_')[0]}].desglose[${String(key).split('_')[1]}].categoria=this.value` : `mpPendingItems[${key}].categoria=this.value`}">
            <button type="button" style="font-size:.6rem;padding:0 4px;border:1px solid var(--border);border-radius:3px;background:var(--bg);cursor:pointer;color:var(--danger);" onclick="${clearCatHandler}" title="Quitar">x</button></div>`
        : `<select class="form-select" style="font-size:.7rem;padding:3px 5px;" data-role="categoria" data-idx="${key}" onchange="${catHandler}">
            <option value="">Categoría</option>${catOpts}<option value="__nueva__">+ Crear nueva</option></select>`;

    const subHtml = isCustomSub
        ? `<div style="display:flex;gap:2px;"><input type="text" class="form-select" style="font-size:.7rem;padding:3px 5px;flex:1;" value="${escHtml(m.subcategoria)}" data-role="subcategoria" data-idx="${key}"
            onchange="${isSub ? `mpPendingItems[${String(key).split('_')[0]}].desglose[${String(key).split('_')[1]}].subcategoria=this.value` : `mpPendingItems[${key}].subcategoria=this.value`}">
            <button type="button" style="font-size:.6rem;padding:0 4px;border:1px solid var(--border);border-radius:3px;background:var(--bg);cursor:pointer;color:var(--danger);" onclick="${clearSubHandler}" title="Quitar">x</button></div>`
        : `<select class="form-select" style="font-size:.7rem;padding:3px 5px;" data-role="subcategoria" data-idx="${key}" onchange="${subHandler}">
            <option value="">Subcategoría</option>${subOpts}${subs.length > 0 ? '<option value="__nueva__">+ Crear nueva</option>' : ''}</select>`;

    return `<div style="display:flex;flex-direction:column;gap:3px;">
        <select class="form-select" style="font-size:.7rem;padding:3px 5px;" data-role="seccion" data-idx="${key}" onchange="${secHandler}">
            <option value="">Sección</option>${secOpts}</select>
        ${catHtml}
        ${subHtml}
    </div>`;
}

function onSubcategoriaChange(key, val, isSub) {
    if (val === '__nueva__') {
        const nombre = prompt('Nombre de la nueva subcategoría:');
        if (!nombre) { rebuildMPTable(); return; }
        if (isSub) {
            const [i, j] = String(key).split('_').map(Number);
            mpPendingItems[i].desglose[j].subcategoria = nombre;
        } else {
            mpPendingItems[key].subcategoria = nombre;
        }
        // Agregar a mpCategorias para que aparezca en futuros selects
        const item = isSub ? mpPendingItems[String(key).split('_')[0]].desglose[String(key).split('_')[1]] : mpPendingItems[key];
        if (item.seccion && item.categoria) {
            mpCategorias.push({ seccion: item.seccion, categoria: item.categoria, subcategoria: nombre, tipo: item.tipo || 'gasto', orden: 999 });
        }
        rebuildMPTable();
    } else {
        if (isSub) {
            const [i, j] = String(key).split('_').map(Number);
            mpPendingItems[i].desglose[j].subcategoria = val;
        } else {
            mpPendingItems[key].subcategoria = val;
        }
    }
}

async function confirmMPImport() {
    const btn = document.getElementById('btnConfirmMP');
    btn.disabled = true;
    btn.textContent = 'Procesando...';

    // Validaciones
    for (const m of mpPendingItems) {
        if (m.action === 'abono_cc' && !m.desglose) {
            const ct = m.concepto_tipo || 'transferencia';
            if (ct === 'cliente' && !m.cliente_id) {
                toast(`"${m.descripcion}": Selecciona un cliente`, 'error');
                btn.disabled = false; btn.textContent = 'Confirmar Importación'; return;
            }
            if (ct === 'socio' && !m.concepto_entidad) {
                toast(`"${m.descripcion}": Selecciona un socio`, 'error');
                btn.disabled = false; btn.textContent = 'Confirmar Importación'; return;
            }
        }
        if (m.desglose && m.action !== 'skip') {
            const total = m.desglose.reduce((s, d) => s + (parseInt(d.monto) || 0), 0);
            if (total !== m.monto) {
                toast(`El desglose de "${m.descripcion}" no cuadra: $${Number(total).toLocaleString('es-CL')} vs $${Number(m.monto).toLocaleString('es-CL')}`, 'error');
                btn.disabled = false;
                btn.textContent = 'Confirmar Importación';
                return;
            }
        }
    }

    const items = mpPendingItems.map(m => {
        const base = {
            mp_id: m.mp_id, action: m.action, tipo: m.tipo,
            descripcion: m.descripcion, monto: m.monto, fecha: m.fecha,
            metodo: m.metodo, op_type: m.op_type,
            seccion: m.seccion || '', categoria: m.categoria || '', subcategoria: m.subcategoria || '',
            cliente_id: m.cliente_id || '',
            concepto_tipo: m.concepto_tipo || 'cliente', concepto_entidad: m.concepto_entidad || '',
            match_id: m.match_id || 0, match_factura_id: m.match_factura_id || 0,
        };
        if (m.desglose && m.desglose.length > 0) {
            base.desglose = m.desglose.map(d => ({
                sub_action: d.sub_action || 'importar',
                descripcion: d.descripcion, monto: parseInt(d.monto) || 0,
                seccion: d.seccion || '', categoria: d.categoria || '', subcategoria: d.subcategoria || '',
                cliente_id: d.cliente_id || '',
                concepto_tipo: d.concepto_tipo || 'cliente',
                concepto_entidad: d.concepto_entidad || '',
            }));
        }
        return base;
    });

    try {
        const body = new FormData();
        body.append('action', 'confirm_mp_import');
        body.append('csrf_token', APP.csrf);
        body.append('items', JSON.stringify(items));
        const raw = await fetch('api/data.php', { method: 'POST', body });
        const res = await raw.json();
        if (res && res.ok) {
            const d = res.data;
            toast(`${d.imported} importados, ${d.reconciled} conciliados, ${d.skipped} omitidos`);
            setTimeout(() => location.reload(), 1500);
        } else {
            toast((res && res.error) || 'Error al confirmar', 'error');
        }
    } catch (e) {
        toast('Error: ' + e.message, 'error');
    }
    btn.disabled = false;
    btn.textContent = 'Confirmar Importación';
}

function cancelMPPreview() {
    mpPendingItems = [];
    document.getElementById('mpPreview').style.display = 'none';
}
</script>
