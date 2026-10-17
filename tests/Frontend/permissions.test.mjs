import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/permissions/index.blade.php', import.meta.url), 'utf8');
const code = view.match(/<script>([\s\S]*?)<\/script>/)[1];

function permissionForm() {
    const listeners = {};
    const rows = Array.from({ length: 2 }, () => {
        const row = {
            querySelectorAll: () => row.inputs,
            querySelector: () => row.inputs[0],
        };
        row.inputs = ['view', 'create', 'edit', 'delete'].map(action => ({
            checked: false,
            dataset: { action },
            matches: () => true,
            closest: () => row,
        }));
        return row;
    });
    const elements = {
        permissionForm: {
            addEventListener: (event, handler) => listeners[event] = handler,
            querySelectorAll: () => rows.flatMap(row => row.inputs),
        },
        selectAll: { addEventListener: (_, handler) => listeners.selectAll = handler },
        clearAll: { addEventListener: (_, handler) => listeners.clearAll = handler },
    };
    vm.runInNewContext(code, { document: { getElementById: id => elements[id] } });
    return {
        rows,
        selectAll: () => listeners.selectAll(),
        clearAll: () => listeners.clearAll(),
        change(rowIndex, actionIndex, checked) {
            const target = rows[rowIndex].inputs[actionIndex];
            target.checked = checked;
            listeners.change({ target });
        },
    };
}

test('selecting an action also selects view for that feature only', () => {
    const ui = permissionForm();
    ui.change(0, 2, true);
    assert.deepEqual(ui.rows[0].inputs.map(input => input.checked), [true, false, true, false]);
    assert.ok(ui.rows[1].inputs.every(input => !input.checked));
});

test('revoking view clears the actions without affecting another feature', () => {
    const ui = permissionForm();
    ui.selectAll();
    ui.change(0, 0, false);
    assert.ok(ui.rows[0].inputs.every(input => !input.checked));
    assert.ok(ui.rows[1].inputs.every(input => input.checked));
});

test('bulk selection and clearing include every feature', () => {
    const ui = permissionForm();
    ui.selectAll();
    assert.ok(ui.rows.every(row => row.inputs.every(input => input.checked)));
    ui.clearAll();
    assert.ok(ui.rows.every(row => row.inputs.every(input => !input.checked)));
});
