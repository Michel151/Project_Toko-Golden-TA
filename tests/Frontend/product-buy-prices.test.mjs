import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/master/products/form.blade.php', import.meta.url), 'utf8');
const code = view.slice(view.indexOf('function updateUnitPreview()'), view.indexOf('function addProductUnitRow()'));

function form() {
    const elements = {
        baseUnitIndex: { value: '3' },
        purchaseUnitIndex: { value: '0' },
        unitPreview: { textContent: '' },
    };
    const rows = ['Dus', 'Bal', 'Slop', 'Bungkus'].map((name, index) => {
        const fields = {
            '.unit-select': { options: [{ text: name }], selectedIndex: 0 },
            '.contains-qty-input': { value: String([6, 10, 10, 16][index]) },
            '.contains-unit-select': { value: index === 3 ? '' : String(index + 1) },
            '.conversion-input': { value: '' },
            '.conversion-preview': { value: '' },
            '.buy-price-input': { value: index === 0 ? '21.120.000' : '0', classList: { toggle() {} } },
        };
        return { querySelector: selector => fields[selector] };
    });
    const context = vm.createContext({
        Intl,
        document: { querySelectorAll: () => rows, getElementById: id => elements[id] },
    });
    vm.runInContext(code, context);
    return {
        rows, elements,
        refresh: () => vm.runInContext('updateUnitPreview()', context),
        prices: () => rows.map(row => row.querySelector('.buy-price-input').value),
    };
}

test('prices and editable field follow the selected purchase unit', () => {
    const ui = form();
    ui.refresh();
    assert.deepEqual(ui.prices(), ['21.120.000', '3.520.000', '352.000', '35.200']);
    assert.deepEqual(ui.rows.map(row => row.querySelector('.buy-price-input').readOnly), [false, true, true, true]);
    ui.elements.purchaseUnitIndex.value = '1';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['21.120.000', '3.520.000', '352.000', '35.200']);
    assert.deepEqual(ui.rows.map(row => row.querySelector('.buy-price-input').readOnly), [true, false, true, true]);
    ui.rows[1].querySelector('.buy-price-input').value = '4.000.000';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['24.000.000', '4.000.000', '400.000', '40.000']);
});

test('changing packaging updates derived prices and ignores base contains quantity', () => {
    const ui = form();
    ui.refresh();
    ui.rows[0].querySelector('.contains-qty-input').value = '8';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['21.120.000', '2.640.000', '264.000', '26.400']);
    assert.equal(ui.rows[3].querySelector('.conversion-input').value, 1);
});

test('fractional and zero prices are displayed correctly', () => {
    const ui = form();
    ui.rows[0].querySelector('.buy-price-input').value = '1.000,25';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['1.000,25', '166,71', '16,67', '1,67']);
    ui.rows[0].querySelector('.buy-price-input').value = '0';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['0', '0', '0', '0']);
});

test('invalid and cyclic chains clear derived prices', () => {
    const ui = form();
    ui.rows[1].querySelector('.contains-unit-select').value = '0';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['21.120.000', '', '', '']);
    ui.rows[1].querySelector('.contains-unit-select').value = '';
    ui.refresh();
    assert.deepEqual(ui.prices(), ['21.120.000', '', '', '']);
});
