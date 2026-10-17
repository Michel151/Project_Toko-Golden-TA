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
        await page.waitForFunction(() => typeof validateCartStock === 'function');
        const fixture = await page.evaluate(() => {
            const product = products.find(p => p.product_units.some(u => u.conversion_to_base === 1) && p.product_units.some(u => u.conversion_to_base > 1));
            const base = product.product_units.find(u => u.conversion_to_base === 1);
            const large = product.product_units.find(u => u.conversion_to_base > 1);
            product.stocks = locations.map(l => ({ location_id: l.id, quantity: 0 }));
            addProductToCart(product);
            return { productId: product.id, baseId: base.id, largeId: large.id, conversion: large.conversion_to_base, firstLocation: locations[0].id, otherLocation: locations.find(l => l.id != tokoLocation.id).id };
        });
        const row = page.locator('.item-block').first();
        await row.locator('.product-unit-select').selectOption(String(fixture.baseId));
        assert.match(await row.locator('.stock-warning').innerText(), /kosong di semua lokasi/);
        await page.locator('#exactPayment').click();
        await page.locator('#transactionConfirmed').check();
        assert.equal(await page.locator('#saveSaleButton').isDisabled(), true);
        await page.evaluate(f => {
            products.find(p => p.id == f.productId).stocks.find(s => s.location_id == f.otherLocation).quantity = 10;
            updateSaveState();
        }, fixture);
        assert.match(await row.locator('.stock-warning').innerText(), /stok kosong.*Sisa di lokasi lain/s);
        await page.locator('#reviewStock').click();
        await row.locator('.source-location-select').selectOption(String(fixture.otherLocation));
        assert.equal(await row.locator('.stock-warning').isVisible(), false);
        assert.equal(await page.locator('#saveSaleButton').isEnabled(), true);
        await row.locator('.qty-input').fill('11');
        assert.match(await row.locator('.stock-warning').innerText(), /stok hanya 10/);
        await row.locator('.qty-input').fill('2');
        assert.equal(await row.locator('.stock-warning').isVisible(), false);
        await page.evaluate(f => {
            products.find(p => p.id == f.productId).stocks.find(s => s.location_id == f.otherLocation).quantity = f.conversion * 1.5;
        }, fixture);
        await row.locator('.product-unit-select').selectOption(String(fixture.largeId));
        assert.match(await row.locator('.stock-warning').innerText(), /stok hanya 1,5/);
        await row.locator('.product-unit-select').selectOption(String(fixture.baseId));
        assert.equal(await row.locator('.stock-warning').isVisible(), false);
        await row.locator('.source-location-select').selectOption('manual');
        assert.match(await row.locator('.stock-warning').innerText(), /Jumlah sumber stok 0 harus sama/);
        const manualIndex = await page.evaluate(f => locations.findIndex(l => l.id == f.otherLocation), fixture);
        await row.locator('.manual-qty').nth(manualIndex).fill('2');
        assert.equal(await row.locator('.stock-warning').isVisible(), false);
        await row.locator('.manual-qty').nth(manualIndex).fill(String(fixture.conversion * 2));
        assert.equal(await page.locator('#saveSaleButton').isDisabled(), true);
        await row.locator('.source-location-select').selectOption(String(fixture.otherLocation));
        await row.locator('.qty-input').fill('6');
        await page.evaluate(f => {
            const product = products.find(p => p.id == f.productId);
            product.stocks.find(s => s.location_id == f.otherLocation).quantity = 10;
            restoreItemFromOld(addItem(false), { product_id: product.id, product_unit_id: f.baseId, quantity: 6, unit_price: 5000, sources: [{ location_id: f.otherLocation, quantity: 6 }] });
        }, fixture);
        assert.equal(await page.locator('.has-stock-error').count(), 2, 'Combined demand must not exceed stock across rows');
        await page.locator('.remove-item').last().click();
        assert.equal(await row.locator('.stock-warning').isVisible(), false);
        await page.evaluate(f => {
            const product = products.find(p => p.id == f.productId);
            product.stocks.forEach(s => s.quantity = 0);
            reservedItems.push({ product_id: product.id, product_unit_id: f.baseId, sources: [{ location_id: f.otherLocation, quantity: 6 }] });
            updateSaveState();
        }, fixture);
        assert.equal(await row.locator('.stock-warning').isVisible(), false, 'Editing credits original reserved stock');
        await row.locator('.qty-input').fill('7');
        assert.match(await row.locator('.stock-warning').innerText(), /stok hanya 6/);
        await page.screenshot({ path: path.join(__dirname, 'pos-stock-warning.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(__dirname, 'pos-stock-mobile.png'), fullPage: true });
        const width = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, window: window.innerWidth, overflowing: [...document.querySelectorAll('body *')].filter(el => getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().right > window.innerWidth).slice(0, 12).map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right })) }));
        assert(width.document <= width.window, JSON.stringify(width));
        assert.deepEqual(errors, []);
        console.log('PASS: empty stock, source shortage, alternative location, quantity changes, unit conversion, manual split, aggregate demand, deletion, reserved edit stock, save blocked, mobile width. Browser fixtures only; no sale submitted.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
