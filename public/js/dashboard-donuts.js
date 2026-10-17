(() => {
    document.querySelectorAll('[data-donut]').forEach(donut => {
        const panel = donut.closest('section');
        const entries = [...panel.querySelectorAll('[data-donut-label]')];
        const total = entries.reduce((sum, entry) => sum + Number(entry.dataset.donutCount), 0);
        const tooltip = document.createElement('div');
        tooltip.className = 'dashboard-donut-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.id = `donut-tooltip-${panel.getAttribute('aria-labelledby')}`;
        tooltip.hidden = true;
        document.body.append(tooltip);
        let start = 0;
        const ranges = entries.map(entry => {
            const end = start + (total ? Number(entry.dataset.donutCount) / total * 100 : 0);
            const range = { start, end };
            start = end;
            entry.setAttribute('aria-describedby', tooltip.id);
            return range;
        });
        const clear = () => {
            tooltip.hidden = true;
            donut.style.removeProperty('--donut-highlight');
            entries.forEach(entry => entry.classList.remove('donut-active'));
        };
        const show = (index, x, y) => {
            const entry = entries[index];
            const count = Number(entry.dataset.donutCount);
            const percent = total ? count / total * 100 : 0;
            tooltip.replaceChildren();
            const title = document.createElement('strong');
            title.textContent = entry.dataset.donutLabel;
            const detail = document.createElement('div');
            detail.textContent = `${count.toLocaleString('id-ID')} ${donut.dataset.donutUnit} (${percent.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%)`;
            tooltip.append(title, detail);
            tooltip.hidden = false;
            entries.forEach((item, i) => item.classList.toggle('donut-active', i === index));
            const range = ranges[index];
            donut.style.setProperty('--donut-highlight', `conic-gradient(transparent 0% ${range.start}%, rgba(255,255,255,.35) ${range.start}% ${range.end}%, transparent ${range.end}% 100%)`);
            const bounds = tooltip.getBoundingClientRect();
            tooltip.style.left = `${Math.max(8, Math.min(x + 12, innerWidth - bounds.width - 8))}px`;
            tooltip.style.top = `${Math.max(8, Math.min(y + 12, innerHeight - bounds.height - 8))}px`;
        };
        // The CSS conic gradient starts at twelve o'clock and runs clockwise.
        const inspect = event => {
            const rect = donut.getBoundingClientRect();
            const x = (event.clientX - rect.left - rect.width / 2) / (rect.width / 2);
            const y = (event.clientY - rect.top - rect.height / 2) / (rect.height / 2);
            const radius = Math.hypot(x, y);
            if (!total || radius < .74 || radius > 1) return clear();
            const angle = ((Math.atan2(x, -y) * 180 / Math.PI + 360) % 360) / 3.6;
            const index = ranges.findIndex(range => angle >= range.start && angle < range.end);
            if (index >= 0) show(index, event.clientX, event.clientY);
        };
        donut.addEventListener('pointermove', inspect);
        donut.addEventListener('click', inspect);
        donut.addEventListener('pointerleave', clear);
        entries.forEach((entry, index) => {
            const activate = () => {
                const rect = entry.getBoundingClientRect();
                show(index, rect.left, rect.bottom);
            };
            entry.addEventListener('pointerenter', activate);
            entry.addEventListener('pointerleave', clear);
            entry.addEventListener('focus', activate);
            entry.addEventListener('blur', clear);
            entry.addEventListener('click', activate);
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') clear(); });
        document.addEventListener('pointerdown', event => { if (!panel.contains(event.target)) clear(); });
        window.addEventListener('scroll', clear, true);
        window.addEventListener('resize', clear);
    });
})();
