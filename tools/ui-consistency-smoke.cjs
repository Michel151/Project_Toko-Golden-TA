const { chromium }=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

(async()=>{
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:1000}});
        const errors=[];
        page.on('pageerror',error=>errors.push(error.message));
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('[name=email]').fill('owner@tokogolden.com');
        await page.locator('[name=password]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
        const directory=path.join(__dirname,'../storage/framework/testing/ui-consistency');
        const routes=['/master/products','/master/categories','/purchase/orders','/purchase/receipts','/purchase/returns','/sale/sales','/inventory/stock','/inventory/transfers','/inventory/opnames','/report/purchase','/report/sale','/report/transfer','/audit-logs','/permissions'];
        for(const width of [1440,390]) {
            await page.setViewportSize({width,height:1000});
            for(const route of routes) {
                const response=await page.goto('http://127.0.0.1:8000'+route);
                assert.equal(response.status(),200,route);
                await page.waitForFunction(()=>innerWidth>768 ? document.getElementById('sidebar').getBoundingClientRect().left>=-1 : document.getElementById('sidebar').getBoundingClientRect().right<=0);
                assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),route+' overflow');
                const content=await page.locator('.page-content').innerText();
                assert(!/Showing \d|\bPrevious\b|\bNext\b/.test(content),route+' untranslated pagination');
                const actions=await page.locator('.app-icon-action').evaluateAll(nodes=>nodes.filter(node=>node.getBoundingClientRect().width>0).map(node=>({width:node.getBoundingClientRect().width,height:node.getBoundingClientRect().height,label:node.getAttribute('aria-label')})));
                assert(actions.every(action=>Math.abs(action.width-32)<1 && Math.abs(action.height-32)<1 && action.label),route+' inconsistent action');
                if(['/purchase/returns','/sale/sales','/report/purchase'].includes(route)) await page.screenshot({path:path.join(directory,route.replaceAll('/','_')+'-'+width+'.png'),fullPage:true});
            }
        }
        await page.route('**/ui-consistency/**',route=>{
            assert.equal(route.request().method(),'GET');
            const name=new URL(route.request().url()).pathname.split('/').pop();
            const html=fs.readFileSync(path.join(directory,name+'.html'),'utf8').replaceAll('http://localhost/','http://127.0.0.1:8000/');
            return route.fulfill({contentType:'text/html',body:html});
        });
        for(const name of ['purchase','transfer','categories']) {
            for(const width of [1440,390]) {
                await page.setViewportSize({width,height:1000});
                await page.goto('http://127.0.0.1:8000/ui-consistency/'+name);
                await page.waitForFunction(()=>innerWidth>768 ? document.getElementById('sidebar').getBoundingClientRect().left>=-1 : document.getElementById('sidebar').getBoundingClientRect().right<=0);
                assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
                assert(await page.getByRole('navigation',{name:'Navigasi halaman'}).isVisible());
                await page.screenshot({path:path.join(directory,name+'-long-'+width+'.png'),fullPage:true});
            }
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: 14 live read-only menus and 3 isolated long-list fixtures at desktop/mobile; Indonesian pagination, 32px accessible actions, no page overflow or JavaScript errors. No business data submitted.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
