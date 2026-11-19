const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(require('node:path').join(__dirname, '../resources/views/purchase/receipts/scripts.blade.php'), 'utf8')
    .replace(/^<script>\s*/, '').replace(/\s*<\/script>\s*$/, '');

function receivingForm(initialPo = '', accept = true) {
    const formHandlers = {};
    const poHandlers = {};
    const po = {
        id: 'poSelect', value: initialPo,
        matches: () => false,
        closest: () => null,
        addEventListener: (name, handler) => { poHandlers[name] = handler; },
    };
    const form = {
        querySelectorAll: () => [],
        addEventListener: (name, handler) => { formHandlers[name] = handler; },
    };
    const elements = {
        receivingForm: form, poSelect: po,
        receiptLocation: {}, receiptQuarantine: {}, receiptQuarantineField: {},
    };
    let confirmations = 0;
    const location = { href: 'https://example.test/receipts/create' + (initialPo ? '?order_id=' + initialPo : '') };
    vm.runInNewContext(source, {
        document: { getElementById: id => elements[id] ?? null },
        location, URL,
        window: { appDialog: { confirm: async () => { confirmations++; return accept; } } },
    });
    return {
        po, location,
        confirmations: () => confirmations,
        edit: () => formHandlers.input({ target: { id: 'receiptNotes', matches: () => false, closest: () => null } }),
        select: async value => {
            po.value = value;
            // Native selects dispatch input before change, which then bubbles to the form.
            formHandlers.input({ target: po });
            const changed = poHandlers.change();
            formHandlers.change({ target: po });
            await changed;
        },
    };
}

test('first PO selection from an empty form does not ask for confirmation', async () => {
    const page = receivingForm();
    await page.select('20');
    assert.equal(page.confirmations(), 0);
    assert.equal(new URL(page.location.href).searchParams.get('order_id'), '20');
});

test('first PO selection preserves the no-confirmation flow after editing header fields', async () => {
    const page = receivingForm();
    page.edit();
    await page.select('20');
    assert.equal(page.confirmations(), 0);
});

test('changing an untouched PO does not mark the form as edited', async () => {
    const page = receivingForm('19');
    await page.select('20');
    assert.equal(page.confirmations(), 0);
    assert.equal(new URL(page.location.href).searchParams.get('order_id'), '20');
});

test('cancelling a PO change preserves the original selection and edited form', async () => {
    const page = receivingForm('19', false);
    page.edit();
    await page.select('20');
    assert.equal(page.confirmations(), 1);
    assert.equal(page.po.value, '19');
    assert.equal(new URL(page.location.href).searchParams.get('order_id'), '19');
});

test('accepting a PO change navigates to the new PO', async () => {
    const page = receivingForm('19');
    page.edit();
    await page.select('20');
    assert.equal(page.confirmations(), 1);
    assert.equal(new URL(page.location.href).searchParams.get('order_id'), '20');
});

test('selecting the same PO does not warn or navigate', async () => {
    const page = receivingForm('19');
    page.edit();
    await page.select('19');
    assert.equal(page.confirmations(), 0);
    assert.equal(new URL(page.location.href).searchParams.get('order_id'), '19');
});
