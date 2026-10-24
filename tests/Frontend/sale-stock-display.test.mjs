import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/sale/form.blade.php', import.meta.url), 'utf8');
const formatter = view.slice(view.indexOf('function formatStok('), view.indexOf('function buildLocationOptions('));
const validation = view.slice(view.indexOf('function validateCartStock('), view.indexOf("document.getElementById('reviewStock')"));

function fixture(stock = 27, reservedItems = []) {
    const product = {
        id: 1, product_units: [
            { id: 1, unit_name: 'Botol', conversion_to_base: 1 },
            { id: 2, unit_name: 'Dus', conversion_to_base: 24 },
        ], stocks: [{ location_id: 1, quantity: stock }],
    };
    const element = () => ({ textContent: '', dataset: {}, classList: { toggle() {} } });
    const option = { value: '1', dataset: {}, textContent: '' };
    const stockLabel = element();
    const manualLabel = element();
    manualLabel.dataset.locationId = '1';
    const quantity = { value: '1', setCustomValidity(message) { this.message = message; } };
    const selectedUnit = { value: '1' };
    const warning = element();
    const fields = {
        '.product-select': { value: '1' }, '.product-unit-select': selectedUnit,
        '.qty-input': quantity, '.source-location-select': { value: '1' },
        '.available-qty': stockLabel, '.stock-warning': warning,
    };
    const row = {
        dataset: {}, classList: { toggle() {} }, querySelector: selector => fields[selector],
        querySelectorAll: selector => selector === '.manual-stock' ? [manualLabel] : [option],
    };
    const elements = { stockOverview: element(), stockOverviewText: element() };
    const context = vm.createContext({
        products: [product], locations: [{ id: 1, name: 'Toko' }], reservedItems,
        document: { querySelectorAll: () => [row], getElementById: id => elements[id] },
        selectedProductUnit: () => product.product_units.find(unit => unit.id == selectedUnit.value),
        rowStockSources: () => [{ locationId: '1', quantity: Number(quantity.value) }],
    });
    vm.runInContext(formatter + validation, context);
    return {
        product, selectedUnit, quantity, option, stockLabel, manualLabel, warning,
        validate: () => vm.runInContext('validateCartStock()', context),
        format: amount => { context.amount = amount; return vm.runInContext('formatStok(amount, products[0])', context); },
    };
}

test('location, available stock and manual labels stay multi-unit when selling bottles or boxes', () => {
    const ui = fixture();
    for (const unitId of ['1', '2', '1']) {
        ui.selectedUnit.value = unitId;
        assert.equal(ui.validate(), true);
        assert.equal(ui.option.textContent, 'Toko (Stok: 1 Dus 3 Botol)');
        assert.equal(ui.stockLabel.textContent, '1 Dus 3 Botol');
        assert.equal(ui.manualLabel.textContent, '1 Dus 3 Botol');
        assert.equal(ui.option.dataset.qty, 27);
        assert.equal(ui.quantity.value, '1');
    }
});

test('editing a pending sale includes its reserved stock in the multi-unit labels', () => {
    const ui = fixture(3, [{ product_id: 1, product_unit_id: 2, sources: [{ location_id: 1, quantity: 1 }] }]);
    assert.equal(ui.validate(), true);
    assert.equal(ui.stockLabel.textContent, '1 Dus 3 Botol');
});

test('stock validation still converts quantities using the selected sale unit', () => {
    const ui = fixture();
    ui.selectedUnit.value = '2';
    ui.quantity.value = '2';
    assert.equal(ui.validate(), false);
    assert.match(ui.warning.textContent, /stok hanya 1 Dus 3 Botol/);
    assert.match(ui.warning.textContent, /Total kebutuhan di keranjang 2 Dus/);
    ui.selectedUnit.value = '1';
    assert.equal(ui.validate(), true);
});

test('multi-unit stock retains fractional base quantities and exact decimal conversions', () => {
    const ui = fixture();
    assert.equal(ui.format(27.125), '1 Dus 3,125 Botol');
    assert.equal(ui.format(0.0001), '0,0001 Botol');
    assert.equal(ui.format(0), '0 Botol');
    ui.product.product_units[1].conversion_to_base = 2.1;
    assert.equal(ui.format(6.3), '3 Dus');
    assert.equal(ui.format(6.3001), '3 Dus 0,0001 Botol');
});
