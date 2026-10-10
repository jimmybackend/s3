'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'so.php'),'utf8');
const menus=source.slice(source.indexOf('  <div class="os-file-context os-folder-context"'),source.indexOf('  <!-- Modal compartido: Seguridad'));
assert.match(source,/if \(\$isExplorerFragment \|\| \$isDrive3dActions\)/,'action mode renders the same authorized explorer');
const errors=[];let contextLoads=0;let sharedTasks=[];
const script=name=>`<script src="/js/${name}"></script>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
 for(const viewport of [{width:1280,height:900},{width:768,height:1024},{width:360,height:800}]){
  const page=await browser.newPage({viewport,hasTouch:viewport.width<=768});
  let failDependency=true;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/js/vendor/jquery-3.5.1.min.js'&&failDependency){failDependency=false;return route.abort('failed');}
   if(/^\/(js|css)\/(?:vendor\/)?[\w.-]+$/.test(url.pathname)){
    let body=fs.readFileSync(path.join(root,url.pathname),'utf8');
    if(url.pathname.endsWith('background-tasks.js'))body=body.replace('BackgroundTaskCenter.boot();','window.TaskCenterClass=BackgroundTaskCenter;');
    return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body});
   }
   if(url.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/css/dataword3d.css"><link rel="stylesheet" href="/css/so.css"></head><body class="dw-real"><div class="dw-world" style="position:fixed;inset:60px 0 0"><section class="dw-hud"><button data-hud-more-actions>Acciones</button><div class="dw-hud-extra-actions" data-hud-extra-actions hidden></div></section></div><script>window.DRIVE_INITIAL_ROUTE='user/docs/';</script>${script('drive3d-actions.js')}${script('background-tasks.js')}</body></html>`});
   if(url.hostname==='stackpath.bootstrapcdn.com')return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:''});
   if(url.hostname==='code.jquery.com')return route.fulfill({contentType:'text/javascript',body:'window.jQuery=function(){};window.jQuery.fn={modal(){}};'});
   if(url.pathname==='/background_tasks.php')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,tasks:sharedTasks,summary:{active:sharedTasks.length,running:sharedTasks.length}})});
   if(url.pathname==='/move_task.php'){
    assert.equal(route.request().method(),'POST');
    sharedTasks=[{id:'move:fixture',kind:'move',status:'running',title:'Copia persistente',detail:'Procesando',actions:[]}];
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,job_id:'fixture',operation:'copy'})});
   }
   if(url.pathname==='/move_task_status.php')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,job_id:'fixture',estado:'running',progress:35})});
   if(url.pathname==='/so.php'){
    assert.equal(url.searchParams.get('_drive3d_actions'),'native','only authenticated components fetched');
    contextLoads++;
    const routeName=url.searchParams.get('ruta') || 'user/docs/';
    const html=`<section class="os-window os-explorer-window"><div class="os-explorer-live" data-explorer-route="${routeName}"><div class="os-explorer-pathrow"></div><div class="os-explorer-body" data-current-folder-route="${routeName}" data-current-folder-name="Docs" data-current-folder-root="${routeName==='user/'?'1':'0'}"><button class="os-file-entry" data-key="user/docs/uno.txt" data-name="uno.txt" data-ext="txt" data-open-url="/open" data-download-url="/download" data-polly="1" data-locked="0"></button><button class="os-file-entry" data-key="user/docs/locked.txt" data-name="locked.txt" data-ext="txt" data-open-url="/open" data-locked="1"></button></div></div></section>${menus}`;
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,html,context:{csrf:'fixture',bucket:'fixture',rootRoute:'user/',route:routeName,folder:{route:routeName,name:'Docs',is_root:routeName==='user/'}},scripts:Array.from(source.matchAll(/<script[^>]*src="js\/([\w.-]+)\?v=/g),match=>match[1]).filter(name=>['polly.js','aws-comprehend.js','media-processing.js','background-task-feedback.js','polly-background.js','transcribe-background.js','filesystem-operations.js','move-tasks.js','carpetas.js','folder-document.js','so-new-text-file.js','sincronizar.js','so-folders.js','file-security.js','arcadelink-share.js','so-share.js','os-window-manager.js','file-applications.js','so.js','so-clipboard.js'].includes(name)).map(name=>'js/'+name+'?v=fixture')})});
   }
   errors.push('Unexpected request '+url.pathname);return route.fulfill({status:404,body:'Unexpected test URL'});
  });
  await page.goto('http://arcade.test/fixture');
  await page.evaluate(()=>{window.calls=[];window.DriveMoveTasks={};window.ArcadeCloudFileSecurity={protect:async key=>{calls.push(['protect',key]);return false;},unlock:async key=>{calls.push(['unlock',key]);return false;}};window.openEmptyTextFileCreator=(route,name)=>calls.push(['new-text',route,name]);});
  assert.equal(await page.locator('#osDesktop').count(),0,'action runtime is lazy');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'file',name:'uno.txt',key:'user/docs/uno.txt',route:'user/docs/'}})));
  await page.locator('[data-hud-more-actions]').click();
  const list=page.locator('[data-hud-extra-actions]');
  await list.getByRole('button',{name:'Reintentar acciones',exact:true}).waitFor();
  assert.match(await list.textContent(),/No se pudo cargar.*jquery/,'failed dependency reports error instead of hanging');
  await list.getByRole('button',{name:'Reintentar acciones',exact:true}).click();
  await list.getByRole('button',{name:'Bloquear con contraseña',exact:true}).waitFor();
  assert.match(await list.textContent(),/Bloquear con contraseña/,JSON.stringify({errors,viewport})+' '+await list.textContent());
  await list.getByRole('button',{name:'Bloquear con contraseña',exact:true}).waitFor();
  await page.evaluate(()=>{window.ArcadeCloudFileSecurity.protect=async key=>{calls.push(['protect',key]);return false;};window.ArcadeCloudFileSecurity.unlock=async key=>{calls.push(['unlock',key]);return false;};window.openEmptyTextFileCreator=(route,name)=>calls.push(['new-text',route,name]);});
  assert.equal(await list.getByRole('button',{name:'Eliminar',exact:true}).count(),1);
  assert.equal(await list.getByRole('button',{name:'Compartir',exact:true}).count(),1);
  assert.equal(await list.getByRole('button',{name:/Polly/}).count(),1,'capability from real SO controller');
  assert.equal(await list.getByRole('button',{name:/Transcribe/}).count(),0,'unsupported action excluded');
  assert.equal(await page.locator('iframe').count(),0,'SO is never embedded or opened');
  assert.equal(await page.evaluate(()=>window.ArcadeCloudDesktop.explorers.size),1,'one authorization context attached to real window runtime');
  const widths=await list.locator('button').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().width));
  assert(widths.every(width=>Math.abs(width-widths[0])<1),'all buttons fill panel width');
  await list.getByRole('button',{name:'Detalles',exact:true}).click();
  await page.locator('.os-file-details-dialog').waitFor();
  assert.match(await page.locator('.os-file-details-dialog').textContent(),/uno.txt/,'actual details dialog runs in 3D document');
  await page.locator('.os-file-details-dialog button').click();
  await list.getByRole('button',{name:'Bloquear con contraseña',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls),[['protect','user/docs/uno.txt']],'real SO handler receives exact key');
  assert.equal(await page.locator('.dw-action-dialog').count(),0);
  await page.evaluate(()=>window.calls=[]);
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'file',name:'locked.txt',key:'user/docs/locked.txt',route:'user/docs/'}})));
  await list.getByRole('button',{name:'Desbloquear',exact:true}).waitFor();
  assert.equal(await list.getByRole('button',{name:'Descargar',exact:true}).count(),0,'locked-file download remains unavailable');
  await list.getByRole('button',{name:'Desbloquear',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls),[['unlock','user/docs/locked.txt']]);
  assert.equal(await page.locator('.dw-action-dialog').count(),0);
  await page.evaluate(()=>window.calls=[]);
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'folder',name:'Docs',openHref:'/dataword3d.php?ruta=user%2Fdocs%2F'}})));
  await list.getByRole('button',{name:'Nuevo archivo de texto',exact:true}).waitFor();
  await list.getByRole('button',{name:'Nuevo archivo de texto',exact:true}).click();
  assert.deepEqual(await page.evaluate(()=>window.calls),[['new-text','user/docs/','Docs']],'folder handler receives authenticated current folder');
  assert.equal(await page.locator('.dw-action-dialog').count(),0);
  await page.evaluate(()=>window.calls=[]);
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('drive3d:selection',{detail:{type:'folder',name:'Raíz',openHref:'/dataword3d.php?ruta=user%2F'}})));
  await page.waitForFunction(()=>!document.querySelector('[data-hud-extra-actions]').textContent.includes('Eliminar') && document.querySelector('[data-hud-extra-actions]').textContent.includes('Nuevo archivo de texto'));
  assert.equal(await list.getByRole('button',{name:'Eliminar',exact:true}).count(),0,'root remains protected');
  assert.equal(await page.locator('iframe').count(),0,'all actions stay in 3D');
  assert.equal(await page.evaluate(()=>window.ArcadeCloudDesktop.explorers.size),1,'old context disposed when selection changes');
  assert.equal(await page.locator('#fileContextMenu').count(),1,'menus/modules reused');
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
  sharedTasks=[];
  await page.evaluate(()=>window.DriveMoveTasks.start({type:'files',operation:'copy',ruta_actual:'user/docs/',nueva_ruta:'user/',archivos_json:'["user/docs/uno.txt"]'}));
  await page.evaluate(()=>{window.center.filter='all';return window.center.refresh();});
  assert.equal(await page.evaluate(()=>window.center.tasks[0].id),'move:fixture','3D reads task created by real transfer starter');
  // The same persisted record survives independent page reloads/views.
  for(const view of ['3d','so','s3']){
    await page.goto('http://arcade.test/fixture?view='+view);
    await page.evaluate(async()=>{window.center=new window.TaskCenterClass(window,document);window.center.open=true;await window.center.refresh();});
    assert.equal(await page.evaluate(()=>window.center.tasks[0].id),'move:fixture','same persistent task ID in '+view);
    assert.equal(await page.evaluate(()=>window.center.summary.active),1,'task is not duplicated in '+view);
  }
  await page.close();
 }
 assert.equal(errors.length,0,JSON.stringify(errors));
 assert(contextLoads>0);
 console.log('3D real SO action dispatch, capabilities, locked/root permissions, native dialogs, full-width buttons and one disposed/reused authorization context, and task scroll/overflow: desktop, tablet and mobile OK');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
