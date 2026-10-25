<script>
// Prices with fractional rupiah use localized display and canonical form data.
function normalizeDecimalCurrencyValue(value) {
    const text = String(value ?? '');
    if (!/^[\d.]*(,\d*)?$/.test(text)) return '';
    const [whole, fraction] = text.replace(/\./g, '').split(',');
    const digits = whole.replace(/^0+(?=\d)/, '') || (fraction !== undefined ? '0' : '');
    return digits + (fraction ? '.' + fraction : '');
}

function formatDecimalCurrencyValue(value) {
    const text = String(value ?? '');
    const raw = text.includes(',') ? normalizeDecimalCurrencyValue(text) : text;
    if (!/^\d+(\.\d+)?$/.test(raw)) return '';
    const [whole, fraction = ''] = raw.split('.');
    const digits = whole.replace(/^0+(?=\d)/, '');
    const decimal = fraction.replace(/0+$/, '');
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (decimal ? ',' + decimal : '');
}

const initializedDecimalCurrencyInputs = new WeakSet();
function initializeDecimalCurrencyInputs(container = document) {
    container.querySelectorAll('.js-decimal-currency').forEach(input => {
        if (initializedDecimalCurrencyInputs.has(input)) return;
        initializedDecimalCurrencyInputs.add(input);
        input.type = 'text';
        input.inputMode = 'decimal';
        input.classList.add('text-end');
        input.value = formatDecimalCurrencyValue(input.value);
        const sync = () => {
            const entered = input.value;
            const cursor = input.selectionStart ?? entered.length;
            const position = entered.slice(0, cursor).replace(/\./g, '').length;
            if (!/^[\d.]*(,\d*)?$/.test(entered)) {
                input.setCustomValidity('Gunakan angka, titik untuk ribuan, dan koma untuk desimal.');
                return;
            }
            const raw = normalizeDecimalCurrencyValue(entered);
            const [, fraction] = entered.split(',');
            const whole = raw.split('.')[0];
            input.value = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction === undefined ? '' : ',' + fraction);
            const min = input.getAttribute('min');
            const max = input.getAttribute('max');
            const precision = Number(input.dataset.currencyDecimals ?? 2);
            const invalid = raw !== '' && ((fraction?.length ?? 0) > precision || (min !== null && Number(raw) < Number(min)) || (max !== null && Number(raw) > Number(max)));
            input.setCustomValidity(invalid ? `Periksa batas nominal dan gunakan maksimal ${precision} angka desimal.` : '');
            let index = 0, count = 0;
            while (index < input.value.length && count < position) {
                if (input.value[index] !== '.') count++;
                index++;
            }
            if (document.activeElement === input) input.setSelectionRange(index, index);
        };
        input.addEventListener('input', sync);
        sync();
    });
}
document.addEventListener('formdata', event => {
    event.target.querySelectorAll('.js-decimal-currency[name]:not(:disabled)').forEach(input => {
        event.formData.set(input.name, normalizeDecimalCurrencyValue(input.value));
    });
});
initializeDecimalCurrencyInputs();
</script>
