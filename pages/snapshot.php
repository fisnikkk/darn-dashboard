<?php
/**
 * DARN Dashboard - Database Snapshots
 * Create, restore, and manage database backups
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

ob_start();
?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Snapshot-et</div>
        <div class="value" id="snapCount">-</div>
    </div>
    <div class="summary-card">
        <div class="label">Krijuar fundit</div>
        <div class="value" id="snapLast" style="font-size:0.95rem;">-</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Krijo Snapshot</h3>
    </div>
    <div class="card-body padded">
        <form id="snapCreateForm" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:200px;">
                <label>Emri (opsional)</label>
                <input type="text" id="snapName" placeholder="p.sh. para-ndryshimeve" style="width:100%;">
            </div>
            <div class="form-group" style="justify-content:flex-end;display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary" id="snapCreateBtn">
                    <i class="fas fa-camera"></i> Krijo Snapshot
                </button>
                <button type="button" class="btn" id="snapUploadBtn" onclick="document.getElementById('snapFileInput').click()" style="background:#f59e0b;color:#fff;">
                    <i class="fas fa-upload"></i> Ngarko Snapshot
                </button>
                <input type="file" id="snapFileInput" accept=".json" style="display:none;" onchange="uploadSnapshot(this)">
                <button type="button" class="btn" id="snapImportBtn" onclick="importFromFiles()" style="background:#6366f1;color:#fff;">
                    <i class="fas fa-file-import"></i> Import nga skedari
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-database"></i> Snapshot-et e ruajtura</h3></div>
    <div class="card-body">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Emri</th>
                        <th>Data e krijimit</th>
                        <th>Madhësia</th>
                        <th style="width:220px;">Veprimet</th>
                    </tr>
                </thead>
                <tbody id="snapList">
                    <tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted);">Duke ngarkuar...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div id="restoreModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center;">
    <div style="background:var(--card-bg,#fff);border-radius:12px;padding:2rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 1rem;color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> Kujdes!</h3>
        <p style="margin:0 0 1rem;line-height:1.6;">
            Rikthimi i snapshot-it <strong id="restoreName"></strong> do të <strong>zëvendësojë të gjitha të dhënat aktuale</strong> me ato të snapshot-it.
        </p>
        <p style="margin:0 0 1.5rem;color:#dc2626;font-weight:600;">Ky veprim nuk mund të kthehet!</p>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn" onclick="closeRestoreModal()" style="background:#e5e7eb;color:#374151;">Anulo</button>
            <button class="btn btn-danger" id="confirmRestoreBtn" onclick="confirmRestore()">
                <i class="fas fa-undo"></i> Po, rikthe snapshot-in
            </button>
        </div>
    </div>
</div>

<script>
let pendingRestore = null;

async function loadSnapshots() {
    try {
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'list'})
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        const list = data.snapshots || [];
        document.getElementById('snapCount').textContent = list.length;
        document.getElementById('snapLast').textContent = list.length > 0 ? list[0].created_at : 'Asnjë';

        const tbody = document.getElementById('snapList');
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted);">Asnjë snapshot. Krijoni një të ri.</td></tr>';
            return;
        }

        tbody.innerHTML = list.map(s => `
            <tr>
                <td><strong>${escH(s.name)}</strong></td>
                <td>${escH(s.created_at)}</td>
                <td>${escH(s.size)}</td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick="restoreSnapshot('${escH(s.name)}')" title="Rikthe">
                        <i class="fas fa-undo"></i> Rikthe
                    </button>
                    <button class="btn btn-sm" style="background:#059669;color:#fff;" onclick="downloadSnapshot('${escH(s.name)}')" title="Shkarko">
                        <i class="fas fa-download"></i> Shkarko
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="deleteSnapshot('${escH(s.name)}')" title="Fshi">
                        <i class="fas fa-trash"></i> Fshi
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        document.getElementById('snapList').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#dc2626;">Gabim: ' + escH(err.message) + '</td></tr>';
    }
}

function escH(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

document.getElementById('snapCreateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('snapCreateBtn');
    const name = document.getElementById('snapName').value.trim();
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke krijuar...';

    try {
        const body = {action: 'create'};
        if (name) body.name = name;
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        document.getElementById('snapName').value = '';
        loadSnapshots();
        showToast(data.message, 'success');
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-camera"></i> Krijo Snapshot';
    }
});

function restoreSnapshot(name) {
    pendingRestore = name;
    document.getElementById('restoreName').textContent = name;
    document.getElementById('restoreModal').style.display = 'flex';
}

function closeRestoreModal() {
    document.getElementById('restoreModal').style.display = 'none';
    pendingRestore = null;
}

async function confirmRestore() {
    if (!pendingRestore) return;
    const btn = document.getElementById('confirmRestoreBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke rikthyer...';

    try {
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'restore', name: pendingRestore})
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        closeRestoreModal();
        showToast(data.message, 'success');
        loadSnapshots();
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-undo"></i> Po, rikthe snapshot-in';
    }
}

async function deleteSnapshot(name) {
    if (!confirm('Jeni i sigurt që doni të fshini snapshot-in "' + name + '"?')) return;
    try {
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete', name: name})
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        loadSnapshots();
        showToast(data.message, 'success');
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    }
}

async function downloadSnapshot(name) {
    try {
        showToast('Duke shkarkuar...', 'success');
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'download', name: name})
        });
        if (!res.ok) throw new Error('Server error: ' + res.status);
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name + '.json';
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    }
}

function showToast(msg, type) {
    // Use existing toast if available, otherwise alert
    if (typeof window.showNotification === 'function') {
        window.showNotification(msg, type);
        return;
    }
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:8px;color:#fff;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:opacity 0.3s;';
    toast.style.background = type === 'error' ? '#dc2626' : '#16a34a';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

async function uploadSnapshot(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = ''; // Reset so same file can be re-selected

    const btn = document.getElementById('snapUploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke ngarkuar...';

    try {
        const formData = new FormData();
        formData.append('snapshot_file', file);

        const res = await fetch('/api/snapshot.php?action=upload', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        loadSnapshots();
        showToast(data.message, 'success');
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Ngarko Snapshot';
    }
}

async function importFromFiles() {
    const btn = document.getElementById('snapImportBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke importuar...';

    try {
        const res = await fetch('/api/snapshot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'import_files'})
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        loadSnapshots();
        showToast(data.message, 'success');
    } catch (err) {
        showToast('Gabim: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-import"></i> Import nga skedari';
    }
}

// Load on page ready
loadSnapshots();
</script>

<?php
$content = ob_get_clean();
renderLayout('Snapshot', 'snapshot', $content);
