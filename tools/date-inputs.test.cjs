const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, locale: 'en-US' });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent('<form><label for="receiptDate">Tanggal Terima</label><input id="receiptDate" name="receipt_date" type="date" value="2026-11-19" required class="form-control batch-expiry" max="2026-11-30"><button>Simpan</button><div id="rows"></div></form>');
            for (const script of ['vendor/flatpickr/flatpickr.min.js', 'vendor/flatpickr/id.js', 'js/date-inputs.js']) {
                await page.addScriptTag({ path: path.resolve(__dirname, '../public', script) });
            }
            const display = page.locator('#receiptDate + input');
            assert.equal(await display.inputValue(), '19/11/2026');
            assert.equal(await display.getAttribute('placeholder'), 'dd/mm/yyyy');
            assert.equal(await page.locator('.batch-expiry').count(), 1);
            assert.equal(await page.locator('#receiptDate').inputValue(), '2026-11-19');
            assert.equal(await display.getAttribute('name'), null);

            await display.fill('20/11/2026');
            await display.press('Tab');
            assert.equal(await page.locator('#receiptDate').inputValue(), '2026-11-20');
            assert.equal(await page.locator('form').evaluate(form => new FormData(form).get('receipt_date')), '2026-11-20');

            for (const invalid of ['31/02/2026', '11/20/2026', '01/12/2026']) {
                await display.fill(invalid);
                await display.press('Tab');
                assert.equal(await display.inputValue(), invalid);
                assert.equal(await display.evaluate(input => input.checkValidity()), false);
            }
            await display.fill('');
            await display.press('Tab');
            assert.equal(await display.evaluate(input => input.checkValidity()), false);
            await page.locator('#receiptDate').evaluate(input => { input.required = false; });
            await page.waitForFunction(() => !document.querySelector('#receiptDate')._flatpickr.altInput.required);
            assert.equal(await display.evaluate(input => input.checkValidity()), true);

            await page.evaluate(() => {
                const template = document.createElement('template');
                template.innerHTML = '<input type="date" name="expiry" class="batch-expiry" required value="2027-01-23" aria-label="Kedaluwarsa">';
                document.getElementById('rows').append(template.content.cloneNode(true));
            });
            await page.waitForFunction(() => document.querySelector('[name="expiry"]')._flatpickr);
            assert.equal(await page.locator('[name="expiry"] + input').inputValue(), '23/01/2027');
            assert.equal(await page.locator('.batch-expiry').count(), 2);
            await display.fill('21/11/2026');
            await display.press('Tab');
            await page.locator('label[for="receiptDate"]').click();
            assert(await page.locator('.flatpickr-calendar.open').count() > 0);
            assert.equal(await page.locator('.flatpickr-mobile').count(), 0);
            assert.deepEqual(errors, []);
            await page.close();
        }
        if (process.argv.includes('--rendered')) {
            const fs = require('node:fs');
            for (const width of [1440, 390]) {
                for (const name of ['date-index', 'date-form']) {
                    const page = await browser.newPage({ viewport: { width, height: 900 }, locale: 'en-US' });
                    // Reproduce hosting where separate JS/CSS assets fail to load.
                    await page.route('**/*', route => route.abort());
                    const html = fs.readFileSync(path.resolve(__dirname, '../storage/framework/testing/receiving', name + '.html'), 'utf8');
                    await page.setContent(html, { waitUntil: 'domcontentloaded' });
                    const fields = name === 'date-index' ? ['from', 'to'] : ['receipt_date'];
                    for (const field of fields) {
                        const canonical = page.locator('input[name="' + field + '"]');
                        const display = canonical.locator('xpath=following-sibling::input[1]');
                        assert.equal(await canonical.getAttribute('type'), 'hidden');
                        const iso = await canonical.inputValue();
                        assert.equal(await display.inputValue(), iso.split('-').reverse().join('/'));
                        assert.equal(await display.getAttribute('placeholder'), 'dd/mm/yyyy');
                    }
                    await page.close();
                }
            }
            console.log('PASS: actual receipt filter and empty form render dd/mm/yyyy at desktop/mobile sizes with every external asset blocked.');
        }
        console.log('PASS: dd/mm/yyyy in desktop/mobile with en-US browser; ISO submission, strict date/range validation, required fields, dynamic expiry inputs, labels and unchanged selectors.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
