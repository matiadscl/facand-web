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
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

<!-- Importar desde Mercado Pago -->
<div class="table-container" style="margin-bottom:20px;">
    <div class="table-header">
        <span class="table-title">Mercado Pago</span>
        <div class="table-actions">
            <button class="btn btn-primary btn-sm" id="btnImportMP" onclick="importMercadoPago()">
                Importar desde Mercado Pago
            </button>
        </div>
    </div>
    <div id="mpStatus" style="display:none;padding:16px;text-align:center;"></div>
    <div id="mpLastSync" style="padding:8px 16px;font-size:.78rem;color:var(--text-muted);">
        <?php
        $ultima_sync_mp = query_scalar("SELECT MAX(created_at) FROM finanzas WHERE origen = 'mercadopago'");
        $total_mp = query_scalar("SELECT COUNT(*) FROM finanzas WHERE origen = 'mercadopago'") ?? 0;
        echo $ultima_sync_mp
            ? "Última sincronización: " . date('d/m/Y H:i', strtotime($ultima_sync_mp)) . " — $total_mp movimientos importados"
            : "Sin importaciones previas";
        ?>
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
async function importMercadoPago() {
    const btn = document.getElementById('btnImportMP');
    const status = document.getElementById('mpStatus');
    btn.disabled = true;
    btn.textContent = 'Importando...';
    status.style.display = 'block';
    status.innerHTML = '<span style="color:var(--text-muted)">Conectando con Mercado Pago...</span>';
    try {
        const res = await API.post('import_mercadopago', {});
        if (res && res.ok) {
            const d = res.data;
            status.innerHTML = `<span style="color:var(--success);font-weight:600">${d.imported} movimientos importados</span>` +
                (d.skipped > 0 ? `<span style="color:var(--text-muted);margin-left:12px">(${d.skipped} omitidos por duplicado o rechazados)</span>` : '');
            if (d.imported > 0) setTimeout(() => location.reload(), 1500);
        } else {
            status.innerHTML = `<span style="color:var(--danger)">Error al importar. Revisa la consola del navegador para más detalles.</span>`;
        }
    } catch (e) {
        status.innerHTML = `<span style="color:var(--danger)">Error de conexión: ${e.message}</span>`;
    }
    btn.disabled = false;
    btn.textContent = 'Importar desde Mercado Pago';
}
</script>
