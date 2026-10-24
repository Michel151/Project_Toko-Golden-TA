(() => {
    const select = document.getElementById('opnameParticipants');
    if (!select || !window.bootstrap?.Dropdown) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'dropdown';
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'opnameParticipantsToggle';
    button.className = 'form-select text-start';
    button.style.whiteSpace = 'normal';
    button.style.overflowWrap = 'anywhere';
    button.dataset.bsToggle = 'dropdown';
    button.dataset.bsAutoClose = 'outside';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-labelledby', 'opnameParticipantsLabel opnameParticipantsSummary');
    const summary = document.createElement('span');
    summary.id = 'opnameParticipantsSummary';
    button.append(summary);
    const menu = document.createElement('div');
    menu.id = 'opnameParticipantsMenu';
    menu.className = 'dropdown-menu w-100 p-2';
    menu.setAttribute('role', 'group');
    menu.setAttribute('aria-labelledby', 'opnameParticipantsLabel');
    button.setAttribute('aria-controls', menu.id);
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm mb-2';
    search.placeholder = 'Cari petugas...';
    search.setAttribute('aria-label', 'Cari petugas opname');
    const options = document.createElement('div');
    options.style.maxHeight = '220px';
    options.style.overflowY = 'auto';
    const checks = [...select.options].map(option => {
        const label = document.createElement('label');
        label.className = 'd-flex align-items-center gap-2 py-2 px-1';
        label.style.cursor = 'pointer';
        const check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'form-check-input m-0 flex-shrink-0';
        check.value = option.value;
        check.checked = option.selected;
        check.style.accentColor = '#1f7a5a';
        const text = document.createElement('span');
        text.className = 'small text-break';
        text.textContent = option.textContent;
        label.append(check, text);
        options.append(label);
        check.addEventListener('change', () => {
            option.selected = check.checked;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        return { check, option, label };
    });
    const empty = document.createElement('div');
    empty.className = 'small text-muted p-2';
    empty.textContent = 'Tidak ada petugas yang sesuai pencarian.';
    empty.hidden = true;
    const error = document.createElement('div');
    error.id = 'opnameParticipantsError';
    error.className = 'small text-danger mt-1';
    error.textContent = 'Pilih minimal satu petugas opname.';
    error.hidden = true;
    error.setAttribute('role', 'alert');
    button.setAttribute('aria-describedby', error.id);
    menu.append(search, options, empty);
    wrapper.append(button, menu, error);
    select.after(wrapper);
    select.hidden = true;
    function refresh() {
        checks.forEach(({ check, option }) => { check.checked = option.selected; });
        const selected = [...select.selectedOptions];
        summary.textContent = selected.length ? selected.map(option => option.textContent).join(', ') : 'Pilih petugas opname';
        if (selected.length) {
            error.hidden = true;
            button.classList.remove('is-invalid');
            button.removeAttribute('aria-invalid');
        }
    }
    select.addEventListener('change', refresh);
    select.addEventListener('invalid', event => {
        event.preventDefault();
        error.hidden = false;
        button.classList.add('is-invalid');
        button.setAttribute('aria-invalid', 'true');
        bootstrap.Dropdown.getOrCreateInstance(button).show();
        search.focus();
    });
    search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('id-ID');
        checks.forEach(({ label, option }) => {
            label.classList.toggle('d-none', !option.textContent.toLocaleLowerCase('id-ID').includes(query));
        });
        empty.hidden = checks.some(({ label }) => !label.classList.contains('d-none'));
    });
    button.addEventListener('shown.bs.dropdown', () => search.focus());
    select.form.addEventListener('reset', () => setTimeout(refresh, 0));
    refresh();
})();
