<script>
(() => {
    const form = document.getElementById('opnameForm');
    if (!form) return;
    form.querySelectorAll('[data-valuation-field]').forEach(field => {
        const display = field.querySelector('[data-valuation-display]');
        const raw = field.querySelector('[data-valuation-value]');
        const sync = () => {
            const entered = display.value;
            const cursor = display.selectionStart ?? entered.length;
            const position = entered.slice(0, cursor).replace(/\./g, '').length;
            if (!/^[\d.]*(,\d*)?$/.test(entered)) {
                display.setCustomValidity('Gunakan angka, titik untuk ribuan, dan koma untuk desimal.');
                raw.value = '';
                return;
            }
            const [whole = '', fraction] = entered.replace(/\./g, '').split(',');
            const digits = whole.replace(/^0+(?=\d)/, '') || (fraction !== undefined ? '0' : '');
            display.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction === undefined ? '' : ',' + fraction);
            raw.value = digits + (fraction ? '.' + fraction : '');
            const invalid = raw.value && (Number(raw.value) <= 0 || Number(raw.value) > 999999999999.99 || (fraction?.length ?? 0) > 10);
            display.setCustomValidity(invalid ? 'Nilai harus lebih dari nol, maksimal 999.999.999.999,99 dengan paling banyak 10 angka desimal.' : '');
            let index = 0, count = 0;
            while (index < display.value.length && count < position) {
                if (display.value[index] !== '.') count++;
                index++;
            }
            if (document.activeElement === display) display.setSelectionRange(index, index);
        };
        display.value = raw.value.replace('.', ',');
        sync();
        display.addEventListener('input', sync);
    });
    const quantityFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 });
    const number = value => quantityFormatter.format(value);
    const mainRows = Array.from(form.querySelectorAll('[data-opname-item]'));
    const mainById = new Map(mainRows.map(main => [main.dataset.opnameItem, main]));
    const detailById = new Map(Array.from(form.querySelectorAll('[data-expiry-item]'), detail => [detail.dataset.expiryItem, detail]));
    const itemCache = new WeakMap();
    const expiryCache = new WeakMap();
    const round = value => Math.round((value + Number.EPSILON) * 10000) / 10000;
    const formatQuantity = (value, units, difference = false) => {
        let remaining = Math.round(Math.abs(value) * 10000);
        const parts = [];
        for (const unit of units) {
            const conversion = Math.round(unit.conversion * 10000);
            if (conversion <= 10000) continue;
            const count = Math.floor(remaining / conversion);
            if (count > 0) parts.push(number(count) + ' ' + unit.name);
            remaining %= conversion;
        }
        if (remaining || !parts.length) parts.push(number(remaining / 10000) + ' ' + (units.find(unit => unit.conversion === 1)?.name || 'Unit'));
        const sign = round(value) < 0 ? '-' : difference && round(value) > 0 ? '+' : '';
        const text = parts.join(' + ');
        return sign + (sign === '-' && parts.length > 1 ? '(' + text + ')' : text);
    };
    const paintDifference = (cell, value, units) => {
        cell.textContent = formatQuantity(value, units, true);
        cell.classList.toggle('text-danger', value < 0);
        cell.classList.toggle('text-success', value > 0);
    };
    function clearNotesError(notes, changed) {
        if (!changed || notes.value.trim()) {
            notes.classList.remove('is-invalid');
            notes.removeAttribute('aria-invalid');
        }
    }
    function updateItem(id) {
        const main = mainById.get(id);
        if (!main) return;
        let cached = itemCache.get(main);
        if (!cached) {
            cached = {
                units: JSON.parse(main.dataset.displayUnits),
                physical: main.querySelector('.item-physical'),
                total: main.querySelector('.item-total'),
                count: main.querySelector('.expiry-count'),
                difference: main.querySelector('.item-difference'),
                notes: main.querySelector('.opname-notes'),
                notesHelp: main.querySelector('[data-notes-help]'),
                valuation: main.querySelector('[data-valuation-field]'),
            };
            cached.valuationInputs = cached.valuation?.querySelectorAll('input');
            itemCache.set(main, cached);
        }
        const { units, notes, notesHelp, valuation } = cached;
        const detail = detailById.get(id);
        let expiryChanged = false;
        if (detail) {
            let total = 0;
            const rows = detail.querySelectorAll('[data-expiry-row]');
            rows.forEach(row => {
                let fields = expiryCache.get(row);
                if (!fields) {
                    fields = { physical: row.querySelector('.physical-value'), difference: row.querySelector('.expiry-difference') };
                    expiryCache.set(row, fields);
                }
                const quantity = Number(fields.physical.value) || 0;
                const diff = fields.difference;
                const expiryDifference = round(quantity - Number(diff.dataset.system));
                paintDifference(diff, expiryDifference, units);
                if (expiryDifference !== 0) expiryChanged = true;
                total += quantity;
            });
            cached.physical.value = round(total);
            cached.total.textContent = formatQuantity(round(total), units);
            cached.count.textContent = rows.length + ' kedaluwarsa';
        }
        const difference = round(Number(cached.physical.value) - Number(main.dataset.system));
        paintDifference(cached.difference, difference, units);
        const changed = difference !== 0 || expiryChanged;
        main.dataset.countChanged = String(changed);
        notes.placeholder = changed ? 'Keterangan selisih' : 'Keterangan (opsional)';
        notesHelp.textContent = changed ? '* Wajib saat menyelesaikan opname' : '';
        notesHelp.hidden = !changed;
        clearNotesError(notes, changed);
        if (valuation) {
            valuation.hidden = difference <= 0;
            cached.valuationInputs.forEach(input => { input.disabled = difference <= 0; });
        }
    }
    function bindQuantity(widget, id) {
        const value = widget.querySelector('.physical-value');
        const inputs = widget.querySelectorAll('.qty-unit');
        const main = mainById.get(id);
        const isMain = !!widget.closest('[data-opname-item]');
        const update = () => {
            let total = 0;
            inputs.forEach(input => {
                total += (Number(input.value) || 0) * Number(input.dataset.conversion);
            });
            value.value = round(total);
            if (isMain) main.querySelector('.item-physical').value = value.value;
            updateItem(id);
        };
        widget.querySelectorAll('input[type="number"]').forEach(input => input.addEventListener('input', update));
    }
    mainRows.forEach(main => {
        const id = main.dataset.opnameItem;
        const notes = main.querySelector('.opname-notes');
        notes.addEventListener('input', () => clearNotesError(notes, main.dataset.countChanged === 'true'));
        main.querySelectorAll('.opname-quantity').forEach(widget => bindQuantity(widget, id));
        const detail = detailById.get(id);
        if (detail) {
            const toggle = main.querySelector('.expiry-toggle');
            toggle.addEventListener('click', () => {
                const expanded = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.setAttribute('aria-expanded', String(expanded));
                detail.hidden = !expanded;
            });
            detail.querySelectorAll('.opname-quantity').forEach(widget => bindQuantity(widget, id));
            detail.querySelector('.add-expiry').addEventListener('click', () => {
                const index = Number(detail.dataset.nextIndex);
                detail.dataset.nextIndex = index + 1;
                const template = detail.querySelector('template').innerHTML.replaceAll('__INDEX__', index);
                const body = detail.querySelector('.expiry-list');
                body.insertAdjacentHTML('beforeend', template);
                const row = body.lastElementChild;
                bindQuantity(row.querySelector('.opname-quantity'), id);
                row.querySelector('.expiry-date').focus();
                updateItem(id);
            });
            detail.addEventListener('click', event => {
                const button = event.target.closest('.remove-expiry');
                if (!button) return;
                button.closest('[data-expiry-row]').remove();
                updateItem(id);
            });
        }
        updateItem(id);
    });
    const search = document.getElementById('opnameSearch');
    const productNames = new Map(mainRows.map(main => [main, main.querySelector('.opname-product-name').textContent.toLocaleLowerCase('id-ID')]));
    const itemCount = document.getElementById('opnameItemCount');
    const empty = document.getElementById('opnameEmpty');
    search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('id-ID');
        let visible = 0;
        mainRows.forEach(main => {
            const matches = productNames.get(main).includes(query);
            main.hidden = !matches;
            const detail = detailById.get(main.dataset.opnameItem);
            if (detail) detail.hidden = !matches || main.querySelector('.expiry-toggle').getAttribute('aria-expanded') !== 'true';
            if (matches) visible++;
        });
        itemCount.textContent = visible + ' / ' + mainRows.length + ' barang';
        empty.hidden = visible !== 0;
    });
    // Reveal invalid fields before the browser tries to focus a collapsed row.
    form.addEventListener('invalid', event => {
        const detail = event.target.closest('[data-expiry-item]');
        const main = detail ? mainById.get(detail.dataset.expiryItem) : event.target.closest('[data-opname-item]');
        if (!main) return;
        search.value = '';
        search.dispatchEvent(new Event('input'));
        if (detail) {
            main.querySelector('.expiry-toggle').setAttribute('aria-expanded', 'true');
            detail.hidden = false;
        }
    }, true);
    document.getElementById('btnSelesaikan').addEventListener('click', async () => {
        if (!form.reportValidity()) return;
        let missingNotes = null;
        mainRows.forEach(main => {
            const notes = main.querySelector('.opname-notes');
            const missing = main.dataset.countChanged === 'true' && !notes.value.trim();
            notes.classList.toggle('is-invalid', missing);
            if (missing) {
                notes.setAttribute('aria-invalid', 'true');
                missingNotes ??= notes;
            } else notes.removeAttribute('aria-invalid');
        });
        if (missingNotes) {
            search.value = '';
            search.dispatchEvent(new Event('input'));
            missingNotes.focus();
            missingNotes.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!await appDialog.confirm({ title: 'Selesaikan Opname?', text: 'Stok fisik dan rincian kedaluwarsa akan disesuaikan dengan hasil penghitungan.', confirmText: 'Selesaikan Opname' })) return;
        form.action = form.dataset.completeUrl;
        form.querySelector('[name="_method"]').disabled = true;
        form.requestSubmit();
    });
})();
</script>
