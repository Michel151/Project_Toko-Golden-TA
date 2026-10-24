const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const root = path.resolve(__dirname, '..');
    const directory = path.join(root, 'storage/framework/testing/permissions');
    const publicRoot = path.join(root, 'public');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname.startsWith('/__permissions/')) {
                const role = url.pathname.split('/').pop();
                if (!['kasir', 'staf_gudang'].includes(role)) return route.abort();
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, role + '.html')) });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            return route.abort();
        });
        for (const width of [1440, 390]) for (const role of ['kasir', 'staf_gudang']) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('http://127.0.0.1:8000/__permissions/' + role);
            await page.waitForFunction(() => document.getElementById('permissionSummary').textContent.includes('izin aktif'));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page overflow');
            if (width === 390) {
                assert(await page.locator('.permission-table').evaluate(element => element.scrollWidth <= element.clientWidth + 1), 'Mobile permissions must fit viewport');
                assert(await page.locator('.permission-action-label').first().isVisible());
            }
            await page.screenshot({ path: path.join(directory, role + '-' + width + '.png'), fullPage: true });
            const selected = await page.locator('#permissionForm').evaluate(form => new FormData(form).getAll('permissions[]'));
            await page.locator('#permissionSearch').fill('Master Data');
            assert.equal(await page.locator('tr[data-feature]:visible').count(), 5);
            assert.equal(await page.locator('.permission-group:visible').count(), 1);
            assert.deepEqual(await page.locator('#permissionForm').evaluate(form => new FormData(form).getAll('permissions[]')), selected, 'Filtering must not change saved choices');
            await page.locator('#permissionSearch').fill('no-such-feature');
            assert(await page.locator('#permissionEmpty').isVisible());
            await page.locator('#selectAll').click();
            const total = await page.locator('.permission-check').count();
            assert.equal(await page.locator('.permission-check:checked').count(), total, 'Bulk selection must retain its all-feature scope');
            assert.equal(await page.locator('#permissionSummary').innerText(), `${total} / ${total} izin aktif`);
            await page.locator('#clearAll').click();
            assert.equal(await page.locator('.permission-check:checked').count(), 0);
            await page.locator('#permissionSearch').fill('Barang');
            const row = page.locator('[data-feature="master.products"]');
            await row.locator('[data-action="edit"]').check();
            assert(await row.locator('[data-action="view"]').isChecked());
            assert.equal(await page.locator('.permission-check:checked').count(), 2);
            await row.locator('[data-action="view"]').uncheck();
            assert.equal(await row.locator('.permission-check:checked').count(), 0);
            await page.locator('#permissionSearch').fill('');
            assert.equal(await page.locator('[data-feature]:visible').count(), 16);
        }
        assert.deepEqual(errors, []);
        console.log('PASS: cashier/warehouse permissions at desktop/mobile, search and group visibility, unchanged form values, selection counters, all-feature bulk actions and view dependencies. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
