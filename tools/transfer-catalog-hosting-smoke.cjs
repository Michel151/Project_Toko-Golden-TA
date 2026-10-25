const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const html = fs.readFileSync(path.resolve(__dirname, '../storage/framework/testing/transfer/form.html'), 'utf8');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname === '/__transfer-ui') return route.fulfill({ contentType: 'text/html', body: html });
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            // Local JS files are deliberately unavailable, as on a partial hosting upload.
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('http://127.0.0.1:8000/__transfer-ui');
            await page.waitForFunction(() => document.getElementById('transferRoute').textContent.length > 0);
            const products = await page.evaluate(() => window.stockTransferConfig.products);
            const source = String(products[0].stocks.find(stock => stock.quantity >= 1).location_id);
            const destination = await page.locator('#toLocation option').evaluateAll((options, source) => options.find(option => option.value && option.value !== source).value, source);
            await page.locator('#fromLocation').selectOption(source);
            await page.locator('#toLocation').selectOption(destination);
            assert(await page.locator('#productSearch').isEnabled());

            await page.locator('#productSearch').fill(products[0].name.slice(0, -1));
            await page.locator(`#productResults [data-product-id="${products[0].id}"]`).click();
            await page.locator('#unitSelect').selectOption(String(products[0].product_units.find(unit => unit.is_base_unit).id));
            await page.locator('#qtyInput').fill('1');
            await page.locator('#addTransferItem').click();
            assert.equal(await page.locator('#transferItems tr').count(), 1);

            await page.locator('#openProductCatalog').click();
            await page.locator('#transferProductCatalogModal.show').waitFor();
            await page.locator('#catalogSearch').fill(products[1].name);
            assert.equal(await page.locator('#catalogProducts [data-product-id]').count(), 1);
            await page.locator('#catalogProducts [data-product-id]').click();
            await page.locator('#transferProductCatalogModal.show').waitFor({ state: 'hidden' });
            await page.locator('#qtyInput').fill('1');
            await page.locator('#addTransferItem').click();
            assert.equal(await page.locator('#transferItems tr').count(), 2);
            assert(await page.locator('#saveTransfer').isEnabled());
            const payload = await page.locator('#transferForm').evaluate(form => Object.fromEntries(new FormData(form)));
            assert.equal(payload.from_location_id, source);
            assert.equal(payload.to_location_id, destination);
            assert.equal(payload['items[0][product_id]'], String(products[0].id));
            assert.equal(payload['items[1][product_id]'], String(products[1].id));
            assert.equal(payload['items[0][quantity]'], '1');
            assert.equal(payload['items[1][quantity]'], '1');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        }
        assert.deepEqual(errors, []);
        console.log('PASS: desktop/mobile product search, catalog, two-item payload and enabled save with local JS unavailable. No transfer saved.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
