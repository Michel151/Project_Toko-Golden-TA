<script>
(() => {
const config = window.stockTransferConfig;
const products = config.products;
let activeCatalogCategory = 'all';
const transferItems = Array.isArray(config.items) ? config.items : Object.values(config.items || {});
const fromLocation = document.getElementById('fromLocation');
const toLocation = document.getElementById('toLocation');
let acceptedSource = fromLocation.value;
let busy = false;
let stockError = '';
let sourceNotice = '';
const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 });

function stockAt(product, locationId = acceptedSource) {
    const stock = product.stocks.find(s => String(s.location_id) === String(locationId));
    return Math.max(0, Number(stock ? (product.is_expirable ? stock.sellable_quantity : stock.quantity) : 0));
}

function itemProblem(item) {
    const product = products.find(p => p.id == item.product_id);
    if (!product) return 'Barang sudah tidak aktif atau tidak tersedia.';
    const unit = product.product_units.find(u => u.id == item.product_unit_id);
    if (!unit) return 'Pilih satuan yang sesuai dengan barang.';
    const quantity = Number(item.quantity);
    if (!Number.isFinite(quantity) || quantity < 0.01) return 'Jumlah minimal 0,01.';
    if (!acceptedSource) return 'Pilih lokasi asal.';
    const available = stockAt(product);
    if (available <= 0) return 'Stok layak transfer kosong di lokasi asal.';
    if (quantity * Number(unit.conversion_to_base) > available + 0.00001) {
        return `Stok asal kurang. Tersedia ${formatStok(available, product)}.`;
    }
    return '';
}

function updateTransferState() {
    const invalid = transferItems.filter(item => itemProblem(item)).length;
    const validRoute = acceptedSource && toLocation.value && acceptedSource !== toLocation.value;
    document.getElementById('transferComposer').disabled = busy || !validRoute;
    document.getElementById('saveTransfer').disabled = busy || !!stockError || !validRoute || !transferItems.length || invalid > 0;
    document.getElementById('transferInfoFields').disabled = busy;
    document.getElementById('transferItemsFields').disabled = busy;
    document.getElementById('addTransferItem').disabled = transferItems.length >= 100;
    document.getElementById('transferItemCount').textContent = transferItems.length;
    const origin = fromLocation.selectedOptions[0]?.textContent || '';
    const destination = toLocation.selectedOptions[0]?.textContent || '';
    document.getElementById('transferRoute').textContent = validRoute ? `${origin} \u2192 ${destination}` : 'Lokasi transfer belum lengkap';
    const alert = document.getElementById('transferStockAlert');
    const text = busy ? 'Memproses lokasi dan pemeriksaan stok...' : stockError || (invalid ? `${invalid} barang perlu diperbaiki sebelum transfer disimpan.` : sourceNotice);
    alert.textContent = text;
    alert.className = `alert py-2 ${text ? (stockError || invalid ? 'alert-danger' : 'alert-info') : 'd-none'}`;
    document.getElementById('retryTransferStock').classList.toggle('d-none', !stockError);
}

function updateDestinationOptions() {
    for (const option of toLocation.options) option.disabled = !!option.value && option.value === acceptedSource;
    if (toLocation.value && toLocation.value === acceptedSource) {
        toLocation.value = '';
        sourceNotice = 'Pilih ulang lokasi tujuan karena sama dengan lokasi asal yang baru.';
    }
}

