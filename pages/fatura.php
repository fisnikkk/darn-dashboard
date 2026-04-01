<?php
/**
 * DARN Dashboard - Invoice (Fatura) Page
 * Generate invoices from distribuimi data, preview PDF, send to clients via email.
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
            <!-- Client (searchable) -->
            <div style="position:relative;">
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:var(--text-secondary);">Klienti</label>
                <input type="text" id="inv-client" autocomplete="off" placeholder="Shkruaj per te kerkuar..."
                    style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem;"
                    onfocus="showClientDropdown()" oninput="filterClients()">
                <div id="client-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0; max-height:200px; overflow-y:auto; background:#fff; border:1px solid var(--border); border-radius:0 0 6px 6px; z-index:100; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                    <?php foreach ($clientNames as $name): ?>
                        <div class="client-option" onclick="selectClient('<?= htmlspecialchars(addslashes($name)) ?>')"
                            style="padding:8px 12px; cursor:pointer; font-size:0.85rem; border-bottom:1px solid #f0f0f0;"
                            onmouseover="this.style.background='#f0f4ff'" onmouseout="this.style.background='#fff'">
                            <?= htmlspecialchars($name) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                <input type="number" id="inv-number" style="width:80px; padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem; text-align:center;">
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:8px;">
                <button onclick="invoicePreview()" id="btn-preview" class="btn" style="background:var(--primary); color:#fff; padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem;">
                    <i class="fas fa-eye"></i> Shiko
                </button>
            </div>
        </div>
    </div>
</div>

<!-- PDF Preview (shown after clicking Shiko) -->
<div id="preview-section" style="display:none; margin-top:16px;">
    <div class="summary-card" style="padding: 16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0; color:var(--primary);"><i class="fas fa-file-pdf"></i> Parashikimi i Fatures</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="invoiceCreate()" id="btn-create" class="btn" style="background:#16a34a; color:#fff; padding:8px 20px; border:none; border-radius:6px; cursor:pointer; font-size:0.9rem; font-weight:600;">
                    <i class="fas fa-check"></i> Krijo Faturen
                </button>
                <button onclick="closePreview()" class="btn" style="background:#6b7280; color:#fff; padding:8px 14px; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem;">
                    <i class="fas fa-times"></i> Mbyll
                </button>
            </div>
        </div>
        <div id="cash-warning" style="display:none; padding:8px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; margin-bottom:12px; font-size:0.85rem; color:#92400e;">
            <i class="fas fa-exclamation-triangle"></i> <span id="cash-warning-text"></span>
        </div>
        <iframe id="pdf-preview-frame" style="width:100%; height:700px; border:1px solid var(--border); border-radius:6px;" src="about:blank"></iframe>
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
var previewCashCount = 0;

// Searchable client dropdown
function showClientDropdown() {
    document.getElementById('client-dropdown').style.display = 'block';
    filterClients();
}

function filterClients() {
    var input = document.getElementById('inv-client').value.toLowerCase();
    var options = document.querySelectorAll('.client-option');
    options.forEach(function(opt) {
        var name = opt.textContent.trim().toLowerCase();
        opt.style.display = name.indexOf(input) !== -1 ? 'block' : 'none';
    });
}

function selectClient(name) {
    document.getElementById('inv-client').value = name;
    document.getElementById('client-dropdown').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dropdown = document.getElementById('client-dropdown');
    var input = document.getElementById('inv-client');
    if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Load next invoice number on page load
(function() {
    fetch('/api/invoice.php?action=next_number')
        .then(r => r.json())
        .then(d => {
            if (d.success) document.getElementById('inv-number').value = d.next_number;
        });
    loadHistory();
})();

// Format invoice number with date: "134-02-2026"
function formatInvoiceNumber(num, dateTo) {
    var d = new Date(dateTo);
    var month = ('0' + (d.getMonth() + 1)).slice(-2);
    var year = d.getFullYear();
    return num + '-' + month + '-' + year;
}

function invoicePreview() {
    var client = document.getElementById('inv-client').value;
    var dateFrom = document.getElementById('inv-date-from').value;
    var dateTo = document.getElementById('inv-date-to').value;
    var invNum = document.getElementById('inv-number').value;

    if (!client || !dateFrom || !dateTo) {
        alert('Ju lutem plotesoni te gjitha fushat (klienti, data nga, data deri)');
        return;
    }

    // First check if there's data (quick JSON check)
    var checkUrl = '/api/invoice.php?action=preview&client=' + encodeURIComponent(client) +
              '&date_from=' + dateFrom + '&date_to=' + dateTo;

    // Show loading state
    var btn = document.getElementById('btn-preview');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke ngarkuar...';

    fetch(checkUrl)
        .then(r => r.json())
        .then(function(d) {
            if (!d.success) { alert(d.error); resetPreviewBtn(); return; }
            if (d.count === 0) {
                alert('Nuk ka te dhena per kete klient ne kete periudhe.');
                document.getElementById('preview-section').style.display = 'none';
                resetPreviewBtn();
                return;
            }

            // Count CASH rows for warning
            previewCashCount = 0;
            d.rows.forEach(function(r) {
                if (r.menyra_e_pageses.toLowerCase().trim() === 'cash') previewCashCount++;
            });

            // Show cash warning if applicable
            var cashWarning = document.getElementById('cash-warning');
            if (previewCashCount > 0) {
                document.getElementById('cash-warning-text').textContent =
                    previewCashCount + ' rreshta me CASH do te ndryshohen ne "PO (FATURE TE RREGULLTE) CASH" kur te krijohet fatura.';
                cashWarning.style.display = 'block';
            } else {
                cashWarning.style.display = 'none';
            }

            // Load PDF preview in iframe
            var pdfUrl = '/api/invoice.php?action=preview_pdf&client=' + encodeURIComponent(client) +
                '&date_from=' + dateFrom + '&date_to=' + dateTo +
                '&invoice_number=' + invNum;

            document.getElementById('pdf-preview-frame').src = pdfUrl;
            document.getElementById('preview-section').style.display = 'block';
            document.getElementById('invoice-status').style.display = 'none';
            resetPreviewBtn();
        })
        .catch(function(e) {
            alert('Gabim: ' + e.message);
            resetPreviewBtn();
        });
}

function resetPreviewBtn() {
    var btn = document.getElementById('btn-preview');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-eye"></i> Shiko';
}

function closePreview() {
    document.getElementById('preview-section').style.display = 'none';
    document.getElementById('pdf-preview-frame').src = 'about:blank';
}

function invoiceCreate() {
    var client = document.getElementById('inv-client').value;
    var dateFrom = document.getElementById('inv-date-from').value;
    var dateTo = document.getElementById('inv-date-to').value;
    var invNum = document.getElementById('inv-number').value;
    var formattedNum = formatInvoiceNumber(invNum, dateTo);

    var msg = 'Jeni te sigurt qe deshironi te krijoni Faturen nr ' + formattedNum + ' per ' + client + '?';
    if (previewCashCount > 0) {
        msg += '\n\nKjo do te:\n- Gjeneroj PDF\n- Ndryshoj ' + previewCashCount + ' statuse CASH ne "Fature te rregullte"\n- Rrisni numrin e fatures';
    } else {
        msg += '\n\nKjo do te:\n- Gjeneroj PDF\n- Rrisni numrin e fatures';
    }
    if (!confirm(msg)) return;

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
            document.getElementById('btn-create').innerHTML = '<i class="fas fa-check"></i> Krijo Faturen';
            return;
        }

        var formattedNum = d.formatted_number || (d.invoice_number + '-' + ('0' + (new Date(dateTo).getMonth()+1)).slice(-2) + '-' + new Date(dateTo).getFullYear());

        // Auto-download the PDF
        var dl = document.createElement('a');
        dl.href = d.download_url;
        dl.download = '';
        document.body.appendChild(dl);
        dl.click();
        document.body.removeChild(dl);

        // Hide preview
        closePreview();

        // Show success
        var status = document.getElementById('invoice-status');
        status.style.display = 'block';
        status.style.background = '#dcfce7';
        status.style.border = '1px solid #86efac';

        var successHtml = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> ' +
            '<strong>Fatura nr ' + formattedNum + ' u krijua me sukses!</strong><br>' +
            'Totali: ' + d.total_amount.toFixed(2) + ' EUR | ' + d.total_rows + ' rreshta';
        if (d.cash_updated > 0) {
            successHtml += ' | ' + d.cash_updated + ' statuse CASH u ndryshuan';
        }
        successHtml += '<br><br>';

        // Download PDF button
        successHtml += '<a href="' + d.download_url + '" class="btn" style="background:var(--primary); color:#fff; padding:6px 14px; border-radius:6px; text-decoration:none; margin-right:8px;">' +
            '<i class="fas fa-download"></i> Shkarko PDF</a>';

        // Send Email via server (PHPMailer) button — shows preview first
        successHtml += '<button onclick="sendEmail(' + d.invoice_id + ', \'' + client.replace(/'/g, "\\'") + '\', \'' + dateTo + '\')" class="btn" id="btn-send-email-' + d.invoice_id + '" style="background:#16a34a; color:#fff; padding:6px 14px; border:none; border-radius:6px; cursor:pointer; margin-right:8px;">' +
            '<i class="fas fa-paper-plane"></i> Dergo Email me PDF</button>';

        // Gmail compose fallback (opens in browser, no attachment)
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
        var gmailUrl = 'https://mail.google.com/mail/?view=cm&to=' + encodeURIComponent(emailTo) + '&su=' + subject + '&body=' + body;
        successHtml += '<a href="' + gmailUrl + '" target="_blank" class="btn" style="background:#2563eb; color:#fff; padding:6px 14px; border-radius:6px; text-decoration:none;">' +
            '<i class="fab fa-google"></i> Hap ne Gmail</a>';

        status.innerHTML = successHtml;

        // Update invoice number
        document.getElementById('inv-number').value = d.invoice_number + 1;

        // Reset create button
        document.getElementById('btn-create').disabled = false;
        document.getElementById('btn-create').innerHTML = '<i class="fas fa-check"></i> Krijo Faturen';

        // Reload history
        loadHistory();
    })
    .catch(function(e) {
        alert('Gabim: ' + e.message);
        document.getElementById('btn-create').disabled = false;
        document.getElementById('btn-create').innerHTML = '<i class="fas fa-check"></i> Krijo Faturen';
    });
}

// Show email preview dialog before sending
function sendEmail(invoiceId, clientName, dateTo) {
    var email = clientEmails[clientName] || '(nuk ka email)';
    var monthFull = formatMonthFull(dateTo);
    var monthShort = formatMonth(dateTo);

    var subject = 'Fatura per ' + clientName + ' per muajin ' + monthShort;
    var body = 'Pershendetje ' + clientName + ',\n\n' +
        'Ju lutemi gjeni te bashkangjitur faturen per muajin ' + monthFull + '.\n' +
        'Falemnderit per bashkepunimin tuaj!\n\n' +
        'Sabri Kadriu\nFinance Director\nDarn Group L.L.C\n' +
        'Bulevardi Deshmoret e Kombit, nr. 62 6/1 Prishtine 10000, Kosove\n\n' +
        'Perfaqesues zyrtar i Hexagon Ragasco ne Kosove\n' +
        'I autorizuari i vetem ne tere Kosoven per mbushjen dhe kontrollimin e cilindrave LPG\n\n' +
        'Cell: +383 (0) 49 62 76 76\nE-mail: sales@darngroup.com\nwww.darngroup.com';

    // Show preview modal
    showEmailPreview(invoiceId, email, subject, body);
}

function showEmailPreview(invoiceId, toEmail, subject, body) {
    // Remove existing modal if any
    var existing = document.getElementById('email-preview-modal');
    if (existing) existing.remove();

    var modal = document.createElement('div');
    modal.id = 'email-preview-modal';
    modal.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; display:flex; align-items:center; justify-content:center;';
    modal.innerHTML =
        '<div style="background:#fff; border-radius:12px; padding:24px; max-width:600px; width:90%; max-height:80vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
            '<h3 style="margin:0 0 16px 0; color:#1e3a5f;"><i class="fas fa-envelope"></i> Parashikimi i Emailit</h3>' +
            '<div style="margin-bottom:12px;">' +
                '<label style="font-size:0.8rem; color:#6b7280; font-weight:600;">Deri te:</label>' +
                '<div style="padding:8px 12px; background:#f3f4f6; border-radius:6px; font-size:0.9rem;">' + toEmail + '</div>' +
            '</div>' +
            '<div style="margin-bottom:12px;">' +
                '<label style="font-size:0.8rem; color:#6b7280; font-weight:600;">Subjekti:</label>' +
                '<div style="padding:8px 12px; background:#f3f4f6; border-radius:6px; font-size:0.9rem;">' + subject + '</div>' +
            '</div>' +
            '<div style="margin-bottom:12px;">' +
                '<label style="font-size:0.8rem; color:#6b7280; font-weight:600;">Bashkangjitja:</label>' +
                '<div style="padding:8px 12px; background:#f3f4f6; border-radius:6px; font-size:0.9rem; color:#16a34a;"><i class="fas fa-file-pdf"></i> Fatura PDF</div>' +
            '</div>' +
            '<div style="margin-bottom:16px;">' +
                '<label style="font-size:0.8rem; color:#6b7280; font-weight:600;">Mesazhi:</label>' +
                '<pre style="padding:12px; background:#f3f4f6; border-radius:6px; font-size:0.8rem; white-space:pre-wrap; font-family:inherit; margin:4px 0 0 0; max-height:200px; overflow-y:auto;">' + body + '</pre>' +
            '</div>' +
            '<div style="display:flex; gap:8px; justify-content:flex-end;">' +
                '<button onclick="closeEmailPreview()" style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; font-size:0.85rem;">Anulo</button>' +
                '<button id="btn-confirm-send" onclick="confirmSendEmail(' + invoiceId + ')" style="padding:8px 20px; border:none; border-radius:6px; background:#16a34a; color:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;"><i class="fas fa-paper-plane"></i> Dergo</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(modal);

    // Close on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeEmailPreview();
    });
}

function closeEmailPreview() {
    var modal = document.getElementById('email-preview-modal');
    if (modal) modal.remove();
}

function confirmSendEmail(invoiceId) {
    var btn = document.getElementById('btn-confirm-send');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke derguar...';

    fetch('/api/invoice.php?action=send_email', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: invoiceId })
    })
    .then(r => r.json())
    .then(function(d) {
        if (d.success) {
            closeEmailPreview();
            var status = document.getElementById('invoice-status');
            status.style.display = 'block';
            status.style.background = '#dcfce7';
            status.style.border = '1px solid #86efac';
            status.innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> <strong>Email u dergua me sukses te ' + d.to + '!</strong>';
        } else {
            alert('Gabim: ' + d.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Dergo';
        }
    })
    .catch(function(e) {
        alert('Gabim: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Dergo';
    });
}

// Send email from history row (also shows preview first)
function sendEmailFromHistory(invoiceId, clientName, dateTo) {
    sendEmail(invoiceId, clientName, dateTo);
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
                // Format invoice number with date
                var fmtNum = formatInvoiceNumber(inv.invoice_number, inv.date_to);

                html += '<tr>';
                html += '<td style="font-weight:600;">' + fmtNum + '</td>';
                html += '<td>' + inv.klienti + '</td>';
                html += '<td>' + inv.date_from + ' - ' + inv.date_to + '</td>';
                html += '<td style="text-align:right; font-weight:600;">' + parseFloat(inv.total_amount).toFixed(2) + ' EUR</td>';
                html += '<td style="text-align:center">' + inv.total_delivered + '</td>';
                html += '<td style="text-align:center">' + inv.total_returned + '</td>';
                html += '<td>' + inv.created_at + '</td>';
                html += '<td style="white-space:nowrap;">';

                // Download
                html += '<a href="/api/invoice.php?action=download&id=' + inv.id + '" style="color:var(--primary); margin-right:8px;" title="Shkarko PDF"><i class="fas fa-download"></i></a>';

                // Send email with attachment (server-side — shows preview first)
                html += '<a href="#" data-email-btn="' + inv.id + '" onclick="sendEmailFromHistory(' + inv.id + ', \'' + inv.klienti.replace(/'/g, "\\'") + '\', \'' + inv.date_to + '\'); return false;" style="color:#16a34a; margin-right:8px;" title="Dergo Email me PDF"><i class="fas fa-paper-plane"></i></a>';

                // Open Gmail compose (fallback)
                var email = clientEmails[inv.klienti] || '';
                var gmailSubject = encodeURIComponent('Fatura per ' + inv.klienti + ' per muajin ' + formatMonth(inv.date_to));
                var gmailBody = encodeURIComponent(
                    'Pershendetje ' + inv.klienti + ',\n\n' +
                    'Ju lutemi gjeni te bashkangjitur faturen per muajin ' + formatMonthFull(inv.date_to) + '.\n' +
                    'Falemnderit per bashkepunimin tuaj!\n\n' +
                    'Sabri Kadriu\nFinance Director\nDarn Group L.L.C'
                );
                var gmailUrl = 'https://mail.google.com/mail/?view=cm&to=' + encodeURIComponent(email) + '&su=' + gmailSubject + '&body=' + gmailBody;
                html += '<a href="' + gmailUrl + '" target="_blank" style="color:#2563eb; margin-right:8px;" title="Hap ne Gmail"><i class="fab fa-google"></i></a>';

                // Delete
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
