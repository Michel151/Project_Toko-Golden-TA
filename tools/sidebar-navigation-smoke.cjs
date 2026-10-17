const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  for(const [role,email,labels] of [
   ['kasir','kasir@tokogolden.com',['Transaksi Penjualan','Monitoring Stok']],
   ['gudang','gudang@tokogolden.com',['Penerimaan Barang','Monitoring Stok','Transfer Stok','Stock Opname']],
  ]){
   const context=await browser.newContext({viewport:{width:1440,height:900}});
   const page=await context.newPage();
   const errors=[];page.on('pageerror',error=>errors.push(error.message));
   await page.goto('http://127.0.0.1:8000/login');
   await page.locator('[name=email]').fill(email);await page.locator('[name=password]').fill('password');
   await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
   await page.screenshot({path:path.join(__dirname,`sidebar-${role}-desktop.png`)});
   assert.equal(await page.locator('#sidebar .nav-section-toggle').count(),0);
   assert.equal(await page.locator('#sidebar .nav-submenu').count(),0);
   for(const label of labels) assert(await page.locator('#sidebar a').filter({hasText:label}).isVisible());
   await page.screenshot({path:path.join(__dirname,`sidebar-${role}-desktop.png`)});
   await page.locator('#sidebar a').filter({hasText:'Monitoring Stok'}).click();
   assert(await page.locator('#sidebar a[aria-current="page"]').getAttribute('href').then(url=>url.endsWith('/inventory/stock')));
   await page.setViewportSize({width:390,height:844});
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
   await page.locator('#sidebarToggle').click();
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().left>=0);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await page.screenshot({path:path.join(__dirname,`sidebar-${role}-mobile.png`)});
   assert.deepEqual(errors,[]);
   await context.close();
  }
  console.log('PASS: cashier and warehouse direct navigation, active links, desktop/mobile without page overflow.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
