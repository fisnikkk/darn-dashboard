<?php
/**
 * DARN Dashboard - Excel Import
 * Upload an Excel (.xlsx / .xlsm) file and import data into the database.
 * Supports Replace (clear + re-insert) and Append (add new rows only) modes.
 * Auto-creates a snapshot before any Replace operation for safety.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Sheet-to-table mapping (display reference — actual parsing is in api/excel_import.php)
$sheetMappings = [
    'Distribuimi' => ['table' => 'distribuimi'],
    'Shpenzimet' => ['table' => 'shpenzimet'],
    'Plini depo' => ['table' => 'plini_depo'],
    'Shitje produkteve prej 9 mar' => ['table' => 'shitje_produkteve'],
    'Kontrata' => ['table' => 'kontrata'],
    'Gjendja bankare' => ['table' => 'gjendja_bankare'],
    'NOTES' => ['table' => 'notes'],
    'Klientet' => ['table' => 'klientet'],
    'Nxemese1' => ['table' => 'nxemese'],
    'Stoku zyrtar' => ['table' => 'stoku_zyrtar'],
    'Depo' => ['table' => 'depo'],
];

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
                <p id="progressText" style="font-size:0.85rem;color:var(--text-muted);text-align:center;">Duke ngarkuar...</p>
            </div>
        </div>

        <!-- Step 2: Preview (hidden until file parsed) -->
        <div id="previewSection" style="display:none;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h4 style="margin:0;"><i class="fas fa-check-circle" style="color:var(--success);"></i> <span id="fileName"></span></h4>
                    <small id="fileInfo" style="color:var(--text-muted);"></small>
                </div>
                <button class="btn btn-outline btn-sm" onclick="resetUpload()"><i class="fas fa-undo"></i> Ndrysho skedarin</button>
            </div>

            <!-- Sheet-to-table mapping -->
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
                    <tbody id="mappingBody">
                    </tbody>
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
            <div id="importLog" style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:12px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:0.82rem;line-height:1.6;">
            </div>
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
                <thead>
                    <tr>
                        <th>Tabela</th>
                        <th style="text-align:right;">Rreshta</th>
                    </tr>
                </thead>
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

<script>
const SHEET_MAPPINGS = <?= json_encode($sheetMappings, JSON_UNESCAPED_UNICODE) ?>;
const TABLE_LABELS = <?= json_encode($tableLabels, JSON_UNESCAPED_UNICODE) ?>;
const TABLE_COUNTS = <?= json_encode($tableCounts) ?>;

let parsedSheets = null; // will hold { sheetName: { rows: N, table: '...', headerRow: N } }

// Drag & drop
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--primary)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--border)'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--border)';
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
});

document.getElementById('excelFile').addEventListener('change', function() {
    if (this.files[0]) handleFile(this.files[0]);
});

function handleFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext !== 'xlsx' && ext !== 'xlsm') {
        showToast('Vetëm skedarë .xlsx ose .xlsm pranohen', 'error');
        return;
    }
    if (file.size > 50 * 1024 * 1024) {
        showToast('Skedari është shumë i madh (max 50 MB)', 'error');
        return;
    }

    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('progressBar').style.width = '30%';
    document.getElementById('progressText').textContent = 'Duke ngarkuar skedarin...';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'parse');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/excel_import.php');

    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 60) + 10;
            document.getElementById('progressBar').style.width = pct + '%';
        }
    });

    xhr.onload = function() {
        document.getElementById('progressBar').style.width = '100%';
        try {
            const resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                document.getElementById('progressText').textContent = 'U analizua me sukses!';
                setTimeout(() => showPreview(file, resp), 500);
            } else {
                document.getElementById('progressText').textContent = 'Gabim: ' + resp.error;
                showToast(resp.error, 'error');
            }
        } catch (e) {
            document.getElementById('progressText').textContent = 'Gabim në përgjigje';
            showToast('Gabim: ' + xhr.responseText.substring(0, 200), 'error');
        }
    };

    xhr.onerror = function() {
        document.getElementById('progressText').textContent = 'Gabim në lidhje';
        showToast('Gabim në lidhje me serverin', 'error');
    };

    xhr.send(formData);
}

function showPreview(file, resp) {
    parsedSheets = resp.sheets;
    document.getElementById('uploadSection').style.display = 'none';
    document.getElementById('previewSection').style.display = 'block';
    document.getElementById('fileName').textContent = file.name;

    const totalRows = Object.values(resp.sheets).reduce((s, sh) => s + sh.rows, 0);
    document.getElementById('fileInfo').textContent =
        Object.keys(resp.sheets).length + ' sheet-a të njohura, ' + totalRows.toLocaleString() + ' rreshta gjithsej';

    const tbody = document.getElementById('mappingBody');
    tbody.innerHTML = '';

    for (const [sheetName, info] of Object.entries(resp.sheets)) {
        const table = info.table;
        const dbCount = TABLE_COUNTS[table] || 0;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="sheet-check" data-sheet="${sheetName}" data-table="${table}" checked></td>
            <td><i class="fas fa-file-excel" style="color:#217346;margin-right:6px;"></i>${sheetName}</td>
            <td><code>${table}</code></td>
            <td style="text-align:right;font-weight:600;">${info.rows.toLocaleString()}</td>
            <td style="text-align:right;">${dbCount.toLocaleString()}</td>
            <td>
                <select class="import-mode" data-table="${table}" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                    <option value="replace">Zëvendëso (Replace)</option>
                    <option value="append">Shto (Append)</option>
                </select>
            </td>
            <td><span class="status-pending" style="color:var(--text-muted);font-size:0.82rem;"><i class="fas fa-clock"></i> Gati</span></td>
        `;
        tbody.appendChild(tr);
    }

    // Select All toggle
    document.getElementById('selectAll').addEventListener('change', function() {
        document.querySelectorAll('.sheet-check').forEach(cb => cb.checked = this.checked);
    });
}

function resetUpload() {
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('importSection').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('excelFile').value = '';
    parsedSheets = null;
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
        const sheet = cb.dataset.sheet;
        const table = cb.dataset.table;
        const mode = document.querySelector(`.import-mode[data-table="${table}"]`).value;
        selected.push({ sheet, table, mode });
    });

    if (selected.length === 0) {
        showToast('Zgjidhni të paktën një tabelë', 'error');
        return;
    }

    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('importSection').style.display = 'block';
    document.getElementById('importLog').innerHTML = '';

    const doSnapshot = document.getElementById('autoSnapshot').checked;
    const hasReplace = selected.some(s => s.mode === 'replace');

    // Step 1: Auto-snapshot if needed
    if (doSnapshot && hasReplace) {
        addLog('Duke krijuar snapshot automatik para importit...', 'info');
        try {
            const snapResp = await fetch('/api/snapshot.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'create', name: 'para-import-' + new Date().toISOString().slice(0,19).replace(/[T:]/g, '-') })
            });
            const snapData = await snapResp.json();
            if (snapData.success) {
                addLog('Snapshot u krijua: ' + snapData.message, 'success');
            } else {
                addLog('Snapshot dështoi: ' + snapData.error, 'error');
                if (!confirm('Snapshot dështoi. Dëshironi të vazhdoni pa snapshot?')) {
                    resetUpload();
                    return;
                }
            }
        } catch (e) {
            addLog('Gabim snapshot: ' + e.message, 'error');
        }
    }

    // Step 2: Import each selected table
    let successCount = 0;
    let errorCount = 0;

    for (const item of selected) {
        addLog(`Duke importuar ${item.sheet} → ${item.table} (${item.mode})...`, 'info');

        // Update status in mapping table
        const statusCell = document.querySelector(`tr:has(.sheet-check[data-table="${item.table}"]) td:last-child`);
        if (statusCell) statusCell.innerHTML = '<span style="color:var(--primary);"><i class="fas fa-spinner fa-spin"></i> Duke importuar...</span>';

        try {
            const resp = await fetch('/api/excel_import.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'import',
                    sheet: item.sheet,
                    table: item.table,
                    mode: item.mode
                })
            });
            const data = await resp.json();
            if (data.success) {
                addLog(`${item.table}: ${data.imported} rreshta u importuan${data.deleted ? ' (' + data.deleted + ' u fshinë)' : ''}`, 'success');
                if (statusCell) statusCell.innerHTML = '<span style="color:var(--success);"><i class="fas fa-check-circle"></i> ' + data.imported + ' rreshta</span>';
                successCount++;
            } else {
                addLog(`${item.table}: Gabim - ${data.error}`, 'error');
                if (statusCell) statusCell.innerHTML = '<span style="color:var(--danger);"><i class="fas fa-times-circle"></i> Gabim</span>';
                errorCount++;
            }
        } catch (e) {
            addLog(`${item.table}: Gabim - ${e.message}`, 'error');
            errorCount++;
        }
    }

    // Final summary
    addLog('', 'info');
    if (errorCount === 0) {
        addLog(`Import u përfundua me sukses! ${successCount} tabela u importuan.`, 'success');
    } else {
        addLog(`Import përfundoi: ${successCount} sukses, ${errorCount} gabime.`, 'error');
    }

    // Show "done" button
    const log = document.getElementById('importLog');
    log.innerHTML += `<div style="margin-top:12px;"><button class="btn btn-primary btn-sm" onclick="location.reload()"><i class="fas fa-redo"></i> Rifresko faqen</button></div>`;
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Import Excel', 'import', $content);
