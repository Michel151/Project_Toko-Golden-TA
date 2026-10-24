(() => {
    const config = window.saleDraftConfig;
    const form = document.getElementById('saleForm');
    const status = document.getElementById('draftSaveStatus');
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('saleDraftModal'));
    const prefix = `golden-sale-draft:v1:${config.userId}:`;
    const writer = crypto.randomUUID();
    const scope = config.saleId || 'new';
    let current = { id: config.oldId || crypto.randomUUID(), version: config.oldVersion || 0, sale_id: config.saleId };
    let ready = false;
    let restoring = false;
    let closed = false;
    let dirty = false;
    let timer;
    let inFlight = null;
    let resumeSubmit = false;
    let submitting = false;
    let candidates = [];
    let cachedRemote = [];
    let candidateLoad = 0;
    let localAvailable = true;
    let lastPayload = '';

    const setStatus = text => { status.textContent = text; };
    const storageKey = () => `${prefix}${scope}:${current.id}:${writer}`;
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            signal: AbortSignal.timeout(8000), ...options
        });
        const body = await response.json().catch(() => ({}));
        return { response, body };
    };
    const updateIdentity = () => {
        document.getElementById('saleDraftId').value = current.id;
        document.getElementById('saleDraftVersion').value = current.version;
    };
    const removeLocal = id => {
        try {
            Object.keys(localStorage).filter(key => key.startsWith(prefix)).forEach(key => {
                if (JSON.parse(localStorage.getItem(key))?.id === id) localStorage.removeItem(key);
            });
        } catch (_) { localAvailable = false; }
    };
    const writeLocal = () => {
        try { localStorage.setItem(storageKey(), JSON.stringify(current)); return true; }
        catch (_) { localAvailable = false; return false; }
    };
    const readLocal = () => {
        try {
            return Object.keys(localStorage).filter(key => key.startsWith(prefix)).flatMap(key => {
                try {
                    const value = JSON.parse(localStorage.getItem(key));
                    return value?.payload && (value.sale_id || null) === config.saleId ? [value] : [];
                } catch (_) { return []; }
            });
        } catch (_) { localAvailable = false; return []; }
    };
    const field = name => form.elements.namedItem(name)?.value ?? '';
    const nullableNumber = value => value === '' || value == null || !Number.isFinite(Number(value)) ? null : Number(value);
    const snapshot = () => ({
        sale_date: field('sale_date'), payment_method: field('payment_method'),
        sale_status: field('sale_status'), customer_name: field('customer_name'),
        amount_paid: parseCurrency(field('amount_paid')),
        default_location_id: nullableNumber(document.getElementById('defaultSourceLocation').value),
        items: [...document.querySelectorAll('#itemsContainer .item-block')].map(row => ({
            product_id: nullableNumber(row.querySelector('.product-select').value),
            product_unit_id: nullableNumber(row.querySelector('.product-unit-select').value),
            product_name: row.querySelector('.product-search').value,
            quantity: nullableNumber(row.querySelector('.qty-input').value),
            unit_price: parseCurrency(row.querySelector('.price-input').value),
            source_mode: row.querySelector('.source-location-select')?.value || '',
            sources: rowStockSources(row).map(source => ({ location_id: nullableNumber(source.locationId), quantity: source.quantity }))
        }))
    });
    const meaningful = payload => payload.items.length > 0 || payload.customer_name.trim() !== '' || payload.amount_paid > 0;

    function capture() {
        if (!ready || restoring || closed) return;
        const payload = snapshot();
        const serialized = JSON.stringify(payload);
        if (serialized === lastPayload) return;
        lastPayload = serialized;
        if (!meaningful(payload) && !current.payload) return;
        current.payload = payload;
        current.client_updated_at = Math.max(Date.now(), Number(current.client_updated_at || 0) + 1);
        dirty = true;
        writeLocal();
        updateIdentity();
        setStatus(localAvailable ? 'Draf tersimpan di perangkat' : 'Penyimpanan perangkat tidak tersedia; menyimpan ke server...');
        clearTimeout(timer);
        timer = setTimeout(sync, 700);
    }

    async function sync() {
        if (!ready || restoring || closed || !dirty) return true;
        if (inFlight) { await inFlight; return dirty && !closed ? sync() : !closed; }
        inFlight = (async () => {
            const sent = JSON.parse(JSON.stringify(current));
            try {
                const { response, body } = await request(`${config.url}/${sent.id}`, {
                    method: 'PUT', body: JSON.stringify({ ...sent, status: 'active' })
                });
                if (response.status === 409 && body.draft) {
                    if (body.draft.status !== 'active') {
                        closed = true;
                        removeLocal(sent.id);
                        setStatus('Draf sudah ditutup. Buka keranjang baru sebelum melanjutkan.');
                        document.getElementById('saveSaleButton').disabled = true;
                        return false;
                    }
                    // Keep this tab's work as a separate draft instead of overwriting another tab.
                    const previousKey = storageKey();
                    current.id = crypto.randomUUID();
                    current.version = 0;
                    if (writeLocal()) {
                        try { localStorage.removeItem(previousKey); } catch (_) {}
                    }
                    updateIdentity();
                    setStatus('Perubahan dipisahkan dari draf di tab lain');
                    clearTimeout(timer);
                    timer = setTimeout(sync, 100);
                    return false;
                }
                if (!response.ok) throw new Error(String(response.status));
                current.version = body.version;
                dirty = current.client_updated_at !== sent.client_updated_at;
                writeLocal();
                updateIdentity();
                setStatus(dirty ? 'Draf tersimpan di perangkat' : `Draf tersimpan ${new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`);
                return true;
            } catch (_) {
                setStatus(localAvailable ? 'Draf tersimpan di perangkat; belum tersinkron' : 'Draf belum tersimpan. Penyimpanan perangkat dan server tidak tersedia.');
                return false;
            }
        })();
        const result = await inFlight;
        inFlight = null;
        return result;
    }

    async function loadCandidates() {
        const load = ++candidateLoad;
        try {
            const query = config.saleId ? `?sale_id=${config.saleId}` : '';
            const { response, body } = await request(config.url + query);
            if (!response.ok || !Array.isArray(body)) throw new Error('Unavailable');
            if (load !== candidateLoad) return null;
            cachedRemote = body;
            cachedRemote.filter(draft => draft.status !== 'active').forEach(draft => removeLocal(draft.id));
            document.getElementById('saleDraftMessage').textContent = '';
        } catch (_) {
            if (load !== candidateLoad) return null;
            document.getElementById('saleDraftMessage').textContent = 'Server belum tersedia. Menampilkan draf pada perangkat ini.';
        }
        mergeCandidates();
        return candidates;
    }

    function mergeCandidates() {
        const remote = cachedRemote;
        const closedIds = new Set(remote.filter(draft => draft.status !== 'active').map(draft => draft.id));
        const local = readLocal().filter(draft => !closedIds.has(draft.id));
        const merged = new Map();
        [...remote.filter(draft => draft.status === 'active' && draft.payload), ...local].forEach(draft => {
            const existing = merged.get(draft.id);
            if (!existing || Number(draft.client_updated_at) > Number(existing.client_updated_at) ||
                (draft.client_updated_at === existing.client_updated_at && draft.version > existing.version)) merged.set(draft.id, draft);
        });
        candidates = [...merged.values()].map(draft => ({ ...draft, remote: remote.find(item => item.id === draft.id) }))
            .sort((a, b) => b.client_updated_at - a.client_updated_at);
        renderCandidates();
    }

    function renderCandidates() {
        const list = document.getElementById('saleDraftList');
        list.innerHTML = candidates.map((draft, index) => {
            const items = draft.payload.items || [];
            const total = items.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0);
            const count = new Set(items.filter(item => item.product_id).map(item => item.product_id)).size;
            return `<div class="border-bottom py-3"><div class="fw-semibold text-break">${escapeHtml(draft.payload.customer_name || 'Pelanggan umum')}</div><div class="small text-muted mb-2">${count} jenis barang · Rp ${formatNumber(total)} · ${escapeHtml(new Date(draft.client_updated_at).toLocaleString('id-ID'))}</div><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-primary" data-resume-draft="${index}"><i class="bi bi-arrow-clockwise me-1"></i>Lanjutkan Keranjang</button><button type="button" class="btn btn-sm btn-outline-danger" data-discard-draft="${index}" title="Hapus draft" aria-label="Hapus draft"><i class="bi bi-trash"></i></button></div></div>`;
        }).join('') || '<div class="text-muted py-3">Tidak ada draft.</div>';
    }

    function restore(draft) {
        restoring = true;
        const payload = draft.payload;
        const server = draft.remote;
        const conflict = server && server.version > draft.version && JSON.stringify(server.payload) !== JSON.stringify(payload);
        current = { id: conflict ? crypto.randomUUID() : draft.id, version: conflict ? 0 : Math.max(server?.version || 0, draft.version), sale_id: config.saleId, payload, client_updated_at: Date.now() };
        closed = false;
        document.getElementById('itemsContainer').innerHTML = '';
        document.getElementById('cartSearch').value = '';
        if (locations.some(location => location.id == payload.default_location_id)) document.getElementById('defaultSourceLocation').value = payload.default_location_id;
        (payload.items || []).forEach(item => {
            const row = addItem(false);
            const product = products.find(p => p.id == item.product_id);
            if (product) {
                restoreItemFromOld(row, item);
                if (item.product_unit_id && !product.product_units.some(u => u.id == item.product_unit_id)) {
                    row.querySelector('.product-unit-select').add(new Option('Satuan tidak tersedia', item.product_unit_id));
                    row.querySelector('.product-unit-select').value = item.product_unit_id;
                }
                const locationSelect = row.querySelector('.source-location-select');
                if (locationSelect && item.source_mode === 'manual') {
                    locationSelect.value = 'manual';
                    locationSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    (item.sources || []).forEach(source => {
                        const input = [...row.querySelectorAll('.manual-location-id')].find(input => input.value == source.location_id);
                        if (input) input.closest('.d-flex').querySelector('.manual-qty').value = source.quantity ?? '';
                    });
                } else if (locationSelect && item.source_mode && !locations.some(l => l.id == item.source_mode)) {
                    locationSelect.add(new Option('Lokasi tidak tersedia', item.source_mode));
                    locationSelect.value = item.source_mode;
                    locationSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else if (item.product_id) {
                row.dataset.missingProduct = 'true';
                row.querySelector('.product-select').add(new Option(item.product_name || 'Barang tidak tersedia', item.product_id));
                row.querySelector('.product-select').value = item.product_id;
            }
            row.querySelector('.product-search').value = item.product_name || product?.name || '';
            row.querySelector('.qty-input').value = item.quantity ?? '';
            row.querySelector('.price-input').value = formatNumber(item.unit_price || 0);
            row.querySelector('.qty-input').dispatchEvent(new Event('input', { bubbles: true }));
            updateSourceSummary(row);
        });
        for (const name of ['sale_date', 'payment_method', 'customer_name']) {
            form.elements.namedItem(name).value = payload[name] ?? '';
        }
        const radio = form.querySelector(`input[type="radio"][name="sale_status"][value="${payload.sale_status === 'pending' ? 'pending' : 'paid'}"]`);
        if (radio) radio.checked = true;
        updateSaleStatus();
        document.getElementById('amountPaid').value = config.saleId || payload.sale_status === 'pending' ? '0' : formatNumber(payload.amount_paid || 0);
        document.getElementById('transactionConfirmed').checked = false;
        updateItemCounter();
        updateTotal();
        restoring = false;
        ready = true;
        lastPayload = '';
        capture();
        modal.hide();
        setStatus(conflict ? 'Draft dipulihkan sebagai salinan terpisah' : 'Draft dipulihkan; periksa stok dan pembayaran');
    }

    document.getElementById('saleDraftList').addEventListener('click', async event => {
        const resume = event.target.closest('[data-resume-draft]');
        if (resume) {
            const draft = candidates[Number(resume.dataset.resumeDraft)];
            if (!draft) return;
            if (inFlight) await inFlight;
            restore(draft);
            return;
        }
        const discard = event.target.closest('[data-discard-draft]');
        if (!discard) return;
        const draft = candidates[Number(discard.dataset.discardDraft)];
        if (!await appDialog.confirm({ title: 'Hapus Draft?', text: 'Keranjang yang belum selesai ini akan dihapus.', tone: 'danger', confirmText: 'Hapus Draft' })) return;
        discard.disabled = true;
        try {
            if (current.id === draft.id && inFlight) await inFlight;
            const version = current.id === draft.id ? current.version : draft.version;
            const { response } = await request(`${config.url}/${draft.id}`, { method: 'PUT', body: JSON.stringify({ sale_id: config.saleId, version, status: 'discarded', client_updated_at: Date.now() }) });
            if (!response.ok) throw new Error('Failed');
            removeLocal(draft.id);
            if (current.id === draft.id) {
                restoring = true;
                current = { id: crypto.randomUUID(), version: 0, sale_id: config.saleId };
                dirty = false;
                document.getElementById('itemsContainer').innerHTML = '';
                document.getElementById('cartSearch').value = '';
                document.getElementById('amountPaid').value = '0';
                form.elements.namedItem('customer_name').value = '';
                document.getElementById('transactionConfirmed').checked = false;
                updateItemCounter(); updateTotal(); updateIdentity();
                lastPayload = JSON.stringify(snapshot());
                restoring = false;
                setStatus('Draft dihapus');
            }
            await loadCandidates();
        } catch (_) {
            document.getElementById('saleDraftMessage').textContent = 'Draft belum dihapus. Sambungkan ke server lalu coba lagi; draft mungkin berubah di tab lain.';
            discard.disabled = false;
        }
    });
    document.getElementById('showSaleDrafts').addEventListener('click', () => {
        capture();
        mergeCandidates();
        document.getElementById('saleDraftMessage').textContent = 'Memperbarui daftar draft...';
        modal.show();
        sync().then(loadCandidates);
    });
    document.getElementById('startNewSaleDraft').addEventListener('click', async () => {
        capture();
        await sync();
        const url = new URL(location.href);
        url.searchParams.set('new', '1');
        location.assign(url);
    });
    document.getElementById('saleDraftModal').addEventListener('hidden.bs.modal', () => document.getElementById('barcodeScanner').focus());
    const scheduleCapture = () => queueMicrotask(capture);
    form.addEventListener('input', scheduleCapture);
    form.addEventListener('change', scheduleCapture);
    document.addEventListener('sale-cart-changed', scheduleCapture);
    form.addEventListener('click', event => {
        if (event.target.closest('.remove-item, #addItem, #exactPayment')) scheduleCapture();
    });
    window.addEventListener('online', sync);
    setInterval(() => { if (dirty && !closed) sync(); }, 20000);
    document.addEventListener('visibilitychange', () => { if (document.hidden) { capture(); sync(); } });
    window.addEventListener('pagehide', () => {
        capture();
        if (dirty && !inFlight && !closed) fetch(`${config.url}/${current.id}`, {
            method: 'PUT', credentials: 'same-origin', keepalive: true,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ...current, status: 'active' })
        }).catch(() => {});
    });
    form.addEventListener('submit', async event => {
        if (resumeSubmit) {
            resumeSubmit = false;
            queueMicrotask(() => { if (event.defaultPrevented) submitting = false; });
            return;
        }
        event.preventDefault(); event.stopImmediatePropagation();
        if (submitting) return;
        if (closed) { await appDialog.notice({ title: 'Draft Sudah Ditutup', text: 'Buka keranjang baru untuk melanjutkan transaksi.' }); return; }
        submitting = true;
        capture();
        if (!await sync()) { submitting = false; setStatus('Draft belum tersinkron. Coba Simpan lagi setelah koneksi tersedia.'); return; }
        // requestSubmit is ignored while the original submit event is still active.
        await new Promise(resolve => setTimeout(resolve, 0));
        if (!form.reportValidity() || document.getElementById('saveSaleButton').disabled) { submitting = false; return; }
        if (!await confirmSaleSubmission()) { submitting = false; return; }
        if (!form.reportValidity() || document.getElementById('saveSaleButton').disabled) { submitting = false; return; }
        updateIdentity(); resumeSubmit = true; form.requestSubmit(document.getElementById('saveSaleButton'));
        if (resumeSubmit) { resumeSubmit = false; submitting = false; }
    }, true);

    updateIdentity();
    lastPayload = JSON.stringify(snapshot());
    ready = true;
    if (config.hasOldInput) { lastPayload = ''; capture(); }
    if (config.startFresh) {
        const url = new URL(location.href);
        url.searchParams.delete('new');
        history.replaceState(null, '', url);
    }
    loadCandidates().then(loaded => {
        if (config.hasOldInput) {
            capture();
        } else if (loaded && config.preferredDraftId && !current.payload && candidates.some(draft => draft.id === config.preferredDraftId)) {
            restore(candidates.find(draft => draft.id === config.preferredDraftId));
        } else if (loaded && candidates.length && !config.startFresh && !current.payload) {
            modal.show();
        } else {
            capture();
        }
    });
})();
