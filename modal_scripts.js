/**
 * Load product details into modal inputs
 */
function editProduct(stockcode) {
    fetch(`get_product.php?stockcode=${encodeURIComponent(stockcode)}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const p = data.product;

                // Populate Form Fields
                document.getElementById('edit_Stockcode').value        = p.Stockcode || '';
                document.getElementById('edit_Barcode').value          = p.Barcode || '';
                document.getElementById('edit_Description').value      = p.Description || '';
                document.getElementById('edit_Description2').value     = p.Description2 || '';
                document.getElementById('edit_MainDepName').value     = p.MainDepName || '';
                document.getElementById('edit_SubDepName').value      = p.SubDepName || '';
                
                // Inventory & Locations
                document.getElementById('edit_SOH').value              = p.SOH || 0;
                document.getElementById('edit_MinimumStock').value     = p.MinimumStock || 0;
                document.getElementById('edit_MaximumStock').value     = p.MaximumStock || 0;
                document.getElementById('edit_ShelfLocation').value    = p.ShelfLocation || '';
                document.getElementById('edit_BinLocation').value      = p.BinLocation || '';

                // Costs & Prices
                document.getElementById('edit_ExclCost').value        = parseFloat(p.ExclCost || 0).toFixed(2);
                document.getElementById('edit_InclCost').value        = parseFloat(p.InclCost || 0).toFixed(2);
                document.getElementById('edit_VatRate').value         = parseFloat(p.VatRate || 0).toFixed(2);
                document.getElementById('edit_Markup').value          = parseFloat(p.Markup || 0).toFixed(2);
                document.getElementById('edit_ExclSellPrice').value   = parseFloat(p.ExclSellPrice || 0).toFixed(2);
                document.getElementById('edit_InclSellPrice').value   = parseFloat(p.InclSellPrice || 0).toFixed(2);
                
                if (document.getElementById('edit_GPPercentage')) {
                    document.getElementById('edit_GPPercentage').value = parseFloat(p.GPPercentage || 0).toFixed(2) + '%';
                }

                // Switches & Toggles
                document.getElementById('edit_ActiveProduct').checked  = parseInt(p.ActiveProduct) === 1;
                document.getElementById('edit_AllowZeroPrice').checked = parseInt(p.AllowZeroPrice) === 1;
                if (document.getElementById('edit_ScaleItem')) {
                    document.getElementById('edit_ScaleItem').checked  = parseInt(p.ScaleItem) === 1;
                }

                // Show Modal (Bootstrap 5 syntax)
                const editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
                editModal.show();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => console.error('Fetch error:', err));
}

/**
 * Real-time dynamic pricing calculation inside modal
 */
function recalculateModalPricing(source) {
    const costInput     = document.getElementById('edit_ExclCost');
    const markupInput   = document.getElementById('edit_Markup');
    const exclSellInput = document.getElementById('edit_ExclSellPrice');
    const inclSellInput = document.getElementById('edit_InclSellPrice');
    const inclCostInput = document.getElementById('edit_InclCost');
    const vatInput      = document.getElementById('edit_VatRate');
    const gpPctInput    = document.getElementById('edit_GPPercentage');

    let cost     = parseFloat(costInput.value) || 0;
    let vat      = parseFloat(vatInput.value) || 0;
    let markup   = parseFloat(markupInput.value) || 0;
    let exclSell = parseFloat(exclSellInput.value) || 0;

    if (source === 'cost' || source === 'markup') {
        exclSell = cost * (1 + (markup / 100));
        exclSellInput.value = exclSell.toFixed(2);
    } else if (source === 'exclSell') {
        markup = cost > 0 ? ((exclSell - cost) / cost) * 100 : 0;
        markupInput.value = markup.toFixed(2);
    }

    let inclSell = exclSell * (1 + (vat / 100));
    let inclCost = cost * (1 + (vat / 100));
    let gpValue  = exclSell - cost;
    let gpPct    = exclSell > 0 ? (gpValue / exclSell) * 100 : 0;

    inclSellInput.value = inclSell.toFixed(2);
    inclCostInput.value = inclCost.toFixed(2);
    if (gpPctInput) {
        gpPctInput.value = gpPct.toFixed(2) + '%';
    }
}

// Bind live event listeners
document.addEventListener('DOMContentLoaded', () => {
    ['edit_ExclCost', 'edit_Markup', 'edit_VatRate'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => recalculateModalPricing('cost'));
    });

    const exclSellEl = document.getElementById('edit_ExclSellPrice');
    if (exclSellEl) {
        exclSellEl.addEventListener('input', () => recalculateModalPricing('exclSell'));
    }

    // AJAX Form Submission
    const form = document.getElementById('editProductForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('update_product.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('Product saved successfully!');
                    location.reload();
                } else {
                    alert('Save failed: ' + data.message);
                }
            })
            .catch(err => console.error('Save error:', err));
        });
    }
});