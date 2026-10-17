const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    const contexts = [];
    let cleanupPage;
    const marker = '[UJI DRAFT] ' + Date.now();
    const errors = [];
    async function session() {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        contexts.push(context);
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('input[name=email]').fill('owner@tokogolden.com');
        await page.locator('input[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        return { page, context };
    }
    async function synced(page) {
        await page.waitForFunction(() => /^Draft tersimpan \d/.test(document.getElementById('draftSaveStatus').textContent));
    }
    async function resume(page, name) {
        await page.locator('#saleDraftModal').waitFor({ state: 'visible' });
        const entry = page.locator('#saleDraftList > div').filter({ hasText: name });
        await entry.locator('[data-resume-draft]').click();
        await page.locator('#saleDraftModal').waitFor({ state: 'hidden' });
    }
    try {
        const { page, context } = await session();
        cleanupPage = page;
        await page.goto('http://127.0.0.1:8000/sale/sales/create?new=1');
        await page.waitForFunction(() => typeof addProductToCart === 'function');
        await page.evaluate(() => products.slice(0, 2).forEach(addProductToCart));
        await page.locator('input[name=customer_name]').fill(marker);
        const first = page.locator('.item-block').first();
        await first.locator('.source-toggle').click();
        await first.locator('.source-location-select').selectOption('manual');
        await first.locator('.qty-input').fill('3');
        await first.locator('.manual-qty').nth(0).fill('1');
        await first.locator('.manual-qty').nth(1).fill('2');
        await first.locator('.price-input').fill('12345');
        await page.locator('#amountPaid').fill('99999');
        await page.locator('#transactionConfirmed').check();
        await synced(page);
        const id = await page.locator('#saleDraftId').inputValue();
        const stockBefore = await page.evaluate(() => JSON.stringify(products[0].stocks));
        const response = await page.request.get('http://127.0.0.1:8000/sale/drafts');
        const saved = (await response.json()).find(d => d.id === id);
        assert.equal(saved.payload.items[0].quantity, 3);
        assert.equal(saved.payload.items[0].unit_price, 12345);
        assert.equal(saved.payload.items[0].source_mode, 'manual');
        assert.equal(saved.payload.transaction_confirmed, undefined);
        await page.reload();
        await resume(page, marker);
        assert.equal(await page.locator('.item-block').count(), 2);
        assert.equal(await page.locator('.qty-input').first().inputValue(), '3');
        assert.equal(await page.locator('.source-location-select').first().inputValue(), 'manual');
        assert.equal(await page.locator('.manual-qty').nth(0).inputValue(), '1');
        assert.equal(await page.locator('.manual-qty').nth(1).inputValue(), '2');
        assert.equal(await page.locator('#transactionConfirmed').isChecked(), false);
        assert.equal(await page.locator('#amountPaid').inputValue(), '99.999');
        assert.equal(await page.evaluate(() => JSON.stringify(products[0].stocks)), stockBefore);
        await synced(page);
        await context.setOffline(true);
        await page.locator('.qty-input').first().fill('4');
        await page.waitForFunction(() => Object.keys(localStorage).filter(k => k.startsWith('golden-sale-draft:')).some(k => JSON.parse(localStorage.getItem(k))?.payload?.items?.[0]?.quantity === 4));
        await page.close();
        await context.setOffline(false);
        const recovered = await context.newPage();
        cleanupPage = recovered;
        recovered.on('pageerror', error => errors.push(error.message));
        await recovered.goto('http://127.0.0.1:8000/sale/sales/create');
        await resume(recovered, marker);
        assert.equal(await recovered.locator('.qty-input').first().inputValue(), '4');
        assert.equal(await recovered.locator('#transactionConfirmed').isChecked(), false);
        await synced(recovered);
        const other = await session();
        await other.page.goto(`http://127.0.0.1:8000/sale/sales/create?draft=${id}`);
        await other.page.waitForFunction(name => document.querySelector('input[name=customer_name]').value === name, marker);
        assert.equal(await other.page.locator('.qty-input').first().inputValue(), '4', 'Server recovery works in a fresh browser context');
        await synced(other.page);
        await recovered.locator('input[name=customer_name]').fill(marker + ' tab A');
        await recovered.waitForFunction(previous => document.getElementById('saleDraftId').value !== previous, id);
        await synced(recovered);
        const forkId = await recovered.locator('#saleDraftId').inputValue();
        assert.notEqual(forkId, id, 'Concurrent edit forks instead of overwriting');
        await other.page.locator('input[name=customer_name]').fill(marker + ' tab B');
        await synced(other.page);
        const remoteDrafts = await (await recovered.request.get('http://127.0.0.1:8000/sale/drafts')).json();
        assert(remoteDrafts.some(d => d.payload?.customer_name === marker + ' tab A'));
        assert(remoteDrafts.some(d => d.payload?.customer_name === marker + ' tab B'));
        await recovered.locator('#showSaleDrafts').click();
        await recovered.locator('#saleDraftModal').waitFor({ state: 'visible' });
        await recovered.locator('#saleDraftList > div').filter({ hasText: marker + ' tab A' }).locator('[data-discard-draft]').click();
        await recovered.locator('#appDialog').waitFor({ state: 'visible' });
        await recovered.locator('[data-dialog-confirm]').click();
        await recovered.waitForFunction(() => document.getElementById('draftSaveStatus').textContent === 'Draft dihapus');
        assert.equal(await recovered.locator('.item-block').count(), 0);
        const closed = (await (await recovered.request.get('http://127.0.0.1:8000/sale/drafts')).json()).find(d => d.id === forkId);
        assert.equal(closed.status, 'discarded');
        await recovered.locator('#saleDraftModal .btn-close').click();
        await recovered.locator('#saleDraftModal').waitFor({ state: 'hidden' });
        await other.page.evaluate(() => {
            products.forEach(product => { product.stocks = locations.map(location => ({ location_id: location.id, quantity: 1000000 })); });
            updateSaveState();
        });
        await other.page.locator('.qty-input').first().fill('3');
        await other.page.locator('#exactPayment').click();
        await other.page.locator('#transactionConfirmed').check();
        let posted;
        await other.page.route('**/sale/sales', async route => {
            posted = new URLSearchParams(route.request().postData());
            await route.fulfill({ contentType: 'text/html', body: '<html><body>Form submission verified without saving a sale.</body></html>' });
        });
        await other.page.locator('#saveSaleButton').click();
        await other.page.locator('#appDialog').waitFor({ state: 'visible' });
        await other.page.locator('[data-dialog-confirm]').click();
        await other.page.waitForURL(url => url.pathname === '/sale/sales');
        assert.equal(posted.get('draft_id'), id);
        const finalDraft = (await (await recovered.request.get('http://127.0.0.1:8000/sale/drafts')).json()).find(d => d.id === id);
        assert.equal(Number(posted.get('draft_version')), finalDraft.version, 'Submission waits for the latest autosave revision');
        const changedPayload = structuredClone(finalDraft.payload);
        changedPayload.customer_name = marker + ' unavailable';
        changedPayload.items[0].product_id = 99999999;
        changedPayload.items[0].product_name = 'Barang sudah dihapus';
        changedPayload.items[1].source_mode = '99999999';
        changedPayload.items[1].sources = [{ location_id: 99999999, quantity: changedPayload.items[1].quantity }];
        const csrf = await recovered.locator('meta[name=csrf-token]').getAttribute('content');
        const staleResourceResponse = await recovered.request.put(`http://127.0.0.1:8000/sale/drafts/${id}`, {
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            data: { sale_id: null, version: finalDraft.version, status: 'active', client_updated_at: Date.now(), payload: changedPayload }
        });
        assert(staleResourceResponse.ok());
        const stalePage = await context.newPage();
        stalePage.on('pageerror', error => errors.push(error.message));
        await stalePage.goto('http://127.0.0.1:8000/sale/sales/create');
        await resume(stalePage, marker + ' unavailable');
        assert.match(await stalePage.locator('.stock-warning').first().innerText(), /sudah tidak tersedia/);
        assert.match(await stalePage.locator('.stock-warning').nth(1).innerText(), /Pilih sumber stok yang valid/);
        assert.equal(await stalePage.locator('#saveSaleButton').isDisabled(), true);
        await synced(stalePage);
        await recovered.setViewportSize({ width: 390, height: 844 });
        await recovered.waitForFunction(() => document.getElementById('sidebar').getBoundingClientRect().right <= 0);
        await recovered.screenshot({ path: path.join(__dirname, 'pos-draft-mobile.png'), fullPage: true });
        assert(await recovered.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        assert.deepEqual(errors, []);
        console.log('PASS: autosave, refresh recovery, manual allocations, prices, payment reset, offline/browser restart recovery, new-device server recovery, concurrent revision fork, discard, final form revision, stock unchanged, mobile width. Final sale POST intercepted; no sale saved.');
    } finally {
        if (cleanupPage && !cleanupPage.isClosed()) {
            const drafts = await (await cleanupPage.request.get('http://127.0.0.1:8000/sale/drafts')).json();
            const token = await cleanupPage.locator('meta[name=csrf-token]').getAttribute('content');
            for (const draft of drafts.filter(d => d.status === 'active' && d.payload?.customer_name?.startsWith(marker))) {
                await cleanupPage.request.put(`http://127.0.0.1:8000/sale/drafts/${draft.id}`, { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, data: { sale_id: null, version: draft.version, status: 'discarded', client_updated_at: Date.now() } });
            }
        }
        for (const context of contexts) await context.close();
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
