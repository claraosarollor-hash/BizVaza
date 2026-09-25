function toggleUserMenu(e) {
    e.stopPropagation();
    document.getElementById('userMenu').classList.toggle('show');
}
document.addEventListener('click', function() {
    var menu = document.getElementById('userMenu');
    if (menu) menu.classList.remove('show');
});
function showForm() {
    document.getElementById('formWrapper').classList.add('show');
    window.scrollTo({ top: document.getElementById('formWrapper').offsetTop - 20, behavior: 'smooth' });
}
function hideForm() {
    document.getElementById('formWrapper').classList.remove('show');
}

(function() {
    var editor = document.getElementById('rcptItemsEditor');
    var body = document.getElementById('rcptItemsBody');
    var invoiceField = document.querySelector('input[name="invoice_number"]');
    var linkedField = document.querySelector('input[name="linked_receipt"]');
    var amountField = document.getElementById('rcptReceivedInput') || document.querySelector('input[name="amount"]');
    var balanceField = document.getElementById('rcptBalanceInput');
    var totalInput = document.getElementById('rcptTotalInput');
    var totalHint = document.getElementById('rcptTotalHint');
    var addBtn = document.getElementById('rcpAddItem');
    if (!editor || !body || !amountField || !totalInput || !balanceField) return;

    var receivedTouched = false;
    var lastMode = '';
    var lookupTimer = null;

    function fmt(n) {
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function getMode() {
        if (linkedField && linkedField.value.trim() !== '') return 'settle';
        if (invoiceField && invoiceField.value.trim() !== '') return 'invoice';
        return 'standalone';
    }

    function computeItems() {
        var total = 0, hasItems = false;
        body.querySelectorAll('.rcp-item-row').forEach(function(row) {
            var qty = parseFloat(row.querySelector('.rcp-qty-input').value) || 0;
            var price = parseFloat((row.querySelector('.rcp-price-input').value || '').replace(/,/g, '')) || 0;
            var amt = Math.round(qty * price * 100) / 100;
            var name = (row.querySelector('input[name="item_name[]"]').value || '').trim();
            row.querySelector('.rcp-amount-input').value = fmt(amt);
            if (name !== '' || qty > 0 || price > 0) hasItems = true;
            total += amt;
        });
        return { total: Math.round(total * 100) / 100, hasItems: hasItems };
    }

    function recalc() {
        var mode = getMode();

        if (mode === 'settle') {
            // Total and hint are server-rendered from the original receipt chain.
            balanceField.readOnly = true;
            var tot = parseFloat(totalInput.value) || 0;
            var recv = parseFloat(amountField.value) || 0;
            balanceField.value = Math.max(0, tot - recv).toFixed(2);
        } else if (mode === 'invoice') {
            // Linked mode: total comes from the invoice lookup, balance derives from the outstanding amount.
            balanceField.readOnly = true;
            var outstanding = parseFloat(totalInput.dataset.outstanding);
            if (isNaN(outstanding)) outstanding = parseFloat(totalInput.value) || 0;
            var received2 = parseFloat(amountField.value) || 0;
            balanceField.value = Math.max(0, outstanding - received2).toFixed(2);
        } else {
            var inv = computeItems();
            totalInput.value = inv.total.toFixed(2);
            delete totalInput.dataset.outstanding;
            if (inv.hasItems) {
                balanceField.readOnly = true;
                if (!receivedTouched) amountField.value = inv.total.toFixed(2);
                var received = parseFloat(amountField.value) || 0;
                balanceField.value = Math.max(0, inv.total - received).toFixed(2);
                if (totalHint) totalHint.textContent = 'Auto-summed from the items below';
            } else {
                balanceField.readOnly = false;
                if (totalHint) totalHint.textContent = 'No item rows — enter the amounts manually';
            }
        }
    }

    function lookupInvoice() {
        var num = invoiceField.value.trim();
        if (num === '') {
            delete totalInput.dataset.outstanding;
            if (totalHint) totalHint.textContent = '';
            recalc();
            return;
        }
        fetch('ajax/receipt-invoice-lookup.php?number=' + encodeURIComponent(num), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.found) {
                totalInput.value = Number(d.invoice_total).toFixed(2);
                totalInput.dataset.outstanding = Number(d.outstanding);
                if (totalHint) totalHint.textContent = d.invoice_number + ' · Already paid ₦' + fmt(Number(d.paid)) + ' · Outstanding ₦' + fmt(Number(d.outstanding));
                if (!receivedTouched) amountField.value = Number(d.outstanding).toFixed(2);
            } else {
                delete totalInput.dataset.outstanding;
                totalInput.value = '0.00';
                if (totalHint) totalHint.textContent = '⚠ Invoice "' + num + '" was not found in your records';
            }
            recalc();
        })
        .catch(function() {});
    }

    function applyTypeState(select) {
        var row = select.closest('.rcp-item-row');
        if (!row) return;
        var qtyInput = row.querySelector('.rcp-qty-input');
        if (select.value === 'service') {
            qtyInput.disabled = true;
            qtyInput.value = 1;
            qtyInput.style.opacity = '0.5';
        } else {
            qtyInput.disabled = false;
            qtyInput.style.opacity = '1';
        }
        recalc();
    }

    function updateEditorState() {
        var mode = getMode();
        editor.style.display = mode === 'standalone' ? 'block' : 'none';
        if (mode !== lastMode) {
            receivedTouched = false; // mode switch: re-seed defaults
            lastMode = mode;
        }
        if (mode === 'standalone') recalc();
    }

    body.addEventListener('input', recalc);
    body.addEventListener('change', function(e) {
        if (e.target.classList.contains('rcp-item-type')) applyTypeState(e.target);
    });
    body.addEventListener('click', function(e) {
        var btn = e.target.closest('.rcp-del-row');
        if (!btn) return;
        if (body.querySelectorAll('.rcp-item-row').length > 1) {
            btn.closest('.rcp-item-row').remove();
            recalc();
        }
    });
    if (addBtn) addBtn.addEventListener('click', function() {
        var rows = body.querySelectorAll('.rcp-item-row');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelector('input[name="item_name[]"]').value = '';
        clone.querySelector('input[name="item_description[]"]').value = '';
        clone.querySelector('select[name="item_type[]"]').value = 'product';
        clone.querySelector('.rcp-qty-input').disabled = false;
        clone.querySelector('.rcp-qty-input').style.opacity = '1';
        clone.querySelector('.rcp-qty-input').value = 1;
        clone.querySelector('.rcp-price-input').value = '';
        clone.querySelector('.rcp-amount-input').value = '0.00';
        body.appendChild(clone);
        recalc();
    });
    amountField.addEventListener('input', function() {
        receivedTouched = amountField.value.trim() !== '';
        recalc();
    });
    if (invoiceField) invoiceField.addEventListener('input', function() {
        updateEditorState();
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookupInvoice, 400);
    });
    updateEditorState();
    if (getMode() === 'invoice') {
        lookupInvoice();
    } else {
        recalc();
    }

    var fw = document.getElementById('formWrapper');
    if (fw && fw.classList.contains('show')) {
        window.scrollTo({ top: fw.offsetTop - 20, behavior: 'smooth' });
    }
})();
