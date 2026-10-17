const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        for (const role of ['owner', 'gudang', 'kasir']) {
            const context = await browser.newContext();
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('http://127.0.0.1:8000/login');
            await page.locator('[name=email]').fill(role + '@tokogolden.com');
            await page.locator('[name=password]').fill('password');
            await Promise.all([page.waitForURL('**/dashboard'), page.locator('button[type=submit]').click()]);
            const routes = role === 'kasir' ? ['/inventory/stock', '/inventory/stock?view=priorities'] : ['/dashboard'];
            for (const route of routes) {
                assert.equal((await page.goto('http://127.0.0.1:8000' + route)).status(), 200);
                const preview = page.locator('#notificationPreview');
                if (await preview.count()) {
                    assert(!(await preview.textContent()).includes('Hampir Expired'));
                    for (const message of await preview.locator('.notification-preview-item .small.text-dark').allTextContents()) {
                        assert(!/(^|\.\s+)Batch\s/.test(message.trim()), 'Legacy notification wording: ' + message);
                    }
                }
                for (const text of ['Batch Kedaluwarsa', 'Batch kedaluwarsa', 'Semua Batch', 'Barang / Batch', 'Batch dalam 30 hari', 'Lengkapi tanggal batch']) {
                    assert.equal(await page.getByText(text, { exact: true }).count(), 0, text);
                }
                const name = role + (route.includes('priorities') ? '-priorities' : route.includes('stock') ? '-stock' : '-dashboard');
                for (const width of [1440, 390, 320]) {
                    await page.setViewportSize({ width, height: 900 });
                    await page.waitForFunction(() => innerWidth > 768 || document.getElementById('sidebar').getBoundingClientRect().right <= 0);
                    await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 1, null, { timeout: 5000 });
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflow ' + width + ' ' + JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed').slice(0,12).map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right, text: el.textContent.trim().slice(0,70) })))));
                    await page.screenshot({ path: path.join(__dirname, `expiry-wording-${name}-${width}.png`), fullPage: true });
                    if (width === 1440 && await preview.count()) {
                        await page.locator('[aria-describedby="notificationPreview"]').hover();
                        await preview.waitFor({ state: 'visible' });
                        await page.screenshot({ path: path.join(__dirname, `expiry-notifications-${role}.png`) });
                        await page.mouse.move(400, 300);
                    }
                }
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log('PASS: owner/warehouse dashboards and cashier stock monitoring/priorities use consistent expiry labels at desktop/390/320px. No business data changed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
