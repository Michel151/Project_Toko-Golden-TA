const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('[name=email]').fill('owner@tokogolden.com');
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'), page.locator('button[type=submit]').click()]);
        let fieldsChecked = false;
        for (const [width, height] of [[1440, 900], [390, 844]]) {
            await page.setViewportSize({ width, height });
            for (const route of ['/purchase/returns', '/purchase/returns/create', '/purchase/receipts/create']) {
                const response = await page.goto('http://127.0.0.1:8000' + route);
                assert.equal(response.status(), 200, route);
                if (route === '/purchase/receipts/create') {
                    const option = page.locator('#poSelect option').nth(1);
                    if (await option.count()) {
                        await page.goto('http://127.0.0.1:8000' + route + '?order_id=' + await option.getAttribute('value'));
                        const row = page.locator('.inspection-row').first();
                        if (await row.count()) {
                            fieldsChecked = true;
                            const detail = page.locator('#' + await row.getAttribute('data-details'));
                            const max = Number(await row.getAttribute('data-max'));
                            await row.locator('.good-qty').fill(String(max));
                            await row.locator('.exception-qty').first().fill('1');
                            assert.equal(await row.locator('.good-qty').evaluate(el => el.validity.customError), true);
                            await row.locator('.good-qty').fill('0');
                            await row.locator('.exception-qty').first().fill('0');
                            await row.locator('.exception-qty').last().fill(String(Math.min(max, 1)));
                            assert.equal(await detail.locator('.inspection-reason').getAttribute('required'), '');
                            assert.equal(await detail.locator('.quarantine-location').getAttribute('required'), null);
                            if (await detail.locator('.batch-expiry').count()) {
                                assert.equal(await detail.locator('.batch-expiry').first().getAttribute('required'), null);
                            }
                            await row.locator('.exception-qty').last().fill('0');
                        }
                    }
                }
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow: ${route} ${width}`);
                await page.locator('.table-responsive').evaluateAll(elements => elements.forEach(el => el.scrollLeft = 0));
                await page.screenshot({ path: path.join(__dirname, `purchase-${route.split('/').slice(2).join('-')}-${width}.png`), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        const previewDirectory = path.join(__dirname, '../storage/framework/testing/purchase-ui');
        for (const name of ['claim-form', 'claim-draft', 'claim-approved', 'receipt-batches-form', 'receipt-batches-show', 'stock-card-batch-po']) {
            const file = path.join(previewDirectory, name + '.html');
            if (!fs.existsSync(file)) continue;
            const url = 'http://127.0.0.1:8000/__purchase_preview/' + name;
            const html = fs.readFileSync(file, 'utf8').replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
            await page.route(url, route => route.fulfill({ contentType: 'text/html', body: html }));
            for (const [width, height] of [[1440, 900], [390, 844]]) {
                await page.setViewportSize({ width, height });
                await page.goto(url);
                if (name === 'stock-card-batch-po') {
                    await page.locator('[data-bs-target^="#stockCardExpiryBatchDetail"]').first().click();
                    await page.locator('tr.collapse.show').first().waitFor();
                    assert.equal(await page.locator('tr.collapse.show').first().getByRole('columnheader', { name: 'No. PO', exact: true }).count(), 1);
                    assert.equal(await page.locator('tr.collapse.show').first().getByRole('link', { name: 'PO-2026-000001', exact: true }).count(), 1);
                    assert.equal(await page.locator('tr.collapse.show').first().getByRole('link', { name: 'PO-2026-000002', exact: true }).count(), 1);
                }
                if (name === 'receipt-batches-form') {
                    fieldsChecked = true;
                    const row = page.locator('.inspection-row').first();
                    const detail = page.locator('#' + await row.getAttribute('data-details'));
                    const panel = detail.locator('.batch-panel');
                    assert.equal(await panel.locator('[name$="[batch_code]"]').count(), 0);
                    assert.equal(await panel.locator('.batch-add').count(), 1);
                    assert((await panel.locator('.batch-add').textContent()).includes('Tambah Kedaluwarsa'));
                    await row.locator('.good-qty').fill('20');
                    assert.equal(await panel.locator('.batch-quantity').inputValue(), '20');
                    assert.equal(await panel.locator('.batch-delete').isDisabled(), true);
                    await panel.locator('.batch-add').click();
                    assert.equal(await panel.locator('.batch-list .batch-row').count(), 2);
                    await panel.locator('.batch-quantity').nth(0).fill('12');
                    await panel.locator('.batch-quantity').nth(1).fill('8');
                    await panel.locator('.batch-expiry').nth(0).fill('2030-01-15');
                    await panel.locator('.batch-expiry').nth(1).fill('2030-07-15');
                    assert.equal(await row.locator('.good-qty').evaluate(el => el.validity.customError), false);
                    await panel.locator('.batch-quantity').nth(1).fill('7');
                    assert.equal(await row.locator('.good-qty').evaluate(el => el.validity.customError), true);
                    await panel.locator('.batch-delete').nth(1).click();
                    await panel.locator('.batch-add').click();
                    assert.equal(await panel.locator('.batch-quantity').nth(1).getAttribute('name'), 'items[0][batches][2][quantity]');
                    await panel.locator('.batch-quantity').nth(1).fill('8');
                    await panel.locator('.batch-expiry').nth(1).fill('2030-07-15');
                    assert.equal(await page.locator('#receivingForm').evaluate(el => el.checkValidity()), true);
                    await page.locator('.table-responsive').evaluateAll(elements => elements.forEach(el => el.scrollLeft = 0));
                }
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${name} ${width} overflow`);
                if (name === 'claim-draft') {
                    await page.locator('button').filter({ hasText: 'Setujui dan Terbitkan PO' }).click();
                    await page.locator('#appDialog.show').waitFor();
                    await page.locator('#appDialog [data-dialog-cancel]').click();
                    await page.locator('#appDialog.show').waitFor({ state: 'hidden' });
                }
                await page.evaluate(() => scrollTo(0, 0));
                await page.screenshot({ path: path.join(__dirname, `purchase-${name}-${width}.png`), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ passed: true, fieldsChecked, screenshots: 'desktop 1440px / mobile 390px', businessDataWritten: false }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
