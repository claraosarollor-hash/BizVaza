document.addEventListener('DOMContentLoaded', () => {
    // === DOM Element Selectors ===
    const itemsTable = document.querySelector('.items-table');
    const btnAddItem = document.querySelector('.btn-add');
    const subtotalElement = document.querySelector('.summary-row:nth-child(1) .summary-value');
    const discountValueElement = document.querySelector('.summary-value-discount');
    const totalAmountElement = document.querySelector('.total-price');
    
    const discountTypeSelect = document.querySelector('.discount-box select');
    const discountInput = document.querySelector('.discount-box input');
    const totalAmountHidden = document.querySelector('input[name="total_amount"]');

    // === Core Calculation Logic ===
    function calculateInvoice() {
        let subtotal = 0;
        const rows = itemsTable.querySelectorAll('.table-row');

        rows.forEach(row => {
            const qtyInput = row.querySelector('.qty-input');
            const priceInput = row.querySelector('.price-input');
            const amountInput = row.querySelector('.amount-input');

            // Parse values safely, default to 0 if empty or invalid
            const qty = parseFloat(qtyInput.value) || 0;
            const price = parseFloat(priceInput.value.replace(/,/g, '')) || 0;
            
            // Calculate row total (rounded to the kobo to match server recompute)
            const amount = Math.round(qty * price * 100) / 100;
            subtotal += amount;

            // Update row total input field (formatted to 2 decimal places)
            if (amountInput) {
                amountInput.value = amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        });

        // Update Subtotal Display
        subtotalElement.textContent = `₦${subtotal.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;

        // Calculate Discount
        let discountAmount = 0;
        const discountRate = parseFloat(discountInput.value) || 0;

        if (discountTypeSelect.value === 'Percentage') {
            discountAmount = subtotal * (discountRate / 100);
        } else {
            // Fixed Amount Type
            discountAmount = discountRate;
        }

        // Round to the kobo so display, total, and stored amount all agree
        discountAmount = Math.round(discountAmount * 100) / 100;

        // Ensure discount doesn't exceed subtotal
        if (discountAmount > subtotal) {
            discountAmount = subtotal;
        }

        // Update Discount Display
        discountValueElement.textContent = `₦${discountAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        // Calculate & Update Grand Total
        const grandTotal = subtotal - discountAmount;
        totalAmountElement.textContent = `₦${grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
        if (totalAmountHidden) {
            totalAmountHidden.value = grandTotal.toFixed(2);
        }
    }

    // === Event Delegation for Table Rows ===
    // Listens for text input adjustments dynamically across all current and new fields
    itemsTable.addEventListener('input', (e) => {
        if (e.target.classList.contains('qty-input') || e.target.classList.contains('price-input')) {
            calculateInvoice();
        }
    });

    // === Toggle qty visibility based on item type ===
    function applyTypeState(select) {
        const row = select.closest('.table-row');
        const qtyInput = row.querySelector('.qty-input');
        if (select.value === 'service') {
            qtyInput.disabled = true;
            qtyInput.value = 1;
            qtyInput.style.opacity = '0.5';
        } else {
            qtyInput.disabled = false;
            qtyInput.style.opacity = '1';
        }
        calculateInvoice();
    }
    itemsTable.addEventListener('change', (e) => {
        if (e.target.classList.contains('item-type')) {
            applyTypeState(e.target);
        }
    });

    // === Add New Item Row ===
    btnAddItem.addEventListener('click', (e) => {
        e.preventDefault(); // Prevents page reload maneuvers

        const newRow = document.createElement('div');
        newRow.className = 'table-row';
        newRow.innerHTML = `
            <input type="text" name="item_name[]" placeholder="Item Name" list="items-list" oninput="fillItemFields(this)" onchange="fillItemFields(this)" data-voice>
            <input type="text" name="item_description[]" placeholder="Description" data-voice>
            <select name="item_type[]" class="item-type">
                <option value="product">Product</option>
                <option value="service">Service</option>
            </select>
            <input type="number" name="item_qty[]" class="qty-input" value="1" min="1">
            <input type="text" name="item_price[]" class="price-input" placeholder="0.00">
            <input type="text" name="item_amount[]" class="amount-input" value="0.00" readonly>
            <button class="btn-delete" type="button" title="Delete Item">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
        `;

        itemsTable.appendChild(newRow);
        calculateInvoice();
    });

    // === Delete Row Handling via Delegation ===
    itemsTable.addEventListener('click', (e) => {
        // Traverses path up to detect click element target safely
        const deleteBtn = e.target.closest('.btn-delete');
        if (deleteBtn) {
            const row = deleteBtn.closest('.table-row');
            
            // Protect against deleting every single row (keep at least one row active)
            if (itemsTable.querySelectorAll('.table-row').length > 1) {
                row.remove();
                calculateInvoice();
            } else {
                alert("An invoice must contain at least one line item.");
            }
        }
    });

    // === Discount Controls Value Changes ===
    discountTypeSelect.addEventListener('change', calculateInvoice);
    discountInput.addEventListener('input', calculateInvoice);

    // === Format Numbers on Blur (Optional Clean UI Touch) ===
    itemsTable.addEventListener('blur', (e) => {
        if (e.target.classList.contains('price-input')) {
            const val = parseFloat(e.target.value.replace(/,/g, ''));
            if (!isNaN(val)) {
                e.target.value = val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }
    }, true); // Uses capturing phase to catch blur events natively

    // === Initialize First Load Calculations ===
    // Apply type state to existing rows
    document.querySelectorAll('.item-type').forEach(applyTypeState);
    calculateInvoice();
    window.calculateInvoice = calculateInvoice;

    // === Voice Recognition (Microphone Button) ===
    const micBtn = document.querySelector('.mic-action-btn');

    // Check if the user's browser supports voice speech recognition
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition && micBtn) {
        const recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.lang = 'en-US';
        recognition.interimResults = false;

        // When the microphone is clicked, start listening
        micBtn.addEventListener('click', (e) => {
            e.preventDefault();
            recognition.start();
            micBtn.style.backgroundColor = '#FEE2E2'; /* Turns button light red when recording */
            micBtn.style.borderColor = '#EF4444';
        });

        // Process the voice results
        recognition.onresult = (event) => {
            const voiceText = event.results[0][0].transcript;
            
            // Example action: Alert what you said. 
            // You can route this text directly into an input field!
            alert(`You said: "${voiceText}".`);
        };

        // Reset button style when voice recording ends
        recognition.onend = () => {
            micBtn.style.backgroundColor = '';
            micBtn.style.borderColor = '';
        };

        recognition.onerror = (event) => {
            console.error("Speech recognition error error: ", event.error);
            micBtn.style.backgroundColor = '';
            micBtn.style.borderColor = '';
        };
    } else {
        console.log("Voice Recognition is not supported in this browser window.");
    }
});