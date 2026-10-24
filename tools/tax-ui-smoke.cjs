const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/taxes');
    const publicRoot = path.resolve(__dirname, '../public');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage(); const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request(); const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname.startsWith('/__tax-ui/')) {
                const name = url.pathname.split('/').pop();
                if (!/^[a-z-]+$/.test(name)) return route.abort();
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, name + '.html'), 'utf8') });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const name of ['index', 'create', 'show', 'edit', 'report']) {
                await page.goto('http://127.0.0.1:8000/__tax-ui/' + name);
                await page.waitForTimeout(200);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' page overflow');
                if (name === 'index') {
                    for (const selected of ['quarterly', 'yearly', 'monthly']) {
                        await page.locator('#taxPeriod').selectOption(selected);
                        assert.equal(await page.locator('#taxMonth').isDisabled(), selected !== 'monthly');
                        assert.equal(await page.locator('#taxQuarter').isDisabled(), selected !== 'quarterly');
                        assert.equal(await page.locator('#taxYear').isDisabled(), selected === 'monthly');
                    }
                }
                if (name === 'create') {
                    await page.locator('#amount').fill('0');
                    assert(await page.locator('#amount').evaluate(input => input.checkValidity()));
                }
                if (name === 'edit') assert(await page.locator('#reason').evaluate(input => input.required));
                if (name === 'report') {
                    assert.equal(await page.locator('table.financial-lines').first().getByText('Laba (Rugi) Bersih', { exact: true }).count(), 1);
                    assert((await page.locator('table.financial-lines').first().innerText()).includes('49.999,75'));
                }
                await page.screenshot({ path: path.join(directory, name + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: tax list/forms/history/report at desktop/mobile, zero input, corrections and period controls. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
