const {chromium}=require('C:/Users/MICHEL/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:650},hasTouch:true});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:8000/login');
  await page.locator('[name=email]').fill('owner@tokogolden.com');await page.locator('[name=password]').fill('password');
  await Promise.all([page.waitForURL('**/dashboard'),page.locator('button[type=submit]').click()]);
  const sidebar=page.locator('#sidebar');
  for(const button of await page.locator('#sidebar .nav-section-toggle').all()){
   if(await button.getAttribute('aria-expanded')==='false') await button.click();
   await page.waitForFunction(()=>!document.querySelector('#sidebar .collapsing'));
  }
  const groupCount=await page.locator('#sidebar .nav-section-toggle').count();
  assert.equal(await page.locator('#sidebar .nav-submenu.show').count(),groupCount);
  for(const [width,height] of [[1440,650],[390,700],[320,568]]){
   await page.setViewportSize({width,height});
   if(width<769){
    await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0||document.getElementById('sidebar').classList.contains('show'));
    if(!await sidebar.evaluate(el=>el.classList.contains('show'))) await page.locator('#sidebarToggle').click();
    await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().left>=0);
   }
   const geometry=await sidebar.evaluate(el=>({height:el.clientHeight,content:el.scrollHeight,viewport:innerHeight}));
   assert(geometry.height<=height+1,`Sidebar exceeds viewport ${width}`);
   assert(geometry.content>geometry.height,`Expanded groups should overflow internally ${width}`);
   await sidebar.evaluate(el=>el.scrollTop=0);
   const pageScroll=await page.evaluate(()=>scrollY);
   await page.mouse.move(120,300);await page.mouse.wheel(0,1800);
   await page.waitForFunction(()=>document.getElementById('sidebar').scrollTop>0);
   assert.equal(await page.evaluate(()=>scrollY),pageScroll);
   const lastLink=page.locator('#sidebar a').last();
   await lastLink.focus();
   assert(await lastLink.evaluate(el=>{const r=el.getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight;}),`Bottom menu reachable ${width}`);
   assert.equal(await page.locator('#sidebar .nav-submenu.show').count(),groupCount);
   await page.screenshot({path:path.join(__dirname,`sidebar-scroll-${width}.png`)});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: all owner groups open simultaneously; independent wheel scroll, keyboard reach to last menu, fixed viewport height at desktop/390/320px.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
