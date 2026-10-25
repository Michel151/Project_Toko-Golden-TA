const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/hosting');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        const drafts = new Map();
        let deletes = 0;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname.startsWith('/sale/drafts')) {
                if (request.method() === 'GET') return route.fulfill({ json: [...drafts.values()] });
                if (request.method() === 'PUT') {
                    const draft = JSON.parse(request.postData());
                    draft.id = url.pathname.split('/').pop();
                    draft.version = (drafts.get(draft.id)?.version || 0) + 1;
                    drafts.set(draft.id, draft);
                    return route.fulfill({ json: { id: draft.id, version: draft.version } });
                }
            }
            if (request.method() !== 'GET') {
                if (url.pathname.startsWith('/master/categories/')) {
                    const payload = new URLSearchParams(request.postData());
                    assert.equal(payload.get('_method'), 'DELETE');
                    deletes++;
                    return route.fulfill({ contentType: 'text/html', body: 'Deletion intercepted' });
                }
                return route.abort();
            }
            if (url.pathname.startsWith('/__hosting/')) {
                const name = url.pathname.split('/').pop();
                if (!/^[a-z_-]+$/.test(name)) return route.abort();
                const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8').replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
                return route.fulfill({ contentType: 'text/html', body: html });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            // All separate application JS requests fail, just as on the deployed site.
            return route.abort();
        });
        const visit = name => page.goto('http://127.0.0.1:8000/__hosting/' + name);
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            for (const name of ['products', 'suppliers', 'purchase', 'receiving', 'transfers', 'opname', 'permissions', 'expense', 'tax', 'dashboard-owner']) {
                await visit(name);
                assert.equal(await page.locator('script[src]').evaluateAll(nodes => nodes.filter(node => new URL(node.src).hostname !== 'cdn.jsdelivr.net').length), 0);
                assert(await page.evaluate(() => typeof window.appDialog?.confirm === 'function'));
                assert(await page.locator('.app-field-required').count() > 0 || name.startsWith('dashboard') || name === 'permissions');
            }
            await visit('categories');
            const remove = page.locator('form[data-confirm] button').first();
            const before = deletes;
            await remove.click();
            await page.locator('#appDialog.show').waitFor();
            await page.locator('[data-dialog-cancel]').click();
            await page.locator('#appDialog.show').waitFor({ state: 'hidden' });
            assert.equal(deletes, before);
            await remove.click();
            await page.locator('#appDialog.show').waitFor();
            await page.locator('[data-dialog-confirm]').click();
            await page.waitForFunction(() => document.body.textContent.includes('Deletion intercepted'));
            assert.equal(deletes, before + 1);

            for (const role of ['kasir', 'staf_gudang']) {
                await visit('dashboard-' + role);
                await page.locator('[data-donut-label]').first().focus();
                assert(await page.locator('.dashboard-donut-tooltip').isVisible());
                await page.keyboard.press('Escape');
                assert(await page.locator('.dashboard-donut-tooltip').isHidden());
            }
            drafts.clear();
            await page.evaluate(() => localStorage.clear());
            await visit('sale');
            await page.locator('[name="customer_name"]').fill('Draf Uji Hosting ' + width);
            await page.waitForFunction(() => /^Draf tersimpan \d/.test(document.getElementById('draftSaveStatus').textContent));
            assert.equal(drafts.size, 1);
            assert.equal([...drafts.values()][0].payload.customer_name, 'Draf Uji Hosting ' + width);
            await visit('sale');
            await page.locator('#saleDraftModal.show').waitFor();
            await page.locator('[data-resume-draft]').first().click();
            await page.locator('#saleDraftModal.show').waitFor({ state: 'hidden' });
            assert.equal(await page.locator('[name="customer_name"]').inputValue(), 'Draf Uji Hosting ' + width);
        }
        assert.deepEqual(errors, []);
        console.log('PASS: all menus load, shared confirm/cancel, form enhancements, dashboard tooltips, sale draft save/restore on desktop/mobile with all local JS blocked. Network writes mocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
