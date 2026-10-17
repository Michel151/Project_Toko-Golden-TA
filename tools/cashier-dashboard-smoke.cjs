const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const errors=[]; page.on('pageerror',error=>errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('[name=email]').fill('kasir@tokogolden.com');
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
        for(const text of ['Menunggu Pembayaran','Draft Keranjang','Nota Belum Dibayar','Keranjang Belum Selesai']) assert(await page.getByText(text,{exact:true}).first().isVisible());
        assert.equal(await page.locator('canvas').count(),0);
        assert.equal(await page.locator('.cashier-donut').count(),1);
        assert.match(await page.locator('.cashier-donut').getAttribute('aria-label'),/lunas.*menunggu pembayaran.*dibatalkan/);
        assert.match(await page.locator('.cashier-donut').evaluate(el=>getComputedStyle(el).backgroundImage),/conic-gradient/);
        assert(!(await page.locator('body').innerText()).includes('Penjualan Bulan Ini'));
        await page.screenshot({path:path.join(__dirname,'cashier-dashboard-desktop.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
        await page.screenshot({path:path.join(__dirname,'cashier-dashboard-mobile.png'),fullPage:true});
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
        assert.deepEqual(errors,[]);
        console.log('PASS: cashier operational metrics, no monthly sales/chart, desktop/mobile display, no JS errors. Read-only dashboard verification.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
