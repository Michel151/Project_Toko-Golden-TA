function initializeSaleStockRefresh({ url, applySnapshot }) {
    const button = document.getElementById('refreshSaleStock');
    const status = document.getElementById('saleStockStatus');
    let inFlight = false;
    let stopped = false;
    let lastAttempt = Date.now();

    async function refresh(manual = false) {
        if (inFlight || (!manual && (stopped || document.hidden || Date.now() - lastAttempt < 5000))) return;
        inFlight = true;
        lastAttempt = Date.now();
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        status.textContent = 'Memperbarui stok…';
        status.className = 'small text-muted';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(url, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if ([401, 403, 419].includes(response.status)) {
                stopped = true;
                throw new Error('Sesi atau hak akses perlu diperiksa. Keranjang tetap di halaman ini; stok belum diperbarui.');
            }
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (!Array.isArray(data.products) || !data.products.every(product =>
                Number.isInteger(product.id) && Array.isArray(product.stocks) && product.stocks.every(stock =>
                    Number.isInteger(stock.location_id) && typeof stock.quantity === 'number' && Number.isFinite(stock.quantity) && stock.quantity >= 0
                )
            )) throw new Error();
            applySnapshot(data.products);
            stopped = false;
            status.textContent = `Stok diperbarui ${new Date().toLocaleTimeString('id-ID', { timeZone: 'Asia/Jakarta', hour12: false })} WIB · Otomatis setiap 30 detik.`;
        } catch (error) {
            status.className = 'small text-danger';
            status.textContent = stopped ? error.message : 'Stok belum diperbarui. Keranjang tetap tersimpan di halaman ini. Coba Perbarui Stok lagi.';
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    }

    button.addEventListener('click', () => refresh(true));
    setInterval(() => refresh(), 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('focus', () => refresh());
    window.addEventListener('online', () => refresh());
}
