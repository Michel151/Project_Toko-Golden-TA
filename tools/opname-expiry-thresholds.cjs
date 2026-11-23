const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.abort());
            // Isolate the counting form from unrelated layout scripts and CDN assets.
            const rendered = fs.readFileSync(path.join(__dirname, '../storage/framework/testing/opname-expiry/edit.html'), 'utf8');
            const html = rendered.match(/<form[^>]*id="opnameForm"[\s\S]*?<\/form>/)[0]
                + fs.readFileSync(path.join(__dirname, '../resources/views/inventory/opnames/count-scripts.blade.php'), 'utf8');
            for (const [days, label, status] of [[-1,'Sudah kedaluwarsa','expired'],[0,'Kedaluwarsa hari ini','today'],[1,'Segera kedaluwarsa','urgent'],[30,'Segera kedaluwarsa','urgent'],[31,'Mendekati kedaluwarsa','soon'],[90,'Mendekati kedaluwarsa','soon'],[91,'Aman','safe']]) {
                const reference = html.match(/data-opname-date="([^"]+)"/)[1];
                const date = new Date(reference + 'T00:00:00Z');
                date.setUTCDate(date.getUTCDate() + days);
                await page.setContent(html.replace('value="2026-10-16"', `value="${date.toISOString().slice(0,10)}"`));
                const row = page.locator('[data-expiry-row]').first();
                assert.equal(await row.locator('[data-expiry-status]').innerText(), label);
                if (status !== 'safe') {
                    assert.equal(await page.locator(`[data-expiry-filter="${status}"] [data-expiry-product-count]`).innerText(), '1 produk');
                    await page.locator(`[data-expiry-filter="${status}"]`).click();
                    assert.equal(await page.locator('[data-opname-item]').first().getAttribute('hidden'), null);
                    await page.locator('#clearOpnameExpiryFilter').click();
                }
            }
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`PASS ${width}px: opname status boundaries, metrics and filters`);
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