async function refreshSource(locationId) {
    if (busy) return;
    const ids = [...new Set(transferItems.map(item => Number(item.product_id)))];
    const currentProduct = Number(document.getElementById('productSelect').value);
    if (currentProduct && !ids.includes(currentProduct) && ids.length < 100) ids.push(currentProduct);
    if (!locationId) {
        fromLocation.value = acceptedSource = '';
        updateDestinationOptions(); updateSourceProducts(); updateStockInfo(); renderTransferItems(); return;
    }
    busy = true; updateTransferState();
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 15000);
    try {
        if (ids.length) {
            const url = new URL(config.availabilityUrl, window.location.origin);
            url.searchParams.set('from_location_id', locationId);
            ids.forEach(id => url.searchParams.append('product_ids[]', id));
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin', signal: abort.signal });
            if (!response.ok) throw new Error('Stock request failed');
            const data = await response.json();
            if (String(data.from_location_id) !== String(locationId) || !Array.isArray(data.products) ||
                data.products.length !== ids.length || ids.some(id => !data.products.some(row => row.id === id)) ||
                data.products.some(row => !Number.isFinite(Number(row.quantity)) || !Number.isFinite(Number(row.sellable_quantity)))) throw new Error('Incomplete stock response');
            for (const row of data.products) {
                const product = products.find(p => p.id === row.id);
                const stock = product.stocks.find(s => String(s.location_id) === String(locationId));
                if (stock) Object.assign(stock, row);
                else product.stocks.push({ ...row, location_id: Number(locationId) });
            }
        }
        fromLocation.value = acceptedSource = String(locationId);
        stockError = '';
        sourceNotice = transferItems.length ? 'Stok seluruh barang sudah diperiksa ulang. Jumlah dan satuan tetap dipertahankan.' : '';
        updateDestinationOptions();
        updateSourceProducts();
        updateStockInfo(); renderTransferItems();
    } catch (_) {
        fromLocation.value = acceptedSource;
        stockError = 'Stok terbaru belum dapat diperiksa. Lokasi asal tetap seperti semula. Periksa ulang stok sebelum menyimpan.';
    } finally {
        clearTimeout(timeout); busy = false; updateTransferState();
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
}

function formatStok(rawQty, product) {
    if (product.product_units && product.product_units.length) {
        const units = [...product.product_units].sort((a, b) => b.conversion_to_base - a.conversion_to_base);
        let remaining = rawQty;
        const parts = [];

        units.forEach((unit, index) => {
            const conversion = Number(unit.conversion_to_base || 0);
            if (conversion <= 0) return;

            const amount = index === units.length - 1 ? Number((remaining / conversion).toFixed(4)) : Math.floor(remaining / conversion);
            if (amount > 0) {
                parts.push(`${numberFormat.format(amount)} ${unit.name}`);
                remaining = remaining % conversion;
            }
        });

        return parts.length ? parts.join(' ') : `0 ${units[units.length - 1].name}`;
    }

    if (product.can_retail && product.conversion_factor > 0) {
        const whole = Math.floor(rawQty / product.conversion_factor);
        const sisa  = Math.round(rawQty % product.conversion_factor);
        return sisa > 0
            ? `${whole} ${product.unit_name_buy} ${sisa} ${product.unit_name_retail}`
            : `${whole} ${product.unit_name_buy}`;
    }
    return `${rawQty.toFixed(2)} ${product.unit_name_buy}`;
}

function productStockTotal(product) {
    return stockAt(product);
}

function sourceProducts() {
    return acceptedSource ? products.filter(product => stockAt(product) > 0) : [];
}

function updateSourceProducts() {
    const selected = products.find(product => product.id == document.getElementById('productSelect').value);
    if (selected && (!acceptedSource || stockAt(selected) <= 0)) {
        document.getElementById('productSearch').value = '';
        clearProductSelection();
    }
    closeProductResults();
    activeCatalogCategory = 'all';
    renderCatalogCategories();
    renderCatalogProducts();
    const origin = fromLocation.selectedOptions[0]?.textContent || '';
    document.getElementById('transferProductCatalogInfo').textContent = acceptedSource
        ? `Hanya barang dengan stok layak transfer di ${origin}. Cari berdasarkan nama atau barcode.`
        : 'Pilih lokasi asal terlebih dahulu.';
}

function matchingProducts(query, limit = 8) {
    const keyword = query.trim().toLowerCase();
    const available = sourceProducts();
    const matches = keyword
        ? available.filter(product =>
            String(product.name || '').toLowerCase().includes(keyword) ||
            String(product.barcode || '').toLowerCase().includes(keyword)
        )
        : available;

    return matches.slice(0, limit);
}

function closeProductResults() {
    const productResults = document.getElementById('productResults');
    productResults.classList.add('d-none');
    productResults.innerHTML = '';
}

