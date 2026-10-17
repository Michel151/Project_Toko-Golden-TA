const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:960}}), errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:8000/login');
  await page.locator('[name=email]').fill('kasir@tokogolden.com');
  await page.locator('[name=password]').fill('password');
  await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
  await page.goto('http://127.0.0.1:8000/inventory/stock');
  assert(await page.getByRole('columnheader',{name:'Fisik / Layak Jual',exact:true}).isVisible());
  assert.equal(await page.getByRole('link',{name:'Transfer Barang',exact:true}).count(),0);
  await page.screenshot({path:path.join(__dirname,'expiry-monitoring-desktop.png'),fullPage:true});
  const firstCard=page.locator('a[href*="stock-card"]').first();
  if(await firstCard.count()){
    const url=await firstCard.getAttribute('href');
    await page.goto(url+'?tab=expiry');
    if(await page.getByText('Stok Fisik',{exact:true}).count()) assert(await page.getByText('Layak Jual',{exact:true}).isVisible());
    await page.goto('http://127.0.0.1:8000/inventory/stock');
  }
  await page.getByRole('link',{name:/Perlu Ditindaklanjuti/}).click();
  assert(await page.getByRole('heading',{name:'Perlu Ditindaklanjuti',exact:true}).isVisible());
  await page.screenshot({path:path.join(__dirname,'expiry-priorities-desktop.png'),fullPage:true});
  for(const width of [390,320]){
   await page.setViewportSize({width,height:844});
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`overflow ${width}`);
   await page.screenshot({path:path.join(__dirname,`expiry-priorities-mobile-${width}.png`),fullPage:true});
   await page.locator('nav[aria-label="Tampilan persediaan"] a').first().click();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`monitoring overflow ${width}`);
   await page.screenshot({path:path.join(__dirname,`expiry-monitoring-mobile-${width}.png`),fullPage:true});
   await page.getByRole('link',{name:/Perlu Ditindaklanjuti/}).click();
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: cashier monitoring and priorities, desktop/mobile layouts, no JS errors; no business data changed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
