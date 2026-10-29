(() => {
    const patterns = window.returnNavigationPatterns.map(pattern => new RegExp(pattern));
    function enhanceLink(link) {
        if (!link.matches('a[href]') || link.hasAttribute('data-back-link') || link.hasAttribute('download') || link.getAttribute('href').startsWith('#')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !patterns.some(pattern => pattern.test(url.pathname))) return;
        const context = url.pathname === location.pathname
            ? new URL(location.href).searchParams.get('return_to')
            : location.pathname + location.search + location.hash;
        if (context) { url.searchParams.set('return_to', context); link.href = url.href; }
    }
    function enhanceForm(form) {
        if (!form.matches('form') || form.closest('#sidebar') || form.action.includes('/logout')) return;
        const current = new URL(location.href);
        let context = current.searchParams.get('return_to');
        if (!context && form.method.toLowerCase() !== 'get' && !patterns.some(pattern => pattern.test(current.pathname))) {
            context = current.pathname + current.search + current.hash;
        }
        if (!context) return;
        const url = new URL(form.action || location.href, location.href);
        if (url.origin !== location.origin) return;
        if (form.method.toLowerCase() === 'get') {
            let input = form.querySelector('input[name="return_to"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'return_to'; form.append(input); }
            input.value = context;
        } else {
            url.searchParams.set('return_to', context);
            form.action = url.href;
        }
    }
    function enhance(root) {
        if (root.matches?.('a[href]')) enhanceLink(root);
        if (root.matches?.('form')) enhanceForm(root);
        root.querySelectorAll?.('a[href]').forEach(enhanceLink);
        root.querySelectorAll?.('form').forEach(enhanceForm);
    }
    enhance(document);
    ['pointerdown', 'click', 'contextmenu'].forEach(type => document.addEventListener(type, event => {
        const link = event.target.closest('a[href]');
        if (link) enhanceLink(link);
    }, true));
    document.addEventListener('submit', event => enhanceForm(event.target), true);
    new MutationObserver(changes => changes.forEach(change => change.addedNodes.forEach(node => {
        if (node.nodeType === Node.ELEMENT_NODE) enhance(node);
    }))).observe(document.body, {childList: true, subtree: true});
})();
