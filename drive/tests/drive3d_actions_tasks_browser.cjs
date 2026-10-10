'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'so.php'),'utf8');
const menus=source.slice(source.indexOf('  <div class="os-file-context os-folder-context"'),source.indexOf('  <!-- Modal compartido: Seguridad'));
const errors=[];let frameLoads=0;
const script=name=>`<script src="/js/${name}"></script>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
 for(const viewport of [{width:1280,height:900},{width:768,height:1024},{width:360,height:800}]){
  const page=await browser.newPage({viewport});
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const url=new URL(route.request().url());
   if(/^\/(js|css)\/[\w.-]+$/.test(url.pathname)){
    let body=fs.readFileSync(path.join(root,url.pathname),'utf8');
    if(url.pathname.endsWith('background-tasks.js'))body=body.replace('BackgroundTaskCenter.boot();','window.TaskCenterClass=BackgroundTaskCenter;');
    return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body});
   }
   if(url.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><link rel="stylesheet" href="/css/dataword3d.css"><link rel="stylesheet" href="/css/so.css"></head><body class="arcade-os"><section class="dw-hud"><button data-hud-more-actions>Acciones</button><div data-hud-extra-actions hidden></div></section><script>window.DRIVE_INITIAL_ROUTE='user/docs/';</script>${script('drive3d-actions.js')}${script('background-tasks.js')}</body></html>`});
   if(url.pathname==='/so.php'){
    frameLoads++;
    const routeName=url.searchParams.get('ruta') || 'user/docs/';
    return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><link rel="stylesheet" href="/css/so.css"></head><body class="arcade-os"><main id="osDesktop"><div class="os-explorer-body" data-current-folder-route="${routeName}" data-current-folder-name="Docs" data-current-folder-root="${routeName==='user/'?'1':'0'}"><button class="os-file-entry" data-key="user/docs/uno.txt" data-name="uno.txt" data-ext="txt" data-open-url="/open" data-download-url="/download" data-polly="1" data-locked="0"></button><button class="os-file-entry" data-key="user/docs/locked.txt" data-name="locked.txt" data-ext="txt" data-open-url="/open" data-locked="1"></button></div></main>${menus}<script>window.calls=[];window.ArcadeCloudFileSecurity={protect:async key=>{calls.push(['protect',key]);return false;},unlock:async key=>{calls.push(['unlock',key]);return false;}};window.openEmptyTextFileCreator=(route,name)=>calls.push(['new-text',route,name]);</script>${script('so-folders.js')}${script('so.js')}${script('so-clipboard.js')}</body></html>`});
   }
   errors.push('Unexpected request '+url.pathname);return route.fulfill({status:404,body:'Unexpected test URL'});
  });
  await page.goto('http://arcade.test/fixture');
  assert.equal(await page.locator('iframe').count(),0,'action runtime is lazy');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'file',name:'uno.txt',key:'user/docs/uno.txt',route:'user/docs/'}})));
  await page.locator('[data-hud-more-actions]').click();
  const list=page.locator('[data-hud-extra-actions]');
  await list.getByRole('button',{name:'Bloquear con contraseña',exact:true}).waitFor();
  assert.equal(await list.getByRole('button',{name:'Eliminar',exact:true}).count(),1);
  assert.equal(await list.getByRole('button',{name:'Compartir',exact:true}).count(),1);
  assert.equal(await list.getByRole('button',{name:/Polly/}).count(),1,'capability from real SO controller');
  assert.equal(await list.getByRole('button',{name:/Transcribe/}).count(),0,'unsupported action excluded');
  assert.equal(await page.locator('iframe').count(),1);
  await list.getByRole('button',{name:'Bloquear con contraseña',exact:true}).click();
  assert.deepEqual(await page.locator('iframe').evaluate(frame=>frame.contentWindow.calls),[['protect','user/docs/uno.txt']],'real SO handler receives exact key');
  await page.getByRole('button',{name:'Volver al 3D'}).click();
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'file',name:'locked.txt',key:'user/docs/locked.txt',route:'user/docs/'}})));
  await list.getByRole('button',{name:'Desbloquear',exact:true}).waitFor();
  assert.equal(await list.getByRole('button',{name:'Descargar',exact:true}).count(),0,'locked-file download remains unavailable');
  await list.getByRole('button',{name:'Desbloquear',exact:true}).click();
  assert.deepEqual(await page.locator('iframe').evaluate(frame=>frame.contentWindow.calls),[['unlock','user/docs/locked.txt']]);
  await page.getByRole('button',{name:'Volver al 3D'}).click();
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'folder',name:'Docs',openHref:'/dataword3d.php?ruta=user%2Fdocs%2F'}})));
  await list.getByRole('button',{name:'Nuevo archivo de texto',exact:true}).waitFor();
  await list.getByRole('button',{name:'Nuevo archivo de texto',exact:true}).click();
  assert.deepEqual(await page.locator('iframe').evaluate(frame=>frame.contentWindow.calls),[['new-text','user/docs/','Docs']],'folder handler receives authenticated current folder');
  await page.getByRole('button',{name:'Volver al 3D'}).click();
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'folder',name:'Raíz',openHref:'/dataword3d.php?ruta=user%2F'}})));
  await page.waitForFunction(()=>document.querySelector('[data-hud-extra-actions]').textContent.includes('Nuevo archivo de texto'));
  assert.equal(await list.getByRole('button',{name:'Eliminar',exact:true}).count(),0,'root remains protected');
  assert.equal(await page.locator('iframe').count(),1,'one reused frame for all selections');
  // Task center uses the production renderer/CSS, with deterministic data.
  await page.evaluate(()=>{
   const center=window.center=new window.TaskCenterClass(window,document);
   center.tasks=Array.from({length:32},(_,i)=>({id:'fixture:'+i,kind:'sync',title:'Task '+i,status:i<12?'running':i<22?'completed':'failed',detail:i>=22?'Reason '+i:'Progress',actions:[]}));
   center.summary={active:12,running:12,completed_recent:10,failed:10};center.open=true;center.render();
  });
  assert.equal(await page.locator('.bg-task-active-card').count(),8);
  assert.equal(await page.locator('.bg-task-item').count(),24,'active overflow and terminal history stay in list');
  const scroll=page.locator('.bg-task-scroll');
  assert(await scroll.evaluate(e=>e.scrollHeight>e.clientHeight),'tasks have real overflow');
  const bounds=await page.locator('#backgroundTaskPanel').boundingBox();
  assert(bounds.y>=0 && bounds.y+bounds.height<=viewport.height,'panel fits viewport including OS taskbar');
  await scroll.evaluate(e=>e.scrollTop=e.scrollHeight);
  assert(await scroll.evaluate(e=>e.scrollTop>0),'task region scrolls to final rows');
  await page.locator('.bg-task-filter').selectOption('running');
  assert.equal(await page.locator('.bg-task-active-card').count(),8);
  assert.equal(await page.locator('.bg-task-item').count(),4,'tasks after eighth remain reachable');
  for(const filter of ['completed','failed']){
   await page.locator('.bg-task-filter').selectOption(filter);
   assert.equal(await page.locator('.bg-task-item').count(),10);
   await scroll.evaluate(e=>e.scrollTop=e.scrollHeight);
   assert(await scroll.evaluate(e=>e.scrollTop>0));
  }
  assert.match(await page.locator('.bg-task-list').textContent(),/Reason 31/,'failure reason remains visible at end');
  await page.close();
 }
 assert.equal(errors.length,0,JSON.stringify(errors));
 assert(frameLoads>0);
 console.log('3D real SO action dispatch, capabilities, locked/root permissions, one lazy frame, and task scroll/overflow: desktop, tablet and mobile OK');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
