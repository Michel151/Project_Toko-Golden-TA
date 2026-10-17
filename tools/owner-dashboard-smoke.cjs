const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
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
        await page.waitForFunction(()=>typeof Chart!=='undefined' && Chart.getChart('salesTrendChart'));
        assert.equal(await page.evaluate(()=>Chart.getChart('salesTrendChart').data.labels.length),7);
        await page.locator('[data-trend-days="30"]').click();
        assert.equal(await page.evaluate(()=>Chart.getChart('salesTrendChart').data.labels.length),30);
        assert.equal(await page.locator('[data-trend-days="30"]').getAttribute('aria-pressed'),'true');
        await page.locator('[data-trend-days="7"]').click();
        await page.locator('#rankingMode').selectOption('quantity');
        assert(await page.locator('[data-ranking=quantity]').isVisible());
        assert(!(await page.locator('[data-ranking=revenue]').isVisible()));
        await page.locator('#rankingMode').selectOption('revenue');
        for(const canvas of await page.locator('canvas').all()) {
            assert(await canvas.evaluate(el=>Array.from(el.getContext('2d').getImageData(0,0,el.width,el.height).data).some((value,i)=>i%4===3 && value>0)),'Canvas must have painted pixels');
        }
        const point=await page.evaluate(()=>{
            const chart=Chart.getChart('paymentChart'),arc=chart.getDatasetMeta(0).data[0],angle=(arc.startAngle+arc.endAngle)/2,radius=(arc.innerRadius+arc.outerRadius)/2,rect=chart.canvas.getBoundingClientRect();
            return {x:rect.left+arc.x+Math.cos(angle)*radius,y:rect.top+arc.y+Math.sin(angle)*radius};
        });
        await page.mouse.move(point.x,point.y);
        await page.waitForFunction(()=>Chart.getChart('paymentChart').tooltip.opacity>0);
        const tooltip=await page.evaluate(()=>Chart.getChart('paymentChart').tooltip.body[0].lines[0]);
        assert.match(tooltip,/Rp .*%/);
        for(const url of await page.locator('.owner-action[href]:not([href^="#"])').evaluateAll(els=>els.map(el=>el.href))) assert.equal((await page.request.get(url)).status(),200,url);
        await page.screenshot({path:path.join(__dirname,'owner-dashboard-desktop.png'),fullPage:true});
        for(const width of [390,320]) {
            await page.setViewportSize({width,height:844});
            await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
            await page.waitForTimeout(300);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),JSON.stringify(await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(el=>el.getBoundingClientRect().right>innerWidth+1 && getComputedStyle(el).position!=='fixed').map(el=>({tag:el.tagName,cls:el.className,right:el.getBoundingClientRect().right})).slice(0,15))));
            await page.screenshot({path:path.join(__dirname,`owner-dashboard-mobile-${width}.png`),fullPage:true});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: owner charts, 7/30 day switch, product ranking, payment tooltip, nonblank canvases, action links, desktop/mobile layouts. Read-only; no transactions changed.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
