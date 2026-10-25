const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const html = fs.readFileSync(path.resolve(__dirname, '../storage/framework/testing/edit-payment/edit.html'), 'utf8')
        .replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 960 } });
            const page = await context.newPage();
            const errors = [];
            const drafts = new Map();
            let submitted;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                const request = route.request();
                const url = new URL(request.url());
                if (url.pathname.startsWith('/sale/drafts')) {
                    if (request.method() === 'GET') return route.fulfill({ json: [...drafts.values()] });
                    const draft = JSON.parse(request.postData());
                    draft.id = url.pathname.split('/').pop();
                    draft.version = (drafts.get(draft.id)?.version || 0) + 1;
                    drafts.set(draft.id, draft);
                    return route.fulfill({ json: { version: draft.version } });
                }
                if (request.method() !== 'GET') {
                    submitted = new URLSearchParams(request.postData());
                    return route.fulfill({ contentType: 'text/html', body: 'Payment intercepted' });
                }
                if (url.pathname === '/edit-payment-preview') return route.fulfill({ contentType: 'text/html', body: html });
                if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
                return route.abort();
            });
            await page.goto('http://127.0.0.1:8000/edit-payment-preview');
            assert(await page.locator('#salePending').isChecked());
            assert(await page.locator('#amountPaid').evaluate(input => input.readOnly));
            await page.locator('#openCatalog').click();
            await page.locator('#productCatalogModal.show').waitFor();
            await page.locator('#catalogProducts [data-product-id]').filter({ hasText: 'Barang Tambahan' }).click();
            await page.locator('#productCatalogModal').getByRole('button', { name: 'Selesai' }).click();
            await page.locator('#productCatalogModal.show').waitFor({ state: 'hidden' });
            const first = page.locator('.item-block').first();
            await first.locator('.qty-input').fill('4');
            await first.locator('.price-input').fill('1200');
            assert.equal(await page.locator('.item-block').count(), 2);
            assert.equal(await page.locator('#grandTotal').inputValue(), '5.300');
            await page.locator('#transactionConfirmed').check();
            assert(await page.locator('#saveSaleButton').isEnabled());

            await page.locator('label[for="salePaid"]').click();
            assert(!(await page.locator('#amountPaid').evaluate(input => input.readOnly)));
            assert(!(await page.locator('#transactionConfirmed').isChecked()));
            await page.locator('#amountPaid').fill('4000');
            await page.locator('#transactionConfirmed').check();
            assert(await page.locator('#saveSaleButton').isDisabled());
            await page.locator('#exactPayment').click();
            assert.equal(await page.locator('#amountPaid').inputValue(), '5.300');
            await page.locator('#amountPaid').fill('6000');
            assert.equal(await page.locator('#changeAmount').inputValue(), '700');
            assert(await page.locator('#saveSaleButton').isEnabled());
            await page.waitForFunction(() => /^Draf tersimpan \d/.test(document.getElementById('draftSaveStatus').textContent));
            await page.reload();
            await page.locator('#saleDraftModal.show').waitFor();
            await page.locator('[data-resume-draft]').first().click();
            await page.locator('#saleDraftModal.show').waitFor({ state: 'hidden' });
            assert(await page.locator('#salePaid').isChecked());
            assert.equal(await page.locator('#amountPaid').inputValue(), '6.000');
            assert.equal(await page.locator('.item-block').count(), 2);
            await page.locator('#transactionConfirmed').check();
            await page.locator('#saveSaleButton').click();
            await page.locator('#appDialog.show').waitFor();
            assert((await page.locator('[data-dialog-text]').innerText()).includes('lunasi'));
            await page.locator('[data-dialog-confirm]').click();
            await page.waitForFunction(() => document.body.textContent.includes('Payment intercepted'));
            assert.equal(submitted.get('_method'), 'PUT');
            assert.equal(submitted.get('sale_status'), 'paid');
            assert.equal(submitted.get('amount_paid'), '6000');
            assert.deepEqual([...submitted].filter(([name]) => /^items\[\d+\]\[quantity\]$/.test(name)).map(([, quantity]) => quantity), ['4', '1']);
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log('PASS: pending edit, extra product, pay-now input, short payment, exact payment/change, draft restoration and confirmed update payload on desktop/mobile. All writes mocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
