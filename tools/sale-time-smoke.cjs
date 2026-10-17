const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const errors=[];
        page.on('pageerror',error=>errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('[name=email]').fill('owner@tokogolden.com');
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
        await page.goto('http://127.0.0.1:8000/sale/sales');
        const row=page.locator('tbody tr').filter({has:page.locator('a.js-print-popup')}).first();
        const time=(await row.textContent()).match(/\d{2}:\d{2}:\d{2} WIB/)[0];
        assert(time);
        await page.screenshot({path:path.join(__dirname,'sale-times-list.png'),fullPage:true});
        const show=await row.locator('a.btn-outline-info').getAttribute('href');
        const print=await row.locator('a.js-print-popup').getAttribute('href');
        await page.goto(show);
        assert((await page.locator('body').textContent()).includes(time));
        await page.goto(print);
        assert((await page.locator('body').textContent()).includes(time));
        await page.screenshot({path:path.join(__dirname,'sale-time-nota.png'),fullPage:true});
        assert.deepEqual(errors,[]);
        console.log(`PASS: saved time ${time} is consistent on sales list, detail and printed note. Read-only checks.`);
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
