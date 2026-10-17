const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage({ viewport: { width: 1800, height: 900 } });
        for (const name of ['po', 'po-retur']) {
            const base = path.join(__dirname, '../storage/framework/testing/po-exports', name);
            await page.goto(pathToFileURL(base + '.html').href);
            const text = await page.locator('body').innerText();
            assert(text.includes(name === 'po' ? 'PO/2026/10/000001' : 'PO/R/2026/10/000001'));
            assert(text.includes('CV. Supplier Uji'));
            assert(!text.includes('Partial'));
            await page.locator('table').screenshot({ path: base + '-excel-preview.png' });
        }
        console.log('PASS: rendered Excel HTML previews preserve monthly PO references and supplier metadata.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
