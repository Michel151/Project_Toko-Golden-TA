const { chromium } = require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({headless:true, channel:'msedge'});
    try {
        const page = await browser.newPage();
        const errors=[];
        page.on('pageerror',error=>errors.push(error.message));
        const directory=path.join(__dirname,'../storage/framework/testing/login');
        await page.route('**/login*',route=> {
            if (new URL(route.request().url()).pathname !== '/login') return route.fallback();
            assert.equal(route.request().method(),'GET');
            const name=route.request().url().includes('cooldown') ? 'cooldown' : 'login';
            const html=fs.readFileSync(path.join(directory,name+'.html'),'utf8')
                .replaceAll('http://localhost/','http://127.0.0.1:8000/')
                .replace(/data-retry-until="\d+"/,`data-retry-until="${Math.floor(Date.now()/1000)+2}"`);
            return route.fulfill({contentType:'text/html',body:html});
        });
        for (const width of [1440,390]) {
            await page.setViewportSize({width,height:900});
            await page.goto('http://127.0.0.1:8000/login');
            await page.locator('.store-image').evaluate(image => image.decode());
            assert(await page.locator('.store-image').evaluate(image => image.naturalWidth > 0));
            const input=page.locator('#password');
            await input.fill('contoh-uji');
            await page.getByRole('button',{name:'Tampilkan password'}).click();
            assert.equal(await input.getAttribute('type'),'text');
            assert.equal(await input.inputValue(),'contoh-uji');
            await page.getByRole('button',{name:'Sembunyikan password'}).click();
            assert.equal(await input.getAttribute('type'),'password');
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            await page.screenshot({path:path.join(directory,`login-${width}.png`),fullPage:true});
        }
        await page.goto('http://127.0.0.1:8000/login?cooldown=1');
        await page.screenshot({path:path.join(directory,'cooldown-mobile.png'),fullPage:true});
        await page.waitForFunction(()=>document.getElementById('loginCooldown').textContent==='Anda dapat mencoba login kembali.');
        assert(await page.getByRole('button',{name:'Masuk'}).isEnabled());
        assert.deepEqual(errors,[]);
        console.log('PASS: password toggle, preserved input, countdown, desktop/mobile. No live login attempts submitted.');
    } finally { await browser.close(); }
})().catch(error=>{ console.error(error);process.exitCode=1; });
