/* ============================================================
   DARN Dashboard - Core JavaScript
   ============================================================ */

function showToast(message, type = 'success') {
    let toast = document.getElementById('toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.className = 'toast';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.style.background = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#1e293b';
    toast.classList.add('show');
    if (toast._toastTimer) clearTimeout(toast._toastTimer);
    toast._toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
}

/* ---- Row-level editing ---- */

function initRowEdit() {
    document.querySelectorAll('table.data-table tbody tr[data-id]').forEach(row => {
        const editableCells = row.querySelectorAll('td.editable');
        if (!editableCells.length) return;

        const lastTd = row.querySelector('td:last-child');
        if (!lastTd) return;

        // Inject edit button before existing buttons (delete)
        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-outline btn-sm row-edit-btn';
        editBtn.innerHTML = '<i class="fas fa-edit"></i>';
        editBtn.title = 'Ndrysho';
        editBtn.addEventListener('click', () => startRowEdit(row));
        lastTd.insertBefore(editBtn, lastTd.firstChild);
    });
}

function startRowEdit(row) {
    // Cancel any other row being edited
    const alreadyEditing = document.querySelector('tr.editing');
    if (alreadyEditing && alreadyEditing !== row) {
        cancelRowEdit(alreadyEditing);
    }
    if (row.classList.contains('editing')) return;

    row.classList.add('editing');
    const editableCells = row.querySelectorAll('td.editable');
    const originals = new Map();

    editableCells.forEach(td => {
        const field = td.dataset.field;
        const type = td.dataset.type || 'text';
        const rawText = td.textContent.trim();

        // Store original HTML so we can restore badges, formatting etc.
        originals.set(field, { html: td.innerHTML, text: rawText });

        let input;
        if (type === 'select') {
            input = document.createElement('select');
            let options = [];
            try { options = JSON.parse(td.dataset.options || '[]'); } catch(e) {}
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '-- Zgjidh --';
            input.appendChild(emptyOpt);
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt;
                if (opt === rawText) o.selected = true;
                input.appendChild(o);
            });
        } else {
            input = document.createElement('input');
            input.type = type === 'number' ? 'number' : type === 'date' ? 'date' : 'text';
            if (type === 'number') {
                input.value = rawText.replace(/[€,\s]/g, '');
                input.step = '0.01';
            } else {
                input.value = rawText;
            }
        }

        input.dataset.field = field;
        td.textContent = '';
        td.appendChild(input);
    });

    // Store originals on the row element
    row._editOriginals = originals;

    // Replace action buttons with Save / Cancel
    const lastTd = row.querySelector('td:last-child');
    row._originalButtons = lastTd.innerHTML;
    lastTd.innerHTML = '';

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-success btn-sm row-save-btn';
    saveBtn.innerHTML = '<i class="fas fa-check"></i> Ruaj';
    saveBtn.title = 'Ruaj ndryshimet';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-outline btn-sm row-cancel-btn';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    cancelBtn.title = 'Anulo';

    saveBtn.addEventListener('click', () => saveRowEdit(row));
    cancelBtn.addEventListener('click', () => cancelRowEdit(row));

    lastTd.appendChild(saveBtn);
    lastTd.appendChild(cancelBtn);

    // Focus first input
    const firstInput = row.querySelector('td.editable input, td.editable select');
    if (firstInput) firstInput.focus();

    // Keyboard shortcuts: Enter = save, Escape = cancel
    row._keyHandler = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            saveRowEdit(row);
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            cancelRowEdit(row);
        }
    };
    row.addEventListener('keydown', row._keyHandler);
}

function cancelRowEdit(row) {
    if (!row.classList.contains('editing')) return;

    const originals = row._editOriginals;
    row.querySelectorAll('td.editable').forEach(td => {
        const field = td.dataset.field;
        const orig = originals.get(field);
        if (orig) td.innerHTML = orig.html;
    });

    // Restore action buttons
    const lastTd = row.querySelector('td:last-child');
    lastTd.innerHTML = row._originalButtons;

    // Re-attach edit button listener
    const editBtn = lastTd.querySelector('.row-edit-btn');
    if (editBtn) editBtn.addEventListener('click', () => startRowEdit(row));

    row.classList.remove('editing');
    if (row._keyHandler) {
        row.removeEventListener('keydown', row._keyHandler);
        delete row._keyHandler;
    }
    delete row._editOriginals;
    delete row._originalButtons;
}

