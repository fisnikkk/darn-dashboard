<?php
/**
 * DARN Dashboard - Excel Import
 * Upload an Excel (.xlsx / .xlsm) file and import data into the database.
 * Uses SheetJS (client-side) for parsing — much more reliable than server-side PHP.
 * Supports Replace (clear + re-insert) and Append (add new rows only) modes.
 * Auto-creates a snapshot before any Replace operation for safety.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Table display names
$tableLabels = [
    'distribuimi' => 'Distribuimi',
    'shpenzimet' => 'Shpenzimet',
    'plini_depo' => 'Plini Depo',
    'shitje_produkteve' => 'Shitje Produkteve',
    'kontrata' => 'Kontrata',
    'gjendja_bankare' => 'Gjendja Bankare',
    'notes' => 'Notes',
    'klientet' => 'Klientet',
    'nxemese' => 'Nxemëse',
    'stoku_zyrtar' => 'Stoku Zyrtar',
    'depo' => 'Depo',
];

// Row counts for each importable table
$tableCounts = [];
foreach ($tableLabels as $t => $label) {
    try {
        $tableCounts[$t] = (int)$db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    } catch (PDOException $e) {
        $tableCounts[$t] = 0;
    }
}

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Tabela të importueshme</div>
        <div class="value"><?= count($tableLabels) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Formati</div>
        <div class="value">.xlsx / .xlsm</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-excel"></i> Import nga Excel</h3>
    </div>
    <div class="card-body">
        <!-- Step 1: Upload -->
        <div id="uploadSection">
            <div style="text-align:center;padding:40px 20px;border:2px dashed var(--border);border-radius:8px;margin-bottom:20px;background:var(--bg-secondary);cursor:pointer;" id="dropZone" onclick="document.getElementById('excelFile').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size:3rem;color:var(--primary);margin-bottom:12px;display:block;"></i>
                <p style="font-size:1.1rem;font-weight:600;margin-bottom:8px;">Kliko ose tërhiq skedarin Excel këtu</p>
                <p style="font-size:0.85rem;color:var(--text-muted);">Pranon .xlsx dhe .xlsm (max 50 MB)</p>
                <input type="file" id="excelFile" accept=".xlsx,.xlsm" style="display:none;">
            </div>
            <div id="uploadProgress" style="display:none;">
                <div style="background:var(--border);border-radius:4px;overflow:hidden;height:8px;margin-bottom:8px;">
                    <div id="progressBar" style="height:100%;background:var(--primary);width:0%;transition:width 0.3s;"></div>
                </div>
                <p id="progressText" style="font-size:0.85rem;color:var(--text-muted);text-align:center;">Duke lexuar skedarin...</p>
            </div>
        </div>

        <!-- Step 2: Preview -->
        <div id="previewSection" style="display:none;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h4 style="margin:0;"><i class="fas fa-check-circle" style="color:var(--success);"></i> <span id="fileName"></span></h4>
                    <small id="fileInfo" style="color:var(--text-muted);"></small>
                </div>
                <button class="btn btn-outline btn-sm" onclick="resetUpload()"><i class="fas fa-undo"></i> Ndrysho skedarin</button>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table" id="mappingTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll" checked></th>
                            <th>Sheet në Excel</th>
                            <th>Tabela në DB</th>
                            <th>Rreshta në Excel</th>
                            <th>Rreshta aktuale në DB</th>
                            <th>Mënyra</th>
                            <th>Statusi</th>
                        </tr>
                    </thead>
                    <tbody id="mappingBody"></tbody>
                </table>
            </div>
            <div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button class="btn btn-primary" id="importBtn" onclick="startImport()">
                    <i class="fas fa-download"></i> Importo tabelat e zgjedhura
                </button>
                <label style="font-size:0.85rem;display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="autoSnapshot" checked>
                    Krijo snapshot para importit (e rekomanduar)
                </label>
            </div>
        </div>

        <!-- Step 3: Import Progress -->
        <div id="importSection" style="display:none;">
            <h4><i class="fas fa-cog fa-spin"></i> Duke importuar...</h4>
            <div id="importLog" style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:12px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:0.82rem;line-height:1.6;"></div>
        </div>
    </div>
</div>

<!-- Current DB status -->
<div class="card" style="margin-top:16px;">
    <div class="card-header">
        <h3><i class="fas fa-database"></i> Gjendja aktuale e bazës</h3>
    </div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Tabela</th><th style="text-align:right;">Rreshta</th></tr></thead>
                <tbody>
                    <?php foreach ($tableCounts as $t => $count): ?>
                    <tr>
                        <td><i class="fas fa-table" style="color:var(--primary);margin-right:6px;"></i> <?= $tableLabels[$t] ?></td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($count) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SheetJS library (CDN) -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>

<script>
const TABLE_LABELS = <?= json_encode($tableLabels, JSON_UNESCAPED_UNICODE) ?>;
const TABLE_COUNTS = <?= json_encode($tableCounts) ?>;

// Sheet→table mapping with column positions (0-based col indices → DB field names)
// headerRow is 1-based (row number in Excel where headers are)
const SHEET_CONFIG = {
    'Distribuimi': {
        table: 'distribuimi', headerRow: 5,
        columns: { 2:'klienti', 3:'data', 4:'sasia', 5:'boca_te_kthyera', 8:'litra', 9:'cmimi', 10:'pagesa', 11:'menyra_e_pageses', 13:'data_e_fletepageses', 14:'koment' },
        dateFields: ['data','data_e_fletepageses'],
        numFields: ['sasia','boca_te_kthyera','litra','cmimi','pagesa']
    },
    'Shpenzimet': {
        table: 'shpenzimet', headerRow: 2,
        columns: { 2:'data_e_pageses', 3:'shuma', 4:'arsyetimi', 5:'lloji_i_pageses', 6:'lloji_i_transaksionit', 7:'pershkrim_i_detajuar', 8:'nafta_ne_litra', 9:'numri_i_fatures', 10:'data_e_fatures', 11:'shuma_fatures', 12:'lloji_fatures' },
        dateFields: ['data_e_pageses','data_e_fatures'],
        numFields: ['shuma','nafta_ne_litra','shuma_fatures']
    },
    'Plini depo': {
        table: 'plini_depo', headerRow: 2,
        columns: { 0:'nr_i_fatures', 1:'data', 2:'kg', 3:'sasia_ne_litra', 4:'cmimi', 5:'faturat_e_pranuara', 6:'dalje_pagesat_sipas_bankes', 7:'menyra_e_pageses', 8:'cash_banke', 9:'furnitori', 10:'koment' },
        dateFields: ['data'],
        numFields: ['kg','sasia_ne_litra','cmimi','faturat_e_pranuara','dalje_pagesat_sipas_bankes']
    },
    'Shitje produkteve prej 9 mar': {
        table: 'shitje_produkteve', headerRow: 1,
        columns: { 0:'data', 1:'cilindra_sasia', 2:'produkti', 3:'klienti', 4:'adresa', 5:'qyteti', 6:'cmimi', 7:'totali', 8:'menyra_pageses', 9:'koment', 10:'statusi_i_pageses' },
        dateFields: ['data'],
        numFields: ['cilindra_sasia','cmimi','totali']
    },
    'Kontrata': {
        table: 'kontrata', headerRow: 1,
        columns: { 0:'nr_i_kontrates', 1:'data', 2:'biznesi', 3:'name_from_database', 4:'numri_ne_stok_sipas_kontrates', 7:'sipas_skenimit_pda', 8:'bashkepunim', 9:'qyteti', 10:'rruga', 11:'numri_unik', 12:'perfaqesuesi', 13:'nr_telefonit', 14:'koment', 15:'email', 16:'ne_grup_njoftues', 17:'kontrate_e_vjeter', 18:'lloji_i_bocave', 20:'bocat_e_paguara', 22:'data_rregullatoret' },
        dateFields: ['data','data_rregullatoret'],
        numFields: ['nr_i_kontrates','numri_ne_stok_sipas_kontrates']
    },
    'Gjendja bankare': {
        table: 'gjendja_bankare', headerRow: 12,
        columns: { 0:'data', 1:'data_valutes', 2:'ora', 3:'shpjegim', 4:'valuta', 5:'debia', 6:'kredi', 7:'bilanci', 8:'deftesa', 9:'lloji' },
        dateFields: ['data','data_valutes'],
        numFields: ['debia','kredi','bilanci']
    },
    'NOTES': {
        table: 'notes', headerRow: 1,
        columns: { 0:'data', 1:'teksti', 2:'barazu_nga' },
        dateFields: ['data'],
        numFields: []
    },
    'Klientet': {
        table: 'klientet', headerRow: 1,
        columns: { 0:'emri', 1:'bashkepunim', 2:'data_e_kontrates', 3:'stoku', 4:'koment', 5:'kontakti', 7:'numri_unik_identifikues', 8:'adresa', 9:'telefoni', 10:'telefoni_2' },
        dateFields: ['data_e_kontrates'],
        numFields: ['stoku']
    },
    'Nxemese1': {
        table: 'nxemese', headerRow: 5,
        columns: { 2:'klienti', 3:'data', 4:'te_dhena', 5:'te_marra', 8:'lloji_i_nxemjes', 9:'koment' },
        dateFields: ['data'],
        numFields: ['te_dhena','te_marra']
    },
    'Stoku zyrtar': {
        table: 'stoku_zyrtar', headerRow: 3,
        columns: { 0:'kodi', 1:'kodi_2', 2:'pershkrimi', 3:'njesi', 4:'sasia', 5:'cmimi', 6:'vlera' },
        dateFields: [],
        numFields: ['sasia','cmimi','vlera']
    },
    'Depo': {
        table: 'depo', headerRow: 1,
        columns: { 1:'data', 2:'produkti', 3:'sasia', 4:'cmimi' },
        dateFields: ['data'],
        numFields: ['sasia','cmimi']
    }
};

let parsedData = {}; // sheetName → { table, rows: [{field:val,...},...] }

// Drag & drop
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--primary)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--border)'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--border)';
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
});
document.getElementById('excelFile').addEventListener('change', function() {
    if (this.files[0]) handleFile(this.files[0]);
});

// Excel date serial → YYYY-MM-DD
function excelDateToYmd(v) {
    if (v == null || v === '' || v === 0) return null;
    if (typeof v === 'string') {
        // Already YYYY-MM-DD
        if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.substring(0, 10);
        // DD/MM/YYYY
        if (/^\d{1,2}\/\d{1,2}\/\d{4}/.test(v)) {
            const p = v.split('/');
            return p[2] + '-' + p[1].padStart(2,'0') + '-' + p[0].padStart(2,'0');
        }
        // DD.MM.YYYY
        if (/^\d{1,2}\.\d{1,2}\.\d{4}/.test(v)) {
            const p = v.split('.');
            return p[2] + '-' + p[1].padStart(2,'0') + '-' + p[0].padStart(2,'0');
        }
        // Unrecognized string → null (not a valid date)
        return null;
    }
    if (typeof v === 'number' && v > 1) {
        // Excel serial date
        const d = new Date((v - 25569) * 86400000);
        if (!isNaN(d.getTime())) {
            const year = d.getUTCFullYear();
            if (year >= 1900 && year <= 2100) {
                return d.toISOString().substring(0, 10);
            }
        }
    }
    return null; // unrecognized → null
}

function cleanNum(v) {
    if (v == null || v === '') return null;
    if (typeof v === 'number') return v;
    const n = parseFloat(String(v).replace(/[^\d.\-]/g, ''));
    return isNaN(n) ? null : n;
}

function cleanStr(v) {
    if (v == null) return null;
    const s = String(v).trim();
    return s === '' ? null : s;
}

function handleFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext !== 'xlsx' && ext !== 'xlsm') {
        showToast('Vetëm .xlsx ose .xlsm', 'error');
        return;
    }

    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('progressBar').style.width = '20%';
    document.getElementById('progressText').textContent = 'Duke lexuar skedarin në browser...';

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('progressBar').style.width = '50%';
        document.getElementById('progressText').textContent = 'Duke analizuar sheet-at...';

        setTimeout(() => {
            try {
                const data = new Uint8Array(e.target.result);
                const wb = XLSX.read(data, { type: 'array', cellDates: false, cellText: false });

                // Build trimmed name lookup
                const sheetLookup = {};
                wb.SheetNames.forEach(name => {
                    const trimmed = name.trim();
                    if (SHEET_CONFIG[trimmed]) sheetLookup[trimmed] = name;
                    else if (SHEET_CONFIG[name]) sheetLookup[name] = name;
                });

                parsedData = {};
                let totalRows = 0;

                for (const [configKey, realName] of Object.entries(sheetLookup)) {
                    const config = SHEET_CONFIG[configKey];
                    const ws = wb.Sheets[realName];
                    const allRows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: null, raw: true });

                    const dataRows = [];
                    for (let i = config.headerRow; i < allRows.length; i++) {
                        const excelRow = allRows[i];
                        if (!excelRow) continue;

                        const record = {};
                        let hasData = false;

                        for (const [colIdx, field] of Object.entries(config.columns)) {
                            let val = excelRow[parseInt(colIdx)];

                            if (config.dateFields.includes(field)) {
                                val = excelDateToYmd(val);
                            } else if (config.numFields.includes(field)) {
                                val = cleanNum(val);
                            } else {
                                val = cleanStr(val);
                            }

                            record[field] = val;
                            if (val !== null && val !== '') hasData = true;
                        }

                        if (hasData) dataRows.push(record);
                    }

                    parsedData[configKey] = { table: config.table, rows: dataRows };
                    totalRows += dataRows.length;
                }

                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressText').textContent = 'U analizua me sukses!';
                setTimeout(() => showPreview(file, totalRows), 300);
            } catch (err) {
                document.getElementById('progressText').textContent = 'Gabim: ' + err.message;
                showToast('Gabim duke lexuar Excel: ' + err.message, 'error');
            }
        }, 50);
    };
    reader.readAsArrayBuffer(file);
}

function showPreview(file, totalRows) {
    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileInfo').textContent =
        Object.keys(parsedData).length + ' sheet-a të njohura, ' + totalRows.toLocaleString() + ' rreshta gjithsej';

    const tbody = document.getElementById('mappingBody');
    tbody.innerHTML = '';

    for (const [sheetName, info] of Object.entries(parsedData)) {
        const table = info.table;
        const dbCount = TABLE_COUNTS[table] || 0;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="sheet-check" data-sheet="${sheetName}" data-table="${table}" checked></td>
            <td><i class="fas fa-file-excel" style="color:#217346;margin-right:6px;"></i>${sheetName}</td>
            <td><code>${table}</code></td>
            <td style="text-align:right;font-weight:600;">${info.rows.length.toLocaleString()}</td>
            <td style="text-align:right;">${dbCount.toLocaleString()}</td>
            <td>
                <select class="import-mode" data-table="${table}" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                    <option value="replace">Zëvendëso (Replace)</option>
                    <option value="append">Shto (Append)</option>
                </select>
            </td>
            <td><span style="color:var(--text-muted);font-size:0.82rem;"><i class="fas fa-clock"></i> Gati</span></td>
        `;
        tbody.appendChild(tr);
    }

    document.getElementById('selectAll').onclick = function() {
        document.querySelectorAll('.sheet-check').forEach(cb => cb.checked = this.checked);
    };
}

function resetUpload() {
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('importSection').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('excelFile').value = '';
    parsedData = {};
}

function addLog(msg, type) {
    const log = document.getElementById('importLog');
    const color = type === 'error' ? 'var(--danger)' : type === 'success' ? 'var(--success)' : 'inherit';
    const icon = type === 'error' ? '✗' : type === 'success' ? '✓' : '→';
    log.innerHTML += `<div style="color:${color};"><span>${icon}</span> ${msg}</div>`;
    log.scrollTop = log.scrollHeight;
}

async function startImport() {
    const selected = [];
    document.querySelectorAll('.sheet-check:checked').forEach(cb => {
        selected.push({
            sheet: cb.dataset.sheet,
            table: cb.dataset.table,
            mode: document.querySelector(`.import-mode[data-table="${cb.dataset.table}"]`).value
        });
    });
    if (!selected.length) { showToast('Zgjidhni të paktën një tabelë', 'error'); return; }

    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('importSection').style.display = 'block';
    document.getElementById('importLog').innerHTML = '';

    const doSnapshot = document.getElementById('autoSnapshot').checked;
    const hasReplace = selected.some(s => s.mode === 'replace');

    // Auto-snapshot
    if (doSnapshot && hasReplace) {
        addLog('Duke krijuar snapshot automatik para importit...', 'info');
        try {
            const r = await fetch('/api/snapshot.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'create', name: 'para-import-' + new Date().toISOString().slice(0,19).replace(/[T:]/g, '-') })
            });
            const d = await r.json();
            addLog(d.success ? 'Snapshot u krijua: ' + d.message : 'Snapshot dështoi: ' + d.error, d.success ? 'success' : 'error');
            if (!d.success && !confirm('Snapshot dështoi. Vazhdoni pa snapshot?')) { resetUpload(); return; }
        } catch (e) { addLog('Gabim snapshot: ' + e.message, 'error'); }
    }

    let successCount = 0, errorCount = 0;

    for (const item of selected) {
        const sheetData = parsedData[item.sheet];
        if (!sheetData || !sheetData.rows.length) {
            addLog(`${item.table}: Asnjë rresht`, 'error');
            errorCount++;
            continue;
        }

        addLog(`Duke importuar ${item.sheet} → ${item.table} (${item.mode}), ${sheetData.rows.length} rreshta...`, 'info');

        const statusCell = document.querySelector(`tr:has(.sheet-check[data-table="${item.table}"]) td:last-child`);
        if (statusCell) statusCell.innerHTML = '<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> Duke importuar...</span>';

        try {
            // Send rows in chunks — smaller for large tables to avoid timeouts
            const CHUNK = sheetData.rows.length > 10000 ? 500 : 2000;
            let totalImported = 0, totalDeleted = 0, totalErrors = [];
            const chunks = Math.ceil(sheetData.rows.length / CHUNK);

            for (let c = 0; c < chunks; c++) {
                const chunkRows = sheetData.rows.slice(c * CHUNK, (c + 1) * CHUNK);
                const isFirst = c === 0;

                let resp;
                let retries = 2;
                while (retries >= 0) {
                    try {
                        resp = await fetch('/api/excel_import.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: 'import_rows',
                                table: item.table,
                                mode: isFirst ? item.mode : 'append',
                                rows: chunkRows
                            })
                        });
                        break; // success
                    } catch (fetchErr) {
                        if (retries > 0) {
                            addLog(`    Chunk ${c+1}/${chunks} dështoi, duke provuar përsëri...`, 'error');
                            await new Promise(r => setTimeout(r, 2000)); // wait 2s before retry
                            retries--;
                        } else {
                            throw fetchErr;
                        }
                    }
                }
                const data = await resp.json();
                if (!data.success) throw new Error(data.error);
                totalImported += data.imported;
                if (data.deleted) totalDeleted += data.deleted;
                if (data.errors) totalErrors = totalErrors.concat(data.errors);

                if (chunks > 1) {
                    const pct = Math.round(((c + 1) / chunks) * 100);
                    if (statusCell) statusCell.innerHTML = `<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> ${pct}%</span>`;
                }
            }

            addLog(`${item.table}: ${totalImported} rreshta u importuan${totalDeleted ? ' (' + totalDeleted + ' u fshinë)' : ''}`, 'success');
            if (totalErrors.length > 0) {
                addLog(`  ⚠ ${totalErrors.length} rreshta me gabime:`, 'error');
                totalErrors.slice(0, 5).forEach(err => addLog(`    ${err}`, 'error'));
                if (totalErrors.length > 5) addLog(`    ... dhe ${totalErrors.length - 5} gabime të tjera`, 'error');
            }
            if (statusCell) statusCell.innerHTML = '<span style="color:var(--success);"><i class="fas fa-check-circle"></i> ' + totalImported + ' rreshta</span>';
            successCount++;
        } catch (e) {
            addLog(`${item.table}: Gabim - ${e.message}`, 'error');
            if (statusCell) statusCell.innerHTML = '<span style="color:var(--danger);"><i class="fas fa-times-circle"></i> Gabim</span>';
            errorCount++;
        }
    }

    addLog('', 'info');
    addLog(errorCount === 0
        ? `Import u përfundua me sukses! ${successCount} tabela u importuan.`
        : `Import përfundoi: ${successCount} sukses, ${errorCount} gabime.`,
        errorCount === 0 ? 'success' : 'error');

    document.getElementById('importLog').innerHTML += `<div style="margin-top:12px;"><button class="btn btn-primary btn-sm" onclick="location.reload()"><i class="fas fa-redo"></i> Rifresko faqen</button></div>`;
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Import Excel', 'import', $content);
