(() => {
    const form = document.getElementById('opnameForm');
    if (!form) return;
    const number = value => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(value);
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
    function updateItem(id) {
        const main = form.querySelector(`[data-opname-item="${id}"]`);
        if (!main) return;
        const units = JSON.parse(main.dataset.displayUnits);
        const detail = form.querySelector(`[data-expiry-item="${id}"]`);
        if (detail) {
            let total = 0;
            detail.querySelectorAll('[data-expiry-row]').forEach(row => {
                const quantity = Number(row.querySelector('.physical-value').value) || 0;
                const diff = row.querySelector('.expiry-difference');
                paintDifference(diff, round(quantity - Number(diff.dataset.system)), units);
                total += quantity;
            });
            main.querySelector('.item-physical').value = round(total);
            main.querySelector('.item-total').textContent = formatQuantity(round(total), units);
            main.querySelector('.expiry-count').textContent = detail.querySelectorAll('[data-expiry-row]').length + ' kedaluwarsa';
        }
        const difference = round(Number(main.querySelector('.item-physical').value) - Number(main.dataset.system));
        paintDifference(main.querySelector('.item-difference'), difference, units);
        const valuation = main.querySelector('[data-valuation-field]');
        if (valuation) {
            valuation.hidden = difference <= 0;
            valuation.querySelector('input').disabled = difference <= 0;
        }
    }
    function bindQuantity(widget, id) {
        const update = () => {
            const value = widget.querySelector('.physical-value');
            let total = 0;
            widget.querySelectorAll('.qty-unit').forEach(input => {
                total += (Number(input.value) || 0) * Number(input.dataset.conversion);
            });
            value.value = round(total);
            const main = form.querySelector(`[data-opname-item="${id}"]`);
            if (widget.closest('[data-opname-item]')) main.querySelector('.item-physical').value = value.value;
            updateItem(id);
        };
        widget.querySelectorAll('input[type="number"]').forEach(input => input.addEventListener('input', update));
    }
    form.querySelectorAll('[data-opname-item]').forEach(main => {
        const id = main.dataset.opnameItem;
        main.querySelectorAll('.opname-quantity').forEach(widget => bindQuantity(widget, id));
        const detail = form.querySelector(`[data-expiry-item="${id}"]`);
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
    search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('id-ID');
        let visible = 0;
        form.querySelectorAll('[data-opname-item]').forEach(main => {
            const matches = main.querySelector('.opname-product-name').textContent.toLocaleLowerCase('id-ID').includes(query);
            main.hidden = !matches;
            const detail = form.querySelector(`[data-expiry-item="${main.dataset.opnameItem}"]`);
            if (detail) detail.hidden = !matches || main.querySelector('.expiry-toggle').getAttribute('aria-expanded') !== 'true';
            if (matches) visible++;
        });
        document.getElementById('opnameItemCount').textContent = visible + ' / ' + form.querySelectorAll('[data-opname-item]').length + ' barang';
        document.getElementById('opnameEmpty').hidden = visible !== 0;
    });
    // Reveal invalid fields before the browser tries to focus a collapsed row.
    form.addEventListener('invalid', event => {
        const detail = event.target.closest('[data-expiry-item]');
        const main = detail ? form.querySelector(`[data-opname-item="${detail.dataset.expiryItem}"]`) : event.target.closest('[data-opname-item]');
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
        if (!await appDialog.confirm({ title: 'Selesaikan Opname?', text: 'Stok fisik dan rincian kedaluwarsa akan disesuaikan dengan hasil penghitungan.', confirmText: 'Selesaikan Opname' })) return;
        form.action = form.dataset.completeUrl;
        form.querySelector('[name="_method"]').disabled = true;
        form.requestSubmit();
    });
})();
