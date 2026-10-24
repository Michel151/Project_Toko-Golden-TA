const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/report-kpis');
    const publicRoot = path.resolve(__dirname, '../public');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname === '/__transfer-report-ui') return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, 'transfer-filtered.html'), 'utf8') });
            if (url.pathname === '/__transfer-report-many') return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, 'transfer-many.html'), 'utf8') });
            if (url.pathname === '/__transfer-index') return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(directory, url.searchParams.has('search') ? 'transfer-index-filtered.html' : 'transfer-index.html'), 'utf8') });
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('http://127.0.0.1:8000/__transfer-report-ui');
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page overflow');
            for (const id of ['transferFromLocation', 'transferToLocation']) {
                assert.notEqual(await page.locator('#' + id).inputValue(), '');
                assert(await page.locator('#' + id).isVisible());
                assert(await page.locator('#' + id).evaluate(element => {
                    const rect = element.getBoundingClientRect();
                    return rect.left >= 0 && rect.right <= innerWidth;
                }));
            }
            await page.screenshot({ path: path.join(directory, 'transfer-filters-' + width + '.png'), fullPage: true });
            await page.goto('http://127.0.0.1:8000/__transfer-report-many');
            await page.waitForFunction(() => window.bootstrap?.Modal);
            assert.equal(await page.locator('#transferReportTable tbody tr').count(), 2);
            assert(!(await page.locator('#transferReportTable').innerText()).includes('Produk Transfer'));
            const openMany = page.getByRole('button', { name: 'Rincian barang TRF-MANY', exact: true });
            assert((await openMany.locator('xpath=ancestor::tr').boundingBox()).height < 100, 'Transfer row should stay compact');
            await openMany.click();
            const modal = page.locator('#transferItemsModal');
            await modal.waitFor({ state: 'visible' });
            await page.waitForFunction(() => document.activeElement?.id === 'transferItemsSearch');
            assert.equal(await page.locator('#transferItemsBody tr').count(), 40);
            assert.equal(await page.locator('#transferItemsTitle').innerText(), 'TRF-MANY');
            assert(await page.locator('.transfer-items-scroll').evaluate(element => element.scrollHeight > element.clientHeight), 'Details must scroll');
            assert(await modal.evaluate(element => {
                const rect = element.querySelector('.modal-content').getBoundingClientRect();
                return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight;
            }), 'Modal must fit viewport');
            await page.screenshot({ path: path.join(directory, 'transfer-items-' + width + '.png'), fullPage: true });
            await page.locator('#transferItemsSearch').fill('Transfer 40');
            assert.equal(await page.locator('#transferItemsBody tr:visible').count(), 1);
            assert((await page.locator('#transferItemsBody tr:visible').innerText()).includes('40 Pcs'));
            await page.locator('#transferItemsSearch').fill('not-found');
            assert(await page.locator('#transferItemsEmpty').isVisible());
            await page.keyboard.press('Escape');
            await modal.waitFor({ state: 'hidden' });
            await page.getByRole('button', { name: 'Rincian barang TRF-SINGLE', exact: true }).click();
            await modal.waitFor({ state: 'visible' });
            assert.equal(await page.locator('#transferItemsSearch').inputValue(), '');
            assert.equal(await page.locator('#transferItemsBody tr').count(), 1);
            assert.equal(await page.locator('#transferItemsTitle').innerText(), 'TRF-SINGLE');
            assert((await page.locator('#transferItemsBody').innerText()).includes('2 Pcs'));
            await modal.getByRole('button', { name: 'Tutup', exact: true }).last().click();
            await modal.waitFor({ state: 'hidden' });
            await page.goto('http://127.0.0.1:8000/__transfer-index');
            await page.waitForFunction(() => window.bootstrap?.Modal);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Transfer list overflow');
            assert.equal(await page.locator('#transferListTable tbody tr').count(), 2);
            assert(!(await page.locator('#transferListTable').innerText()).includes('Produk Transfer'));
            for (const id of ['transferListFromLocation', 'transferListToLocation']) {
                assert(await page.locator('#' + id).isVisible());
                assert(await page.locator('#' + id).evaluate(element => {
                    const rect = element.getBoundingClientRect();
                    return rect.left >= 0 && rect.right <= innerWidth;
                }), 'Location filter must fit viewport');
            }
            const alignment = await page.locator('#transferFilterForm').evaluate(form => {
                const fields = [...form.querySelectorAll('input:not([type="hidden"]),select')].map(element => element.getBoundingClientRect());
                return fields.every(rect => Math.abs(rect.height - fields[0].height) < 1) && (innerWidth < 1200 || fields.every(rect => Math.abs(rect.top - fields[0].top) < 1));
            });
            assert(alignment, 'Filter controls should have equal heights and align on desktop');
            assert((await page.getByRole('button', { name: 'Rincian barang TRF-MANY', exact: true }).locator('xpath=ancestor::tr').boundingBox()).height < 100);
            await page.getByRole('button', { name: 'Rincian barang TRF-MANY', exact: true }).locator('xpath=ancestor::tr').locator('a[href*="/inventory/transfers/"]').waitFor({ state: 'visible' });
            await page.screenshot({ path: path.join(directory, 'transfer-index-' + width + '.png'), fullPage: true });
            await page.getByRole('button', { name: 'Rincian barang TRF-MANY', exact: true }).click();
            await modal.waitFor({ state: 'visible' });
            await page.waitForFunction(() => document.activeElement?.id === 'transferItemsSearch');
            assert.equal(await page.locator('#transferItemsBody tr').count(), 40);
            await page.keyboard.press('Escape');
            await modal.waitFor({ state: 'hidden' });
            await page.locator('#transferLiveSearch').fill('Produk Transfer 40');
            await page.locator('#transferListFromLocation').selectOption({ label: 'Gudang A' });
            await page.locator('#transferListToLocation').selectOption({ label: 'Toko B' });
            await page.locator('#transferFilterForm button[type="submit"], #transferFilterForm button:not([type])').click();
            await page.waitForFunction(() => document.querySelectorAll('#transferListTable tbody tr').length === 1);
            const selectedUrl = new URL(page.url());
            assert(selectedUrl.searchParams.get('from_location_id'));
            assert(selectedUrl.searchParams.get('to_location_id'));
            assert(await page.locator('#transferResetFilter').isVisible());
            assert.equal(await page.locator('#transferItemsModal').count(), 1);
            await page.getByRole('button', { name: 'Rincian barang TRF-MANY', exact: true }).click();
            await modal.waitFor({ state: 'visible' });
            await page.waitForFunction(() => document.activeElement?.id === 'transferItemsSearch');
            assert.equal(await page.locator('#transferItemsBody tr').count(), 40, 'AJAX filters must preserve all document items');
            await page.locator('#transferItemsSearch').fill('Transfer 40');
            assert.equal(await page.locator('#transferItemsBody tr:visible').count(), 1);
            await page.screenshot({ path: path.join(directory, 'transfer-index-detail-' + width + '.png'), fullPage: true });
            await page.keyboard.press('Escape');
            await modal.waitFor({ state: 'hidden' });
        }
        assert.deepEqual(errors, []);
        console.log('PASS: report and inventory transfer lists, compact 40-item rows, searchable modal, quantities, empty results, keyboard close, switching documents and details after AJAX filtering at desktop/mobile. Writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