function renderProductResults() {
    const productSearch = document.getElementById('productSearch');
    const productResults = document.getElementById('productResults');
    const matches = matchingProducts(productSearch.value);

    productResults.classList.remove('d-none');
    if (!matches.length) {
        productResults.innerHTML = '<div class="transfer-product-empty">Tidak ada barang dengan stok layak transfer yang sesuai di lokasi asal.</div>';
        return;
    }

    productResults.innerHTML = matches.map(product => `
        <button type="button" class="transfer-product-result" data-product-id="${escapeHtml(product.id)}">
            <span class="transfer-product-name">${escapeHtml(product.name)}</span>
            <span class="transfer-product-meta">${product.barcode ? `Barcode: ${escapeHtml(product.barcode)}` : 'Tanpa barcode'}</span>
        </button>
    `).join('');
}

function chooseProduct(product) {
    const productSelect = document.getElementById('productSelect');
    const productSearch = document.getElementById('productSearch');
    if (!product || busy || !acceptedSource || stockAt(product) <= 0) return;

    productSelect.value = product.id;
    productSearch.value = product.name;
    productSearch.setCustomValidity('');
    closeProductResults();
    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
}

function clearProductSelection() {
    const productSelect = document.getElementById('productSelect');
    const productSearch = document.getElementById('productSearch');
    productSelect.value = '';
    productSearch.setCustomValidity(productSearch.value.trim() ? 'Pilih barang dari daftar pencarian.' : '');
    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
}

function selectProductFromSearch() {
    const productSearch = document.getElementById('productSearch');
    const query = productSearch.value.trim().toLowerCase();
    const product = sourceProducts().find(p =>
        String(p.name || '').trim().toLowerCase() === query ||
        String(p.barcode || '').trim().toLowerCase() === query
    );

    if (product) {
        chooseProduct(product);
    } else if (query) {
        clearProductSelection();
    }
}

function updateStockInfo() {
    const productId  = document.getElementById('productSelect').value;
    const locationId = document.getElementById('fromLocation').value;
    const infoEl     = document.getElementById('stockInfo');
    const unitSelect = document.getElementById('unitSelect');
    const qtyHelp    = document.getElementById('qtyHelp');

    if (!productId) {
        infoEl.textContent = '';
        unitSelect.innerHTML = '<option value="stored">unit</option>';
        qtyHelp.textContent = '';
        updateQuantityLimit();
        return;
    }

    const product = products.find(p => p.id == productId);
    if (!product) return;

    const previousUnit = unitSelect.value;
    unitSelect.innerHTML = '';
    if (product.product_units && product.product_units.length) {
        product.product_units.forEach(unit => {
            const option = new Option(unit.name, unit.id);
            option.dataset.conversion = unit.conversion_to_base;
            unitSelect.add(option);
        });
    } else {
        unitSelect.add(new Option(product.unit_name_buy, 'stored'));
    }
    if ([...unitSelect.options].some(option => option.value === previousUnit)) {
        unitSelect.value = previousUnit;
    }

    if (!locationId) {
        infoEl.textContent = '';
        qtyHelp.textContent = '';
        updateQuantityLimit();
        return;
    }

    const stock = product.stocks.find(s => s.location_id == locationId);
    const rawQty = stock ? (product.is_expirable ? stock.sellable_quantity : stock.quantity) : 0;

    const displayStok = formatStok(rawQty, product);
    infoEl.textContent = product.is_expirable
        ? `Stok layak transfer: ${displayStok}`
        : `Stok tersedia: ${displayStok}`;

    const selectedUnit = product.product_units?.find(unit => String(unit.id) === String(unitSelect.value));
    const baseUnit = product.product_units?.find(unit => unit.is_base_unit) || product.product_units?.[product.product_units.length - 1];
    if (selectedUnit && baseUnit && selectedUnit.conversion_to_base > 1) {
        qtyHelp.textContent = `1 ${selectedUnit.name} = ${selectedUnit.conversion_to_base.toLocaleString('id-ID')} ${baseUnit.name}. Pilih satuan sesuai jumlah yang ditransfer.`;
    } else {
        qtyHelp.textContent = '';
    }

    updateQuantityLimit();
}

