/* ============================================================
   DARN Dashboard - Core JavaScript
   ============================================================ */

/* ---- Sidebar toggle with persistent state ---- */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('mobile-open');
        backdrop.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
        // Persist collapsed state
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    }
}
function closeMobileSidebar() {
    document.getElementById('sidebar').classList.remove('mobile-open');
    document.getElementById('sidebarBackdrop').classList.remove('active');
}
// Restore sidebar state on page load
(function() {
    if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === '1') {
        document.getElementById('sidebar').classList.add('collapsed');
    }
})();

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

        // Inject revert button
        const table = row.closest('table').dataset.table;
        const revertBtn = document.createElement('button');
        revertBtn.className = 'btn btn-outline btn-sm row-revert-btn';
        revertBtn.innerHTML = '<i class="fas fa-undo"></i>';
        revertBtn.title = 'Kthe ndryshimin e fundit';
        revertBtn.addEventListener('click', () => revertRow(table, row.dataset.id));
        lastTd.insertBefore(revertBtn, lastTd.firstChild);

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
            let options = [];
            try { options = JSON.parse(td.dataset.options || '[]'); } catch(e) {}
            const allowCustom = td.dataset.allowCustom === 'true';

            if (allowCustom) {
                // Use text input + datalist — allows typing new values
                input = document.createElement('input');
                input.type = 'text';
                input.value = rawText;
                const dlId = 'dl_' + field + '_' + row.dataset.id;
                input.setAttribute('list', dlId);
                input.placeholder = 'Shkruaj ose zgjidh...';
                const datalist = document.createElement('datalist');
                datalist.id = dlId;
                options.forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt;
                    datalist.appendChild(o);
                });
                input._datalist = datalist; // store to append after td is cleared
            } else {
                input = document.createElement('select');
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
            }
        } else {
            input = document.createElement('input');
            input.type = type === 'number' ? 'number' : type === 'date' ? 'date' : 'text';
            if (type === 'number') {
                const stripped = rawText.replace(/[€,\s]/g, '');
                input.value = (stripped === '-' || stripped === '—' || stripped === '') ? '' : stripped;
                input.step = '0.01';
            } else {
                input.value = rawText;
            }
        }

        input.dataset.field = field;
        // Prevent row-level keydown from stealing Backspace/Delete etc.
        input.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== 'Escape') e.stopPropagation();
        });
        td.textContent = '';
        td.appendChild(input);
        if (input._datalist) td.appendChild(input._datalist);
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

    // Re-attach button listeners (edit & revert are added by JS, delete uses inline onclick)
    const table = row.closest('table').dataset.table;
    const editBtn = lastTd.querySelector('.row-edit-btn');
    if (editBtn) editBtn.addEventListener('click', () => startRowEdit(row));
    const revertBtn = lastTd.querySelector('.row-revert-btn');
    if (revertBtn) revertBtn.addEventListener('click', () => revertRow(table, row.dataset.id));

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

        // For number fields compare the stripped original (treat dash as empty)
        let origCompare = origText;
        if (type === 'number') {
            const stripped = origText.replace(/[€,\s]/g, '');
            origCompare = (stripped === '-' || stripped === '—') ? '' : stripped;
        }

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

    try {
        const resp = await fetch('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ table, id, changes })
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (data.success) {
            showToast('U ruajt me sukses');
            setTimeout(() => location.reload(), 400);
        } else {
            showToast('Gabim: ' + (data.error || ''), 'error');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-check"></i> Ruaj'; }
        }
    } catch (e) {
        showToast('Gabim ne ruajtje', 'error');
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
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
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
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
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

/* ---- Revert row to previous state ---- */

function revertRow(table, id) {
    if (!confirm('Kthe ndryshimin e fundit per kete rresht?')) return;
    fetch('/api/revert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table, id })
    })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(data => {
        if (data.success) {
            showToast(data.message || 'U kthye me sukses');
            setTimeout(() => location.reload(), 400);
        } else {
            showToast(data.error || 'Nuk ka ndryshime per te kthyer', 'error');
        }
    })
    .catch(() => showToast('Gabim ne server', 'error'));
}

/* ---- Delete row ---- */

function deleteRow(table, id) {
    if (!confirm('A jeni te sigurt?')) return;
    fetch('/api/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table, id })
    })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(data => {
        if (data.success) {
            showToast('U fshi');
            // Remove the row from DOM immediately (no page reload needed)
            const row = document.querySelector('tr[data-id="' + id + '"]');
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 300);
            }
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

    // Mixed types: dates > numbers > text (respect sort direction)
    const priority = { date: 3, number: 2, text: 1 };
    const cmp = priority[a.type] - priority[b.type];
    return dir === 'asc' ? cmp : -cmp;
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
                        // Use data-sort-value if present (for custom sort keys like YYYY-MM)
                        const text = (cell && cell.dataset.sortValue) ? cell.dataset.sortValue : (cell ? cell.textContent.trim() : '');
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

/* ---- "Add new" select-to-input swap ---- */

function initAddNewSelects() {
    document.querySelectorAll('select[id$="-select"]').forEach(select => {
        select.addEventListener('change', function() {
            if (this.value !== '__new__') return;

            const group = this.closest('.form-group');
            const name = this.name;

            // Hide the select
            this.style.display = 'none';
            this.removeAttribute('name');

            // Create text input + cancel button wrapper
            const wrap = document.createElement('div');
            wrap.className = 'add-new-wrap';
            wrap.innerHTML = `<input type="text" name="${name}" placeholder="Shkruaj kategorinë e re..." required autofocus>
                <button type="button" class="btn btn-outline btn-sm add-new-cancel" title="Anulo"><i class="fas fa-times"></i></button>`;
            group.appendChild(wrap);

            wrap.querySelector('input').focus();
            wrap.querySelector('.add-new-cancel').addEventListener('click', function() {
                select.value = '';
                select.style.display = '';
                select.setAttribute('name', name);
                wrap.remove();
            });
        });
    });
}

/* ---- Client-side filter helper ---- */

function applyClientFilters(table) {
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const allRows = tbody.querySelectorAll('tr');
    const filters = table._clientFilters || {};

    allRows.forEach(row => {
        let visible = true;
        for (const key of Object.keys(filters)) {
            const filter = filters[key];
            const cell = row.cells[filter.colIdx];
            const val = cell ? cell.textContent.trim() : '';
            if (!filter.selectedValues.has(val)) {
                visible = false;
                break;
            }
        }
        row.style.display = visible ? '' : 'none';
    });
}

/* ---- Excel-like Column Filters ---- */

function positionFilterDropdown(btn, dropdown) {
    const btnRect = btn.getBoundingClientRect();
    // Default: open below the button, aligned left
    let top = btnRect.bottom + 4;
    let left = btnRect.left;

    // Temporarily show to measure
    dropdown.style.top = '-9999px';
    dropdown.style.left = '-9999px';
    const ddRect = dropdown.getBoundingClientRect();

    // If it would go off-screen bottom, open above the button
    if (top + ddRect.height > window.innerHeight - 10) {
        top = btnRect.top - ddRect.height - 4;
    }
    // If it would go off-screen right, align right edge to button
    if (left + ddRect.width > window.innerWidth - 10) {
        left = btnRect.right - ddRect.width;
    }
    // Clamp to viewport
    if (left < 4) left = 4;
    if (top < 4) top = 4;

    dropdown.style.top = top + 'px';
    dropdown.style.left = left + 'px';
}

function initColumnFilters() {
    // Find all th[data-filter] elements — each has a JSON list of distinct values
    // and the URL param name to use
    document.querySelectorAll('th[data-filter]').forEach(th => {
        const paramName = th.dataset.filter;      // e.g. "f_menyra_e_pageses"
        let values = [];
        try { values = JSON.parse(th.dataset.filterValues || '[]'); } catch(e) {}
        if (!values.length) return;

        // Read currently active filter from URL
        // Handle both JS format (f_lloji[]) and PHP http_build_query format (f_lloji[0], f_lloji[1], ...)
        const url = new URL(window.location);
        const activeFilters = url.searchParams.getAll(paramName + '[]');
        for (let i = 0; i < 200; i++) {
            const v = url.searchParams.get(paramName + '[' + i + ']');
            if (v === null) break;
            activeFilters.push(v);
        }

        // Build filter button
        const wrap = document.createElement('span');
        wrap.className = 'col-filter-wrap';
        const btn = document.createElement('button');
        btn.className = 'col-filter-btn' + (activeFilters.length ? ' active' : '');
        btn.innerHTML = '<i class="fas fa-filter"></i>';
        btn.title = 'Filtro';
        btn.type = 'button';

        // Build dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'col-filter-dropdown';
        dropdown.innerHTML = `
            <div class="col-filter-search"><input type="text" placeholder="Kërko..." autocomplete="off"></div>
            <div class="col-filter-list"></div>
            <div class="col-filter-actions">
                <button type="button" class="cf-cancel">Anulo</button>
                <button type="button" class="cf-clear">Pastro</button>
                <button type="button" class="cf-ok">OK</button>
            </div>
        `;

        const list = dropdown.querySelector('.col-filter-list');
        const searchInput = dropdown.querySelector('.col-filter-search input');

        // Determine initial check state: if no active filters, all are checked (no filter)
        const hasActive = activeFilters.length > 0;

        // Build "Select All" item
        const selectAllItem = document.createElement('div');
        selectAllItem.className = 'col-filter-item select-all';
        selectAllItem.innerHTML = `<input type="checkbox" ${!hasActive ? 'checked' : ''}><label>(Zgjidh të gjitha)</label>`;
        list.appendChild(selectAllItem);
        const selectAllCb = selectAllItem.querySelector('input');

        // Build items for each value
        const items = [];
        values.forEach(val => {
            const displayVal = val || '(Bosh)';
            const isChecked = !hasActive || activeFilters.includes(val);
            const item = document.createElement('div');
            item.className = 'col-filter-item';
            item.dataset.value = val;
            item.innerHTML = `<input type="checkbox" ${isChecked ? 'checked' : ''}><label>${escHtml(displayVal)}</label>`;
            list.appendChild(item);
            items.push(item);

            // Click on label/item toggles checkbox
            item.addEventListener('click', function(e) {
                if (e.target.tagName === 'INPUT') return;
                const cb = this.querySelector('input');
                cb.checked = !cb.checked;
                updateSelectAll();
            });
        });

        function updateSelectAll() {
            const allChecked = items.every(it => it.querySelector('input').checked);
            selectAllCb.checked = allChecked;
        }

        // Select All toggle
        selectAllItem.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') {
                items.forEach(it => it.querySelector('input').checked = selectAllCb.checked);
                return;
            }
            selectAllCb.checked = !selectAllCb.checked;
            items.forEach(it => it.querySelector('input').checked = selectAllCb.checked);
        });

        // Search within filter values
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            items.forEach(it => {
                const text = it.querySelector('label').textContent.toLowerCase();
                it.style.display = text.includes(q) ? '' : 'none';
            });
        });

        // OK button - apply filter
        dropdown.querySelector('.cf-ok').addEventListener('click', function() {
            const allChecked = items.every(it => it.querySelector('input').checked);
            const noneChecked = items.every(it => !it.querySelector('input').checked);

            // Client-side filter mode (for calculated columns)
            if (th.dataset.filterMode === 'client') {
                const colIdx = parseInt(th.dataset.filterCol, 10);
                const table = th.closest('table');
                if (!table._clientFilters) table._clientFilters = {};

                if (allChecked || noneChecked) {
                    delete table._clientFilters[paramName];
                } else {
                    const selectedValues = new Set();
                    items.forEach(it => {
                        if (it.querySelector('input').checked) selectedValues.add(it.dataset.value);
                    });
                    table._clientFilters[paramName] = { colIdx, selectedValues };
                }

                applyClientFilters(table);
                dropdown.classList.remove('open');
                btn.classList.toggle('active', !allChecked && !noneChecked);
                return;
            }

            // Server-side filter (URL-based)
            const newUrl = new URL(window.location);
            // Delete both f_xxx[] (JS format) and f_xxx[0], f_xxx[1]... (PHP http_build_query format)
            newUrl.searchParams.delete(paramName + '[]');
            for (let i = 0; i < 200; i++) {
                if (!newUrl.searchParams.has(paramName + '[' + i + ']')) break;
                newUrl.searchParams.delete(paramName + '[' + i + ']');
            }
            newUrl.searchParams.set('page', '1');

            if (!allChecked && !noneChecked) {
                items.forEach(it => {
                    if (it.querySelector('input').checked) {
                        newUrl.searchParams.append(paramName + '[]', it.dataset.value);
                    }
                });
            }
            // If all checked or none checked, remove filter entirely
            window.location.href = newUrl.toString();
        });

        // Cancel button
        dropdown.querySelector('.cf-cancel').addEventListener('click', function() {
            dropdown.classList.remove('open');
        });

        // Clear button - remove this filter
        dropdown.querySelector('.cf-clear').addEventListener('click', function() {
            // Client-side filter mode
            if (th.dataset.filterMode === 'client') {
                const table = th.closest('table');
                if (table._clientFilters) delete table._clientFilters[paramName];
                applyClientFilters(table);
                items.forEach(it => it.querySelector('input').checked = true);
                selectAllCb.checked = true;
                btn.classList.remove('active');
                dropdown.classList.remove('open');
                return;
            }

            // Server-side filter — delete both JS and PHP array formats
            const newUrl = new URL(window.location);
            newUrl.searchParams.delete(paramName + '[]');
            for (let i = 0; i < 200; i++) {
                if (!newUrl.searchParams.has(paramName + '[' + i + ']')) break;
                newUrl.searchParams.delete(paramName + '[' + i + ']');
            }
            newUrl.searchParams.set('page', '1');
            window.location.href = newUrl.toString();
        });

        // Toggle dropdown
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            // Close any other open dropdowns
            document.querySelectorAll('.col-filter-dropdown.open').forEach(d => {
                if (d !== dropdown) d.classList.remove('open');
            });
            dropdown.classList.toggle('open');
            if (dropdown.classList.contains('open')) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                // Position dropdown using fixed positioning relative to the button
                positionFilterDropdown(btn, dropdown);
                setTimeout(() => searchInput.focus(), 50);
            }
        });

        // Close on outside click
        dropdown.addEventListener('click', function(e) { e.stopPropagation(); });

        wrap.appendChild(btn);
        wrap.appendChild(dropdown);
        th.appendChild(wrap);
    });

    // Global: close all filter dropdowns on outside click
    document.addEventListener('click', function() {
        document.querySelectorAll('.col-filter-dropdown.open').forEach(d => d.classList.remove('open'));
    });

    // Reposition open filter dropdowns on page scroll (not dropdown-internal scroll)
    var _scrollElements = [window, document.querySelector('.table-wrapper'), document.querySelector('.card-body')].filter(Boolean);
    _scrollElements.forEach(function(el) {
        el.addEventListener('scroll', function() {
            document.querySelectorAll('.col-filter-dropdown.open').forEach(function(d) {
                var btn = d.previousElementSibling;
                if (btn) positionFilterDropdown(btn, d);
            });
        });
    });
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

/* ---- Preserve column filters when submitting top filter forms ---- */
function initFilterPersistence() {
    const url = new URL(window.location);
    // Collect all f_* column filter params from the current URL
    const filterParams = [];
    for (const [key, value] of url.searchParams.entries()) {
        if (key.startsWith('f_')) {
            filterParams.push({ name: key, value: value });
        }
    }
    if (!filterParams.length) return;

    // For every GET form in .filters, inject hidden inputs so column filters survive form submit
    document.querySelectorAll('.filters form[method="GET"], .filters form:not([method])').forEach(form => {
        filterParams.forEach(p => {
            // Don't duplicate if form already has this exact field
            if (form.querySelector('input[name="' + CSS.escape(p.name) + '"][value="' + CSS.escape(p.value) + '"]')) return;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = p.name;
            hidden.value = p.value;
            hidden.className = 'cf-preserved';
            form.appendChild(hidden);
        });
    });
}

/* ---- Init on page load ---- */

document.addEventListener('DOMContentLoaded', () => {
    initRowEdit();
    initForms();
    initTableSearch();
    initTableSort();
    initAddNewSelects();
    initColumnFilters();
    initFilterPersistence();
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
