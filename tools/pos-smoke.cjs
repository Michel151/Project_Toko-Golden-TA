const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await page.route('**/sale/drafts**', async route => {
            const data = route.request().postDataJSON();
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify(route.request().method() === 'GET' ? [] : { version: (data?.version || 0) + 1 }) });
        });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('input[name=email]').fill('owner@tokogolden.com');
        await page.locator('input[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        await page.goto('http://127.0.0.1:8000/sale/sales/create');
        await page.waitForFunction(() => typeof addProductToCart === 'function');
        const count = await page.evaluate(() => products.length);
        assert(count >= 50, `Need 50 catalog products; found ${count}`);
        // Isolated browser fixtures; no database stock is changed.
        await page.evaluate(() => {
            products.slice(0, 50).forEach(product => {
                product.stocks = locations.map(location => ({ location_id: location.id, quantity: 1000000 }));
                addProductToCart(product);
            });
        });
        assert.equal(await page.locator('.item-block').count(), 50);
        assert.equal(await page.locator('.source-section:not(.is-collapsed)').count(), 0);
        const dimensions = await page.locator('.pos-items-scroll').evaluate(el => ({ client: el.clientHeight, scroll: el.scrollHeight }));
        assert(dimensions.scroll > dimensions.client, 'Cart must scroll independently');
        await page.locator('#cartSearch').fill(await page.evaluate(() => products[0].name));
        assert(await page.locator('.item-block:not(.d-none)').count() < 50);
        const totals = await page.evaluate(() => {
            const before = [...document.querySelectorAll('.item-subtotal')].reduce((sum, el) => sum + Number(el.dataset.value), 0);
            addProductToCart(products[0]);
            const after = [...document.querySelectorAll('.item-subtotal')].reduce((sum, el) => sum + Number(el.dataset.value), 0);
            return { before, after, price: Number((products[0].product_units.find(u => u.is_default_sale) || products[0].product_units[0]).sell_price) };
        });
        assert.equal(await page.locator('.item-block').count(), 50, 'Repeated scan should merge default rows');
        assert.equal(totals.after - totals.before, totals.price);
        await page.locator('#exactPayment').click();
        assert.equal(await page.locator('#amountPaid').inputValue(), await page.locator('#grandTotal').inputValue());
        await page.locator('.source-toggle').first().click();
        assert.equal(await page.locator('.source-section:not(.is-collapsed)').count(), 1);
        await page.locator('.source-toggle').first().click();
        await page.locator('#barcodeScanner').fill(await page.evaluate(() => products[1].name));
        await page.locator('#barcodeScanner').press('Enter');
        assert.equal(await page.locator('.item-block').count(), 50);
        const alternativeLocation = await page.evaluate(() => locations.find(loc => loc.id != tokoLocation.id)?.id);
        if (alternativeLocation) {
            await page.locator('#defaultSourceLocation').selectOption(String(alternativeLocation));
            await page.evaluate(() => addProductToCart(products[0]));
            assert.equal(await page.locator('.item-block').count(), 51, 'Different location must create a separate row');
            assert.equal(await page.locator('.source-location-select').last().inputValue(), String(alternativeLocation));
            const firstLocation = await page.locator('.source-location-select').first().inputValue();
            assert.notEqual(firstLocation, String(alternativeLocation), 'Existing rows retain their stock location');
            await page.locator('.remove-item').last().click();
            await page.locator('#defaultSourceLocation').selectOption(firstLocation);
        }
        await page.locator('#exactPayment').click();
        await page.locator('#transactionConfirmed').check();
        assert.equal(await page.locator('#saveSaleButton').isEnabled(), true);
        const firstQty = await page.locator('.qty-input').first().inputValue();
        await page.locator('.qty-input').first().fill('0');
        await page.locator('#cartSearch').fill('tidak-ada-barang');
        assert.equal(await page.evaluate(() => document.getElementById('saleForm').reportValidity()), false);
        assert.equal(await page.locator('#cartSearch').inputValue(), '', 'Invalid filtered rows must become visible');
        await page.locator('.qty-input').first().fill(firstQty);
        await page.locator('#openCatalog').click();
        await page.locator('#catalogSearch').fill(await page.evaluate(() => products[0].name));
        await page.locator('#catalogProducts [data-product-id]').first().click();
        assert.equal(await page.locator('#productCatalogModal').isVisible(), true, 'Bulk catalog stays open after adding a product');
        await page.locator('.modal-footer [data-bs-dismiss]').click();
        await page.locator('#productCatalogModal').waitFor({ state: 'hidden' });
        await page.locator('#exactPayment').click();
        await page.screenshot({ path: path.join(__dirname, 'pos-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(__dirname, 'pos-mobile.png'), fullPage: true });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'No mobile page overflow');
        assert.deepEqual(errors, []);
        console.log('PASS: 50 products, scroll, filter, repeated scan, totals, source toggle, default stock location, exact payment, confirmation, mobile overflow, no JS errors. No sale submitted.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