function updateQuantityLimit() {
    const product = products.find(p => p.id == document.getElementById('productSelect').value);
    const stock = product?.stocks.find(s => s.location_id == document.getElementById('fromLocation').value);
    const qtyInput = document.getElementById('qtyInput');
    if (!product || !document.getElementById('fromLocation').value) { qtyInput.removeAttribute('max'); return; }

    const selectedUnit = product.product_units?.find(unit => String(unit.id) === String(document.getElementById('unitSelect').value));
    const conversion = selectedUnit ? Number(selectedUnit.conversion_to_base || 1) : 1;
    const availableQty = stock ? (product.is_expirable ? stock.sellable_quantity : stock.quantity) : 0;
    const maxQty = availableQty / conversion;
    qtyInput.max = maxQty;
    qtyInput.step = '0.01';
}

function renderCatalogCategories() {
    const categories = [...new Map(sourceProducts().map(product => [
        String(product.category_id ?? 'uncategorized'),
        product.category_name || 'Tanpa Kategori'
    ])).entries()];

    document.getElementById('catalogCategories').innerHTML = [
        `<button type="button" class="nav-link active" data-category="all">Semua</button>`,
        ...categories.map(([id, name]) =>
            `<button type="button" class="nav-link" data-category="${escapeHtml(id)}">${escapeHtml(name)}</button>`
        )
    ].join('');
}

function renderCatalogProducts() {
    const query = document.getElementById('catalogSearch').value.trim().toLowerCase();
    const filtered = sourceProducts().filter(product => {
        const categoryId = String(product.category_id ?? 'uncategorized');
        const matchesCategory = activeCatalogCategory === 'all' || categoryId === activeCatalogCategory;
        const matchesSearch = !query || String(product.name || '').toLowerCase().includes(query) ||
            String(product.barcode || '').toLowerCase().includes(query);
        return matchesCategory && matchesSearch;
    });

    document.getElementById('catalogProducts').innerHTML = filtered.map(product => `
        <div class="col-md-6 col-lg-4">
            <button type="button" class="transfer-catalog-product w-100" data-product-id="${escapeHtml(product.id)}">
                <div class="d-flex flex-column align-items-start gap-1 mb-2">
                    <span class="product-name text-break">${escapeHtml(product.name)}</span>
                    <span class="badge bg-light text-secondary border text-wrap text-start mw-100">${escapeHtml(product.category_name || 'Tanpa Kategori')}</span>
                </div>
                <div class="small text-muted mb-2">${product.barcode ? `Barcode: ${escapeHtml(product.barcode)}` : 'Tanpa barcode'}</div>
                <div class="d-flex justify-content-between align-items-end">
                    <span class="small text-muted">${product.is_expirable ? 'Stok layak transfer' : 'Stok tersedia'}</span>
                    <span class="small text-success">${escapeHtml(formatStok(productStockTotal(product), product))}</span>
                </div>
            </button>
        </div>
    `).join('');

    document.getElementById('catalogEmpty').classList.toggle('d-none', filtered.length > 0);
}

