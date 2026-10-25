const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/account-password');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        let writes = 0;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') {
                writes++;
                return route.fulfill({ contentType: 'text/html', body: 'Action intercepted' });
            }
            if (url.pathname.startsWith('/__password/')) {
                const name = url.pathname.split('/').pop();
                if (!['change','forced','users','reset-result'].includes(name)) return route.abort();
                const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8').replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
                return route.fulfill({ contentType: 'text/html', body: html });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 950 });
            for (const name of ['change','forced']) {
                await page.goto('http://127.0.0.1:8000/__password/' + name);
                await page.locator('#topbar .dropdown-toggle').click();
                assert(await page.locator('.dropdown-menu a[href$="/account/password"]').isVisible());
                await page.keyboard.press('Escape');
                await page.locator('#accountCurrentPassword').fill('Current12!');
                await page.locator('#userPassword').fill('NewSecure34!');
                await page.locator('#userPasswordConfirmation').fill('NewSecure34!');
                assert.equal(await page.locator('[data-password-rule].text-success').count(), 5);
                assert(await page.locator('#passwordMatchStatus').evaluate(node => node.classList.contains('text-success')));
                for (const id of ['accountCurrentPassword','userPassword','userPasswordConfirmation']) {
                    const toggle = page.locator(`[data-password-toggle="${id}"]`);
                    await toggle.click();
                    assert.equal(await page.locator('#' + id).getAttribute('type'), 'text');
                    await toggle.click();
                    assert.equal(await page.locator('#' + id).getAttribute('type'), 'password');
                }
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            }
            await page.goto('http://127.0.0.1:8000/__password/users');
            const reset = page.locator('form[action$="/reset-password"] button').first();
            const before = writes;
            await reset.click();
            await page.locator('#appDialog.show').waitFor();
            assert((await page.locator('#appDialogTitle').innerText()).includes('Reset Password'));
            await page.locator('[data-dialog-cancel]').click();
            await page.locator('#appDialog.show').waitFor({ state: 'hidden' });
            assert.equal(writes, before);
            await reset.click();
            await page.locator('#appDialog.show').waitFor();
            await page.locator('[data-dialog-confirm]').click();
            await page.waitForFunction(() => document.body.textContent.includes('Action intercepted'));
            assert.equal(writes, before + 1);
            await page.goto('http://127.0.0.1:8000/__password/reset-result');
            assert(await page.locator('#temporaryPassword').evaluate(node => node.readOnly && node.value.length >= 16));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        }
        assert.deepEqual(errors, []);
        console.log('PASS: account menu, normal/forced password form, three eye controls, live requirements, owner reset confirm/cancel and one-time result layout on desktop/mobile. Network writes mocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
