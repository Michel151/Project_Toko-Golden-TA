const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const html = fs.readFileSync(path.resolve(__dirname, '../storage/framework/testing/costing/costing-opname.html'), 'utf8');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (route.request().method() !== 'GET') return route.abort();
            if (url.pathname === '/__opname-cost') return route.fulfill({ contentType: 'text/html', body: html });
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('http://127.0.0.1:8000/__opname-cost');
            const row = page.locator('[data-opname-item]').first();
            const system = Number(await row.getAttribute('data-system'));
            await row.locator('.qty-base').fill(String(system + 1));
            const display = row.locator('[data-valuation-display]');
            const raw = row.locator('[data-valuation-value]');
            assert(await display.isVisible());
            for (const [entered, formatted, normalized] of [
                ['2300', '2.300', '2300'],
                ['2300,50', '2.300,50', '2300.50'],
                ['1234567,1234567890', '1.234.567,1234567890', '1234567.1234567890'],
                ['0,0000000001', '0,0000000001', '0.0000000001'],
                ['', '', ''],
            ]) {
                await display.fill(entered);
                assert.equal(await display.inputValue(), formatted);
                assert.equal(await raw.inputValue(), normalized);
                assert(await display.evaluate(input => input.validity.valid));
                const payloadValue = await raw.evaluate(input => new FormData(input.form).get(input.name));
                assert.equal(payloadValue, normalized);
            }
            await display.pressSequentially('2300');
            assert.equal(await display.inputValue(), '2.300');
            await display.fill('2300,12345678901');
            assert(!(await display.evaluate(input => input.validity.valid)));
            await display.fill('abc');
            assert(!(await display.evaluate(input => input.validity.valid)));
            await display.fill('2300');
            await row.locator('.qty-base').fill(String(system));
            assert(await display.isHidden());
            assert(await raw.evaluate(input => input.disabled));
            assert.equal(await raw.evaluate(input => new FormData(input.form).has(input.name)), false);
            await row.locator('.qty-base').fill(String(system + 1));
            assert.equal(await display.inputValue(), '2.300');
            assert.equal(await raw.inputValue(), '2300');
        }
        assert.deepEqual(errors, []);
        console.log('PASS: grouped rupiah input, decimal precision, typing, canonical payload, invalid values and stock-difference visibility on desktop/mobile. No records saved.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
