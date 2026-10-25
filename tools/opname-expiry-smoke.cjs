const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const directory = path.join(__dirname, '../storage/framework/testing/opname-expiry');
        const publicRoot = path.resolve(__dirname, '../public');
        let posted;
        await page.route('**/*', route => {
            if (route.request().method() !== 'GET') {
                posted = { url: route.request().url(), data: new URLSearchParams(route.request().postData()) };
                return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Pengujian selesai.</p>' });
            }
            const requestUrl = new URL(route.request().url());
            // Reproduce hosting without the separate counting script.
            if (requestUrl.pathname === '/js/opname-counts.js') return route.abort();
            if (!requestUrl.pathname.startsWith('/inventory/opnames/')) {
                if (requestUrl.hostname === 'cdn.jsdelivr.net') return route.continue();
                const file = path.resolve(publicRoot, '.' + decodeURIComponent(requestUrl.pathname));
                if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && /\.(js|css)$/.test(file)) {
                    return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(file) });
                }
                return route.abort();
            }
            const name = route.request().url().includes('test-large') ? 'show-large' : route.request().url().includes('test-multi') ? 'multi-edit' : route.request().url().includes('test-show') ? 'show' : 'edit';
            const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8').replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
            return route.fulfill({ status: 200, contentType: 'text/html', body: html });
        });
        const url = 'http://127.0.0.1:8000/inventory/opnames/999/edit';
        await page.goto(url + '?test-multi=1');
        const multiMain = page.locator('[data-opname-item]').first();
        await multiMain.locator('.expiry-toggle').click();
        const multiDetail = page.locator('[data-expiry-item]').first();
        const multiRow = multiDetail.locator('[data-expiry-row]').first();
        assert.equal(await multiRow.locator('.qty-unit').count(), 3);
        await multiRow.getByRole('spinbutton', { name: 'Dus A Susu Uji', exact: true }).fill('1');
        await multiRow.getByRole('spinbutton', { name: 'Slop A Susu Uji', exact: true }).fill('1');
        await multiRow.getByRole('spinbutton', { name: 'Pcs A Susu Uji', exact: true }).fill('2.25');
        assert.equal(await multiRow.locator('.physical-value').inputValue(), '17.25');
        assert.equal(await multiMain.locator('.item-total').innerText(), '2 Dus + 1 Slop + 2,25 Pcs');
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.waitForFunction(() => innerWidth > 768 ? document.getElementById('sidebar').getBoundingClientRect().left >= -1 : document.getElementById('sidebar').getBoundingClientRect().right <= 0);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            const inputs = await multiRow.locator('.qty-unit').evaluateAll(nodes => nodes.map(node => { const r=node.getBoundingClientRect(); return {left:r.left,top:r.top,right:r.right,bottom:r.bottom}; }));
            const groups = await multiRow.locator('.opname-quantity .input-group').evaluateAll(nodes => nodes.map(node => { const r=node.getBoundingClientRect(); return {top:r.top,width:r.width}; }));
            assert(groups.every(group => Math.abs(group.top - groups[0].top) < 1 && Math.abs(group.width - groups[0].width) < 1));
            for (let i=0;i<inputs.length;i++) for (let j=i+1;j<inputs.length;j++) {
                const a=inputs[i], b=inputs[j];
                assert(!(a.left<b.right && a.right>b.left && a.top<b.bottom && a.bottom>b.top));
            }
            await page.screenshot({ path: path.join(directory, `multi-${width}.png`), fullPage: true });
        }
        await multiDetail.locator('.add-expiry').click();
        assert.equal(await multiDetail.locator('[data-expiry-row]').last().locator('.qty-unit').count(), 3);
        await page.setViewportSize({ width:1440, height:1000 });
        await page.goto(url);
        const ordinary = page.locator('[data-opname-item]').filter({ hasText: 'B Barang Tanpa Kedaluwarsa' });
        for (const [quantity, expected] of [['31', '+1 Pcs'], ['29', '-1 Pcs'], ['30', '0 Pcs']]) {
            await ordinary.locator('.qty-base').fill(quantity);
            assert.equal(await ordinary.locator('.item-difference').innerText(), expected);
            assert.equal(await ordinary.locator('.physical-value').inputValue(), quantity);
            assert.equal(await ordinary.locator('.item-difference').evaluate(node => node.classList.contains('text-danger')), Number(quantity) < 30);
            assert.equal(await ordinary.locator('.item-difference').evaluate(node => node.classList.contains('text-success')), Number(quantity) > 30);
        }
        const detail = page.locator('[data-expiry-item]').first();
        const id = await detail.getAttribute('data-expiry-item');
        const main = page.locator(`[data-opname-item="${id}"]`);
        assert(await detail.isHidden());
        assert(await main.evaluate(row => row.getBoundingClientRect().height < 70));
        await page.screenshot({ path: path.join(directory, 'edit-compact.png'), fullPage: true });
        await page.locator('#opnameSearch').fill('Tidak Ada Barang Ini');
        assert(await main.isHidden());
        assert(await page.locator('#opnameEmpty').isVisible());
        await page.locator('#opnameSearch').fill('');
        await main.locator('.expiry-toggle').click();
        assert(await detail.isVisible());
        const expired = detail.locator('[data-expiry-row]').filter({ hasText: '16/10/2026' });
        await expired.locator('.qty-whole').fill('0');
        assert.equal(await main.locator('.item-physical').inputValue(), '10');
        assert.equal(await main.locator('.item-total').innerText(), '1 Dus');
        assert.equal(await main.locator('.item-difference').innerText(), '-1 Dus');
        await detail.locator('.add-expiry').click();
        const added = detail.locator('[data-expiry-row]').last();
        await added.locator('.expiry-date').fill('2027-01-01');
        await added.locator('.qty-base').fill('5');
        assert.equal(await main.locator('.item-physical').inputValue(), '15');
        assert.equal(await main.locator('.item-total').innerText(), '1 Dus + 5 Pcs');
        assert.equal(await added.locator('.expiry-difference').innerText(), '+5 Pcs');
        await added.locator('.remove-expiry').click();
        assert.equal(await main.locator('.item-physical').inputValue(), '10');
        await detail.locator('.add-expiry').click();
        await main.locator('.expiry-toggle').click();
        await page.locator('#opnameForm button[type="submit"]').click();
        assert(await detail.isVisible());
        assert(page.url().endsWith('/edit'));
        await detail.locator('[data-expiry-row]').last().locator('.remove-expiry').click();
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.waitForFunction(() => innerWidth > 768 ? document.getElementById('sidebar').getBoundingClientRect().left >= -1 : document.getElementById('sidebar').getBoundingClientRect().right <= 0);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            await page.screenshot({ path: path.join(directory, `edit-${width}.png`), fullPage: true });
        }
        await page.locator('#opnameForm button[type="submit"]').click();
        await page.waitForURL(/\/inventory\/opnames\/\d+$/);
        assert.equal(posted.data.get('_method'), 'PUT');
        assert.equal(posted.data.get(`items[${id}][expiries][0][physical_quantity]`), '0');
        assert.equal(posted.data.get(`items[${id}][physical_quantity]`), '10');
        await page.goto(url);
        await page.evaluate(() => { window.appDialog.confirm = async () => true; });
        await page.getByRole('button', { name: 'Selesaikan Opname' }).click();
        await page.waitForURL(/\/complete$/);
        assert(!posted.data.has('_method'));
        assert(posted.url.endsWith('/complete'));
        await page.goto(url + '?test-show=1');
        const resultToggle = page.locator('.opname-detail-toggle').first();
        const resultDetail = page.locator('#' + await resultToggle.getAttribute('aria-controls'));
        assert(await resultDetail.isHidden());
        await resultToggle.click();
        assert(await resultDetail.isVisible());
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.waitForFunction(() => innerWidth > 768 ? document.getElementById('sidebar').getBoundingClientRect().left >= -1 : document.getElementById('sidebar').getBoundingClientRect().right <= 0);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            await page.screenshot({ path: path.join(directory, `show-${width}.png`), fullPage: true });
        }
        await resultToggle.click();
        assert(await resultDetail.isHidden());
        await page.goto(url + '?test-large=1');
        assert.equal(await page.locator('[data-result-item]').count(), 25);
        for (const width of [1440, 390]) {
            await page.setViewportSize({width,height:1000});
            await page.waitForFunction(() => innerWidth > 768 ? document.getElementById('sidebar').getBoundingClientRect().left >= -1 : document.getElementById('sidebar').getBoundingClientRect().right <= 0);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            await page.locator('.opname-scroll').evaluate(container=>{container.scrollTop=0;});
            await page.screenshot({path:path.join(directory,`show-large-${width}.png`),fullPage:true});
            assert(await page.locator('.opname-scroll').evaluate(container=>{
                container.scrollTop=300;
                const heading=container.querySelector('thead').getBoundingClientRect();
                return container.scrollTop>0 && Math.abs(heading.top-container.getBoundingClientRect().top)<2;
            }));
        }
        assert.deepEqual(errors, []);
        console.log('PASS: per-expiry quantities, totals, add/remove date, draft and completion payloads, desktop/mobile. No live opname was created or submitted.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
