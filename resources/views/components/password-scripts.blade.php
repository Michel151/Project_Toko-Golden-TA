<script>
document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const input = document.getElementById(button.dataset.passwordToggle);
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        const label = `${visible ? 'Sembunyikan' : 'Tampilkan'} ${button.dataset.passwordLabel}`;
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', label);
        button.title = label;
        button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
(() => {
    const password = document.getElementById('userPassword');
    const confirmation = document.getElementById('userPasswordConfirmation');
    const rules = {
        length: value => [...value].length >= 8,
        uppercase: value => /\p{Lu}/u.test(value),
        lowercase: value => /\p{Ll}/u.test(value),
        number: value => /\p{N}/u.test(value),
        symbol: value => /[\p{Z}\p{S}\p{P}]/u.test(value),
    };
    const refresh = () => {
        let passed = 0;
        document.querySelectorAll('[data-password-rule]').forEach(item => {
            const met = rules[item.dataset.passwordRule](password.value);
            if (met) passed++;
            item.classList.toggle('text-success', met);
            item.classList.toggle('text-muted', !met);
            item.querySelector('i').className = met ? 'bi bi-check-circle-fill' : 'bi bi-circle';
            item.querySelector('[data-rule-status]').textContent = met ? 'Terpenuhi: ' : 'Belum terpenuhi: ';
        });
        const summary = document.getElementById('passwordRequirementsStatus');
        const summaryText = `${passed} dari 5 syarat password terpenuhi.`;
        if (summary.textContent !== summaryText) summary.textContent = summaryText;
        if (!confirmation) return;
        const match = password.value !== '' && password.value === confirmation.value;
        const mismatch = confirmation.value !== '' && !match;
        const status = document.getElementById('passwordMatchStatus');
        status.classList.toggle('text-success', match);
        status.classList.toggle('text-danger', mismatch);
        status.textContent = match ? '✓ Konfirmasi password sudah cocok.' : mismatch ? 'Konfirmasi password belum cocok.' : 'Ulangi password untuk memastikan keduanya sama.';
    };
    [password, confirmation].filter(Boolean).forEach(input => {
        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
    });
    password.form.addEventListener('reset', () => setTimeout(refresh, 0));
    window.addEventListener('pageshow', refresh);
    refresh();
})();
</script>