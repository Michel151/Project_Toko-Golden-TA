const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.join(__dirname, '../storage/framework/testing/expenses');
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
            if (url.pathname.startsWith('/__expense-ui/')) {
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
            for (const name of ['expenses-list', 'expenses-quarterly', 'expenses-yearly', 'expenses-create', 'expenses-edit', 'expenses-show', 'profit-loss', 'profit-loss-quarterly', 'profit-loss-yearly']) {
                await page.goto('http://127.0.0.1:8000/__expense-ui/' + name);
                await page.waitForTimeout(400);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflow');
                const header = page.locator('.page-content .inventory-heading, .page-content .report-heading').first();
                assert.equal(await header.evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(36, 50, 40)');
                if (name === 'expenses-create') {
                    assert(await page.locator('#paidDate').isDisabled());
                    await page.locator('#paymentStatus').selectOption('paid');
                    assert(await page.locator('#paidDate').isEnabled());
                    assert(await page.locator('#paidDate').evaluate(node => node.required));
                }
                if (['expenses-list', 'expenses-quarterly', 'expenses-yearly'].includes(name)) {
                    const mode = name === 'expenses-quarterly' ? 'quarterly' : name === 'expenses-yearly' ? 'yearly' : 'monthly';
                    assert.equal(await page.locator('#expensePeriod').inputValue(), mode);
                    assert.equal(await page.locator('#expenseMonth').isVisible(), mode === 'monthly');
                    assert.equal(await page.locator('#expenseQuarter').isVisible(), mode === 'quarterly');
                    assert.equal(await page.locator('#expenseYear').isVisible(), mode !== 'monthly');
                    if (name === 'expenses-list') {
                        for (const selected of ['quarterly', 'yearly', 'monthly']) {
                            await page.locator('#expensePeriod').selectOption(selected);
                            assert.equal(await page.locator('#expenseMonth').isDisabled(), selected !== 'monthly');
                            assert.equal(await page.locator('#expenseQuarter').isDisabled(), selected !== 'quarterly');
                            assert.equal(await page.locator('#expenseYear').isDisabled(), selected === 'monthly');
                        }
                    }
                }
                if (name.startsWith('profit-loss')) {
                    assert.equal(await page.locator('.page-content input[type=date]').count(), 0);
                    const mode = name === 'profit-loss-quarterly' ? 'quarterly' : name === 'profit-loss-yearly' ? 'yearly' : 'monthly';
                    assert.equal(await page.locator('#plPeriod').inputValue(), mode);
                    assert.equal(await page.locator('#plMonth').isVisible(), mode === 'monthly');
                    assert.equal(await page.locator('#plQuarter').isVisible(), mode === 'quarterly');
                    assert.equal(await page.locator('#plYear').isVisible(), mode !== 'monthly');
                    if (name === 'profit-loss') {
                        for (const selected of ['quarterly', 'yearly', 'monthly']) {
                            await page.locator('#plPeriod').selectOption(selected);
                            assert.equal(await page.locator('#plMonth').isDisabled(), selected !== 'monthly');
                            assert.equal(await page.locator('#plQuarter').isDisabled(), selected !== 'quarterly');
                            assert.equal(await page.locator('#plYear').isDisabled(), selected === 'monthly');
                        }
                    }
                }
                await page.screenshot({ path: path.join(directory, name + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 9 expense/report fixtures at desktop/mobile; monthly/quarterly/yearly controls, no overflow or JavaScript errors, green headers. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
