<script>
(() => {
    const modal = document.getElementById('transferItemsModal');
    if (!modal) return;
    const search = document.getElementById('transferItemsSearch');
    const body = document.getElementById('transferItemsBody');
    // Menyaring nama barang di modal tanpa meminta data lagi ke server.
    function filterItems() {
        const query = search.value.trim().toLocaleLowerCase('id-ID');
        const rows = [...body.querySelectorAll('[data-transfer-item]')];
        let visible = 0;
        for (const row of rows) {
            row.hidden = !row.querySelector('[data-item-name]').textContent.toLocaleLowerCase('id-ID').includes(query);
            if (!row.hidden) visible++;
        }
        document.getElementById('transferItemsCount').textContent = `${visible.toLocaleString('id-ID')} / ${rows.length.toLocaleString('id-ID')} barang`;
        document.getElementById('transferItemsEmpty').hidden = visible > 0;
    }
    // Menyalin template transfer terpilih, mengisi judul, dan mengosongkan pencarian sebelumnya.
    modal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        if (!button) return;
        const template = document.getElementById('transfer-items-' + button.dataset.transferId);
        body.replaceChildren(template.content.cloneNode(true));
        document.getElementById('transferItemsTitle').textContent = button.dataset.transferNumber;
        document.getElementById('transferItemsContext').textContent = button.dataset.transferContext;
        search.value = '';
        modal.querySelector('.transfer-items-scroll').scrollTop = 0;
        filterItems();
    });
    // Memberi fokus pencarian setelah modal selesai ditampilkan.
    modal.addEventListener('shown.bs.modal', () => search.focus());
    search.addEventListener('input', filterItems);
})();
</script>
