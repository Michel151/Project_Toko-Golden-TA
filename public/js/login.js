(() => {
    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');
    toggle?.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        const label = visible ? 'Sembunyikan password' : 'Tampilkan password';
        toggle.setAttribute('aria-pressed', String(visible));
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
    const cooldown = document.getElementById('loginCooldown');
    if (!cooldown) return;
    const update = () => {
        const seconds = Math.max(0, Math.ceil(Number(cooldown.dataset.retryUntil) - Date.now() / 1000));
        cooldown.textContent = seconds > 0
            ? `Terlalu banyak percobaan login. Coba lagi dalam ${seconds} detik.`
            : 'Anda dapat mencoba login kembali.';
        return seconds;
    };
    if (update() > 0) {
        const timer = setInterval(() => { if (update() === 0) clearInterval(timer); }, 1000);
    }
})();
