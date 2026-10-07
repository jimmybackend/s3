const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {readFileSync,mkdirSync}=require('node:fs');
const {createServer}=require('node:http');
const {resolve}=require('node:path');
const assert=require('node:assert/strict');
const root=resolve(__dirname,'..');
const php=readFileSync(root+'/dataword3d.php','utf8');
const template=php.match(/<template id="dwShelfTemplate">([\s\S]*?)<\/template>/)[1];
const hud=['name','type','format','size','date','path','counts','open','desk','play','download'].map(k=>`<button data-hud-${k}></button>`).join('');
const html=`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/css/dataword3d.css"><body class="dw3d"><header class="dw-topbar">Drive 3D</header><aside class="dw-sidebar"><button data-dw-environment>Entorno</button><div class="dw-user-chip">Usuario</div></aside><main class="dw-world" id="dwWorld"><div class="dw-camera-scene" data-dw-camera-scene><div class="dw-dome">GLASS</div><div class="dw-room-panorama"><div class="dw-room-scenery"></div><div class="dw-room-mullions"></div></div><section class="dw-stage"><div id="dwShelfRing" class="dw-ring"><template id="dwShelfTemplate">${template}</template>${Array.from({length:24},(_,i)=>`<article class="dw-shelf" data-dw-item data-item-type="folder" data-item-name="Carpeta ${i}" data-open-href="/folder/${i}" data-preview-href="/preview/${i}"></article>`).join('')}</div></section></div><aside class="dw-radar" data-dw-radar><div class="dw-radar-room"><span class="dw-radar-view" data-radar-view></span><span class="dw-radar-center"></span><div data-radar-points></div></div></aside><div hidden>${hud}</div><div data-dw-current-files></div></main><script>window.ARCADECLOUD_DRIVE3D={};</script><script src="/js/dataword3d.js"></script></body>`;
let requests=0;
const server=createServer((req,res)=>{
 if(req.url.startsWith('/preview/')) {requests++;setTimeout(()=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,state:{folders:[],files:[{name:'Visible.txt',type:'file',kind:'document',open_href:'/file'}],folder_count:0,file_count:1}}));},250);return;}
 if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 if(!['/js/dataword3d.js','/css/dataword3d.css'].includes(req.url)){res.writeHead(404).end();return;}
 res.setHeader('Content-Type',req.url.endsWith('.js')?'text/javascript':'text/css');res.end(readFileSync(root+req.url));
});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:960}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(`http://127.0.0.1:${server.address().port}`);await page.waitForFunction(()=>window.ArcadeCloudDrive3D);await page.waitForTimeout(250);
 assert.equal(requests,0,'Overview must not fetch file details');
 assert(await page.locator('.dw-shelf').count()<=7);
 const boxes=await page.locator('.dw-shelf').evaluateAll(items=>items.map(i=>({left:i.getBoundingClientRect().left,right:i.getBoundingClientRect().right})).sort((a,b)=>a.left-b.left));
 for(let i=1;i<boxes.length;i++)assert(boxes[i-1].right<=boxes[i].left+3,'Adjacent shelves must not overlap');assert.equal(await page.locator('.dw-dome').isVisible(),false);
 const dots=await page.locator('.dw-radar-point').evaluateAll(items=>items.map(i=>parseFloat(i.style.top)));assert(Math.max(...dots)-Math.min(...dots)>70);
 await page.evaluate(()=>{const app=window.ArcadeCloudDrive3D;app.focusShelf(app.frontShelf());});await page.waitForFunction(()=>window.ArcadeCloudDrive3D.previewCache.size>0);
 await page.evaluate(()=>window.ArcadeCloudDrive3D.turnCamera(180));await page.waitForTimeout(700);
 const state=await page.evaluate(()=>{const a=window.ArcadeCloudDrive3D;return {cache:a.previewCache.size,requests:a.previewRequests.size,detached:a.shelves.filter(s=>!s.isConnected).every(s=>s.childElementCount===0),mounted:document.querySelectorAll('.dw-shelf').length,stale:[...a.previewCache.keys()].some(key=>![...a.visibleShelves].some(s=>s.dataset.previewHref===key))};});
 assert(state.cache<=3);assert(state.requests<=3);assert(state.detached);assert(state.mounted<=7);assert(!state.stale);
 await page.evaluate(()=>window.ArcadeCloudDrive3D.centerCamera());await page.waitForTimeout(250);assert.equal(await page.evaluate(()=>window.ArcadeCloudDrive3D.previewCache.size),0);
 // Rapid turns must not resurrect an obsolete response.
 await page.evaluate(()=>{const a=window.ArcadeCloudDrive3D;a.focusShelf(a.frontShelf());a.turnCamera(160);a.turnCamera(-120);a.centerCamera();});await page.waitForTimeout(700);
 assert.equal(await page.evaluate(()=>window.ArcadeCloudDrive3D.previewCache.size),0);
 const out=process.env.SCREENSHOT_DIR;if(out){mkdirSync(out,{recursive:true});await page.screenshot({path:resolve(out,'zones-desktop.png')});}
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(300);
 assert.equal(await page.evaluate(()=>getComputedStyle(document.querySelector('.dw-world')).left),'0px');
 assert((await page.locator('.dw-sidebar').boundingBox()).height<150);
 if(out)await page.screenshot({path:resolve(out,'zones-mobile.png')});assert.deepEqual(errors,[]);
 console.log('PASS visible DOM budget, zero overview fetch, bounded details/cache, cancellation, stale responses, radial map, clear dome and floating mobile sidebar');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