document.getElementById('productSearch').addEventListener('input', function() {
    const query = this.value.trim().toLowerCase();
    const product = sourceProducts().find(p =>
        String(p.name || '').trim().toLowerCase() === query ||
        String(p.barcode || '').trim().toLowerCase() === query
    );

    if (product) {
        chooseProduct(product);
        return;
    }

    clearProductSelection();
    if (query) {
        renderProductResults();
    } else {
        closeProductResults();
    }
});
document.getElementById('productSearch').addEventListener('focus', renderProductResults);
document.getElementById('productSearch').addEventListener('blur', () => setTimeout(closeProductResults, 120));
document.getElementById('productSearch').addEventListener('change', selectProductFromSearch);
document.getElementById('productSearch').addEventListener('keydown', function(event) {
    if (event.key !== 'Enter') return;
    const firstResult = document.getElementById('productResults').querySelector('[data-product-id]');
    if (!firstResult || document.getElementById('productResults').classList.contains('d-none')) return;
    event.preventDefault();
    chooseProduct(products.find(p => p.id == firstResult.dataset.productId));
});
document.getElementById('productResults').addEventListener('mousedown', event => event.preventDefault());
document.getElementById('productResults').addEventListener('click', function(event) {
    const button = event.target.closest('[data-product-id]');
    if (!button) return;
    chooseProduct(products.find(p => p.id == button.dataset.productId));
});
document.getElementById('openProductCatalog').addEventListener('click', function() {
    activeCatalogCategory = 'all';
    document.getElementById('catalogSearch').value = '';
    renderCatalogCategories();
    renderCatalogProducts();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('transferProductCatalogModal')).show();
    document.getElementById('transferProductCatalogModal').addEventListener('shown.bs.modal', () => {
        document.getElementById('catalogSearch').focus();
    }, { once: true });
});
document.getElementById('catalogSearch').addEventListener('input', renderCatalogProducts);
document.getElementById('catalogCategories').addEventListener('click', function(event) {
    const button = event.target.closest('[data-category]');
    if (!button) return;
    activeCatalogCategory = button.dataset.category;
    this.querySelectorAll('.nav-link').forEach(tab => tab.classList.toggle('active', tab === button));
    renderCatalogProducts();
});
document.getElementById('catalogProducts').addEventListener('click', function(event) {
    const button = event.target.closest('[data-product-id]');
    if (!button) return;
    chooseProduct(products.find(p => p.id == button.dataset.productId));
    bootstrap.Modal.getInstance(document.getElementById('transferProductCatalogModal')).hide();
});

document.getElementById('productSelect').addEventListener('change', updateStockInfo);
fromLocation.addEventListener('change', async () => {
    const candidate = fromLocation.value;
    fromLocation.value = acceptedSource;
    if (busy || candidate === acceptedSource) return;
    busy = true;
    updateTransferState();
    let accepted = false;
    try {
        accepted = !transferItems.length || await window.appDialog.confirm({
            title: 'Ganti lokasi asal transfer?',
            text: 'Seluruh barang akan memakai lokasi asal yang baru. Stok setiap barang diperiksa ulang; jumlah dan satuan tidak diubah.',
            confirmText: 'Ganti dan Periksa Stok',
            details: [['Asal semula', [...fromLocation.options].find(o => o.value === acceptedSource)?.textContent || '-'],
                ['Asal baru', [...fromLocation.options].find(o => o.value === candidate)?.textContent || 'Belum dipilih'], ['Jumlah jenis barang', String(transferItems.length)]]
        });
    } finally {
        busy = false; updateTransferState();
    }
    if (accepted) await refreshSource(candidate);
});

toLocation.addEventListener('change', () => { sourceNotice = ''; updateTransferState(); });
document.getElementById('retryTransferStock').addEventListener('click', () => refreshSource(acceptedSource));
document.getElementById('unitSelect').addEventListener('change', function() {
    updateStockInfo();
    updateQuantityLimit();
});
const initialProduct = products.find(p => p.id == document.getElementById('productSelect').value);
if (initialProduct) {
    document.getElementById('productSearch').value = initialProduct.name;
}
updateStockInfo();

function validateTransferRows() {
    document.querySelectorAll('#transferItems tr[data-index]').forEach(row => {
        const item = transferItems[Number(row.dataset.index)];
        const problem = itemProblem(item);
        row.classList.toggle('table-danger', !!problem);
        row.querySelector('[data-item-problem]').textContent = problem;
        const input = row.querySelector('.item-quantity');
        if (input) input.setCustomValidity(problem);
    });
    updateTransferState();
}

