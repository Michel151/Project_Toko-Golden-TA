(() => {
    if (!window.flatpickr) return;

    function initialize(root) {
        const inputs = root.matches?.('input[type="date"]') ? [root] : [];
        inputs.push(...(root.querySelectorAll?.('input[type="date"]') ?? []));
        for (const input of inputs) {
            if (input._flatpickr) continue;
            let invalidText = '';
            const picker = flatpickr(input, {
                locale: 'id',
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd/m/Y',
                // Do not copy behavioural classes such as batch-expiry to the display input.
                altInputClass: [...input.classList].filter(name => ['form-control', 'form-control-sm', 'form-control-lg', 'is-invalid', 'is-valid'].includes(name)).join(' ') || 'form-control',
                allowInput: true,
                disableMobile: true,
                minDate: input.min || undefined,
                maxDate: input.max || undefined,
                parseDate: (value, format) => {
                    const match = (format === 'd/m/Y' ? /^(\d{2})\/(\d{2})\/(\d{4})$/ : /^(\d{4})-(\d{2})-(\d{2})$/).exec(value);
                    if (!match) return undefined;
                    const [year, month, day] = format === 'd/m/Y'
                        ? [Number(match[3]), Number(match[2]), Number(match[1])]
                        : [Number(match[1]), Number(match[2]), Number(match[3])];
                    const date = new Date(year, month - 1, day);
                    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : undefined;
                },
                errorHandler: () => {}, // Invalid manual dates are reported through field validation.
                onChange: (dates, _value, instance) => {
                    if (dates.length) invalidText = '';
                    instance.altInput?.setCustomValidity('');
                },
                onClose: (_dates, _value, instance) => {
                    if (invalidText) {
                        instance.altInput.value = invalidText;
                        validate();
                    }
                },
            });
            const display = picker.altInput;
            display.placeholder = 'dd/mm/yyyy';
            display.setAttribute('aria-label', input.getAttribute('aria-label')
                || (input.id && document.querySelector('label[for="' + CSS.escape(input.id) + '"]')?.textContent.trim())
                || 'Tanggal (dd/mm/yyyy)');
            // Let existing code focus the visible field when opening expiry details.
            input.focus = options => display.focus(options);
            function validate() {
                const value = display.value.trim();
                let message = '';
                if (value) {
                    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
                    const date = match && new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
                    if (!match || date.getFullYear() !== Number(match[3])
                        || date.getMonth() !== Number(match[2]) - 1 || date.getDate() !== Number(match[1])) {
                        message = 'Masukkan tanggal yang valid dengan format dd/mm/yyyy.';
                    } else {
                        const iso = match[3] + '-' + match[2] + '-' + match[1];
                        if ((input.min && iso < input.min) || (input.max && iso > input.max)) {
                            message = 'Tanggal berada di luar rentang yang diizinkan.';
                        }
                    }
                }
                invalidText = message ? value : '';
                display.setCustomValidity(message);
                return !message;
            }
            display.addEventListener('input', validate);
            // Keep invalid text visible for correction instead of silently changing its date.
            display.addEventListener('blur', event => {
                if (!validate()) event.stopImmediatePropagation();
            }, true);
            display.addEventListener('keydown', event => {
                if (['Enter', 'Tab'].includes(event.key) && !validate()) {
                    event.stopImmediatePropagation();
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        display.reportValidity();
                    }
                }
            }, true);
            new MutationObserver(changes => {
                display.required = input.required;
                display.disabled = input.disabled;
                for (const { attributeName } of changes) {
                    if (attributeName === 'min') picker.set('minDate', input.min || undefined);
                    if (attributeName === 'max') picker.set('maxDate', input.max || undefined);
                }
            }).observe(input, { attributes: true, attributeFilter: ['required', 'disabled', 'min', 'max'] });
        }
    }
    initialize(document);
    new MutationObserver(changes => {
        for (const change of changes) for (const node of change.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) initialize(node);
        }
    }).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('click', event => {
        const label = event.target.closest('label[for]');
        const picker = label && document.getElementById(label.htmlFor)?._flatpickr;
        if (picker && !picker.input.disabled) picker.open();
    });
})();
