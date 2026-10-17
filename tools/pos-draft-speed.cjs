const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let blockedMethod;
        let release;
        let pendingRequest;
        await page.route('**/sale/drafts**', async route => {
            if (route.request().method() === blockedMethod) {
                pendingRequest = true;
                await new Promise(resolve => { release = resolve; });
            }
            const data = route.request().postDataJSON();
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify(route.request().method() === 'GET' ? [] : { version: (data?.version || 0) + 1 }) });
        });
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('input[name=email]').fill('owner@tokogolden.com');
        await page.locator('input[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        await page.goto('http://127.0.0.1:8000/sale/sales/create?new=1');
        await page.locator('input[name=customer_name]').fill('Uji draft responsif');
        await page.waitForFunction(() => /^Draft tersimpan \d/.test(document.getElementById('draftSaveStatus').textContent));
        const timings = [];
        for (const method of ['GET', 'PUT']) {
            blockedMethod = method;
            pendingRequest = false;
            if (method === 'PUT') await page.locator('input[name=customer_name]').fill('Uji draft responsif terbaru');
            const result = await page.evaluate(() => {
                const started = performance.now();
                document.getElementById('showSaleDrafts').click();
                return { elapsed: performance.now() - started, visible: getComputedStyle(document.getElementById('saleDraftModal')).display === 'block', content: document.getElementById('saleDraftList').textContent };
            });
            assert(result.visible, `${method}: modal must be visible before the network completes`);
            assert(result.elapsed < 100, `${method}: dialog opening took ${result.elapsed}ms`);
            assert.match(result.content, method === 'PUT' ? /Uji draft responsif terbaru/ : /Uji draft responsif/);
            timings.push(`${method} pending: ${result.elapsed.toFixed(1)}ms`);
            await page.waitForFunction(() => document.getElementById('saleDraftMessage').textContent.includes('Memperbarui'));
            while (!pendingRequest) await new Promise(resolve => setTimeout(resolve, 10));
            blockedMethod = null;
            release();
            await page.waitForFunction(() => document.getElementById('saleDraftMessage').textContent === '');
            await page.locator('#saleDraftModal .btn-close').click();
        }
        assert.deepEqual(errors, []);
        console.log(`PASS: draft dialog opens immediately with stalled GET and PUT requests (${timings.join(', ')}). No database drafts or sales created.`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
