(() => {
    const root = document.querySelector('.page-content');
    if (!root) return;
    let nextId = 0;
    function enhance(scope) {
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
