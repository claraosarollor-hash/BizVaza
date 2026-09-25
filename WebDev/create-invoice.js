document.addEventListener('DOMContentLoaded', () => {

    // === 1. SPLIT BUTTON DROPDOWN CONTROL ===
    const arrowBtn = document.querySelector('.btn-primary-arrow');
    if (arrowBtn) {
        arrowBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Mocking a dropdown options layout menu action
            alert('Opening distribution options: Send via Email, Export Meta Data, or Schedule Delivery.');
        });
    }

    // === 3. DOWNLOAD MENU (PDF / Image) ===
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.download-wrap')) closeDownloadMenu();
    });

    // === 4. WHATSAPP SHARE WITH IMAGE ===
    // handled by global function shareViaWhatsApp(btn) defined below

    // === 5. SCALE-TO-FIT: fixed desktop layout, scaled down on small screens ===
    fitSheet();
});

function fitSheet() {
    const sheet = document.querySelector('.invoice-printable-sheet');
    if (!sheet) return;
    const wrap = sheet.parentElement;
    if (!wrap || !wrap.classList.contains('sheet-scroll')) return;
    const avail = wrap.clientWidth;
    if (avail <= 0) return;
    const scale = Math.min(1, avail / 720);
    if (scale < 1) {
        sheet.style.transform = 'scale(' + scale + ')';
        wrap.style.height = Math.round(sheet.offsetHeight * scale) + 'px';
    } else {
        sheet.style.transform = '';
        wrap.style.height = '';
    }
}

window.addEventListener('load', fitSheet);
window.addEventListener('resize', fitSheet);

function closeDownloadMenu() {
    const menu = document.querySelector('.download-menu');
    if (menu) menu.classList.remove('show');
}

function toggleDownloadMenu(e) {
    if (e) e.stopPropagation();
    const menu = document.querySelector('.download-menu');
    if (menu) menu.classList.toggle('show');
}

function downloadPdf(e) {
    if (e) e.preventDefault();
    closeDownloadMenu();
    window.print();
}

function downloadImage(e) {
    if (e) e.preventDefault();
    closeDownloadMenu();
    const sheet = document.querySelector('.invoice-printable-sheet');
    if (!sheet) return;
    const idText = document.querySelector('.invoice-id-number');
    const baseName = (idText ? idText.textContent.trim() : 'bizvaza-document').replace(/[^a-zA-Z0-9-_]/g, '') || 'bizvaza-document';

    function downloadPng(canvas) {
        canvas.toBlob(function(blob) {
            if (!blob) return;
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = baseName + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() { URL.revokeObjectURL(url); }, 4000);
        }, 'image/png');
    }

    if (typeof html2canvas !== 'undefined') {
        document.body.classList.add('js-capture');
        html2canvas(sheet, { scale: 2, useCORS: true }).then(function(canvas) {
            document.body.classList.remove('js-capture');
            downloadPng(canvas);
        }).catch(function() {
            document.body.classList.remove('js-capture');
        });
    }
}

function shareViaWhatsApp(btn) {
    const phone = btn.dataset.phone;
    const invoiceNum = document.querySelector('.invoice-id-number').textContent.trim();
    const sheet = document.querySelector('.invoice-printable-sheet');

    function fallback(phone) {
        var clean = phone ? phone.replace(/[^0-9]/g, '') : '';
        var url = clean ? 'https://wa.me/' + clean : 'https://wa.me/';
        window.open(url, '_blank');
    }

    if (typeof html2canvas !== 'undefined' && sheet) {
        document.body.classList.add('js-capture');
        html2canvas(sheet, { scale: 2, useCORS: true }).then(function(canvas) {
            document.body.classList.remove('js-capture');
            canvas.toBlob(function(blob) {
                if (!blob) { fallback(phone); return; }
                var file = new File([blob], 'invoice-' + invoiceNum.replace(/[^a-zA-Z0-9-]/g, '') + '.png', { type: 'image/png' });
                if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({ files: [file] }).catch(function() {
                        fallback(phone);
                    });
                } else {
                    fallback(phone);
                }
            });
        }).catch(function() {
            document.body.classList.remove('js-capture');
            fallback(phone);
        });
    } else {
        fallback(phone);
    }
}