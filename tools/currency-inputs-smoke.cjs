const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const directory = path.resolve(__dirname, '../storage/framework/testing/currency');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        let submitted;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') {
                submitted = request.postData();
                return route.fulfill({ contentType: 'text/html', body: '<p>Test complete</p>' });
            }
            if (url.pathname.startsWith('/__currency-ui/')) {
                const name = url.pathname.split('/').pop();
                if (!['product', 'supplier', 'purchase', 'sale', 'expense', 'tax'].includes(name)) return route.abort();
                const html = fs.readFileSync(path.join(directory, name + '.html'), 'utf8').replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
                return route.fulfill({ contentType: 'text/html', body: html });
            }
            if (url.hostname === 'cdn.jsdelivr.net') return route.continue();
            return route.abort();
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            for (const name of ['expense', 'tax', 'supplier', 'purchase']) {
                await page.goto('http://127.0.0.1:8000/__currency-ui/' + name);
                const input = page.locator('.js-decimal-currency').first();
                await input.waitFor();
                if (['supplier', 'purchase'].includes(name)) assert.equal(await input.inputValue(), '2.300,5');
                for (const [typed, display, raw] of [
                    ['2300', '2.300', '2300'],
                    ['1234567,50', '1.234.567,50', '1234567.50'],
                    ['0,01', '0,01', '0.01'],
                ]) {
                    await input.fill(typed);
                    assert.equal(await input.inputValue(), display);
                    assert(await input.evaluate(node => node.validity.valid));
                    assert.equal(await input.evaluate(node => new FormData(node.form).get(node.name)), raw);
                }
                await input.fill('2300,123');
                assert(!(await input.evaluate(node => node.validity.valid)));
                await input.fill('0');
                assert.equal(await input.evaluate(node => node.validity.valid), name !== 'expense');
                await input.fill('2300,50');
                if (name === 'purchase') {
                    await page.locator('#addItem').click();
                    const added = page.locator('.js-decimal-currency').last();
                    await added.fill('4567,89');
                    assert.equal(await added.inputValue(), '4.567,89');
                    assert.equal(await added.evaluate(node => new FormData(node.form).get(node.name)), '4567.89');
                }
                if (name === 'supplier') {
                    await page.locator('.supplier-product-check').first().uncheck();
                    assert(await input.isDisabled());
                    assert.equal(await input.evaluate(node => new FormData(node.form).has(node.name)), false);
                }
                if (name === 'expense') {
                    submitted = undefined;
                    await input.evaluate(node => { node.form.noValidate = true; node.form.requestSubmit(); });
                    await page.waitForFunction(() => document.body.textContent.includes('Test complete'));
                    assert(submitted.includes('2300.50'));
                    assert(!submitted.includes('2.300,50'));
                }
            }
            await page.goto('http://127.0.0.1:8000/__currency-ui/product');
            await page.locator('#addProductUnit').click();
            const salePrice = page.locator('.product-unit-row .js-currency-input').last();
            await salePrice.fill('1234567');
            assert.equal(await salePrice.inputValue(), '1.234.567');
            const purchasePrice = page.locator('.buy-price-input').first();
            await purchasePrice.fill('2300,50');
            assert.equal(await purchasePrice.inputValue(), '2.300,50');
            await page.goto('http://127.0.0.1:8000/__currency-ui/sale');
            await page.locator('#amountPaid').fill('1234567');
            assert.equal(await page.locator('#amountPaid').inputValue(), '1.234.567');
        }
        assert.deepEqual(errors, []);
        console.log('PASS: expense/tax nominal, supplier/PO decimals, native form submission, disabled fields, dynamic PO/product rows and sale payment on desktop/mobile. No live records saved.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
