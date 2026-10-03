'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const render = view => execFileSync('php', [path.join(__dirname, 'system_panel_fixture.php'), view], {env:{...process.env, ARCADECLOUD_ISOLATED_TEST:'1'}, encoding:'utf8'});
const views = {about:render('about'), moderation:render('moderation'), ec2:render('ec2')};
(async()=>{
 const browser = await chromium.launch({headless:true, args:['--no-sandbox']});
 try {
 const page = await browser.newPage({viewport:{width:360,height:800}});
 const errors=[]; const posts=[]; const calls=[];
 let officeActivity=0;
 const gateway=fs.readFileSync(path.join(root,'office-gateway.php'),'utf8');
 const officeScript=gateway.slice(gateway.indexOf('(() => {\n  const frame'),gateway.indexOf('</script>',gateway.indexOf('(() => {\n  const frame'))).replace(/<\?= [\s\S]*?\?>/g,'false');
 page.on('pageerror', e=>errors.push(e.message));
 page.on('dialog', d=>d.accept());
 let ec2State='stopped', rdsState='stopped';
 await page.route('**/*', async route=>{
  const req=route.request(), url=new URL(req.url()); calls.push(url.pathname);
  const json = value=>route.fulfill({contentType:'application/json',body:JSON.stringify(value)});
  if(req.method()==='POST') posts.push({path:url.pathname,body:req.postData(),headers:req.headers()});
  if(url.pathname==='/__office_activity') {officeActivity++; return json({ok:true});}
  if(url.pathname==='/__office_idle') return json({ok:true,idle:{state:'running',idle_elapsed_seconds:5,idle_grace_seconds:1200}});
  if(url.pathname==='/desktop-fixture') return route.fulfill({contentType:'text/html',body:'<input id="remote" autofocus><script>document.addEventListener("pointerdown",e=>e.stopPropagation());</script>'});
  if(url.pathname==='/office-fixture') return route.fulfill({contentType:'text/html',body:`<iframe id="officeFrame" src="/desktop-fixture"></iframe><div id="officeIdleWarning" hidden></div><span id="officeIdleCount"></span><button id="officeContinue"></button><div id="officeIdleStatus"></div><script>${officeScript}</script>`});
  if(url.pathname==='/federationcloud/os-admin.php') return route.fulfill({contentType:'text/html',body:views.about});
  if(url.pathname==='/federationcloud/moderation.php') return route.fulfill({contentType:'text/html',body:views.moderation});
  if(url.pathname==='/ec2.php') {
   if(req.method()==='POST') {if(req.postData().includes('rds_action')) rdsState='available'; else ec2State='running'; return json({ok:true});}
   if(url.searchParams.get('ajax')==='status') return json({ok:true,state:ec2State,pip:'192.0.2.1',prip:'10.0.0.1',type:'c7i.xlarge',az:'us-east-1a'});
   if(url.searchParams.get('ajax')==='rds_status') return json({ok:true,status:rdsState});
   return route.fulfill({contentType:'text/html',body:views.ec2});
  }
  if(url.pathname==='/federationcloud/nodes.php') return json({ok:true,connected_nodes:2,local_node:{node_id:'acn_fixture',node_name:'Drive'},nodes:[{node_id:'acn_peer',node_name:'FastDrive',public_url:'https://'+ 'long'.repeat(40)+'.invalid'}]});
  if(url.pathname==='/federationcloud/provider-admin.php') return json({ok:true,pending:[],active:[]});
  if(url.pathname==='/server-settings.php') return json({ok:true,settings:[],helper:{}});
  if(url.pathname==='/federationcloud/moderation-api.php') return json({ok:true,reports:[{ReportId:'report-fixture',Category:'fixture',TargetType:'file',TargetId:'fixture'}],active_blocks:[{ContentId:'a'.repeat(64)}]});
  if(/^\/(css|js)\/[\w.-]+$/.test(url.pathname)) {
   let file=path.join(root,url.pathname); let body=fs.readFileSync(file,'utf8');
   if(url.pathname.endsWith('os-window-manager.js')) body=body.slice(0,body.lastIndexOf("if (typeof window !== 'undefined')"));
   return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body});
  }
  if(url.pathname==='/so.php') return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head>${['so.css','personal-tools.css','os-system-panel.css','federation-os-admin.css','federation-moderation.css'].map(x=>`<link rel="stylesheet" href="/css/${x}">`).join('')}</head><body class="arcade-os"><div id="federationToolbar"></div><div id="federationApp"></div><div id="tool" class="os-tool-body"></div><script src="/js/os-window-manager.js"></script><script src="/js/so-federation.js"></script></body></html>`});
  return route.fulfill({status:404,body:'Unexpected fixture URL'});
 });
 await page.goto('http://arcade.test/so.php');
 for(let repeat=0;repeat<2;repeat++){
  await page.evaluate(()=>window.ArcadeCloudOsFederation.openView('about'));
  await page.waitForFunction(()=>document.querySelector('#federationAboutNodeName')?.textContent==='Drive');
  assert.equal(await page.locator('#modalServerAdmin').evaluate(e=>e.closest('#federationApp')!==null),true,'server settings stay inside Federation');
  assert(calls.includes('/server-settings.php'),'admin settings loaded with preserved role');
  assert.equal(await page.locator('.os-node-card h3').first().evaluate(e=>parseFloat(getComputedStyle(e).fontSize)<18),true);
  assert.equal(await page.locator('#federationApp').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'no mobile horizontal overflow');
  await page.locator('a[href="/federationcloud/moderation.php?embed=1"]').click();
  await page.waitForSelector('[data-decision="confirm"]');
  for(const decision of ['confirm','reject']){
   await page.locator('.decision-reason').fill('fixture review reason');
   await page.locator(`[data-decision="${decision}"]`).click();
   await page.waitForFunction(()=>!document.querySelector('[data-decision="confirm"]').disabled);
  }
  await page.locator('.unblock-reason').fill('fixture revoke reason');
  await page.locator('[data-unblock]').click();
 }
 await page.evaluate(async()=>{
  const runtime={window,document};
  await ArcadeCloudDesktopRuntime.prototype.loadToolContent.call(runtime,document.getElementById('tool'),'ec2.php?surface=os');
 });
 assert.equal(await page.locator('#tbl article').count(),1);
 assert.equal(await page.locator('#rdsTbl article').count(),1);
 await page.locator('[data-action="start"]').click();
 await page.locator('#pw').fill('fixture-password');
 await page.locator('#confirm').click();
 await page.waitForFunction(()=>document.querySelector('[data-state]')?.textContent==='running');
 assert.equal(await page.locator('[data-pip]').textContent(),'192.0.2.1');
 await page.locator('[data-rds-action="start"]').click();
 await page.locator('#pw').fill('fixture-password');
 await page.locator('#confirm').click();
 await page.waitForFunction(()=>document.querySelector('[data-rds-state]')?.textContent==='available');
 assert.equal(await page.locator('#tool').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'EC2 mobile overflow');
 assert(posts.filter(p=>p.path==='/federationcloud/moderation-api.php').length>=6,'moderation actions across repeated opens');
 assert(posts.some(p=>p.headers['x-federation-moderation-csrf']==='moderation-fixture'),'moderation CSRF preserved');
 assert.deepEqual(errors,[],'no JavaScript errors while reopening panels');
 for(const width of [320,768,1440]){await page.setViewportSize({width,height:900});assert.equal(await page.locator('#tool').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,`EC2 overflow at ${width}`);}
 await page.clock.install();
 await page.goto('http://arcade.test/office-fixture');
 await page.waitForFunction(()=>document.getElementById('officeFrame').contentDocument.getElementById('remote'));
 await page.clock.runFor(25000);
 assert.equal(officeActivity,1,'idle polling and iframe loading do not generate human activity');
 await page.frameLocator('#officeFrame').locator('#remote').click();
 await page.waitForFunction(()=>document.getElementById('officeIdleStatus').textContent.includes('Inactivo'));
 await page.clock.runFor(25000);
 assert.equal(officeActivity,2,'capture sees remote mouse activity even when desktop stops bubbling');
 await page.frameLocator('#officeFrame').locator('#remote').press('A');
 await page.clock.runFor(25000);
 assert.equal(officeActivity,3,'remote keyboard resets activity');
 await page.goto('http://arcade.test/so.php');
 await page.clock.runFor(60000);
 assert.equal(officeActivity,3,'leaving Office ends all activity');
 assert.deepEqual(errors,[],'no JavaScript errors in Office wrapper');
 console.log('System panels browser: embedded/reopen/moderation/EC2/RDS/mobile OK');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
