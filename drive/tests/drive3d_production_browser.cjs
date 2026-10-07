const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { readFileSync, mkdirSync } = require('node:fs');
const { createServer } = require('node:http');
const { resolve } = require('node:path');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '..');
const source = readFileSync(root + '/dataword3d.php','utf8');
// Real production markup and scripts, replacing only server-derived identity/catalog data.
let body = source.slice(source.indexOf('<body'), source.indexOf('  <script>'));
body = body.replace(/<\?php foreach \(\$breadcrumbs[\s\S]*?<\?php endforeach; \?>/, '<a>Data / Documentos</a>');
const template = source.match(/<template id="dwShelfTemplate">([\s\S]*?)<\/template>/)[1];
const start = body.indexOf('        <?php if ($state[\'folders\']');
const end = body.indexOf('</div>', body.indexOf('<?php endif; ?>', body.indexOf('<?php endforeach; ?>', start)));
// Replace the ring via its following end marker instead of trying to execute PHP bootstrap.
const ringEnd = body.indexOf('    </section>', start);
body = body.slice(0,start) + `<template id="dwShelfTemplate">${template}</template>` + Array.from({length:24},(_,i)=>`<article class="dw-shelf" data-dw-item data-item-type="folder" data-item-name="${['Documentos','Imágenes','Proyectos','Música','Archivo','Libretas'][i%6]} ${i}" data-item-path="Data/${i}/" data-open-href="/folder/${i}" data-preview-href="/preview/${i}"></article>`).join('') + '</div>' + body.slice(ringEnd);
// Drop server loops for direct files/backgrounds and replace escaped scalars.
body = body.replace(/<\?php foreach[\s\S]*?<\?php endforeach; \?>/g,'');
body = body.replace(/<\?=[\s\S]*?\?>/g,'Usuario').replace(/<\?php[\s\S]*?\?>/g,'');
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/css/dataword3d.css">${body}<script>window.ARCADECLOUD_DRIVE3D={renderer:'three'};</script><script src="/js/dataword3d.js"></script></body>`;
let requests=0;
const server=createServer((req,res)=>{
 const path = req.url.split('?')[0];
 if(path.startsWith('/preview/')) { requests++; setTimeout(()=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,state:{folders:[{name:'Subcarpeta real',kind:'folder',open_href:'/subfolder'}],files:[{name:'Informe.pdf',kind:'pdf',open_href:'/report.pdf',download_href:'/download'}],folder_count:1,file_count:1}}));},200);return; }
 if(path==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 if(!/^\/(js|css|three-lab)\/[\w/.-]+$/.test(path)){res.writeHead(404).end();return;}
 try{res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':'image/jpeg');res.end(readFileSync(root+path));}catch{res.writeHead(404).end();}
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,args:['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader']});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:960}}),errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`http://127.0.0.1:${server.address().port}`);
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D?.three?.snapshot().environmentReady,{},{timeout:30000});
  await page.waitForTimeout(500);
  page.setDefaultTimeout(60000);
  const snap=()=>page.evaluate(()=>window.ArcadeCloudDrive3D.three.snapshot());
  if(process.env.SCREENSHOT_DIR){mkdirSync(process.env.SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:resolve(process.env.SCREENSHOT_DIR,'production-initial.png')});}
  const initial=await snap(); assert(initial.visible.length>0 && initial.visible.length<=9);assert.equal(requests,0);
  assert.equal(await page.locator('.dw-camera-scene').isVisible(),false);
  assert(await page.locator('#dwThreeViewport canvas').isVisible());
  for(const shelf of initial.shelves){const [x,,z]=shelf.position;assert(Math.sqrt(initial.domeRadius**2-(Math.hypot(x,z)+shelf.depth)**2)>shelf.height,'Roof clears cabinet corners');}
  // Real raycasting, not calling selection directly. Center cabinet is above the globe.
  const viewBox=await page.locator('#dwThreeViewport').boundingBox();
  const [px,py]=initial.pickPoints[12];
  await page.locator('#dwThreeViewport').click({position:{x:(px+1)*viewBox.width/2,y:(1-py)*viewBox.height/2}});
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.previewCache.size>0);
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().shelves.some(s=>s.realBooks>0));
  assert((await snap()).shelves.some(s=>s.realBooks>=2),'Real folder/file data is rendered as 3D books');
  assert(await page.locator('[data-three-content]').isVisible());
  await page.getByRole('button',{name:'Subcarpeta real',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.ArcadeCloudDrive3D.selected.openHref),'/subfolder');
  await page.getByRole('button',{name:'Informe.pdf',exact:true}).first().click();
  assert.equal(await page.evaluate(()=>window.ArcadeCloudDrive3D.selected.downloadHref),'/download');
  await page.evaluate(()=>{const a=window.ArcadeCloudDrive3D;a.three.look(180,0);});await page.waitForFunction(()=>Math.abs(window.ArcadeCloudDrive3D.three.snapshot().yaw)>3);
  const turned=await snap();assert.deepEqual(turned.panorama,initial.panorama);assert(Math.abs(turned.yaw)>3);
  assert.equal(await page.evaluate(()=>window.ArcadeCloudDrive3D.previewCache.size),0);
  assert(turned.shelves.filter(s=>s.loaded).length<=9);assert(turned.shelves.some((s,i)=>initial.shelves[i].loaded && !s.loaded));
  const out=process.env.SCREENSHOT_DIR; if(out){mkdirSync(out,{recursive:true});await page.screenshot({path:resolve(out,'production-turned.png')});}
  await page.evaluate(()=>window.ArcadeCloudDrive3D.centerCamera());await page.waitForFunction(()=>Math.abs(window.ArcadeCloudDrive3D.three.snapshot().yaw)<.01);
  await page.keyboard.down('KeyD');await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().camera[0]>.2);await page.keyboard.up('KeyD');
  assert((await snap()).camera[0]>.1,'Movement changes world position');
  await page.evaluate(()=>window.ArcadeCloudDrive3D.centerCamera());await page.waitForTimeout(600);
  if(out)await page.screenshot({path:resolve(out,'production-front.png')});
  // Repeated load/unload must release cabinet geometry/textures.
  const memory=(await snap()).textures;
  for(const yaw of [100,-100,175,0]){await page.evaluate(y=>window.ArcadeCloudDrive3D.three.look(y,4),yaw);await page.waitForFunction(y=>Math.abs(window.ArcadeCloudDrive3D.three.snapshot().yaw+y*Math.PI/180)<.01,yaw);}
  assert((await snap()).textures<=memory+4);
  await page.setViewportSize({width:390,height:844});await page.waitForTimeout(500);
  assert((await page.locator('.dw-sidebar').boundingBox()).height<150);
  if(out)await page.screenshot({path:resolve(out,'production-mobile.png')});
  const beforeMove=(await snap()).camera[0];
  const button=await page.locator('[data-camera-strafe="1"]').boundingBox();
  await page.mouse.move(button.x+button.width/2,button.y+button.height/2);await page.mouse.down();
  await page.waitForFunction(x=>window.ArcadeCloudDrive3D.three.snapshot().camera[0]>x+.15,beforeMove);await page.mouse.up();
  assert.deepEqual(errors,[]);
  console.log('PASS production WebGL, raycast selection, real file/folder metadata, frustum unloading, bounded textures, fixed panorama, movement and mobile');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
