/* ===== SIDEBAR TOGGLE (mobile off-canvas / desktop collapse rail) ===== */
function toggleSidebar() {
    if (window.innerWidth <= 768) {
        var s = document.getElementById('sidebar'), o = document.getElementById('sidebarOverlay');
        if (s && o) { s.classList.toggle('open'); o.classList.toggle('show'); }
    } else {
        var c = document.body.classList.toggle('sb-collapsed');
        try { localStorage.setItem('bizvaza_sidebar', c ? 'collapsed' : 'open'); } catch (e) {}
    }
}
function applySidebarState() {
    if (window.innerWidth > 768) {
        var v = 'open';
        try { v = localStorage.getItem('bizvaza_sidebar') || 'open'; } catch (e) {}
        document.body.classList.toggle('sb-collapsed', v === 'collapsed');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    applySidebarState();
    var s = document.getElementById('sidebar');
    if (s) {
        s.querySelectorAll('a').forEach(function(a) {
            a.addEventListener('click', function() {
                var o = document.getElementById('sidebarOverlay');
                if (o && o.classList.contains('show')) { toggleSidebar(); }
            });
        });
    }
});
window.addEventListener('resize', applySidebarState);

/* ===== THEME TOGGLE (dark mode) ===== */
var SUN_PATH = 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42';
var MOON_PATH = 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z';
function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('bizvaza_theme', theme); } catch (e) {}
    var label = document.getElementById('themeLabel');
    if (label) label.textContent = theme === 'dark' ? 'Light Mode' : 'Dark Mode';
    var icon = document.querySelector('#themeToggle .theme-icon-moon');
    if (icon) icon.setAttribute('d', theme === 'dark' ? SUN_PATH : MOON_PATH);
    if (window.Chart) { window.dispatchEvent(new Event('bizvazaThemeChange')); }
}
function toggleTheme() {
    setTheme(getTheme() === 'dark' ? 'light' : 'dark');
}
document.addEventListener('DOMContentLoaded', function() {
    setTheme(getTheme());
});

/* ===== USER DROPDOWN ===== */
function toggleUserMenu(e) {
    e.stopPropagation();
    var m = document.getElementById('userMenu');
    if (m) m.classList.toggle('show');
}
document.addEventListener('click', function() {
    var m = document.getElementById('userMenu');
    if (m) m.classList.remove('show');
});

/* ===== PASSWORD TOGGLE ===== */
function togglePw(b) {
    var i = b.parentElement.querySelector('input');
    if (!i) return;
    var p = i.type === 'password';
    i.type = p ? 'text' : 'password';
    b.innerHTML = p
        ? '<svg viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        : '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

/* ===== MODAL ===== */
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});

/* ===== NOTIFICATION PANEL ===== */
function toggleNotif(e) {
    e.stopPropagation();
    var p = document.getElementById('notifPanel'), o = document.getElementById('notifOverlay');
    if (p) {
        var opening = !p.classList.contains('show');
        p.classList.toggle('show');
        if (o) o.classList.toggle('show');
        if (opening) markNotifsRead();
    }
}
function markNotifsRead() {
    var dot = document.querySelector('.bell-dot');
    if (!dot || dot.style.display === 'none') return;
    var meta = document.querySelector('meta[name=csrf-token]');
    if (!meta) return;
    fetch('ajax/mark-notifications-read.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': meta.getAttribute('content'), 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d && d.ok) {
            if (dot) dot.style.display = 'none';
            document.querySelectorAll('.notif-dot').forEach(function(n) { n.remove(); });
        }
    }).catch(function() {});
}
function closeNotif() {
    var p = document.getElementById('notifPanel'), o = document.getElementById('notifOverlay');
    if (p) p.classList.remove('show');
    if (o) o.classList.remove('show');
}

/* ===== FORM SHOW/HIDE ===== */
function showForm(id) {
    id = id || 'formWrapper';
    var el = document.getElementById(id);
    if (el) { el.classList.add('show'); window.scrollTo({ top: el.offsetTop - 20, behavior: 'smooth' }); }
}
function hideForm(id) {
    id = id || 'formWrapper';
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

/* ===== GENERIC TOAST ===== */
function showToast(msg, type) {
    var t = document.getElementById('toastEl');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast ' + (type || 'success') + ' show';
    setTimeout(function() { t.className = 'toast ' + (type || 'success'); }, 4000);
}

/* ===== TABLE FILTER HELPERS ===== */
function filterTable(inputId, tableSelector, colIndex) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var q = input.value.toLowerCase();
    var rows = document.querySelectorAll(tableSelector + ' tbody tr');
    rows.forEach(function(row) {
        var cell = row.querySelectorAll('td')[colIndex];
        if (cell) {
            row.style.display = cell.textContent.toLowerCase().includes(q) ? '' : 'none';
        }
    });
}

/* ===== INVOICE FILTER ===== */
function applyFilters() {
    var from = document.getElementById('filterDateFrom');
    var to = document.getElementById('filterDateTo');
    var cust = document.getElementById('filterCustomer');
    if (!from || !to || !cust) return;
    var fv = from.value, tv = to.value, cv = cust.value.toLowerCase();
    var rows = document.querySelectorAll('.inv-table tbody tr, table tbody tr');
    rows.forEach(function(row) {
        var date = row.querySelector('.inv-date');
        var customer = row.querySelector('.inv-customer');
        var show = true;
        if (date) {
            var d = date.getAttribute('data-date') || date.textContent.trim();
            if (fv && d < fv) show = false;
            if (tv && d > tv) show = false;
        }
        if (customer && cv && !customer.textContent.toLowerCase().includes(cv)) show = false;
        row.style.display = show ? '' : 'none';
    });
}
function clearFilters() {
    var from = document.getElementById('filterDateFrom');
    var to = document.getElementById('filterDateTo');
    var cust = document.getElementById('filterCustomer');
    if (from) from.value = '';
    if (to) to.value = '';
    if (cust) cust.value = '';
    applyFilters();
}

/* ===== DELETE INVOICE ===== */
function deleteInvoice(num, id) {
    if (confirm('Delete invoice ' + num + '?')) {
        var f = document.createElement('form'); f.method = 'POST'; f.action = '';
        var t = document.createElement('input'); t.type = 'hidden'; t.name = '_csrf_token'; t.value = document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').getAttribute('content') : '';
        var i = document.createElement('input'); i.type = 'hidden'; i.name = 'delete_id'; i.value = id;
        f.appendChild(t); f.appendChild(i); document.body.appendChild(f); f.submit();
    }
    return false;
}

/* ===== PREVENT DOUBLE SUBMIT ===== */
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || !form.tagName || form.tagName.toLowerCase() !== 'form') return;
    if (form.dataset.submitting === '1') {
        e.preventDefault();
        e.stopPropagation();
        return;
    }
    form.dataset.submitting = '1';
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; }
    setTimeout(function() {
        if (btn) { btn.disabled = false; }
        delete form.dataset.submitting;
    }, 5000);
}, true);

/* ===== ANTI-COPY / ANTI-DEVTOOLS IIFE (disabled for now — uncomment to restore) ===== */
/*
(function() {
    function c(e) { e.preventDefault(); }
    document.addEventListener('contextmenu', c);
    function k(e) {
        if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J')) || (e.ctrlKey && e.key === 'U'))
            e.preventDefault();
    }
    document.addEventListener('keydown', k);
})();
*/
