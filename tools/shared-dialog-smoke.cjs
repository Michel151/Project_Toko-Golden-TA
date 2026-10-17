const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => { errors.push('Unexpected native browser dialog'); dialog.dismiss(); });
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('input[name=email]').fill('owner@tokogolden.com');
        await page.locator('input[name=password]').fill('password');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type=submit]').click()]);
        await page.goto('http://127.0.0.1:8000/master/categories');
        let posts = 0;
        let body;
        await page.route('**/master/categories/*', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            posts++;
            body = new URLSearchParams(route.request().postData());
            await route.fulfill({ contentType: 'text/html', body: '<html><body>Delete intercepted.</body></html>' });
        });
        const button = page.locator('form[data-confirm] button').first();
        for (const cancel of ['escape', 'button']) {
            await button.click();
            await page.locator('#appDialog').waitFor({ state: 'visible' });
            assert.match(await page.locator('[data-dialog-confirm]').getAttribute('class'), /btn-danger/);
            if (cancel === 'escape') await page.keyboard.press('Escape');
            else await page.locator('[data-dialog-cancel]').click();
            await page.locator('#appDialog').waitFor({ state: 'hidden' });
            assert.equal(posts, 0);
        }
        await button.click();
        await page.locator('#appDialog').waitFor({ state: 'visible' });
        await page.locator('[data-dialog-confirm]').click();
        await page.waitForURL(url => /\/master\/categories\/\d+$/.test(url.pathname));
        assert.equal(posts, 1);
        assert.equal(body.get('_method'), 'DELETE');
        assert(body.get('_token'));
        assert.deepEqual(errors, []);
        console.log('PASS: shared deletion dialog, Escape and Cancel preserve data, Confirm sends exactly one POST with CSRF and DELETE method. POST intercepted; no master data deleted.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
