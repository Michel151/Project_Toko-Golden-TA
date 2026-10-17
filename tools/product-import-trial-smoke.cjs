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
        await page.goto('http://127.0.0.1:8000/master/products/import');
        await page.goto('http://127.0.0.1:8000/master/products/import-trial');
        assert.equal(new URL(page.url()).pathname,'/master/products/import');
        const downloaded=page.waitForEvent('download');
        await page.getByRole('link',{name:'Template Excel (3 Sheet)'}).click();
        const file=await downloaded;
        const template=path.join(__dirname,'template-uji-multi-satuan.xlsx');
        await file.saveAs(template);
        await page.locator('#trialFile').setInputFiles(template);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Periksa & Pratinjau'}).click()]);
        assert(await page.getByText('Struktur file valid. Periksa hasil sebelum menyimpan.',{exact:true}).isVisible());
        assert((await page.locator('body').innerText()).includes('1.003 Bks'));
        assert((await page.locator('body').innerText()).includes('51 Pcs'));
        assert.equal(await page.getByText('Supplier per Barang',{exact:true}).count(),0);
        assert.equal(await page.locator('form[action$="/import/store"]').count(),1);
        const save=page.locator('#multiImportSave');
        assert(await save.isDisabled());
        await page.locator('#multiImportConfirmed').check();
        assert(await save.isEnabled());
        await save.click();
        await page.locator('#appDialog.show').waitFor({state:'visible'});
        assert((await page.locator('#appDialog').innerText()).includes('Simpan Impor Multi Satuan?'));
        await page.locator('#appDialog [data-dialog-cancel]').click();
        await page.locator('#appDialog.show').waitFor({state:'hidden'});
        await page.locator('#multiImportConfirmed').uncheck();
        assert(await save.isDisabled());
        await page.evaluate(()=>window.scrollTo(0,0));
        await page.screenshot({path:path.join(__dirname,'product-import-trial-desktop.png'),fullPage:true});
        for(const width of [390,320]) {
            await page.setViewportSize({width,height:844});
            await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
            await page.evaluate(()=>window.scrollTo(0,0));
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`No overflow at ${width}px`);
            await page.screenshot({path:path.join(__dirname,`product-import-trial-mobile-${width}.png`),fullPage:true});
        }
        await page.locator('a[href$="/master/products"]').filter({hasText:'Kembali'}).click();
        assert.equal(new URL(page.url()).pathname,'/master/products');
        assert.deepEqual(errors,[]);
        console.log('PASS: single canonical import with legacy redirect, three-sheet product template, preview conversions, checkbox save guard, confirmation dialog cancel, desktop/mobile layouts. No live products or stock imported.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