async function saveRowEdit(row) {
    const table = row.closest('table').dataset.table;
    const id = row.dataset.id;
    const originals = row._editOriginals;
    const changes = [];

    row.querySelectorAll('td.editable').forEach(td => {
        const field = td.dataset.field;
        const input = td.querySelector('input, select');
        if (!input) return;

        const newVal = input.value;
        const orig = originals.get(field);
        const origText = orig ? orig.text : '';
        const type = td.dataset.type || 'text';

        // For number fields compare the stripped original
        const origCompare = type === 'number' ? origText.replace(/[€,\s]/g, '') : origText;

        if (newVal !== origCompare) {
            changes.push({ field, value: newVal });
        }
    });

    if (changes.length === 0) {
        cancelRowEdit(row);
        return;
    }

    // Disable save button while processing
    const saveBtn = row.querySelector('.row-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    let allSuccess = true;
    for (const change of changes) {
        try {
            const resp = await fetch('/api/update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ table, id, field: change.field, value: change.value })
            });
            const data = await resp.json();
            if (!data.success) {
                allSuccess = false;
                showToast('Gabim: ' + (data.error || ''), 'error');
                break;
            }
        } catch (e) {
            allSuccess = false;
            showToast('Gabim ne ruajtje', 'error');
            break;
        }
    }

    if (allSuccess) {
        showToast('U ruajt me sukses');
        // Reload page to get proper formatting (badges, numbers, calculated columns)
        setTimeout(() => location.reload(), 400);
    } else {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-check"></i> Ruaj'; }
    }
}

/* ---- Bank reconciliation toggle ---- */

function toggleHighlight(id, table) {
    fetch('/api/update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table, id, field: 'e_kontrolluar', value: 'toggle' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if (row) row.classList.toggle('verified');
            showToast(data.verified ? 'E kontrolluar' : 'Hequr kontrolli');
        } else {
            showToast('Gabim: ' + (data.error || ''), 'error');
        }
    })
    .catch(() => showToast('Gabim ne server', 'error'));
}

/* ---- Ajax form submission ---- */

function initForms() {
    document.querySelectorAll('form.ajax-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.dataset.origHtml = submitBtn.innerHTML; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Duke ruajtur...'; }
            fetch(this.action, { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'U shtua me sukses');
                    if (data.reload) location.reload();
                    else { this.reset(); if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = submitBtn.dataset.origHtml; } }
                } else {
                    showToast('Gabim: ' + (data.error || ''), 'error');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = submitBtn.dataset.origHtml; }
                }
            })
            .catch(() => { showToast('Gabim ne server', 'error'); if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = submitBtn.dataset.origHtml; } });
        });
    });
}

/* ---- Modals ---- */

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

/* ---- Delete row ---- */

function deleteRow(table, id) {
    if (!confirm('A jeni te sigurt?')) return;
    fetch('/api/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table, id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if (row) row.remove();
            showToast('U fshi');
        } else {
            showToast('Gabim: ' + (data.error || ''), 'error');
        }
    })
    .catch(() => showToast('Gabim ne fshirje', 'error'));
}

/* ---- Table Search / Filter ---- */

function initTableSearch() {
    document.querySelectorAll('table.data-table').forEach(table => {
        const wrapper = table.closest('.table-wrapper');
        if (!wrapper) return;

        // Don't add client-side search if the page has server-side filters
        // (they search all rows, client-side only searches current page - confusing)
        const card = table.closest('.card');
        if (card && card.querySelector('.filters')) return;

        // Don't add search if table has very few rows
        const rows = table.querySelectorAll('tbody tr');
        if (rows.length < 3) return;

        // Create search bar
        const searchBar = document.createElement('div');
        searchBar.className = 'table-search';
        searchBar.innerHTML = `
            <div class="table-search-input-wrap">
                <i class="fas fa-search table-search-icon"></i>
                <input type="text" class="table-search-input" placeholder="Kërko në tabelë..." autocomplete="off">
                <span class="table-search-count"></span>
                <button class="table-search-clear" title="Pastro" style="display:none;">&times;</button>
            </div>
        `;
        wrapper.parentNode.insertBefore(searchBar, wrapper);

        const input = searchBar.querySelector('.table-search-input');
        const countEl = searchBar.querySelector('.table-search-count');
        const clearBtn = searchBar.querySelector('.table-search-clear');
        const tbody = table.querySelector('tbody');
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        const tfoot = table.querySelector('tfoot');

        input.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            clearBtn.style.display = query ? 'block' : 'none';

            if (!query) {
                allRows.forEach(r => r.style.display = '');
                countEl.textContent = '';
                if (tfoot) tfoot.style.display = '';
                return;
            }

            let visible = 0;
            allRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = text.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            countEl.textContent = visible + ' / ' + allRows.length;
            // Hide tfoot totals when filtering (they'd be wrong)
            if (tfoot) tfoot.style.display = visible < allRows.length ? 'none' : '';
        });

        clearBtn.addEventListener('click', function() {
            input.value = '';
            input.dispatchEvent(new Event('input'));
            input.focus();
        });

        // Ctrl+F / Cmd+F focuses table search instead of browser search
        // (only when a data-table is visible on page)
    });
}

/* ---- Column Sorting ---- */

