const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.join(__dirname, '../storage/framework/testing/costing');
    const publicRoot = path.resolve(__dirname, '../public');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname.startsWith('/__costing-ui/')) {
                const name = url.pathname.split('/').pop();
                if (!/^[a-z-]+$/.test(name)) return route.abort();
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, name + '.html'), 'utf8') });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) {
                return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            }
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 950 });
            for (const name of ['costing-ready', 'costing-legacy', 'costing-active', 'costing-history', 'costing-profit-loss', 'costing-opname', 'costing-format-history', 'costing-format-list']) {
                await page.goto('http://127.0.0.1:8000/__costing-ui/' + name);
                await page.waitForTimeout(400);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' page overflow');
                if (name !== 'costing-opname') {
                    const header = page.locator('.page-content .inventory-heading, .page-content .report-heading').first();
                    assert.equal(await header.evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(36, 50, 40)');
                }
                if (['costing-ready', 'costing-legacy', 'costing-active'].includes(name)) {
                    assert.equal(await page.locator('.page-content [name=inventory_value]').count(), 0);
                    assert.equal(await page.locator('.page-content form[method=POST]').count(), 0);
                    assert(await page.locator('.page-content').innerText().then(text => text.includes('Otomatis dari Stok Nol')));
                    if (name === 'costing-ready') assert(await page.locator('tbody').innerText().then(text => text.includes('Menunggu Penerimaan')));
                    if (name === 'costing-legacy') assert(await page.locator('tbody').innerText().then(text => text.includes('Stok Lama / Metode Sebelumnya')));
                }
                if (name === 'costing-opname') {
                    const value = page.locator('[data-valuation-field]');
                    assert.equal(await value.isVisible(), false);
                    const quantity = page.locator('.qty-unit').first();
                    await quantity.fill('105');
                    assert.equal(await value.isVisible(), true);
                    await value.locator('input').fill('12000');
                    assert.equal(await page.locator('.item-difference').innerText(), '+5 Botol');
                    await quantity.fill('100');
                    assert.equal(await value.locator('input').isDisabled(), true);
                    await quantity.fill('105');
                }
                if (name.startsWith('costing-format-')) {
                    const content = await page.locator('tbody').innerText();
                    assert(content.includes('52.702,70'));
                    assert(!content.includes('52.702,702703'));
                    assert(!content.includes('37,0000'));
                }
                if (name === 'costing-format-history') {
                    const content = await page.locator('tbody').innerText();
                    assert(content.includes('PO/test/1'));
                    assert(content.includes('INV/2026/10/'));
                    assert(!content.includes('PurchaseReceipt #'));
                    assert(!content.includes('Sale #'));
                    assert.match(await page.getByRole('link', { name: 'PO/test/1', exact: true }).getAttribute('href'), /\/purchase\/orders\/\d+$/);
                    const invoice = page.locator('tbody a').filter({ hasText: 'INV/2026/10/' }).first();
                    assert.match(await invoice.getAttribute('href'), /\/sale\/sales\/\d+$/);
                }
                await page.screenshot({ path: path.join(directory, name + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 8 costing/report/opname fixtures at 1440px and 390px; readable Indonesian numbers, read-only status, conditional gain cost, green headers, no overflow or JavaScript errors. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
