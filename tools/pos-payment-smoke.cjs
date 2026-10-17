const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/sale/drafts**', async route => {
            const data = route.request().postDataJSON();
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify(route.request().method() === 'GET' ? [] : { version: (data?.version || 0) + 1 }) });
        });
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('input[name=email]').fill('kasir@tokogolden.com');
        await page.locator('input[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        let posted;
        let posts = 0;
        page.on('dialog', dialog => { errors.push('Unexpected native browser dialog'); dialog.dismiss(); });
        await page.route('**/sale/sales', async route => {
            posted = new URLSearchParams(route.request().postData());
            posts++;
            await route.fulfill({ contentType: 'text/html', body: '<html><body>Payment POST intercepted.</body></html>' });
        });
        for (const mode of ['synced', 'dirty']) {
            posted = null;
            const previousPosts = posts;
            await page.goto('http://127.0.0.1:8000/sale/sales/create?new=1');
            await page.evaluate(() => {
                const product = products.find(p => p.name === 'Teh Pucuk') || products[0];
                product.stocks = locations.map(location => ({ location_id: location.id, quantity: 1000000 }));
                addProductToCart(product);
            });
            await page.locator('#exactPayment').click();
            await page.locator('#transactionConfirmed').check();
            await page.waitForFunction(() => /^Draft tersimpan \d/.test(document.getElementById('draftSaveStatus').textContent));
            if (mode === 'dirty') await page.locator('input[name=customer_name]').fill('Payment test');
            assert.equal(await page.locator('#saveSaleButton').isEnabled(), true);
            assert.equal(await page.evaluate(() => document.getElementById('saleForm').checkValidity()), true);
            if (mode === 'synced') {
                await page.locator('#saveSaleButton').click();
                await page.locator('#appDialog').waitFor({ state: 'visible' });
                assert.match(await page.locator('[data-dialog-details]').innerText(), /Total Transaksi.*Jumlah Bayar.*Kembalian/s);
                await page.screenshot({ path: path.join(__dirname, 'payment-dialog-desktop.png') });
                await page.setViewportSize({ width: 390, height: 844 });
                await page.waitForFunction(() => document.getElementById('sidebar').getBoundingClientRect().right <= 0);
                await page.screenshot({ path: path.join(__dirname, 'payment-dialog-mobile.png') });
                assert(await page.locator('#appDialog .modal-content').evaluate(el => { const rect = el.getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth; }));
                await page.locator('[data-dialog-cancel]').click();
                await page.locator('#appDialog').waitFor({ state: 'hidden' });
                await page.setViewportSize({ width: 1280, height: 720 });
                assert.equal(posted, null, 'Cancelling confirmation must not send a sale');
            }
            await page.locator('#saveSaleButton').click();
            await page.locator('#appDialog').waitFor({ state: 'visible' });
            await page.locator('[data-dialog-confirm]').click();
            await page.waitForURL(url => url.pathname === '/sale/sales', { timeout: 5000 });
            assert.equal(posts - previousPosts, 1);
            assert.equal(posted.get('transaction_confirmed'), '1');
            assert.equal(posted.get('amount_paid'), posted.get('items[0][unit_price]'));
            assert(Number(posted.get('draft_version')) > 0);
        }
        assert.deepEqual(errors, []);
        console.log('PASS: cashier payment submits both already-synced and dirty drafts, one confirmation each, correct payment and draft revision. All sale and draft requests intercepted; no database changes.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
