<?php
/**
 * DARN Dashboard - Invoice (Fatura) Page
 * Generate invoices from distribuimi data, send to clients via email.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Get client names from distribuimi (primary source — always has real names)
$clientNames = $db->query("SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti ASC")->fetchAll(PDO::FETCH_COLUMN);

// Get client emails map from klientet (match by name)
$clientEmails = [];
$klientet = $db->query("SELECT emri, email FROM klientet WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($klientet as $c) {
    $clientEmails[$c['emri']] = $c['email'];
}

ob_start();
?>

<!-- Invoice Form -->
<div class="summary-grid" style="grid-template-columns: 1fr;">
    <div class="summary-card" style="padding: 20px;">
        <h3 style="margin: 0 0 15px 0; color: var(--primary);"><i class="fas fa-file-invoice"></i> Gjenero Fature</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto auto; gap: 12px; align-items: end;">
            <!-- Client -->
            <div>
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:var(--text-secondary);">Klienti</label>
                <select id="inv-client" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem;">
                    <option value="">-- Zgjidh klientin --</option>
                    <?php foreach ($clientNames as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date from -->
            <div>
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:var(--text-secondary);">Nga data</label>
                <input type="date" id="inv-date-from" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem;">
            </div>

            <!-- Date to -->
            <div>
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:var(--text-secondary);">Deri ne date</label>
                <input type="date" id="inv-date-to" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem;">
            </div>

            <!-- Invoice number -->
            <div>
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:var(--text-secondary);">Nr. Fatures</label>
                <input type="number" id="inv-number" style="width:80px; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem; text-align:center;" readonly>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:8px;">
                <button onclick="invoicePreview()" class="btn" style="background:var(--primary); color:#fff; padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem;">
                    <i class="fas fa-eye"></i> Shiko
                </button>
                <button onclick="invoiceCreate()" id="btn-create" class="btn" style="background:#16a34a; color:#fff; padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem; display:none;">
                    <i class="fas fa-file-pdf"></i> Krijo Faturen
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Preview Table -->
<div id="preview-section" style="display:none; margin-top:16px;">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Pershkrimi</th>
                    <th>Dhene</th>
                    <th>Kthyer</th>
                    <th>Sasia (L)</th>
                    <th>Cmimi</th>
                    <th>Ulje%</th>
                    <th>Vlera</th>
                    <th>Pagesa</th>
                </tr>
            </thead>
            <tbody id="preview-body"></tbody>
            <tfoot id="preview-footer"></tfoot>
        </table>
    </div>
</div>

<!-- Status message -->
<div id="invoice-status" style="display:none; margin-top:16px; padding:12px 16px; border-radius:8px;"></div>

<!-- Invoice History -->
<div style="margin-top:24px;">
    <h3 style="margin:0 0 12px 0;"><i class="fas fa-history"></i> Historia e Faturave</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nr.</th>
                    <th>Klienti</th>
                    <th>Periudha</th>
                    <th>Totali</th>
                    <th>Dhene</th>
                    <th>Kthyer</th>
                    <th>Krijuar</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody id="history-body">
                <tr><td colspan="8" style="text-align:center; padding:20px; color:var(--text-secondary);">Duke ngarkuar...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// Client emails map
var clientEmails = <?= json_encode($clientEmails) ?>;

// Load next invoice number on page load
(function() {
    fetch('/api/invoice.php?action=next_number')
        .then(r => r.json())
        .then(d => {
            if (d.success) document.getElementById('inv-number').value = d.next_number;
        });
    loadHistory();
})();

function invoicePreview() {
    var client = document.getElementById('inv-client').value;
    var dateFrom = document.getElementById('inv-date-from').value;
    var dateTo = document.getElementById('inv-date-to').value;

    if (!client || !dateFrom || !dateTo) {
        alert('Ju lutem plotesoni te gjitha fushat (klienti, data nga, data deri)');
        return;
    }

    var url = '/api/invoice.php?action=preview&client=' + encodeURIComponent(client) +
              '&date_from=' + dateFrom + '&date_to=' + dateTo;

    fetch(url)
        .then(r => r.json())
        .then(function(d) {
            if (!d.success) { alert(d.error); return; }
            if (d.count === 0) {
                alert('Nuk ka te dhena per kete klient ne kete periudhe.');
                document.getElementById('preview-section').style.display = 'none';
                document.getElementById('btn-create').style.display = 'none';
                return;
            }

            var tbody = document.getElementById('preview-body');
            var html = '';
            var cashCount = 0;
            d.rows.forEach(function(r) {
                var isCash = r.menyra_e_pageses.toLowerCase().trim() === 'cash';
                if (isCash) cashCount++;
                html += '<tr' + (isCash ? ' style="background:#fef3c7;"' : '') + '>';
                html += '<td>' + r.data + '</td>';
                html += '<td>GAS I LENGET (L)</td>';
                html += '<td style="text-align:center">' + r.sasia + '</td>';
                html += '<td style="text-align:center">' + r.boca_te_kthyera + '</td>';
                html += '<td style="text-align:center">' + parseFloat(r.litra).toFixed(1) + '</td>';
                html += '<td style="text-align:center">' + parseFloat(r.cmimi).toFixed(2) + '</td>';
                html += '<td style="text-align:center">0</td>';
                html += '<td style="text-align:right">' + parseFloat(r.pagesa).toFixed(2) + '</td>';
                html += '<td>' + (isCash ? '<span style="color:#d97706; font-weight:600;">CASH \u2192 Fature</span>' : r.menyra_e_pageses) + '</td>';
                html += '</tr>';
            });
            tbody.innerHTML = html;

            // Footer totals
            var tfoot = document.getElementById('preview-footer');
            tfoot.innerHTML = '<tr style="font-weight:700; background:var(--bg-secondary);">' +
                '<td>TOTALI</td><td>' + d.count + ' rreshta</td>' +
                '<td style="text-align:center">' + d.total_delivered + '</td>' +
                '<td style="text-align:center">' + d.total_returned + '</td>' +
                '<td colspan="3"></td>' +
                '<td style="text-align:right">' + d.total_amount.toFixed(2) + ' EUR</td>' +
                '<td>' + (cashCount > 0 ? cashCount + ' CASH do ndryshohen' : '') + '</td></tr>';

            document.getElementById('preview-section').style.display = 'block';
            document.getElementById('btn-create').style.display = 'inline-flex';
        })
        .catch(function(e) { alert('Gabim: ' + e.message); });
}

function invoiceCreate() {
    var client = document.getElementById('inv-client').value;
    var dateFrom = document.getElementById('inv-date-from').value;
    var dateTo = document.getElementById('inv-date-to').value;
    var invNum = document.getElementById('inv-number').value;

    if (!confirm('Jeni te sigurt qe deshironi te krijoni Faturen nr ' + invNum + ' per ' + client + '?\n\nKjo do te:\n- Gjeneroj PDF\n- Ndryshoj statusin CASH ne "Fature te rregullte"\n- Rrisni numrin e fatures')) {
        return;
    }

    document.getElementById('btn-create').disabled = true;
    document.getElementById('btn-create').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke krijuar...';

    fetch('/api/invoice.php?action=create', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            client: client,
            date_from: dateFrom,
            date_to: dateTo,
            invoice_number: parseInt(invNum)
        })
    })
    .then(r => r.json())
    .then(function(d) {
        if (!d.success) {
            alert('Gabim: ' + d.error);
            document.getElementById('btn-create').disabled = false;
            document.getElementById('btn-create').innerHTML = '<i class="fas fa-file-pdf"></i> Krijo Faturen';
            return;
        }

        // Show success
        var status = document.getElementById('invoice-status');
        status.style.display = 'block';
        status.style.background = '#dcfce7';
        status.style.border = '1px solid #86efac';
        status.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> ' +
            '<strong>Fatura nr ' + d.invoice_number + ' u krijua me sukses!</strong><br>' +
            'Totali: ' + d.total_amount.toFixed(2) + ' EUR | ' + d.total_rows + ' rreshta | ' +
            d.cash_updated + ' statuse CASH u ndryshuan<br><br>' +
            '<a href="' + d.download_url + '" class="btn" style="background:var(--primary); color:#fff; padding:6px 14px; border-radius:6px; text-decoration:none; margin-right:8px;">' +
            '<i class="fas fa-download"></i> Shkarko PDF</a>';

        // Add email button (always show, like Android app)
        var emailTo = d.client_email || '';
        var subject = encodeURIComponent('Fatura per ' + client + ' per muajin ' + formatMonth(dateTo));
        var body = encodeURIComponent(
            'Pershendetje ' + client + ',\n\n' +
            'Ju lutemi gjeni te bashkangjitur faturen per muajin ' + formatMonthFull(dateTo) + '.\n' +
            'Falemnderit per bashkepunimin tuaj!\n\n' +
            'Sabri Kadriu\nFinance Director\nDarn Group L.L.C\n' +
            'Bulevardi Deshmoret e Kombit, nr. 62 6/1 Prishtine 10000, Kosove\n\n' +
            'Perfaqesues zyrtar i Hexagon Ragasco ne Kosove\n' +
            'I autorizuari i vetem ne tere Kosoven per mbushjen dhe kontrollimin e cilindrave LPG\n\n' +
            'Cell: +383 (0) 49 62 76 76\nE-mail: sales@darngroup.com\nwww.darngroup.com'
        );
        status.innerHTML += ' <a href="mailto:' + emailTo + '?subject=' + subject + '&body=' + body + '" class="btn" style="background:#2563eb; color:#fff; padding:6px 14px; border-radius:6px; text-decoration:none;">' +
            '<i class="fas fa-envelope"></i> Dergo Email</a>';

        // Update invoice number
        document.getElementById('inv-number').value = d.invoice_number + 1;
        document.getElementById('btn-create').style.display = 'none';
        document.getElementById('preview-section').style.display = 'none';

        // Reload history
        loadHistory();
    })
    .catch(function(e) {
        alert('Gabim: ' + e.message);
        document.getElementById('btn-create').disabled = false;
        document.getElementById('btn-create').innerHTML = '<i class="fas fa-file-pdf"></i> Krijo Faturen';
    });
}

function loadHistory() {
    fetch('/api/invoice.php?action=history')
        .then(r => r.json())
        .then(function(d) {
            if (!d.success || d.invoices.length === 0) {
                document.getElementById('history-body').innerHTML = '<tr><td colspan="8" style="text-align:center; padding:20px; color:var(--text-secondary);">Nuk ka fatura te krijuara ende.</td></tr>';
                return;
            }

            var html = '';
            d.invoices.forEach(function(inv) {
                var email = clientEmails[inv.klienti] || '';
                html += '<tr>';
                html += '<td style="font-weight:600;">' + inv.invoice_number + '</td>';
                html += '<td>' + inv.klienti + '</td>';
                html += '<td>' + inv.date_from + ' - ' + inv.date_to + '</td>';
                html += '<td style="text-align:right; font-weight:600;">' + parseFloat(inv.total_amount).toFixed(2) + ' EUR</td>';
                html += '<td style="text-align:center">' + inv.total_delivered + '</td>';
                html += '<td style="text-align:center">' + inv.total_returned + '</td>';
                html += '<td>' + inv.created_at + '</td>';
                html += '<td>';
                html += '<a href="/api/invoice.php?action=download&id=' + inv.id + '" style="color:var(--primary); margin-right:8px;" title="Shkarko PDF"><i class="fas fa-download"></i></a>';
                var hSubject = encodeURIComponent('Fatura per ' + inv.klienti + ' per muajin ' + formatMonth(inv.date_to));
                var hBody = encodeURIComponent(
                    'Pershendetje ' + inv.klienti + ',\n\n' +
                    'Ju lutemi gjeni te bashkangjitur faturen per muajin ' + formatMonthFull(inv.date_to) + '.\n' +
                    'Falemnderit per bashkepunimin tuaj!\n\n' +
                    'Sabri Kadriu\nFinance Director\nDarn Group L.L.C'
                );
                html += '<a href="mailto:' + (email || '') + '?subject=' + hSubject + '&body=' + hBody + '" style="color:#2563eb; margin-right:8px;" title="Dergo Email"><i class="fas fa-envelope"></i></a>';
                html += '<a href="#" onclick="invoiceDelete(' + inv.id + ', ' + inv.invoice_number + '); return false;" style="color:#dc2626;" title="Fshij Faturen"><i class="fas fa-trash"></i></a>';
                html += '</td>';
                html += '</tr>';
            });
            document.getElementById('history-body').innerHTML = html;
        });
}

function invoiceDelete(id, num) {
    if (!confirm('Jeni te sigurt qe deshironi te fshini Faturen nr ' + num + '?\n\nKjo do te:\n- Fshij PDF faturen\n- Kthej statuset CASH ne gjendjen e meparshme\n- Rikthej numrin e fatures ne ' + num)) {
        return;
    }
    fetch('/api/invoice.php?action=delete&id=' + id)
        .then(r => r.json())
        .then(function(d) {
            if (!d.success) { alert('Gabim: ' + d.error); return; }
            var status = document.getElementById('invoice-status');
            status.style.display = 'block';
            status.style.background = '#fef2f2';
            status.style.border = '1px solid #fca5a5';
            status.innerHTML = '<i class="fas fa-trash" style="color:#dc2626;"></i> ' +
                '<strong>Fatura nr ' + d.deleted_number + ' u fshi!</strong> ' +
                d.reverted_statuses + ' statuse u kthyen ne CASH.';
            document.getElementById('inv-number').value = d.next_number;
            loadHistory();
        })
        .catch(function(e) { alert('Gabim: ' + e.message); });
}

function formatMonth(dateStr) {
    var d = new Date(dateStr);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + '-' + d.getFullYear();
}

function formatMonthFull(dateStr) {
    var d = new Date(dateStr);
    var months = ['Janar','Shkurt','Mars','Prill','Maj','Qershor','Korrik','Gusht','Shtator','Tetor','Nentor','Dhjetor'];
    return months[d.getMonth()] + ' ' + d.getFullYear();
}
</script>

<?php
$content = ob_get_clean();
renderLayout('Fatura', 'fatura', $content);
