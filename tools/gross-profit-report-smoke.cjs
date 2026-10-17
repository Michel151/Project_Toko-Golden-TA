const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
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
        await page.goto('http://127.0.0.1:8000/report/gross-profit');
        await page.screenshot({path:path.join(__dirname,'gross-profit-report-initial.png'),fullPage:true});
        await page.locator('#sidebar a[href$="/report/gross-profit"]').waitFor({state:'visible'});
        assert(await page.getByText('Rincian per Nota',{exact:true}).isVisible());
        assert(await page.locator('tbody tr').count()>0);
        for(const format of ['pdf','excel']) {
            const url=await page.locator(`a[href*="gross-profit/${format}"]`).getAttribute('href');
            const response=await page.request.get(url);
            assert.equal(response.status(),200);
            const bytes=await response.body();
            assert.equal(bytes.subarray(0,format==='pdf'?4:2).toString(),format==='pdf'?'%PDF':'PK');
            fs.writeFileSync(path.join(__dirname,`gross-profit-report.${format==='pdf'?'pdf':'xlsx'}`),bytes);
        }
        await page.screenshot({path:path.join(__dirname,'gross-profit-report-desktop.png'),fullPage:true});
        await page.locator('#profitFrom').fill('2000-01-01');
        await page.locator('#profitTo').fill('2000-01-31');
        await Promise.all([page.waitForURL('**/gross-profit?**'),page.getByRole('button',{name:'Terapkan'}).click()]);
        assert(await page.getByText('Tidak ada nota lunas pada periode ini.',{exact:true}).isVisible());
        assert((await page.locator('a[href*="gross-profit/pdf"]').getAttribute('href')).includes('from=2000-01-01'));
        await page.goto('http://127.0.0.1:8000/report/gross-profit');
        for(const width of [390,320]) {
            await page.setViewportSize({width,height:844});
            await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`No global overflow at ${width}px`);
            await page.screenshot({path:path.join(__dirname,`gross-profit-report-mobile-${width}.png`),fullPage:true});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: gross-profit menu, date filter, empty state, PDF/XLSX exports, desktop and 390/320px layouts. No transactions changed.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
