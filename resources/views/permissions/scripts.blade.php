<script>
(() => {
    const form = document.getElementById('permissionForm');
    if (!form) return;
    const search = document.getElementById('permissionSearch');
    const rows = [...form.querySelectorAll('[data-feature]')];
    function summary() {
        const checks = [...form.querySelectorAll('.permission-check')];
        document.getElementById('permissionSummary').textContent = `${checks.filter(input => input.checked).length} / ${checks.length} izin aktif`;
    }
    function filter() {
        const query = search.value.trim().toLocaleLowerCase('id-ID');
        rows.forEach(row => { row.hidden = !row.dataset.search.includes(query); });
        form.querySelectorAll('[data-permission-group]').forEach(group => {
            group.hidden = !rows.some(row => row.dataset.group === group.dataset.permissionGroup && !row.hidden);
        });
        const count = rows.filter(row => !row.hidden).length;
        document.getElementById('permissionEmpty').hidden = count > 0;
        document.getElementById('permissionVisibleCount').textContent = `${count} / ${rows.length} fitur`;
    }
    form.addEventListener('change', summary);
    for (const id of ['selectAll', 'clearAll']) document.getElementById(id).addEventListener('click', summary);
    search.addEventListener('input', filter);
    summary();
    filter();
})();
</script>
