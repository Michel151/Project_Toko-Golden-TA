const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:960}}), errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  let posted=0;
  await page.route('**/inventory/transfers',async route=>{
   if(route.request().method()==='POST'){posted++;await route.abort();}else await route.continue();
  });
  await page.goto('http://127.0.0.1:8000/login');
  await page.locator('[name=email]').fill('gudang@tokogolden.com');await page.locator('[name=password]').fill('password');
  await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
  await page.goto('http://127.0.0.1:8000/inventory/transfers/create');
  const {products}=await page.evaluate(()=>window.stockTransferConfig);
  const locations=await page.locator('#fromLocation option[value]:not([value=""])').evaluateAll(options=>options.map(o=>o.value));
  const source=locations.find(id=>products.filter(p=>p.product_units.some(u=>u.is_base_unit)&&p.stocks.some(s=>s.location_id==id&&(p.is_expirable?s.sellable_quantity:s.quantity)>=1)).length>=2);
  assert(source,'Two test products with stock in a shared source are needed');
  const selected=products.filter(p=>p.product_units.some(u=>u.is_base_unit)&&p.stocks.some(s=>s.location_id==source&&(p.is_expirable?s.sellable_quantity:s.quantity)>=1)).slice(0,2);
  const destination=locations.find(id=>id!==source), changedSource=locations.find(id=>id!==source&&id!==destination);
  assert(changedSource);
  await page.locator('#fromLocation').selectOption(source);
  await page.waitForFunction(id=>document.getElementById('fromLocation').value===id&&!document.getElementById('transferInfoFields').disabled,source);
  await page.locator('#toLocation').selectOption(destination);
  for(const product of selected){
   await page.locator('#productSearch').fill(product.name);
   await page.locator('#unitSelect').selectOption(String(product.product_units.find(u=>u.is_base_unit).id));
   await page.locator('#qtyInput').fill('1');await page.locator('#addTransferItem').click();
  }
  assert.equal(await page.locator('#transferItems tr').count(),2);
  assert(await page.locator('#saveTransfer').isEnabled());
  await page.locator('#fromLocation').selectOption(changedSource);
  await page.locator('#appDialog.show').waitFor();
  assert(await page.locator('#saveTransfer').isDisabled());
  await page.locator('#appDialog [data-dialog-cancel]').click();
  await page.locator('#appDialog.show').waitFor({state:'hidden'});
  assert.equal(await page.locator('#fromLocation').inputValue(),source);
  assert.equal(await page.locator('#transferItems .item-quantity').first().inputValue(),'1');

  let fail=false;
  await page.route('**/inventory/transfers/availability?**',async route=>{
   if(fail){fail=false;await route.abort('failed');return;}
   const url=new URL(route.request().url());
   const ids=url.searchParams.getAll('product_ids[]').map(Number);
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({from_location_id:Number(url.searchParams.get('from_location_id')),products:ids.map(id=>({id,quantity:id===selected[0].id?0:0.5,sellable_quantity:id===selected[0].id?0:0.5}))})});
  });
  await page.locator('#fromLocation').selectOption(changedSource);
  await page.locator('#appDialog.show').waitFor();await page.locator('#appDialog [data-dialog-confirm]').click();
  await page.waitForFunction(id=>document.getElementById('fromLocation').value===id&&!document.getElementById('transferInfoFields').disabled,changedSource);
  assert.equal(await page.locator('#transferItems .table-danger').count(),2);
  assert((await page.locator('#transferItems').innerText()).includes('Stok layak transfer kosong'));
  assert((await page.locator('#transferItems').innerText()).includes('Stok asal kurang'));
  assert(await page.locator('#saveTransfer').isDisabled());
  assert.deepEqual(await page.locator('#transferItems .item-quantity').evaluateAll(inputs=>inputs.map(i=>i.value)),['1','1']);
  await page.locator('#transferItems .remove-item').first().click();
  await page.locator('#transferItems .item-quantity').fill('0.25');
  assert(await page.locator('#saveTransfer').isEnabled());
  const second=selected[1],base=second.product_units.find(u=>u.is_base_unit), larger=second.product_units.find(u=>u.conversion_to_base>1);
  if(larger){
   await page.locator('#transferItems .item-unit').selectOption(String(larger.id));
   assert(await page.locator('#saveTransfer').isDisabled());
   await page.locator('#transferItems .item-unit').selectOption(String(base.id));
  }
  fail=true;
  await page.locator('#fromLocation').selectOption(source);
  await page.locator('#appDialog.show').waitFor();await page.locator('#appDialog [data-dialog-confirm]').click();
  await page.locator('#retryTransferStock:not(.d-none)').waitFor();
  assert.equal(await page.locator('#fromLocation').inputValue(),changedSource);
  assert(await page.locator('#saveTransfer').isDisabled());
  await page.locator('#retryTransferStock').click();
  await page.waitForFunction(()=>!document.getElementById('saveTransfer').disabled);
  await page.locator('#fromLocation').selectOption(destination);
  await page.locator('#appDialog.show').waitFor();await page.locator('#appDialog [data-dialog-confirm]').click();
  await page.waitForFunction(id=>document.getElementById('fromLocation').value===id&&!document.getElementById('transferInfoFields').disabled,destination);
  assert.equal(await page.locator('#toLocation').inputValue(),'');
  assert(await page.locator('#saveTransfer').isDisabled());
  await page.locator('#toLocation').selectOption(source);
  assert(await page.locator('#saveTransfer').isEnabled());
  assert.equal(await page.locator('[name=from_location_id]').count(),1);
  assert.equal(await page.locator('[name=to_location_id]').count(),1);
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.screenshot({path:path.join(__dirname,'transfer-locations-desktop.png'),fullPage:true});
  for(const width of [390,320]){
   await page.setViewportSize({width,height:844});
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`overflow ${width}`);
   await page.screenshot({path:path.join(__dirname,`transfer-locations-mobile-${width}.png`),fullPage:true});
  }
  assert.deepEqual(errors,[]);assert.equal(posted,0);
  console.log('PASS: one route per transfer, cancel/confirm, fresh stock checks for all items, shortages and units, failed request rollback/retry, destination collision, desktop/mobile. No transfer saved.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
