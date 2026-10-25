<script>
(() => {
    const element = document.getElementById('appDialog');
    const modal = bootstrap.Modal.getOrCreateInstance(element);
    const title = element.querySelector('.modal-title');
    const text = element.querySelector('[data-dialog-text]');
    const details = element.querySelector('[data-dialog-details]');
    const confirmButton = element.querySelector('[data-dialog-confirm]');
    const cancelButton = element.querySelector('[data-dialog-cancel]');
    const icon = element.querySelector('[data-dialog-icon]');
    let queue = Promise.resolve();

    async function present(options) {
        const previous = document.querySelector('.modal.show:not(#appDialog)');
        if (previous) {
            const hidden = new Promise(resolve => previous.addEventListener('hidden.bs.modal', resolve, { once: true }));
            bootstrap.Modal.getInstance(previous).hide();
            await hidden;
        }
        title.textContent = options.title || 'Konfirmasi';
        text.textContent = options.text || '';
        details.replaceChildren();
        for (const [label, value] of options.details || []) {
            const row = document.createElement('div');
            row.className = 'd-flex justify-content-between gap-3 py-1';
            const name = document.createElement('span');
            name.className = 'text-muted';
            name.textContent = label;
            const amount = document.createElement('strong');
            amount.className = 'text-end text-break';
            amount.textContent = value;
            row.append(name, amount);
            details.append(row);
        }
        details.hidden = !details.childElementCount;
        const danger = options.tone === 'danger';
        icon.className = `bi ${danger ? 'bi-exclamation-triangle text-danger' : options.notice ? 'bi-info-circle text-primary' : 'bi-check-circle text-success'} fs-4`;
        confirmButton.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
        confirmButton.textContent = options.confirmText || (options.notice ? 'Mengerti' : 'Ya, Lanjutkan');
        cancelButton.hidden = Boolean(options.notice);
        let accepted = false;
        const accept = () => { accepted = true; modal.hide(); };
        confirmButton.addEventListener('click', accept);
        const result = new Promise(resolve => element.addEventListener('hidden.bs.modal', () => {
            confirmButton.removeEventListener('click', accept);
            if (previous?.isConnected) bootstrap.Modal.getInstance(previous).show();
            resolve(accepted);
        }, { once: true }));
        element.addEventListener('shown.bs.modal', () => (options.notice ? confirmButton : cancelButton).focus(), { once: true });
        modal.show();
        return result;
    }

    const open = options => {
        const result = queue.then(() => present(options));
        queue = result.catch(() => {});
        return result;
    };
    window.appDialog = { confirm: open, notice: options => open({ ...options, notice: true }) };

    const approved = new WeakSet();
    const pending = new WeakSet();
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('form[data-confirm]')) return;
        if (approved.has(form)) { approved.delete(form); return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending.has(form)) return;
        pending.add(form);
        const submitter = event.submitter;
        try {
            const accepted = await open({ title: form.dataset.confirmTitle || 'Konfirmasi Tindakan', text: form.dataset.confirm, tone: form.dataset.confirmTone || 'danger', confirmText: form.dataset.confirmLabel || 'Ya, Hapus' });
            if (!accepted || !form.reportValidity()) return;
            approved.add(form);
            if (submitter) form.requestSubmit(submitter);
            else form.requestSubmit();
            approved.delete(form);
        } finally { pending.delete(form); }
    }, true);
})();
</script>
