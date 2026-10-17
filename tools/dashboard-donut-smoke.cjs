const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        for (const account of ['kasir', 'gudang']) {
            const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('http://127.0.0.1:8000/login');
            await page.locator('[name=email]').fill(`${account}@tokogolden.com`);
            await page.locator('[name=password]').fill('password');
            await Promise.all([page.waitForURL('**/dashboard'), page.locator('button[type=submit]').click()]);
            const donut = page.locator('[data-donut]');
            const entries = page.locator('[data-donut-label]');
            const tooltip = page.locator('.dashboard-donut-tooltip');
            for (let i = 0; i < 3; i++) {
                await entries.nth(i).focus();
                assert(await tooltip.isVisible());
                assert((await tooltip.textContent()).includes(await entries.nth(i).getAttribute('data-donut-label')));
                assert.match(await tooltip.textContent(), /\d+,\d%/);
                await page.keyboard.press('Escape');
                assert(!(await tooltip.isVisible()));
            }
            await entries.first().blur();
            await donut.scrollIntoViewIfNeeded();
            let start = 0;
            const counts = await entries.evaluateAll(els => els.map(el => Number(el.dataset.donutCount)));
            const total = counts.reduce((a, b) => a + b, 0);
            const box = await donut.boundingBox();
            for (let i = 0; i < 3; i++) {
                const portion = counts[i] / total;
                if (portion > 0) {
                    const angle = (start + portion / 2) * Math.PI * 2;
                    await page.mouse.move(box.x + box.width / 2 + Math.sin(angle) * box.width * .43, box.y + box.height / 2 - Math.cos(angle) * box.height * .43);
                    assert(await tooltip.isVisible());
                    assert((await tooltip.textContent()).includes(await entries.nth(i).getAttribute('data-donut-label')));
                }
                start += portion;
            }
            await page.screenshot({ path: path.join(__dirname, `${account}-donut-hover.png`), fullPage: true });
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            assert(!(await tooltip.isVisible()), 'Center must not pretend to be a segment');
            await page.setViewportSize({ width: 390, height: 844 });
            await entries.last().click();
            assert(await tooltip.isVisible());
            const tipBox = await tooltip.boundingBox();
            assert(tipBox.x >= 0 && tipBox.x + tipBox.width <= 390);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log('PASS: cashier and warehouse segment hover, exact labels, percentages, keyboard/Escape, mobile legend tap and tooltip bounds. No database changes.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
