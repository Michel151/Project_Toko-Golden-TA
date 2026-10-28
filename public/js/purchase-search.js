(() => {
    let nextId = 0;
    const instances = new Set();

    function position(control) {
        if (!control.isOpen) return;
        const rect = control.control.getBoundingClientRect();
        const popup = control.dropdown;
        const content = control.dropdown_content;
        const width = Math.min(rect.width, window.innerWidth - 16);
        popup.style.width = `${width}px`;
        popup.style.left = `${window.scrollX + Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
        const below = window.innerHeight - rect.bottom - 12;
        const above = rect.top - 12;
        const upward = below < 180 && above > below;
        const headerHeight = popup.offsetHeight - content.offsetHeight;
        content.style.maxHeight = `${Math.max(48, Math.min(260, (upward ? above : below) - headerHeight))}px`;
        popup.style.top = `${window.scrollY + (upward ? rect.top - popup.offsetHeight - 4 : rect.bottom + 4)}px`;
    }

    function attach(select, products = []) {
        if (!window.TomSelect || !(select instanceof HTMLSelectElement) || select.tomselect) return;
        const supplier = select.id === 'supplierSelect';
        const label = supplier ? 'Cari supplier' : 'Cari barang atau barcode';
        const byId = new Map(products.map(product => [String(product.id), product]));
        if (!select.id) select.id = `poProductSearch${++nextId}`;
        const control = new TomSelect(select, {
            create: false, maxItems: 1, maxOptions: null, closeAfterSelect: true,
            dropdownParent: 'body', copyClassesToDropdown: false,
            searchField: supplier ? ['text'] : ['text', 'barcode'], placeholder: label,
            render: {
                option: (data, escape) => `<div><div>${escape(data.text)}</div>${data.barcode ? `<div class="small text-muted">${escape(data.barcode)}</div>` : ''}</div>`,
                no_results: () => '<div class="no-results">Tidak ada hasil yang sesuai.</div>',
            },
            onDropdownOpen() { requestAnimationFrame(() => position(this)); },
            onType() { requestAnimationFrame(() => position(this)); },
        });
        for (const [value, product] of byId) {
            if (control.options[value]) control.updateOption(value, { value, text: product.name, barcode: product.barcode ?? '' });
        }
        control.wrapper.classList.add('po-search-select');
        control.wrapper.dataset.poSearch = supplier ? 'supplier' : 'product';
        control.dropdown.classList.add('po-search-dropdown');
        control.control_input.setAttribute('aria-label', label);
        instances.add(control);
        control.on('destroy', () => instances.delete(control));
    }

    function destroy(container) {
        container.querySelectorAll('select.product-select').forEach(select => select.tomselect?.destroy());
    }

    function setValue(select, value) {
        if (select.tomselect) {
            select.tomselect.setValue(value, true);
            select.tomselect.close();
        }
        else select.value = value;
    }

    const reposition = () => instances.forEach(position);
    window.addEventListener('resize', reposition);
    document.addEventListener('scroll', reposition, true);
    window.purchaseSearch = { attach, destroy, setValue };
})();
