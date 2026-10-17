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
        await page.goto('http://127.0.0.1:8000/master/suppliers');
        await page.locator('a[href$="/master/suppliers/import"]').click();
        assert.equal(new URL(page.url()).pathname,'/master/suppliers/import');
        const downloaded=page.waitForEvent('download');
        await page.getByRole('link',{name:'Template Supplier'}).click();
        const file=await downloaded;
        const template=path.join(__dirname,'template-uji-import-supplier.xlsx');
        await file.saveAs(template);
        await page.locator('#supplierFile').setInputFiles(template);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Periksa & Pratinjau'}).click()]);
        assert(await page.getByText('Struktur file valid untuk uji coba.',{exact:true}).isVisible());
        assert(await page.getByText('Supplier per Barang',{exact:true}).isVisible());
        assert((await page.locator('body').innerText()).includes('Hubungan baru (uji coba)'));
        assert.equal(await page.getByRole('button',{name:/Simpan|Import Supplier/}).count(),0);
        await page.screenshot({path:path.join(__dirname,'supplier-import-trial-desktop.png'),fullPage:true});
        for(const width of [390,320]){
            await page.setViewportSize({width,height:844});
            await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`No overflow at ${width}px`);
            await page.screenshot({path:path.join(__dirname,`supplier-import-trial-mobile-${width}.png`),fullPage:true});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: Master Supplier import menu, template with registered product references, upload/preview, no save action, desktop/mobile layouts. No supplier or stock data changed.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
