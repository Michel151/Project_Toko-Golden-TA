const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/profit-loss');
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
            if (url.pathname.startsWith('/__profit-loss-ui/')) {
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
            await page.setViewportSize({ width, height: 1000 });
            for (const name of ['complete', 'partial', 'unavailable', 'mismatch']) {
                await page.goto('http://127.0.0.1:8000/__profit-loss-ui/' + name);
                await page.waitForTimeout(300);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' page overflow');
                assert.equal(await page.locator('.report-heading').evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(36, 50, 40)');
                assert.equal(await page.locator('.pl-section').count(), 3);
                assert.equal(await page.getByText('PENDAPATAN DAN BEBAN LAINNYA', { exact: true }).count(), 1);
                assert.equal(await page.locator('table.financial-lines').first().getByText('Harga Pokok Penjualan', { exact: true }).count(), 1);
                const status = await page.locator('#inventoryReconciliation .badge').innerText();
                assert.equal(status, { complete: 'Seimbang', partial: 'Sebagian Data', unavailable: 'Belum Tersedia', mismatch: 'Perlu Diperiksa' }[name]);
                if (name === 'unavailable') assert((await page.locator('#inventoryReconciliation tbody').innerText()).includes('Belum tersedia'));
                assert.equal(await page.locator('input[type=date]').count(), 0);
                for (const selected of ['quarterly', 'yearly', 'monthly']) {
                    await page.locator('#plPeriod').selectOption(selected);
                    assert.equal(await page.locator('#plMonth').isDisabled(), selected !== 'monthly');
                    assert.equal(await page.locator('#plQuarter').isDisabled(), selected !== 'quarterly');
                    assert.equal(await page.locator('#plYear').isDisabled(), selected === 'monthly');
                }
                await page.screenshot({ path: path.join(directory, name + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 4 profit-loss/reconciliation states at desktop/mobile, period controls, no overflow or JavaScript errors. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
