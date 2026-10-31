import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/views/inventory/opnames/count-scripts.blade.php', import.meta.url), 'utf8');
const updateItem = source.slice(source.indexOf('    function updateItem('), source.indexOf('    function bindQuantity('));
const completion = source.slice(source.indexOf("    document.getElementById('btnSelesaikan')"), source.lastIndexOf('})();'));

function fixture(expiry = false) {
    const classes = new Set();
    const notes = {
        value: '', focused: false, attributes: {},
        classList: { toggle(name, active) { active ? classes.add(name) : classes.delete(name); }, remove(name) { classes.delete(name); } },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        focus() { this.focused = true; }, scrollIntoView() {},
    };
    const physical = { value: 10 };
    const help = { textContent: '' };
    const parts = { '.opname-notes': notes, '.item-physical': physical, '[data-notes-help]': help,
        '.item-difference': {}, '.item-total': {}, '.expiry-count': {} };
    const main = { dataset: { displayUnits: '[]', system: '10' }, querySelector: selector => parts[selector] ?? null };
    const expiryRows = [5, 5].map(quantity => ({
        physical: { value: quantity }, diff: { dataset: { system: String(quantity) } },
        querySelector(selector) { return selector === '.physical-value' ? this.physical : this.diff; },
    }));
    const method = { disabled: false };
    const form = {
        dataset: { completeUrl: '/opname/complete' }, submitted: false,
        querySelector: selector => selector.startsWith('[data-opname-item=') ? main
            : selector.startsWith('[data-expiry-item=') ? (expiry ? { querySelectorAll: () => expiryRows } : null) : method,
        querySelectorAll: () => [main], reportValidity: () => true,
        requestSubmit() { this.submitted = true; },
    };
    const button = { addEventListener(_, handler) { this.click = handler; } };
    const search = { value: 'filtered out', dispatchEvent() { this.refreshed = true; } };
    let confirmations = 0;
    const context = vm.createContext({ form, search, Event, Number, Math,
        round: value => Math.round(value * 10000) / 10000,
        formatQuantity: () => '', paintDifference() {},
        document: { getElementById: () => button },
        appDialog: { async confirm() { confirmations++; return true; } },
    });
    vm.runInContext(updateItem + completion, context);
    return { notes, physical, help, main, expiryRows, form, search, classes,
        update: () => vm.runInContext('updateItem("1")', context),
        complete: () => button.click(), confirmations: () => confirmations };
}

test('quantity differences mark notes for completion without requiring them for a draft', async () => {
    const ui = fixture();
    for (const quantity of [8, 12]) {
        ui.physical.value = quantity;
        ui.update();
        assert.equal(ui.main.dataset.countChanged, 'true');
        assert.match(ui.help.textContent, /Wajib saat menyelesaikan/);
        assert.equal(ui.help.hidden, false);
        assert.equal(ui.notes.placeholder, 'Keterangan selisih');
        assert.ok(!ui.notes.required);
        ui.notes.value = '   ';
        await ui.complete();
        assert.equal(ui.form.submitted, false);
        assert.equal(ui.confirmations(), 0);
        assert.equal(ui.notes.focused, true);
        assert.equal(ui.search.value, '');
        assert.ok(ui.classes.has('is-invalid'));
    }
    ui.notes.value = 'Barang ditemukan di rak lain';
    ui.update();
    assert.ok(!ui.classes.has('is-invalid'));
    await ui.complete();
    assert.equal(ui.form.submitted, true);
    assert.equal(ui.confirmations(), 1);
});

test('expiry redistribution requires notes even when the total quantity stays unchanged', async () => {
    const ui = fixture(true);
    ui.expiryRows[0].physical.value = 3;
    ui.expiryRows[1].physical.value = 7;
    ui.update();
    assert.equal(ui.physical.value, 10);
    assert.equal(ui.main.dataset.countChanged, 'true');
    await ui.complete();
    assert.equal(ui.form.submitted, false);
    ui.notes.value = 'Koreksi pembagian tanggal kedaluwarsa';
    ui.update();
    await ui.complete();
    assert.equal(ui.form.submitted, true);
});

test('matching counts leave notes optional including after a correction is reverted', async () => {
    const ui = fixture(true);
    ui.expiryRows[0].physical.value = 4;
    ui.update();
    await ui.complete();
    assert.ok(ui.classes.has('is-invalid'));
    ui.expiryRows[0].physical.value = 5;
    ui.update();
    assert.equal(ui.main.dataset.countChanged, 'false');
    assert.equal(ui.help.hidden, true);
    assert.equal(ui.notes.placeholder, 'Keterangan (opsional)');
    assert.ok(!ui.classes.has('is-invalid'));
    await ui.complete();
    assert.equal(ui.form.submitted, true);
});
