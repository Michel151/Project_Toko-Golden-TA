(() => {
    const form = document.getElementById('receivingForm');
    if (!form) return;
    const rows = [...form.querySelectorAll('.inspection-row')];
    const number = input => Number(input?.value) || 0;
    const format = value => value.toLocaleString('id-ID', { maximumFractionDigits: 4 });
    let edited = false;
    function update(row, syncSingleBatch = false) {
        const detail = document.getElementById(row.dataset.details);
        const good = number(row.querySelector('.good-qty'));
        const exceptions = [...row.querySelectorAll('.exception-qty')].map(number);
        const total = good + exceptions.reduce((sum, qty) => sum + qty, 0);
        const max = Number(row.dataset.max);
        const panel = detail.querySelector('.batch-panel');
        let batchMismatch = false;
        if (panel) {
            const batches = [...panel.querySelectorAll('.batch-list .batch-row')];
            if (syncSingleBatch && batches.length === 1) {
                batches[0].querySelector('.batch-quantity').value = good;
            }
            const batchTotal = batches.reduce((sum, batch) => sum + number(batch.querySelector('.batch-quantity')), 0);
            batchMismatch = Math.abs(batchTotal - good) > 0.00001;
            const batchSummary = panel.querySelector('.batch-summary');
            batchSummary.textContent = `Total rincian: ${format(batchTotal)} / ${format(good)}`;
            batchSummary.classList.toggle('text-danger', batchMismatch);
            for (const batch of batches) {
                const positive = number(batch.querySelector('.batch-quantity')) > 0;
                batch.querySelector('.batch-expiry').required = positive;
                batch.querySelector('.batch-location').required = positive;
                batch.querySelector('.batch-delete').disabled = batches.length === 1;
            }
            panel.querySelector('.batch-add').disabled = batches.length >= 100;
        }
        const invalid = total > max + 0.00001;
        const goodInput = row.querySelector('.good-qty');
        goodInput.setCustomValidity(invalid ? 'Total pemeriksaan melebihi sisa penerimaan PO.' : batchMismatch ? 'Total rincian kedaluwarsa harus sama dengan jumlah barang baik yang diterima.' : '');
        const allocation = row.querySelector('.allocation-qty');
        if (allocation) allocation.value = good;
        const summary = row.querySelector('.receiving-summary');
        summary.textContent = invalid ? 'Melebihi sisa PO' : total === 0 ? 'Belum diperiksa' : `${format(total)} diperiksa; ${format(max - total)} belum diperiksa`;
        summary.classList.toggle('text-danger', invalid || batchMismatch);
        const goodLocation = detail.querySelector('.good-location');
        if (goodLocation) goodLocation.required = good > 0;
        detail.querySelector('.quarantine-location').required = exceptions[0] + exceptions[1] > 0;
        detail.querySelector('.inspection-reason').required = exceptions.some(qty => qty > 0);
        return total;
    }
    function updateAll(syncRow = null) {
        const count = rows.filter(row => update(row, row === syncRow) > 0).length;
        const counter = document.getElementById('inspectionCount');
        if (counter) counter.textContent = `${count} barang diperiksa`;
        return count;
    }
    form.addEventListener('input', event => {
        edited = true;
        updateAll(event.target.matches('.good-qty') ? event.target.closest('.inspection-row') : null);
    });
    form.addEventListener('change', event => {
        if (event.target.id !== 'poSelect') edited = true;
        updateAll();
    });
    form.addEventListener('click', event => {
        const add = event.target.closest('.batch-add');
        const remove = event.target.closest('.batch-delete');
        if (!add && !remove) return;
        const panel = (add || remove).closest('.batch-panel');
        const list = panel.querySelector('.batch-list');
        if (add && list.children.length < 100) {
            const fragment = panel.querySelector('.batch-template').content.cloneNode(true);
            const index = panel.dataset.nextIndex;
            panel.dataset.nextIndex = Number(index) + 1;
            fragment.querySelectorAll('[name]').forEach(input => input.name = input.name.replace('__BATCH__', index));
            list.append(fragment);
            list.lastElementChild.querySelector('.batch-quantity').focus();
        } else if (remove && list.children.length > 1) {
            remove.closest('.batch-row').remove();
        }
        edited = true;
        updateAll();
    });
    document.getElementById('fillGood')?.addEventListener('click', async () => {
        if (!await window.appDialog.confirm({ title: 'Isi Sisa sebagai Barang Baik?', text: 'Jumlah rusak, tidak sesuai, dan kurang tetap dipertahankan.', confirmText: 'Isi Barang Baik' })) return;
        for (const row of rows) {
            const exceptions = [...row.querySelectorAll('.exception-qty')].reduce((sum, input) => sum + number(input), 0);
            row.querySelector('.good-qty').value = Math.max(0, Number(row.dataset.max) - exceptions).toFixed(4);
            update(row, true);
        }
        edited = true;
        updateAll();
    });
    form.addEventListener('submit', event => {
        if (!updateAll()) {
            event.preventDefault();
            window.appDialog.notice({ title: 'Belum Ada Pemeriksaan', text: 'Isi jumlah barang baik atau jumlah barang bermasalah terlebih dahulu.' });
        }
    });
    const poSelect = document.getElementById('poSelect');
    const initialPo = poSelect.value;
    poSelect.addEventListener('change', async () => {
        const selected = poSelect.value;
        if ((edited || rows.some(row => [...row.querySelectorAll('.inspection-qty')].some(input => number(input) > 0))) &&
            !await window.appDialog.confirm({ title: 'Ganti Purchase Order?', text: 'Hasil pemeriksaan yang belum disimpan akan dikosongkan.', confirmText: 'Ganti PO' })) {
            poSelect.value = initialPo;
            return;
        }
        const url = new URL(location.href);
        url.searchParams.delete('order_id');
        if (selected) url.searchParams.set('order_id', selected);
        location.href = url.href;
    });
    updateAll();
})();
