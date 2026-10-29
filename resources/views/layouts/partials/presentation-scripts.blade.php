<script>
(() => {
    const root = document.querySelector('.page-content');
    if (!root) return;
    let nextId = 0;
    function normalizeFilters(form) {
        if (form.dataset.filterLayoutReady) return;
        form.dataset.filterLayoutReady = 'true';
        const controls = [...form.querySelectorAll('input:not([type="hidden"]), select')];
        const hidden = [...form.querySelectorAll('input[type="hidden"]')];
        const fields = controls.map(control => {
            const periodGroup = control.closest('[data-pl-period]');
            if (periodGroup) { periodGroup.classList.add('app-unified-field'); return {field:periodGroup, order:2}; }
            let field = control.parentElement;
            if (field === form || field.querySelectorAll('input:not([type="hidden"]),select').length !== 1 || field.querySelector('button,a.btn')) {
                field = document.createElement('div');
                const label = control.labels?.[0];
                if (label && form.contains(label)) { label.classList.remove('visually-hidden'); field.append(label); }
                field.append(control);
            } else {
                while (field.parentElement !== form && field.parentElement.querySelectorAll('input:not([type="hidden"]),select').length === 1 && !field.parentElement.querySelector('button,a.btn')) field = field.parentElement;
            }
            field.classList.add('app-unified-field');
            const search = control.type === 'search' || ['search','q'].includes(control.name);
            field.classList.toggle('app-unified-search', search);
            return {field, order: search ? 0 : control.tagName === 'SELECT' ? 1 : 2};
        });
        const actions = document.createElement('div');
        actions.className = 'app-unified-actions';
        for (const button of form.querySelectorAll('button:not([type="button"]),input[type="submit"]')) {
            button.classList.add('btn','btn-sm','btn-primary');
            actions.append(button);
        }
        let reset = [...form.querySelectorAll('a')].find(link => link.textContent.trim() === 'Reset');
        if (!reset) {
            reset = document.createElement('a');
            const url = new URL(form.action || window.location.href, window.location.href);
            url.search = '';
            for (const name of ['tab','view']) {
                const input = hidden.find(input => input.name === name);
                if (input?.value) url.searchParams.set(name, input.value);
            }
            reset.href = url.href;
            reset.textContent = 'Reset';
        }
        reset.classList.add('btn','btn-sm','btn-outline-secondary');
        actions.append(reset);
        const uniqueFields = fields.filter((item,index) => fields.findIndex(other => other.field === item.field) === index);
        uniqueFields.sort((a,b) => a.order-b.order);
        form.replaceChildren(...hidden, ...uniqueFields.map(item => item.field), actions);
    }
    function enhance(scope) {
        scope.querySelectorAll('form.app-unified-filter').forEach(normalizeFilters);
        const controls = scope.querySelectorAll('input:not([type="hidden"]),select,textarea');
        for (const input of controls) {
            if (!input.labels?.length && !input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')) {
                const parent = input.parentElement;
                const label = parent.querySelector('label:not([for])');
                if (label && parent.querySelectorAll('input:not([type="hidden"]),select,textarea').length === 1) {
                    if (!input.id) {
                        do { input.id = 'app-field-' + ++nextId; } while (document.querySelectorAll('#' + input.id).length > 1);
                    }
                    label.htmlFor = input.id;
                }
            }
            for (const label of input.labels || []) label.classList.toggle('app-field-required', input.matches(':required'));
        }
        for (const table of scope.querySelectorAll('[data-mobile-records]')) {
            const headings = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
            for (const row of table.querySelectorAll('tbody tr')) {
                if (row.cells.length !== headings.length) continue;
                [...row.cells].forEach((cell, index) => { cell.dataset.label = headings[index]; });
            }
        }
        for (const list of scope.querySelectorAll('[data-detail-list]')) {
            if (list.dataset.listReady) continue;
            list.dataset.listReady = 'true';
            const search = list.querySelector('[data-list-search]');
            const rows = [...list.querySelectorAll('tbody tr')].filter(row => !row.querySelector('[colspan]'));
            const names = rows.map(row => row.cells[0].textContent.toLocaleLowerCase('id-ID'));
            const refresh = () => {
                const query = search.value.trim().toLocaleLowerCase('id-ID');
                let visible = 0;
                rows.forEach((row, index) => {
                    row.hidden = !names[index].includes(query);
                    if (!row.hidden) visible++;
                });
                list.querySelector('[data-list-count]').textContent = `${visible.toLocaleString('id-ID')} / ${rows.length.toLocaleString('id-ID')} baris`;
                list.querySelector('[data-list-empty]').hidden = visible > 0 || rows.length === 0;
            };
            search.addEventListener('input', refresh);
            refresh();
        }
    }
    enhance(root);
    root.addEventListener('change', () => enhance(root));
    new MutationObserver(changes => {
        if (changes.some(change => [...change.addedNodes].some(node => node.nodeType === Node.ELEMENT_NODE))) enhance(root);
    }).observe(root, { childList: true, subtree: true });
})();
</script>
