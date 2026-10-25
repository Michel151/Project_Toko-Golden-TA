const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/receiving');
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
            // Reproduce hosting where the separate receiving script is unavailable.
            if (url.pathname === '/js/purchase-receiving.js') return route.abort();
            if (url.pathname.startsWith('/__receiving-ui/')) {
                const name = url.pathname.split('/').pop();
                if (!['blank', 'filled'].includes(name)) return route.abort();
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
            await page.goto('http://127.0.0.1:8000/__receiving-ui/blank');
            await page.waitForFunction(() => document.querySelector('.inspection-row').dataset.status === 'uninspected');
            assert.equal(await page.locator('.inspection-row').count(), 30);
            assert.equal(await page.locator('.receiving-details:visible').count(), 0);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page overflow');
            await page.screenshot({ path: path.join(directory, 'compact-' + width + '.png'), fullPage: true });
            await page.locator('#receiptSearch').fill('Air Mineral');
            assert.equal(await page.locator('.inspection-row:visible').count(), 1);
            const first = page.locator('.inspection-row').first();
            const detail = page.locator('.receiving-details').first();
            await first.locator('.good-qty').fill('3');
            assert(await detail.isVisible());
            assert.equal(await detail.locator('.batch-quantity').inputValue(), '3');
            assert.equal(await first.getAttribute('data-status'), 'incomplete');
            await detail.locator('.batch-expiry').fill('2027-01-01');
            assert.equal(await first.getAttribute('data-status'), 'ready');
            await detail.locator('.batch-add').click();
            assert.equal(await detail.locator('.batch-list .batch-row').count(), 2);
            await detail.locator('.batch-delete').last().click();
            assert.equal(await detail.locator('.batch-list .batch-row').count(), 1);
            await detail.locator('.batch-expiry').fill('');
            await detail.locator('.detail-close').click();
            await page.locator('#receiptSearch').fill('Buku Tulis');
            assert.equal(await page.locator('.inspection-row:visible').count(), 1);
            assert.equal(await page.locator('#receivingForm').evaluate(form => form.reportValidity()), false);
            assert(await detail.isVisible(), 'Invalid hidden detail must open');
            assert.equal(await page.locator('#receiptSearch').inputValue(), '');
            const plainItem = page.locator('.inspection-row').nth(2);
            await plainItem.locator('.good-qty').fill('5');
            assert.equal(await plainItem.locator('.allocation-qty').inputValue(), '5');
            assert.equal(await plainItem.getAttribute('data-status'), 'ready');
            const payload = await page.locator('#receivingForm').evaluate(form => Object.fromEntries(new FormData(form)));
            assert.equal(payload['items[2][quantity_received]'], '5');
            assert.equal(payload['items[2][allocations][0][quantity]'], '5');
            assert(payload['items[2][allocations][0][location_id]']);
            await plainItem.locator('.good-qty').fill('0');
            await detail.locator('.batch-expiry').fill('2027-01-01');
            await page.locator('#receiptFilter').selectOption('ready');
            assert.equal(await page.locator('.inspection-row:visible').count(), 1);
            assert.equal(await first.locator('.good-qty').inputValue(), '3');
            await page.locator('#receiptFilter').selectOption('all');
            await page.locator('#receiptSearch').fill('not-found');
            assert(await page.locator('#receiptNoResults').isVisible());
            await page.locator('#receiptSearch').fill('Air Mineral');
            await first.locator('.exception-qty').first().fill('1');
            assert(await detail.locator('.receiving-issues').isVisible());
            assert(await detail.locator('.quarantine-location').evaluate(input => input.required));
            assert.equal(await first.getAttribute('data-status'), 'incomplete');
            await page.screenshot({ path: path.join(directory, 'expanded-' + width + '.png'), fullPage: true });
            await page.goto('http://127.0.0.1:8000/__receiving-ui/filled');
            await page.waitForFunction(() => document.querySelector('.inspection-row').dataset.status === 'ready');
            assert.equal(await page.locator('.good-qty').first().inputValue(), '10');
            assert.equal(await page.locator('.receiving-details').first().locator('.batch-list .batch-row').count(), 2);
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 30 items, desktop/mobile layout, search, status filters, expiry splits, hidden-field validation, issue fields and restored values. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