function renderTransferItems() {
    document.getElementById('transferItems').innerHTML = transferItems.map((item, index) => {
        const product = products.find(p => p.id == item.product_id);
        const remove = `<button type="button" class="btn btn-outline-danger remove-item" title="Hapus barang" aria-label="Hapus ${escapeHtml(product?.name || 'barang tidak tersedia')}"><i class="bi bi-trash"></i></button>`;
        if (!product) return `<tr data-index="${index}"><td colspan="4">Barang tidak tersedia<div class="small text-danger" data-item-problem></div></td><td>${remove}</td></tr>`;
        const available = stockAt(product);
        const unit = product.product_units.find(u => u.id == item.product_unit_id);
        const max = acceptedSource ? `max="${Number((available / Number(unit?.conversion_to_base || 1)).toFixed(4))}"` : '';
        return `<tr data-index="${index}">
            <td class="fw-semibold">${escapeHtml(product.name)}<input type="hidden" name="items[${index}][product_id]" value="${escapeHtml(product.id)}"><div class="small text-danger fw-normal" data-item-problem></div></td>
            <td class="small">${acceptedSource ? escapeHtml(formatStok(available, product)) : '-'}</td>
            <td><input aria-label="Jumlah ${escapeHtml(product.name)}" type="number" class="form-control item-quantity" name="items[${index}][quantity]" value="${escapeHtml(item.quantity)}" min="0.01" step="0.01" ${max} required></td>
            <td><select aria-label="Satuan ${escapeHtml(product.name)}" class="form-select item-unit" name="items[${index}][product_unit_id]" required>${!unit ? '<option value="">Pilih satuan</option>' : ''}${product.product_units.map(u => `<option value="${escapeHtml(u.id)}" ${u.id == item.product_unit_id ? 'selected' : ''}>${escapeHtml(u.name)}</option>`).join('')}</select></td>
            <td>${remove}</td>
        </tr>`;
    }).join('');
    document.getElementById('transferItemsEmpty').classList.toggle('d-none', transferItems.length > 0);
    validateTransferRows();
}

function addTransferItem() {
    if (busy || !acceptedSource || !toLocation.value || toLocation.value === acceptedSource) return false;
    if (transferItems.length >= 100) {
        window.appDialog.notice({ title: 'Batas barang transfer', text: 'Maksimal 100 jenis barang dalam satu transfer.' });
        return false;
    }
    const product = products.find(p => p.id == document.getElementById('productSelect').value);
    const search = document.getElementById('productSearch');
    const qty = document.getElementById('qtyInput');
    if (!product) {
        search.setCustomValidity('Pilih barang dari daftar pencarian.');
        search.reportValidity();
        return false;
    }
    if (transferItems.some(item => item.product_id == product.id)) {
        search.setCustomValidity('Barang sudah ditambahkan. Ubah jumlah pada daftar barang.');
        search.reportValidity();
        return false;
    }
    const unitId = document.getElementById('unitSelect').value;
    if (!product.product_units.some(unit => unit.id == unitId)) {
        search.setCustomValidity('Barang belum memiliki satuan transfer.');
        search.reportValidity();
        return false;
    }
    if (!qty.value || !qty.checkValidity()) { qty.reportValidity(); return false; }
    transferItems.push({ product_id: product.id, quantity: qty.value, product_unit_id: unitId });
    search.value = '';
    clearProductSelection();
    qty.value = '1';
    renderTransferItems();
    return true;
}

document.getElementById('addTransferItem').addEventListener('click', addTransferItem);
document.getElementById('transferItems').addEventListener('input', event => {
    const row = event.target.closest('[data-index]');
    if (row && event.target.classList.contains('item-quantity')) {
        transferItems[row.dataset.index].quantity = event.target.value;
        validateTransferRows();
    }
});
document.getElementById('transferItems').addEventListener('change', event => {
    const row = event.target.closest('[data-index]');
    if (row && event.target.classList.contains('item-unit')) {
        transferItems[row.dataset.index].product_unit_id = event.target.value;
        renderTransferItems();
    }
});
document.getElementById('transferItems').addEventListener('click', event => {
    const button = event.target.closest('.remove-item');
    if (!button) return;
    transferItems.splice(Number(button.closest('[data-index]').dataset.index), 1);
    renderTransferItems();
});
document.getElementById('transferForm').addEventListener('submit', event => {
    if (busy || stockError || transferItems.some(item => itemProblem(item))) {
        event.preventDefault(); validateTransferRows(); return;
    }
    if (document.getElementById('productSearch').value.trim() && !addTransferItem()) {
        event.preventDefault();
        return;
    }
    if (!transferItems.length) {
        event.preventDefault();
        const search = document.getElementById('productSearch');
        search.setCustomValidity('Tambahkan minimal satu barang untuk ditransfer.');
        search.reportValidity();
    }
});
renderTransferItems();
updateDestinationOptions();
updateSourceProducts();
updateTransferState();
if (acceptedSource && transferItems.length) refreshSource(acceptedSource);
})();
</script>
