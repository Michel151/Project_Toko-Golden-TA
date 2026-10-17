const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const errors=[];
        page.on('pageerror',error=>errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('[name=email]').fill('gudang@tokogolden.com');
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
        for(const name of ['Prioritas Penanganan','Kondisi Stok Layak Jual','Jenis Barang per Lokasi','Mutasi Stok Terakhir']) assert(await page.getByText(name,{exact:true}).isVisible());
        assert.match(await page.locator('.warehouse-donut').evaluate(el=>getComputedStyle(el).backgroundImage),/conic-gradient/);
        const links=[];
        for(const heading of ['priorityHeading','receiptHeading','opnameHeading']) {
            links.push(...await page.locator(`[aria-labelledby=${heading}] a.btn`).evaluateAll(els=>els.slice(0,2).map(el=>el.href)));
        }
        for(const url of links) assert.equal((await page.request.get(url)).status(),200,`Action link must be accessible: ${url}`);
        await page.screenshot({path:path.join(__dirname,'warehouse-dashboard-desktop.png'),fullPage:true});
        for(const width of [390,320]) {
            await page.setViewportSize({width,height:844});
            await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),`No page overflow at ${width}px`);
            await page.screenshot({path:path.join(__dirname,`warehouse-dashboard-mobile-${width}.png`),fullPage:true});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: warehouse priorities, sellable-stock chart, operational sections, accessible action links, desktop and 390/320px layouts, no JS errors. Read-only verification; no stock or transactions modified.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
