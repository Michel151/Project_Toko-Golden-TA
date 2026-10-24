const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const root = path.resolve(__dirname, '..');
    const directory = path.join(root, 'storage/framework/testing/opname-participants');
    const publicRoot = path.join(root, 'public');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (route.request().method() !== 'GET') return route.abort();
            if (url.pathname.startsWith('/__opname-participants/')) {
                const name = url.pathname.split('/').pop();
                if (!['create','restored','edit','show','legacy'].includes(name)) return route.abort();
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, name + '.html')) });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const name of ['create','restored','edit','show','legacy']) {
                await page.goto('http://127.0.0.1:8000/__opname-participants/' + name);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' page overflow');
                if (name === 'create' || name === 'restored') {
                    const select = page.locator('#opnameParticipants');
                    await page.locator('#opnameParticipantsToggle').waitFor({ state: 'visible' });
                    assert.equal(await select.isVisible(), false);
                    assert(await select.evaluate(input => input.multiple && input.required && input.labels.length === 1));
                    assert.equal(await select.locator('option:checked').count(), name === 'create' ? 1 : 2);
                    const ids = await select.locator('option').evaluateAll(options => options.slice(0, 2).map(option => option.value));
                    await page.locator('#opnameParticipantsToggle').click();
                    const menu = page.locator('#opnameParticipantsMenu');
                    await menu.waitFor({ state: 'visible' });
                    for (const checkbox of await menu.locator('input[type="checkbox"]').all()) await checkbox.uncheck();
                    await menu.locator('label').nth(0).click();
                    await menu.locator('label').nth(1).click();
                    assert.equal(await select.locator('option:checked').count(), 2);
                    assert.deepEqual(await select.evaluate(input => new FormData(input.form).getAll('participant_ids[]')), ids);
                    assert(await select.evaluate(input => input.validity.valid));
                    await menu.getByRole('searchbox').fill('Budi');
                    assert.equal(await menu.locator('label:visible').count(), 1);
                    assert.equal(await select.locator('option:checked').count(), 2);
                    await menu.getByRole('searchbox').fill('not-found');
                    assert((await menu.innerText()).includes('Tidak ada petugas yang sesuai pencarian.'));
                    await menu.getByRole('searchbox').fill('');
                    for (const checkbox of await menu.locator('input[type="checkbox"]').all()) await checkbox.uncheck();
                    assert.equal(await select.evaluate(input => input.validity.valid), false);
                    await page.keyboard.press('Escape');
                    assert.equal(await menu.isVisible(), false);
                    assert.equal(await select.evaluate(input => input.reportValidity()), false);
                    assert(await page.locator('#opnameParticipantsError').isVisible());
                    assert(await menu.isVisible());
                    await menu.locator('label').nth(0).click();
                    await menu.locator('label').nth(1).click();
                    assert.equal(await page.locator('#opnameParticipantsError').isVisible(), false);
                    assert.equal(await select.locator('option:checked').count(), 2);
                    assert(await menu.evaluate(element => {
                        const rect = element.getBoundingClientRect();
                        return rect.left >= 0 && rect.right <= innerWidth;
                    }));
                }
                if (name === 'show' || name === 'edit') assert((await page.locator('.page-content').innerText()).includes('Andi Gudang, Budi Kasir'));
                if (name === 'legacy') assert((await page.locator('.page-content').innerText()).includes('Belum dicatat'));
                await page.screenshot({ path: path.join(directory, name + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: multiple participant selection, submitted IDs, required state, restored input and historical participant display on desktop/mobile. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
