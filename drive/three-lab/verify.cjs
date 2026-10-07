// Run: PLAYWRIGHT_MODULE=/path/to/playwright node drive/three-lab/verify.cjs
// Serves only a local HTML fixture: PHP authentication is NOT exercised here.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { createServer } = require('node:http');
const { readFileSync, mkdirSync } = require('node:fs');
const { resolve, extname } = require('node:path');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '../..');
const server = createServer((req, res) => {
    const path = resolve(root, '.' + req.url.split('?')[0]);
    if (!path.startsWith(root + '/')) { res.writeHead(403).end(); return; }
    try {
        let data = readFileSync(path);
        const ext = extname(path);
        if (ext === '.php') data = Buffer.from(data.toString().split('?>')[1]);
        res.setHeader('Content-Type', ({ '.php': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.jpg': 'image/jpeg' })[ext] || 'application/octet-stream');
        res.end(data);
    } catch { res.writeHead(404).end(); }
});
function corners(s) {
    const [x, , z] = s.position, a = s.rotation;
    return [[-1,-1],[1,-1],[1,1],[-1,1]].map(([u,v]) => {
        const dx=u*s.width/2, dz=v*s.depth/2;
        return [x+Math.cos(a)*dx+Math.sin(a)*dz,z-Math.sin(a)*dx+Math.cos(a)*dz];
    });
}
function overlap(a,b) {
    for (const p of [a,b]) for(let i=0;i<4;i++) {
        const q=p[(i+1)%4], axis=[-(q[1]-p[i][1]),q[0]-p[i][0]];
        const range=v=>v.map(c=>c[0]*axis[0]+c[1]*axis[1]);
        const x=range(a),y=range(b);
        if(Math.max(...x)<=Math.min(...y)||Math.max(...y)<=Math.min(...x)) return false;
    }
    return true;
}
(async () => {
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
    try {
        const errors=[];
        const page=await browser.newPage({viewport:{width:1440,height:1000}});
        page.on('pageerror',e=>errors.push(e.message));
        await page.goto(`http://127.0.0.1:${server.address().port}/drive/three-lab/drive3d-lab.php`);
        await page.waitForFunction(()=>window.drive3dLab?.snapshot().environmentReady);
        page.setDefaultTimeout(60000);
        const snapshot=()=>page.evaluate(()=>window.drive3dLab.snapshot());
        const initial=await snapshot();
        assert.equal(initial.shelves.length,7);
        for(const shelf of initial.shelves) for(const [x,z] of corners(shelf)) assert(x*x+z*z+shelf.height**2 < initial.domeRadius**2, 'Cabinet must fit inside dome');
        for(let i=0;i<7;i++) for(let j=i+1;j<7;j++) assert(!overlap(corners(initial.shelves[i]),corners(initial.shelves[j])), 'Cabinets must not intersect');
        const out=process.env.SCREENSHOT_DIR;
        if(out) mkdirSync(out,{recursive:true});
        const shot=async name=>{if(out) await page.screenshot({path:resolve(out,name+'.png')});};
        assert.deepEqual(initial.table,[0,0,-1.3]);
        await shot('front');
        for(const view of ['left','right']) {
            await page.locator(`[data-view="${view}"]`).click();
            await page.waitForTimeout(150);
            const state=await snapshot();
            assert.deepEqual(state.shelves.map(({loaded,...s})=>s),initial.shelves.map(({loaded,...s})=>s)); assert.deepEqual(state.panorama,initial.panorama);
            assert.notEqual(state.yaw,initial.yaw); await shot(view);
        }
        await page.setViewportSize({width:800,height:600});
        await page.locator('[data-view="front"]').click();
        await page.keyboard.down('w'); await page.waitForFunction(z=>window.drive3dLab.snapshot().camera[2]<z-.15,initial.camera[2]); await page.keyboard.up('w');
        assert((await snapshot()).camera[2]<initial.camera[2]-.1,'Walk must move camera');
        await page.keyboard.down('w'); await page.waitForFunction(()=>window.drive3dLab.snapshot().camera[2]<.72).catch(async error=>{console.log('Movement diagnostic',await snapshot());throw error;}); await page.waitForTimeout(400); await page.keyboard.up('w');
        assert((await snapshot()).camera[2]>=.59,'Table collision must stop walking through the globe pedestal');
        await page.mouse.move(300,260); await page.mouse.down(); await page.mouse.move(500,220,{steps:5}); await page.mouse.up();
        await page.waitForFunction(()=>window.drive3dLab.snapshot().yaw<-.2); assert((await snapshot()).yaw<-.2,'Drag must turn camera');
        await page.setViewportSize({width:390,height:844});
        await page.locator('[data-view="front"]').click();
        await shot('mobile');
        assert(await page.locator('#minimap').isVisible());
        const button=page.locator('[data-move="forward"]');
        // Use real pointer capture for the held movement check below.
        const bounds=await button.boundingBox();
        await page.mouse.move(bounds.x+20,bounds.y+20); await page.mouse.down(); await page.waitForFunction(z=>window.drive3dLab.snapshot().camera[2]<z-.15,initial.camera[2]); await page.mouse.up();
        await page.evaluate(()=>window.dispatchEvent(new Event('blur')));
        assert((await snapshot()).camera[2]<initial.camera[2]-.1);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        assert.deepEqual(errors,[]);
        console.log('PASS: actual WebGL render, dome clearance, nonoverlap, fixed panorama/furniture, presets, drag, walk, mobile controls/minimap.');
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
