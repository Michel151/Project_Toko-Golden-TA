const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const root = path.resolve(__dirname, '..');
    const fixtures = path.join(root, 'storage/framework/testing');
    const directory = path.join(fixtures, 'presentation');
    fs.mkdirSync(directory, { recursive: true });
    const names = ['ui-consistency/products-list', 'ui-consistency/returns-list', 'ui-consistency/sales-list',
        'ui-consistency/stock-theme', 'ui-consistency/stock-card', 'ui-consistency/sale-cashier',
        'ui-consistency/supplier-theme', 'ui-consistency/audit-theme', 'ui-consistency/transfer-theme',
        'ui-consistency/sale-theme', 'taxes/create', 'expenses/expenses-create',
        'purchase-ui/receipt-many', 'purchase-ui/return-many'];
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname.startsWith('/__presentation/')) {
                const name = url.pathname.slice('/__presentation/'.length);
                if (!names.includes(name)) return route.abort();
                return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(fixtures, name + '.html')) });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            const publicRoot = path.join(root, 'public');
            const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) {
                return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
            }
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const name of names) {
                await page.goto('http://127.0.0.1:8000/__presentation/' + name);
                await page.waitForTimeout(100);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' page overflow');
                assert.equal(await page.locator('[id]').evaluateAll(elements => {
                    const ids = elements.map(element => element.id);
                    return ids.length - new Set(ids).size;
                }), 0, name + ' duplicate IDs');
                if (name.endsWith('supplier-theme')) {
                    for (const field of ['name', 'phone', 'address']) assert(await page.locator(`[name="${field}"]`).evaluate(input => input.labels.length > 0));
                }
                if (name === 'taxes/create' || name === 'expenses/expenses-create') {
                    assert(await page.locator('[name="amount"]').evaluate(input => [...input.labels].some(label => label.classList.contains('app-field-required'))));
                    assert.equal(await page.locator('label[for="amount"] .text-danger').count(), name.startsWith('expenses') ? 1 : 0);
                }
                if (name.endsWith('stock-theme') && width === 390) {
                    const cell = page.locator('.stock-table tbody .stock-name-cell').first();
                    const before = await cell.boundingBox();
                    await page.locator('.stock-table').evaluate(table => { table.parentElement.scrollLeft = 250; });
                    const after = await cell.boundingBox();
                    assert(Math.abs(before.x - after.x) < 2, 'Stock identity must remain visible while scrolling');
                    const positions = await page.locator('.stock-stat .card-body').evaluateAll(cards => cards.map(card => {
                        const icon = card.querySelector('.stock-stat-icon').getBoundingClientRect();
                        const text = card.lastElementChild.getBoundingClientRect();
                        return text.top >= icon.bottom;
                    }));
                    assert(positions.every(Boolean), 'All mobile stock KPIs must use the same vertical arrangement');
                }
                if ((name.endsWith('sale-cashier') || name.endsWith('stock-card')) && width === 390) {
                    const table = page.locator('[data-mobile-records]').first();
                    assert(await table.evaluate(element => element.scrollWidth <= element.clientWidth + 1), 'Mobile records must fit viewport');
                    const row = table.locator('tbody tr').first();
                    const cells = await row.locator('td[data-label]').count();
                    assert(cells > 0);
                    assert(await row.locator('td').last().evaluate(element => element.getBoundingClientRect().right <= innerWidth));
                }
                if (name.startsWith('purchase-ui/')) {
                    const lists = page.locator('[data-detail-list]');
                    const list = lists.first();
                    assert.equal(await list.locator('tbody tr').count(), 40);
                    assert(await list.locator('.app-detail-scroll').evaluate(element => element.scrollHeight > element.clientHeight), 'Long details must scroll');
                    await list.locator('[data-list-search]').fill('Rincian 40');
                    assert.equal(await list.locator('tbody tr:visible').count(), 1);
                    if (await lists.count() > 1) assert.equal(await lists.nth(1).locator('tbody tr:visible').count(), 40, 'Searches must be independent');
                    await list.locator('[data-list-search]').fill('missing-product');
                    assert(await list.locator('[data-list-empty]').isVisible());
                    await list.locator('[data-list-search]').fill('');
                    assert.equal(await list.locator('tbody tr:visible').count(), 40);
                    if (name.endsWith('receipt-many')) assert((await list.innerText()).includes('2,125'));
                }
                await page.screenshot({ path: path.join(directory, name.replaceAll('/', '-') + '-' + width + '.png'), fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: 14 pages at desktop/mobile, shared filters, mobile records, sticky stock identity, KPI arrangement, field labels, required markers and independent 40-row detail searches. All writes blocked.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