function parseSortValue(text) {
    // Returns { type: 'empty'|'date'|'number'|'text', value: ... }
    if (!text || text === '-' || text === '—') {
        return { type: 'empty', value: null };
    }

    // Date: YYYY-MM-DD (MySQL format)
    const isoMatch = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (isoMatch) {
        return { type: 'date', value: new Date(isoMatch[1], isoMatch[2] - 1, isoMatch[3]).getTime() };
    }

    // Date: DD.MM.YYYY or DD/MM/YYYY
    const euMatch = text.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})/);
    if (euMatch) {
        return { type: 'date', value: new Date(euMatch[3], euMatch[2] - 1, euMatch[1]).getTime() };
    }

    // Number: strip €, commas, spaces, and trailing text like "ditë", "boca", "L"
    const cleaned = text.replace(/[€,\s]/g, '');
    const num = parseFloat(cleaned);
    if (!isNaN(num) && /^[€\s]*-?[\d.,]+/.test(text)) {
        return { type: 'number', value: num };
    }

    // Text fallback
    return { type: 'text', value: text.toLowerCase() };
}

function compareValues(a, b, dir) {
    // Empties always sort last regardless of direction
    if (a.type === 'empty' && b.type === 'empty') return 0;
    if (a.type === 'empty') return 1;
    if (b.type === 'empty') return -1;

    // Same type: direct comparison
    if (a.type === b.type) {
        let cmp = 0;
        if (a.type === 'text') {
            cmp = a.value.localeCompare(b.value);
        } else {
            cmp = a.value - b.value;
        }
        return dir === 'asc' ? cmp : -cmp;
    }

    // Mixed types: dates > numbers > text
    const priority = { date: 3, number: 2, text: 1 };
    return priority[a.type] - priority[b.type];
}

function initTableSort() {
    document.querySelectorAll('table.data-table').forEach(table => {
        // Skip tables with server-side sorting (they use onclick navigation in headers)
        if (table.dataset.serverSort || table.querySelector('th.server-sort')) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const headers = table.querySelectorAll('thead th');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length < 2) return;

        // Store original order for reset
        rows.forEach((row, i) => row._origIndex = i);

        headers.forEach((th, colIndex) => {
            // Skip empty headers (action columns)
            if (!th.textContent.trim()) return;
            // Skip checkbox/toggle columns
            if (th.textContent.trim() === '✓') return;

            th.classList.add('sortable');
            th.dataset.sortDir = ''; // '', 'asc', 'desc'

            // Add sort icon
            const icon = document.createElement('span');
            icon.className = 'sort-icon';
            icon.innerHTML = ' <i class="fas fa-sort"></i>';
            th.appendChild(icon);

            th.addEventListener('click', function() {
                const currentDir = this.dataset.sortDir;
                let newDir;

                if (currentDir === '') newDir = 'asc';
                else if (currentDir === 'asc') newDir = 'desc';
                else newDir = ''; // Reset to original

                // Reset all other headers
                headers.forEach(h => {
                    if (h !== th) {
                        h.dataset.sortDir = '';
                        const si = h.querySelector('.sort-icon');
                        if (si) si.innerHTML = ' <i class="fas fa-sort"></i>';
                        h.classList.remove('sort-asc', 'sort-desc');
                    }
                });

                this.dataset.sortDir = newDir;
                this.classList.remove('sort-asc', 'sort-desc');
                const sortIcon = this.querySelector('.sort-icon');

                if (newDir === 'asc') {
                    if (sortIcon) sortIcon.innerHTML = ' <i class="fas fa-sort-up"></i>';
                    this.classList.add('sort-asc');
                } else if (newDir === 'desc') {
                    if (sortIcon) sortIcon.innerHTML = ' <i class="fas fa-sort-down"></i>';
                    this.classList.add('sort-desc');
                } else {
                    if (sortIcon) sortIcon.innerHTML = ' <i class="fas fa-sort"></i>';
                }

                // Get current visible rows (respect search filter)
                const visibleRows = rows.filter(r => r.style.display !== 'none');
                const hiddenRows = rows.filter(r => r.style.display === 'none');

                let sortedRows;
                if (newDir === '') {
                    // Restore original order
                    sortedRows = [...rows].sort((a, b) => a._origIndex - b._origIndex);
                } else {
                    // Pre-parse all values for this column (avoids re-parsing during sort)
                    const parsed = new Map();
                    visibleRows.forEach(row => {
                        const cell = row.cells[colIndex];
                        const text = cell ? cell.textContent.trim() : '';
                        parsed.set(row, parseSortValue(text));
                    });

                    visibleRows.sort((a, b) => {
                        return compareValues(parsed.get(a), parsed.get(b), newDir);
                    });

                    sortedRows = [...visibleRows, ...hiddenRows];
                }

                // Rebuild DOM using DocumentFragment for reliable repaint
                const frag = document.createDocumentFragment();
                sortedRows.forEach(row => frag.appendChild(row));
                tbody.textContent = ''; // Clear tbody completely
                tbody.appendChild(frag);
            });
        });
    });
}

/* ---- Init on page load ---- */

document.addEventListener('DOMContentLoaded', () => {
    initRowEdit();
    initForms();
    initTableSearch();
    initTableSort();
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
    });

    // Global Escape key: close any open modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        }
    });
});
