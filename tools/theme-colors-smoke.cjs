const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const directory = path.join(__dirname, '../storage/framework/testing/ui-consistency');
    const files = fs.readdirSync(directory).filter(name => name.endsWith('-theme.html'));
    assert.equal(files.length, 11, 'Run UiConsistencyTest with CAPTURE_CONSISTENCY_UI=1 first.');
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const context = await browser.newContext({ javaScriptEnabled: false });
        const page = await context.newPage();
        await page.route('**/*', route => route.abort());
        let headings = 0;
        let tabs = 0;
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const file of files) {
                await page.setContent(fs.readFileSync(path.join(directory, file), 'utf8'));
                const colors = await page.evaluate(() => {
                    const headings = [...document.querySelectorAll('.page-content .master-heading, .page-content .procurement-heading, .page-content .report-heading, .page-content .pos-heading, .page-content .inventory-heading, .page-content .stock-heading, .page-content .stock-card-heading, .page-content .audit-heading, .page-content .backup-hero, .page-content .dashboard-hero, .page-content .product-hero')];
                    // Catalog categories are populated by JavaScript in the application.
                    document.querySelectorAll('.catalog-tabs, .transfer-catalog-tabs, .supplier-catalog-tabs').forEach(catalog => {
                        if (!catalog.querySelector('.nav-link.active')) {
                            const button = document.createElement('button');
                            button.className = 'nav-link active';
                            button.textContent = 'Semua';
                            catalog.appendChild(button);
                        }
                    });
                    const tabs = [...document.querySelectorAll('.page-content .nav-link.active')];
                    return {
                        headings: headings.map(node => getComputedStyle(node).backgroundColor),
                        tabs: tabs.map(node => ({ background: getComputedStyle(node).backgroundColor, color: getComputedStyle(node).color })),
                    };
                });
                assert(colors.headings.length + colors.tabs.length > 0, file + ' missing themed header or tab');
                colors.headings.forEach(color => assert.equal(color, 'rgb(36, 50, 40)', file + ' header'));
                colors.tabs.forEach(color => {
                    assert.equal(color.background, 'rgb(31, 122, 90)', file + ' active tab');
                    assert.equal(color.color, 'rgb(255, 255, 255)', file + ' tab text');
                });
                headings += colors.headings.length;
                tabs += colors.tabs.length;
            }
        }
        console.log(`PASS: ${files.length} isolated pages at desktop/mobile; ${headings} green headers and ${tabs} green active tabs. No live data modified.`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
