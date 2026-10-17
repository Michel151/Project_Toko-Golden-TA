const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

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
        assert.equal((await page.goto('http://127.0.0.1:8000/master/products/import')).status(), 200);
        assert.equal(await page.locator('.page-content').getByText(/kode batch/i).count(), 0);
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Template Excel (3 Sheet)' }).click(),
        ]);
        assert.equal(await download.failure(), null);
        assert.equal(download.suggestedFilename(), 'template-import-barang.xlsx');
        const html = fs.readFileSync(path.join(__dirname, '../storage/framework/testing/import-ui/preview.html'), 'utf8');
        await page.route('**/master/products/import?test-preview=1', route => route.fulfill({ status: 200, contentType: 'text/html', body: html }));
        await page.goto('http://127.0.0.1:8000/master/products/import?test-preview=1');
        const stocks = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Stok Fisik & Layak Jual per Lokasi / Kedaluwarsa' }) });
        await stocks.waitFor();
        assert.equal(await stocks.locator('thead th').nth(2).textContent(), 'Kedaluwarsa');
        assert.equal(await stocks.locator('tbody tr').count(), 6);
        assert(!(await stocks.textContent()).includes('BATCH-DEMO'));
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.waitForFunction(() => innerWidth > 768 || document.getElementById('sidebar').getBoundingClientRect().right <= 0);
            await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 1);
            await page.screenshot({ path: path.join(__dirname, `import-expiry-preview-${width}.png`), fullPage: true });
        }
        assert.deepEqual(errors, []);
        console.log('PASS: template download and isolated expiry preview at 1440/390px; no live import submitted.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
