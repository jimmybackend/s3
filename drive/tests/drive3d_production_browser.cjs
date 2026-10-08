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
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/css/dataword3d.css"><link rel="stylesheet" href="/css/os-media-cloud.css">${body}<script>window.ARCADECLOUD_OS_APPEARANCE={};window.DRIVE_INITIAL_ROUTE='Data/';window.ARCADECLOUD_DRIVE3D={renderer:'three',visiblePath:'Data/12/',deskOnLoad:new URLSearchParams(location.search).get('desk')==='1',deskApiHref:'/desk-current'};</script><script src="/js/os-media-cloud.js"></script><script src="/js/dataword3d.js"></script></body>`;
let requests=0;
const server=createServer((req,res)=>{
 const path = req.url.split('?')[0];
 if(path.startsWith('/preview/')) { requests++; setTimeout(()=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,state:{
   folders:[{name:'Subcarpeta real',kind:'folder',open_href:'/subfolder',preview_href:'/preview/sub'}],
   files:[
    {name:'Informe.pdf',kind:'pdf',extension:'PDF',open_href:'/report.pdf',download_href:'/download',media_key:'Data/Informe.pdf',media_route:'Data/12/',mime:'application/pdf'},
    {name:'Foto.jpg',kind:'image',extension:'JPG',open_href:'/three-lab/assets/alpine-panorama.jpg',thumbnail_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/Foto.jpg',media_route:'Data/12/',mime:'image/jpeg'},
    {name:'Audio.mp3',kind:'audio',extension:'MP3',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/Audio.mp3',media_route:'Data/12/',mime:'audio/mpeg'},
    {name:'Video.mp4',kind:'video',extension:'MP4',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/Video.mp4',media_route:'Data/12/',mime:'video/mp4'}
   ],
   folder_count:1,file_count:4
 }}));},200);return; }
 if(path==='/desk-current') {
   const files=Array.from({length:18},(_,i)=>({name:`Imagen ${String(i+1).padStart(2,'0')}.jpg`,kind:'image',extension:'JPG',open_href:'/three-lab/assets/alpine-panorama.jpg',thumbnail_href:'/three-lab/assets/alpine-panorama.jpg',media_key:`Data/12/Imagen-${i+1}.jpg`,media_route:'Data/12/',mime:'image/jpeg'}));
   files.push(
     {name:'Audio.mp3',kind:'audio',extension:'MP3',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/12/Audio.mp3',media_route:'Data/12/',mime:'audio/mpeg'},
     {name:'Video.mp4',kind:'video',extension:'MP4',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/12/Video.mp4',media_route:'Data/12/',mime:'video/mp4'}
   );
   res.setHeader('Content-Type','application/json');
   res.end(JSON.stringify({ok:true,state:{visible_path:'Data/12/',files,file_count:20,file_page:1,file_pages:1,file_limit:100,folder_count:0}}));
   return;
 }
 if(path==='/' || path.startsWith('/folder/')){res.setHeader('Content-Type','text/html');res.end(html);return;}
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

  // Image media opens as independent world-anchored picture windows.
  await page.evaluate(()=>{
    const app=window.ArcadeCloudDrive3D;
    app.showFileInDome({
      type:'file',kind:'image',name:'Vista espacial A.jpg',format:'JPG',path:'Data/Vista espacial A.jpg',
      openHref:'/three-lab/assets/alpine-panorama.jpg',locked:false
    });
    app.showFileInDome({
      type:'file',kind:'image',name:'Vista espacial B.jpg',format:'JPG',path:'Data/Vista espacial B.jpg',
      openHref:'/three-lab/assets/alpine-panorama.jpg',locked:false
    });
  });
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().spatialImages.length===2);
  assert.equal(await page.locator('.dw-spatial-picture-window').count(),2);
  const picturesBefore=(await snap()).spatialImages;
  const firstPicture=picturesBefore[0];
  const secondPicture=picturesBefore[1];
  assert.notEqual(firstPicture.id,secondPicture.id,'Each image has its own persistent spatial id');

  // A picture can be switched to resize mode, dragged from its lower-right corner,
  // and the chosen size is kept in the profile state without moving its world anchor.
  const secondSelector = `[data-spatial-picture-id="${secondPicture.id}"]`;
  const secondWindow = page.locator(secondSelector);
  const secondBefore = await secondWindow.boundingBox();
  await page.locator(secondSelector + ' button[aria-label="Redimensionar cuadro"]').click({force:true});
  assert.equal(await page.locator(secondSelector + ' .dw-spatial-picture-resize-handle').isVisible(),true);
  const resizeHandle = await page.locator(secondSelector + ' .dw-spatial-picture-resize-handle').boundingBox();
  await page.mouse.move(resizeHandle.x + resizeHandle.width/2, resizeHandle.y + resizeHandle.height/2);
  await page.mouse.down();
  await page.mouse.move(resizeHandle.x + resizeHandle.width/2 + 120, resizeHandle.y + resizeHandle.height/2 + 75,{steps:5});
  await page.mouse.up();
  await page.waitForTimeout(120);
  const secondAfter = await secondWindow.boundingBox();
  assert(secondAfter.width > secondBefore.width + 25,'Dragging the corner enlarges the picture');
  const resizedState = await page.evaluate(id => window.ArcadeCloudDrive3D.spatialPictureState.find(entry => entry.id === id),secondPicture.id);
  assert(Array.isArray(resizedState.size) && resizedState.size[0] > 0 && resizedState.size[1] > 0,'Picture size is stored with the persistent picture state');
  assert.deepEqual((await snap()).spatialImages.find(p=>p.id===secondPicture.id).world,secondPicture.world,'Resizing does not move the picture world anchor');

  await page.evaluate(()=>window.ArcadeCloudDrive3D.three.look(105,0));
  await page.waitForFunction(()=>Math.abs(window.ArcadeCloudDrive3D.three.snapshot().yaw)>1.7);
  const lookedPictures=(await snap()).spatialImages;
  assert.deepEqual(lookedPictures.find(p=>p.id===firstPicture.id).world,firstPicture.world,'First picture remains at the same world coordinate while looking away');
  assert.deepEqual(lookedPictures.find(p=>p.id===secondPicture.id).world,secondPicture.world,'Second picture remains at the same world coordinate while looking away');

  await page.evaluate(id=>window.ArcadeCloudDrive3D.three.placeSpatialMedia(id),firstPicture.id);
  await page.waitForTimeout(120);
  const movedFirst=(await snap()).spatialImages.find(p=>p.id===firstPicture.id).world;
  assert.notDeepEqual(movedFirst,firstPicture.world,'One picture can be repositioned without moving the other');
  assert.deepEqual((await snap()).spatialImages.find(p=>p.id===secondPicture.id).world,secondPicture.world);

  await page.evaluate(id=>{
    document.querySelector(`[data-spatial-picture-id="${id}"] .dw-spatial-picture-actions button[aria-label="Quitar cuadro de la sala"]`)?.click();
  },firstPicture.id);
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().spatialImages.length===1);
  assert.equal(await page.locator('.dw-spatial-picture-window').count(),1);

  for(const shelf of initial.shelves){const [x,,z]=shelf.position;assert(Math.sqrt(initial.domeRadius**2-(Math.hypot(x,z)+shelf.depth)**2)>shelf.height,'Roof clears cabinet corners');}
  // Real raycasting, not calling selection directly. Center cabinet is part of the dome wall.
  const viewBox=await page.locator('#dwThreeViewport').boundingBox();
  const [px,py]=initial.pickPoints[12];
  await page.locator('#dwThreeViewport').click({position:{x:(px+1)*viewBox.width/2,y:(1-py)*viewBox.height/2}});
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.previewCache.size>0);
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().shelves.some(s=>s.realBooks>0));
  const populated = (await snap()).shelves.find(s=>s.realBooks>0);
  assert(populated.realBooks>=2,'Real folder/file data is rendered as 3D books');
  assert(populated.realItems.some(item=>item.name==='Subcarpeta real' && item.open==='/subfolder'),'Folder metadata lives in the 3D shelf');
  assert(populated.realItems.some(item=>item.name==='Informe.pdf' && item.open==='/report.pdf'),'File metadata lives in the 3D shelf');
  assert.equal(await page.locator('[data-dw-desk-carousel]').isVisible(),false,'Desk carousel stays hidden while the selected folder is still outside');
  assert.match(await page.locator('[data-hud-desk]').innerText(),/Entrar y traer al escritorio/);
  await page.locator('[data-hud-desk]').click({force:true});
  await page.waitForURL(url=>url.pathname.startsWith('/folder/') && url.searchParams.get('desk')==='1');
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D?.three?.snapshot().environmentReady,{},{timeout:30000});
  await page.waitForFunction(()=>!document.querySelector('[data-dw-desk-carousel]')?.hidden);
  await page.waitForTimeout(350);
  assert.equal(await page.locator('[data-dw-desk-carousel]').isVisible(),true,'Desk carousel appears only after entering the folder');
  assert.equal(await page.locator('[data-dw-current-files] [data-dw-item]').count(),20,'Every direct file in the entered folder is available in the desk carousel');
  assert.match(await page.locator('[data-desk-carousel-count]').innerText(),/de 20/i);
  assert.equal(await page.locator('[data-desk-prev]').isVisible(),true);
  assert.equal(await page.locator('[data-desk-next]').isVisible(),true);
  const allDeskItems=page.locator('[data-dw-current-files] [data-dw-item]');
  const firstDeskName=await allDeskItems.first().getAttribute('data-item-name');
  const firstPageNames=await allDeskItems.evaluateAll(nodes=>nodes.filter(node=>!node.hidden).map(node=>node.dataset.itemName));
  const pageBeforeNext=await page.evaluate(()=>window.ArcadeCloudDrive3D.deskCarouselPage);
  await page.locator('[data-desk-next]').click({force:true});
  await page.waitForTimeout(120);
  const pageAfterNext=await page.evaluate(()=>window.ArcadeCloudDrive3D.deskCarouselPage);
  const counterAfterNext=await page.locator('[data-desk-carousel-count]').innerText();
  const nextPageNames=await allDeskItems.evaluateAll(nodes=>nodes.filter(node=>!node.hidden).map(node=>node.dataset.itemName));
  assert(pageAfterNext!==pageBeforeNext,'Next arrow changes the carousel page index');
  assert.match(counterAfterNext,/7–12 de 20/i);
  assert(nextPageNames.length>0 && nextPageNames[0]!==firstPageNames[0],'Next arrow advances to the next group in the full carousel');
  assert.equal(await allDeskItems.count(),20,'Carousel paging keeps all files mounted');
  assert.equal(await allDeskItems.first().getAttribute('data-item-name'),firstDeskName,'Carousel navigation never replaces or truncates the file collection');
  const deskProjection = (await snap()).deskScreen;
  const deskCarouselBox = await page.locator('[data-dw-desk-carousel]').boundingBox();
  assert(Number.isFinite(deskProjection.x) && Number.isFinite(deskProjection.y),'Physical table supplies a valid screen projection');

  const audioDeskItem=page.locator('[data-dw-current-files] [data-item-kind="audio"]');
  for(let i=0;i<4 && await audioDeskItem.evaluate(node=>node.hidden);i++){
    await page.locator('[data-desk-next]').click({force:true});
    await page.waitForTimeout(60);
  }
  assert.equal(await audioDeskItem.evaluate(node=>node.hidden),false,'Carousel arrows can reach the audio item after all image pages');
  await audioDeskItem.click({force:true});
  await page.evaluate(()=>window.ArcadeCloudDrive3D.playSelected());
  await page.waitForFunction(()=>window.ArcadeCloudMediaCloud?.state?.type==='audio' && !document.getElementById('arcadeCloudMediaCloud')?.hidden);
  assert.equal(await page.locator('#arcadeCloudMediaCloud').isVisible(),true,'Audio uses the shared ArcadeCloud cloud player');

  const player = page.locator('#arcadeCloudMediaCloud');
  const playerStart = await player.boundingBox();
  const playerHead = await page.locator('#arcadeCloudMediaCloud [data-media-drag-handle]').boundingBox();
  await page.mouse.move(playerHead.x + 80, playerHead.y + 12);
  await page.mouse.down();
  await page.mouse.move(playerHead.x + 150, playerHead.y + 42,{steps:4});
  await page.mouse.up();
  const playerResize = await page.locator('#arcadeCloudMediaCloud [data-media-resize-handle]').boundingBox();
  await page.mouse.move(playerResize.x + playerResize.width/2,playerResize.y + playerResize.height/2);
  await page.mouse.down();
  await page.mouse.move(playerResize.x + playerResize.width/2 + 60,playerResize.y + playerResize.height/2 + 35,{steps:4});
  await page.mouse.up();
  if((await page.locator('#arcadeCloudMediaCloud [data-media-pin]').getAttribute('aria-pressed'))!=='true') {
    await page.locator('#arcadeCloudMediaCloud [data-media-pin]').click({force:true});
  } else {
    await page.locator('#arcadeCloudMediaCloud [data-media-pin]').click({force:true});
    await page.locator('#arcadeCloudMediaCloud [data-media-pin]').click({force:true});
  }
  const playerFixed = await player.boundingBox();
  assert(playerFixed.width > playerStart.width + 20,'Cloud player can be resized before fixing it');
  await page.locator('#arcadeCloudMediaCloud [data-media-close]').click({force:true});
  await page.waitForFunction(()=>document.getElementById('arcadeCloudMediaCloud')?.hidden===true);
  await page.locator('[data-dw-current-files] [data-item-kind="audio"]').click({force:true});
  await page.evaluate(()=>window.ArcadeCloudDrive3D.playSelected());
  await page.waitForFunction(()=>!document.getElementById('arcadeCloudMediaCloud')?.hidden);
  const playerRestored = await player.boundingBox();
  assert(Math.abs(playerRestored.x-playerFixed.x)<4 && Math.abs(playerRestored.y-playerFixed.y)<4 && Math.abs(playerRestored.width-playerFixed.width)<4,'Pinned player restores its chosen position and size');
  await page.locator('#arcadeCloudMediaCloud [data-media-close]').click({force:true});
  await page.waitForFunction(()=>document.getElementById('arcadeCloudMediaCloud')?.hidden===true);

  await page.locator('[data-dw-current-files] [data-item-kind="video"]').click({force:true});
  await page.evaluate(()=>window.ArcadeCloudDrive3D.playSelected());
  await page.waitForFunction(()=>window.ArcadeCloudMediaCloud?.state?.type==='video');
  assert.equal(await page.locator('#arcadeCloudMediaCloud').isVisible(),true,'Video uses the same cloud player design');
  assert.equal(await page.locator('[data-three-content]').count(),0,'No redundant middle content window');
  await page.locator('#arcadeCloudMediaCloud [data-media-close]').click({force:true});
  await page.waitForFunction(()=>document.getElementById('arcadeCloudMediaCloud')?.hidden===true);
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
  console.log('PASS production WebGL, entered-folder full carousel, pinned player geometry, persistent picture resize, shared cloud audio/video player, spatial pictures, raycast selection, frustum unloading, bounded textures, fixed panorama, movement and mobile');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
