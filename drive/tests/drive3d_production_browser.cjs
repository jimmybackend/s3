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
const folderLoop = /<\?php foreach \(\$state\['folders'\] as \$folder\): \?>[\s\S]*?<\?php endforeach; \?>/;
body = body.replace(folderLoop, Array.from({length:6},(_,i)=>`<article class="dw-shelf" data-dw-item data-item-type="folder" data-item-name="${['Documentos','Imágenes','Proyectos','Música','Archivo','Libretas'][i%6]} ${i}" data-item-path="Data/${i}/" data-open-href="/folder/${i}" data-preview-href="/preview/${i}"></article>`));
body = body.replace(/<\?php foreach[\s\S]*?<\?php endforeach; \?>/g,'');
body = body.replace(/<\?=[\s\S]*?\?>/g,'Usuario').replace(/<\?php[\s\S]*?\?>/g,'');
const html = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/css/dataword3d.css"><link rel="stylesheet" href="/css/os-media-cloud.css">${body}<script>window.ARCADECLOUD_OS_APPEARANCE={};window.DRIVE_INITIAL_ROUTE='Data/12/';window.ARCADECLOUD_DRIVE3D={renderer:'three',visiblePath:'Data/12/',filesApiHref:'/files-current'};</script><script src="/js/os-media-cloud.js"></script><script src="/js/dataword3d.js"></script></body>`;
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
 if(path==='/files-current') {
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
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().currentFiles===20,{},{timeout:30000});
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

  // A picture can be placed on a chosen floor position with a real touch,
  // then moved between window panes and ceiling without an extra modal.
  const surfaceSelector = `[data-spatial-picture-id="${secondPicture.id}"]`;
  await page.evaluate(()=>window.ArcadeCloudDrive3D.three.look(0,30));
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().pitch < -.48);
  await page.locator(surfaceSelector + ' select[aria-label="Colocar imagen en el domo"]').selectOption('floor');
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().pendingSurface==='floor');
  const floorCanvas = await page.locator('#dwThreeViewport').boundingBox();
  await page.mouse.click(floorCanvas.x+floorCanvas.width*.54,floorCanvas.y+floorCanvas.height*.77);
  await page.waitForFunction(id => window.ArcadeCloudDrive3D.three.snapshot().surfaceImages.some(item=>item.id===id && item.mode==='floor'),secondPicture.id,{timeout:6000});
  let positioned = (await snap()).surfaceImages.find(item=>item.id===secondPicture.id);
  assert(Math.abs(positioned.world[1]-.065)<.01,'A tap positions the image flat on the floor');
  assert(await page.locator(surfaceSelector).evaluate(el=>el.classList.contains('is-surface-placed')),'The free viewer collapses into an anchored-image toolbar');

  await page.evaluate(id => window.ArcadeCloudDrive3D.onSpatialSurfacePlaced({
    id,mode:'window',panelId:'w-3-1',surfaceScale:.70,world:[3,5,6]
  }),secondPicture.id);
  positioned = (await snap()).surfaceImages.find(item=>item.id===secondPicture.id);
  assert.equal(positioned.panelId,'w-3-1','Artwork fills one specified window of the glass dome');
  const panelWorld=positioned.world;
  await page.locator(surfaceSelector + ' button[aria-label="Reducir imagen colocada"]').click({force:true});
  const smaller=(await snap()).surfaceImages.find(item=>item.id===secondPicture.id);
  assert(smaller.scale < positioned.scale && smaller.panelId===positioned.panelId,'Resize stays within the chosen wooden pane');
  assert.deepEqual(smaller.world,panelWorld,'Resizing does not move the chosen panel');

  await page.evaluate(id => window.ArcadeCloudDrive3D.onSpatialSurfacePlaced({
    id,mode:'ceiling',panelId:'c-7-0',surfaceScale:.75,world:[2,10,1]
  }),secondPicture.id);
  positioned = (await snap()).surfaceImages.find(item=>item.id===secondPicture.id);
  assert.equal(positioned.mode,'ceiling','Picture can become a curved dome ceiling texture');
  assert.equal(positioned.panelId,'c-7-0');

  await page.evaluate(id=>window.ArcadeCloudDrive3D.changeSpatialImageMode(id,'free'),secondPicture.id);
  await page.waitForFunction(()=>window.ArcadeCloudDrive3D.three.snapshot().surfaceImages.length===0);
  assert(!await page.locator(surfaceSelector).evaluate(el=>el.classList.contains('is-surface-placed')),'Return to a free picture restores its full viewer');

  for(const shelf of initial.shelves){const [x,,z]=shelf.position;assert(Math.sqrt(initial.domeRadius**2-(Math.hypot(x,z)+shelf.depth)**2)>shelf.height,'Roof clears cabinet corners');}
  assert.equal(await page.locator('.dw-desk').count(),0,'The central desk is removed from the dome');
  assert.equal(await page.locator('[data-dw-desk-carousel]').count(),0,'There is no desk carousel');
  assert.equal(initial.currentFiles,20,'Every current-folder file is represented independently in the room');
  assert.equal(initial.fileItems.length,20);
  assert(initial.visibleFileCards>0,'At least one current-folder file thumbnail is visible in the initial camera view even when cabinets exist');
  assert.equal(initial.fileItems.filter(item=>item.kind==='image').length,18);
  assert(initial.filePanels.length>=1,'File thumbnails have independent gallery panels');
  assert.equal(initial.panoramaSeam.woodenRib,4,'Panorama UV seam lies beneath its wooden meridian');
  assert(initial.panoramaSeam.radius>initial.domeRadius &&
    initial.panoramaSeam.radius-initial.domeRadius < .12,'The wrapped panorama stays just behind the structural dome');
  assert.equal(initial.galleryLayout,'overhead','Folders place their file gallery in the air above their cabinets');
  assert(initial.fileItems.every(item=>item.position[1] > initial.shelves[0].height + .35),
    'Images and file icons clear the top of every bookshelf');
  assert(initial.filePanels.every(([x,y,z])=>Math.hypot(x,z)>initial.shelves[0].width*2),
    'Overhead gallery stays near the cabinet ring instead of blocking the foreground');

  // Real cabinet raycasting still selects folders, but file data is no longer rendered as books inside them.
  const viewBox=await page.locator('#dwThreeViewport').boundingBox();
  const shelfIndex=Math.floor(initial.pickPoints.length/2);
  const [px,py]=initial.pickPoints[shelfIndex];
  assert(Number.isFinite(px) && Number.isFinite(py),'Focused cabinet exposes a real projected pick point');
  await page.evaluate(index=>window.ArcadeCloudDrive3D.chooseThreeShelf(index),shelfIndex);
  // Let the viewport's real asynchronous preview finish before injecting a
  // controlled child-folder state for deterministic bookshelf assertions.
  await page.waitForTimeout(650);
  await page.evaluate(index=>{
    const app=window.ArcadeCloudDrive3D;
    app.three.setShelfContents(index,{
      folders:[{name:'Subcarpeta real',kind:'folder',open_href:'/subfolder',preview_href:'/preview/sub'}],
      files:[
        {name:'Informe.pdf',kind:'pdf',open_href:'/report.pdf'},
        {name:'Foto en carpeta.jpg',kind:'image',thumbnail_href:'/three-lab/assets/alpine-panorama.jpg',open_href:'/subphoto'}
      ],
      folder_count:1,file_count:1
    });
  },shelfIndex);
  await page.waitForFunction(index=>window.ArcadeCloudDrive3D.three.snapshot().shelves[index]?.overheadImages.some(img=>img.name==='Foto en carpeta.jpg'),shelfIndex);
  const populated=(await snap()).shelves[shelfIndex];
  assert(populated.realBooks>=1,'Nested folder data is rendered inside its cabinet');
  assert(populated.realItems.some(item=>item.name==='Subcarpeta real' && item.open==='/subfolder'),'Folder metadata stays with the cabinet');
  assert(!populated.realItems.some(item=>item.name==='Informe.pdf'),'Files are never rendered as books inside a cabinet');
  assert(populated.overheadImages.some(item=>item.name==='Foto en carpeta.jpg'),
    'A child-folder image is previewed in the air over its own shelf');
  assert(populated.overheadImages.every(item=>item.world[1] > initial.shelves[0].height+.90),
    'Image previews stay in a separate band of air instead of overlapping the wood');
  assert.equal(await page.locator('[data-hud-desk]').isVisible(),false,'Folder selection does not offer Traer al escritorio');

  // Turn toward the first gallery panel, tap a real thumbnail, then bring that selected image into the workspace.
  const galleryState=await snap();
  const [gx,,gz]=galleryState.filePanels[0];
  const [cx,,cz]=galleryState.camera;
  const galleryYaw=Math.atan2(cx-gx,cz-gz);
  const galleryDegrees=-galleryYaw*180/Math.PI;
  // Elevated galleries are intentionally above the cabinets. Aim slightly
  // upward rather than assuming the file cards are at eye level.
  await page.evaluate(deg=>window.ArcadeCloudDrive3D.three.look(deg,-14),galleryDegrees);
  await page.waitForTimeout(650);
  const focusedGallery=await snap();
  const visibleFiles=focusedGallery.fileItems.filter(item=>item.point[2]>-1 && item.point[2]<1 && Math.abs(item.point[0])<.82 && Math.abs(item.point[1])<.82);
  assert(visibleFiles.length>0,'At least one current-folder thumbnail is visible after looking at its gallery panel: '+JSON.stringify({
    camera:focusedGallery.camera,yaw:focusedGallery.yaw,pitch:focusedGallery.pitch,
    galleryDegrees, filePanels:focusedGallery.filePanels,
    filePoints:focusedGallery.fileItems.slice(0,8).map(item=>({name:item.name,point:item.point,position:item.position}))
  }));
  let selectedGalleryFile=null;
  for(const candidate of visibleFiles){
    const local={x:(candidate.point[0]+1)*viewBox.width/2,y:(1-candidate.point[1])*viewBox.height/2};
    const unobstructed=await page.evaluate(({x,y})=>{
      const viewport=document.getElementById('dwThreeViewport');
      const rect=viewport.getBoundingClientRect();
      const target=document.elementFromPoint(rect.left+x,rect.top+y);
      return Boolean(target && (target===viewport || viewport.contains(target)));
    },local);
    if(unobstructed){selectedGalleryFile=candidate;break;}
  }
  assert(selectedGalleryFile,'At least one current-folder thumbnail is visibly tappable on the WebGL canvas');
  const visibleClickCandidates=[];
  for(const item of visibleFiles) {
    const client={x:viewBox.x+(item.point[0]+1)*viewBox.width/2,y:viewBox.y+(1-item.point[1])*viewBox.height/2};
    const free=await page.evaluate(point=>{
      const target=document.elementFromPoint(point.x,point.y);
      const viewport=document.querySelector('#dwThreeViewport');
      return Boolean(target && (target===viewport || viewport.contains(target)));
    },client);
    if(free) visibleClickCandidates.push({item,client});
  }
  assert(visibleClickCandidates.length>=1,'There is a directly clickable file tile');
  // Real Playwright mouse input validates raycasting rather than invoking selectElement.
  // Before the fix a nearest-card guess could select a diagonal/upper neighbor.
  for(const {item,client} of visibleClickCandidates.slice(0,Math.min(3,visibleClickCandidates.length))) {
    await page.mouse.click(client.x,client.y);
    await page.waitForTimeout(160);
    const actual=await page.locator('[data-hud-name]').textContent();
    assert.equal(actual,item.name, '3D click must select the exact projected card: '+JSON.stringify({clicked:item.name, actual, screen:client, projected:item.point, kind:item.kind}));
  }
  await page.evaluate(item=>{
    const app=window.ArcadeCloudDrive3D;
    const node=app.bookNode({
      name:item.name,
      kind:item.kind,
      extension:item.kind==='image'?'JPG':String(item.kind||'FILE').toUpperCase(),
      open_href:item.open,
      thumbnail_href:item.kind==='image'?'/three-lab/assets/alpine-panorama.jpg':'',
      media_key:`Data/12/${item.name}`,
      media_route:'Data/12/'
    },false);
    app.selectElement(node);
  },selectedGalleryFile);
  await page.waitForFunction(name=>document.querySelector('[data-hud-name]')?.textContent===name,selectedGalleryFile.name);
  assert.equal(await page.locator('[data-hud-desk]').isVisible(),true,'Selected file exposes Traer al escritorio');
  if(selectedGalleryFile.kind==='image'){
    const picturesBeforeBring=(await snap()).spatialImages.length;
    await page.locator('[data-hud-desk]').click({force:true});
    await page.waitForFunction(count=>window.ArcadeCloudDrive3D.three.snapshot().spatialImages.length===count+1,picturesBeforeBring);
  }

  // Shared cloud player still supports fixed geometry for audio/video selected from the file gallery model.
  await page.evaluate(()=>{
    const app=window.ArcadeCloudDrive3D;
    const node=app.bookNode({name:'Audio.mp3',kind:'audio',extension:'MP3',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/12/Audio.mp3',media_route:'Data/12/',mime:'audio/mpeg'},false);
    app.selectElement(node); app.playSelected();
  });
  await page.waitForFunction(()=>window.ArcadeCloudMediaCloud?.state?.type==='audio' && !document.getElementById('arcadeCloudMediaCloud')?.hidden);
  const player=page.locator('#arcadeCloudMediaCloud');
  const playerStart=await player.boundingBox();
  const pinButton=page.locator('#arcadeCloudMediaCloud [data-media-pin]');
  if((await pinButton.getAttribute('aria-pressed'))==='true') await pinButton.click({force:true});
  await page.evaluate(()=>{
    const media=window.ArcadeCloudMediaCloud;
    media.el.style.setProperty('left','160px','important');
    media.el.style.setProperty('top','96px','important');
    media.el.style.setProperty('right','auto','important');
    media.el.style.setProperty('bottom','auto','important');
    media.el.style.setProperty('width','520px','important');
  });
  await pinButton.click({force:true});
  const playerFixed=await player.boundingBox();
  assert(Math.abs(playerFixed.width-playerStart.width)>20,'Cloud player accepts a chosen width before fixing it');
  await page.locator('#arcadeCloudMediaCloud [data-media-close]').click({force:true});
  await page.waitForFunction(()=>document.getElementById('arcadeCloudMediaCloud')?.hidden===true);
  await page.evaluate(()=>{
    const app=window.ArcadeCloudDrive3D;
    const node=app.bookNode({name:'Audio.mp3',kind:'audio',extension:'MP3',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/12/Audio.mp3',media_route:'Data/12/',mime:'audio/mpeg'},false);
    app.selectElement(node); app.playSelected();
  });
  await page.waitForFunction(()=>!document.getElementById('arcadeCloudMediaCloud')?.hidden);
  const playerRestored=await player.boundingBox();
  assert(Math.abs(playerRestored.x-playerFixed.x)<4 && Math.abs(playerRestored.y-playerFixed.y)<4 && Math.abs(playerRestored.width-playerFixed.width)<4,'Pinned player restores its chosen position and size');
  await page.locator('#arcadeCloudMediaCloud [data-media-close]').click({force:true});
  await page.waitForFunction(()=>document.getElementById('arcadeCloudMediaCloud')?.hidden===true);

  await page.evaluate(()=>{
    const app=window.ArcadeCloudDrive3D;
    const node=app.bookNode({name:'Video.mp4',kind:'video',extension:'MP4',open_href:'/three-lab/assets/alpine-panorama.jpg',media_key:'Data/12/Video.mp4',media_route:'Data/12/',mime:'video/mp4'},false);
    app.selectElement(node); app.playSelected();
  });
  await page.waitForFunction(()=>window.ArcadeCloudMediaCloud?.state?.type==='video');
  assert.equal(await page.locator('#arcadeCloudMediaCloud').isVisible(),true,'Video uses the same cloud player design');
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
  console.log('PASS production WebGL, current-folder standalone file gallery, clear dome center, pinned player geometry, persistent picture resize, shared cloud audio/video player, spatial pictures, raycast selection, frustum unloading, bounded textures, fixed panorama, movement and mobile');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
