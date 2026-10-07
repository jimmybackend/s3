class Drive3DThreeLab {
    constructor(T, Reflector) {
        const R = 10, shelfRadius = 7.25, width = 2.05, depth = .6, height = 4.2;
        const step = 2 * Math.atan((width / 2 + .025) / (shelfRadius - depth / 2));
        const viewport = document.querySelector('#viewport');
        const renderer = new T.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
        renderer.setPixelRatio(Math.min(devicePixelRatio, 1.5));
        renderer.outputColorSpace = T.SRGBColorSpace;
        renderer.toneMapping = T.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;
        viewport.append(renderer.domElement);
        const scene = new T.Scene();
        const camera = new T.PerspectiveCamera(64, 1, .08, 150);
        camera.rotation.order = 'YXZ';
        camera.position.set(0, 2.05, 2);
        scene.add(new T.HemisphereLight(0xb5d5f3, 0x33251b, 1.4));
        const sun = new T.DirectionalLight(0xffdfaf, 2.8);
        sun.position.set(-6, 10, 3); scene.add(sun);
        const material = (color, extra = {}) => new T.MeshStandardMaterial({ color, roughness: .55, ...extra });
        // Deterministic material maps, not images of furniture. All forms remain geometry.
        const texture = (kind) => {
            const c = document.createElement('canvas'); c.width = c.height = 512;
            const ctx = c.getContext('2d'), data = ctx.createImageData(512, 512);
            for (let y = 0; y < 512; y++) for (let x = 0; x < 512; x++) {
                const n = Math.sin(x * 12.9898 + y * 78.233) * 43758.5453;
                let v;
                if (kind === 'wood') v = 95 + 23 * Math.sin(x * .26 + Math.sin(y * .021) * 2 + Math.sin(x * .037) * 4) + (n - Math.floor(n)) * 16;
                else v = 42 + 28 * Math.pow(Math.abs(Math.sin(x * .02 + y * .035 + Math.sin(y * .019) * 3 + Math.cos(x * .014) * 2)), 18) + (n - Math.floor(n)) * 9;
                const i = (y * 512 + x) * 4;
                data.data[i] = v * (kind === 'wood' ? 1.3 : 1.05);
                data.data[i + 1] = v * (kind === 'wood' ? .74 : 1.1);
                data.data[i + 2] = v * (kind === 'wood' ? .38 : 1.18); data.data[i + 3] = 255;
            }
            ctx.putImageData(data, 0, 0);
            const t = new T.CanvasTexture(c); t.colorSpace = T.SRGBColorSpace; t.wrapS = t.wrapT = T.RepeatWrapping;
            t.anisotropy = Math.min(4, renderer.capabilities.getMaxAnisotropy()); return t;
        };
        const grain = texture('wood'), marble = texture('marble'); marble.repeat.set(4, 4);
        const wood = material(0xd8ab75, { map: grain, roughness: .34 });
        const trim = material(0xc3a160, { metalness: .78, roughness: .25 });
        const darkWood = material(0x5b361b, { map: grain, roughness: .6 });
        const blackStone = material(0x273547, { map: marble, metalness: .55, roughness: .2 });
        const glow = new T.MeshBasicMaterial({ color: 0xffc673 });
        const cyan = new T.MeshBasicMaterial({ color: 0x67d7ff });
        const boxMeshes = [], cube = new T.BoxGeometry(1, 1, 1);
        function box(parent, w, h, d, x, y, z, mat) {
            const mesh = new T.Mesh(cube, mat); mesh.scale.set(w, h, d);
            mesh.position.set(x, y, z); parent.add(mesh); boxMeshes.push(mesh); return mesh;
        }
        function tube(points, radius = .045, mat = trim, parent = scene) {
            const curve = new T.CatmullRomCurve3(points);
            const mesh = new T.Mesh(new T.TubeGeometry(curve, 64, radius, 6, false), mat);
            parent.add(mesh); return mesh;
        }
        function ring(parent, radius, thickness, y, mat) {
            const mesh = new T.Mesh(new T.TorusGeometry(radius, thickness, 6, 96), mat);
            mesh.rotation.x = Math.PI / 2; mesh.position.y = y; parent.add(mesh); return mesh;
        }
        function cylinder(parent, rt, rb, h, y, mat) {
            const mesh = new T.Mesh(new T.CylinderGeometry(rt, rb, h, 48), mat); mesh.position.y = y; parent.add(mesh); return mesh;
        }
        // Panorama is fixed to world coordinates and also supplies natural material reflections.
        const panorama = new T.Mesh(new T.SphereGeometry(65, 64, 32), new T.MeshBasicMaterial({ color: 0x93b4c9, side: T.BackSide }));
        panorama.name = 'fixed-360-panorama'; scene.add(panorama);
        let environmentReady = false;
        new T.TextureLoader().load(new URL('../three-lab/assets/alpine-panorama.jpg', import.meta.url).href, map => {
            map.colorSpace = T.SRGBColorSpace;
            panorama.material.map = map; panorama.material.color.set(0xffffff); panorama.material.needsUpdate = true;
            const env = map.clone(); env.mapping = T.EquirectangularReflectionMapping; env.needsUpdate = true;
            scene.environment = env; scene.environmentIntensity = .55; environmentReady = true;
        }, undefined, () => {
            status.hidden = false; status.textContent = 'El paisaje no pudo cargarse. La escena sigue disponible; recarga para intentarlo de nuevo.';
        });
        const floorMirror = new Reflector(new T.CircleGeometry(R, 96), { color: 0x8894a0, textureWidth: innerWidth < 700 ? 256 : 768, textureHeight: innerWidth < 700 ? 256 : 768 });
        floorMirror.rotation.x = -Math.PI / 2; floorMirror.position.y = -.015; scene.add(floorMirror);
        const floor = new T.Mesh(new T.CircleGeometry(R, 96), material(0x9aa1ad, { map: marble, transparent: true, opacity: .66, metalness: .2, roughness: .32 }));
        floor.rotation.x = -Math.PI / 2; floor.renderOrder = 1; scene.add(floor);
        for (const radius of [2.55, 2.62, 6.1, 6.17, 9.75]) ring(scene, radius, .016, .015, radius === 2.55 || radius === 6.1 ? glow : trim);
        for (let i = 0; i < 24; i++) {
            const a = i / 24 * Math.PI * 2;
            tube([new T.Vector3(2.65 * Math.sin(a), .006, 2.65 * Math.cos(a)), new T.Vector3(9.8 * Math.sin(a), .006, 9.8 * Math.cos(a))], .007, trim);
        }
        const dome = new T.Mesh(new T.SphereGeometry(R, 64, 24, 0, Math.PI * 2, 0, Math.PI / 2), material(0xc5e2f4, { transparent: true, opacity: .055, side: T.DoubleSide, depthWrite: false, metalness: .1 }));
        dome.name = 'glass-hemisphere'; scene.add(dome);
        for (let i = 0; i < 16; i++) {
            const a = i / 16 * Math.PI * 2;
            tube(Array.from({ length: 33 }, (_, k) => {
                const p = k / 32 * Math.PI / 2;
                return new T.Vector3(R * Math.sin(p) * Math.sin(a), R * Math.cos(p), R * Math.sin(p) * Math.cos(a));
            }), .065);
        }
        for (const elevation of [.47, .72, 1.05]) {
            tube(Array.from({ length: 97 }, (_, k) => {
                const a = k / 96 * Math.PI * 2;
                return new T.Vector3(R * Math.cos(elevation) * Math.sin(a), R * Math.sin(elevation), R * Math.cos(elevation) * Math.cos(a));
            }), .065);
        }
        const shelves = [];
        const books = [0x132e48, 0x432119, 0x213723, 0x262535].map(color => material(color, { roughness: .6 }));
        const pages = material(0xc6b890);
        // One warm gradient per compartment simulates bounced shelf light without 28 dynamic lights.
        const lc = document.createElement('canvas'); lc.width = 16; lc.height = 128;
        const lx = lc.getContext('2d'), lg = lx.createLinearGradient(0, 0, 0, 128);
        lg.addColorStop(0, '#ffc66b'); lg.addColorStop(.16, '#946035'); lg.addColorStop(1, '#24150d'); lx.fillStyle = lg; lx.fillRect(0, 0, 16, 128);
        const lt = new T.CanvasTexture(lc); lt.colorSpace = T.SRGBColorSpace;
        const bounce = new T.MeshBasicMaterial({ map: lt });
        const titles = ['Proyectos', 'Documentos', 'Imágenes', 'Biblioteca', 'Música', 'Videos', 'Archivo'];
        titles.forEach((title, i) => {
            const angle = (i - 3) * step, group = new T.Group(); group.name = `shelf-${i}`;
            group.position.set(shelfRadius * Math.sin(angle), 0, -shelfRadius * Math.cos(angle)); group.rotation.y = -angle;
            scene.add(group); shelves.push(group);
            box(group, width, height, .09, 0, height / 2, -.255, darkWood);
            for (const x of [-width / 2 + .065, width / 2 - .065]) {
                box(group, .13, height, depth, x, height / 2, 0, wood);
                box(group, .033, height - .15, .025, x, height / 2, .307, trim);
            }
            for (const y of [.12, .98, 1.84, 2.70, 3.56, 4.13]) {
                box(group, width, .13, depth, 0, y, 0, wood);
                box(group, width - .12, .024, .03, 0, y + .045, .31, trim);
            }
            box(group, width - .2, .37, .14, 0, 3.86, .22, wood);
            for (let row = 0; row < 4; row++) {
                const y = .19 + row * .86;
                const back = new T.Mesh(new T.PlaneGeometry(width - .25, .72), bounce); back.position.set(0, y + .37, -.20); group.add(back);
                box(group, width - .27, .024, .025, 0, y + .72, .26, glow);
                for (let j = 0; j < 7; j++) {
                    if ((row + i) % 3 === 0 && j > 3) continue;
                    const h = .45 + ((i + row + j) % 3) * .075, x = -.77 + j * .225;
                    box(group, .175, h, .30, x, y + h / 2, .09, books[(i + row) % 4]);
                    box(group, .125, .018, .26, x, y + h + .008, .07, pages);
                    for (const offset of [-.16, .16]) box(group, .145, .018, .008, x, y + h / 2 + offset, .244, trim);
                    const emblem = new T.Mesh(new T.TorusGeometry(.033, .005, 4, 12), trim); emblem.position.set(x, y + h / 2, .249); group.add(emblem);
                }
                if ((row + i) % 3 === 0) {
                    const ornament = new T.Group(); ornament.position.set(.58, y, .08); group.add(ornament);
                    cylinder(ornament, .13, .18, .05, .025, trim);
                    const orb = new T.Mesh(new T.SphereGeometry(.16, 16, 12), material(0x4e8390, { metalness: .65, roughness: .2 })); orb.position.y = .27; ornament.add(orb);
                    const hoop = new T.Mesh(new T.TorusGeometry(.23, .012, 6, 32), trim); hoop.position.y = .27; hoop.rotation.z = -.4; ornament.add(hoop);
                }
            }
            const label = document.createElement('canvas'); label.width = 512; label.height = 96;
            const c = label.getContext('2d'); c.clearRect(0, 0, 512, 96); c.fillStyle = '#ffe6b2'; c.textAlign = 'center'; c.font = '36px Georgia'; c.fillText(title, 256, 61);
            const tex = new T.CanvasTexture(label); tex.colorSpace = T.SRGBColorSpace;
            const sign = new T.Mesh(new T.PlaneGeometry(1.65, .30), new T.MeshBasicMaterial({ map: tex, transparent: true })); sign.position.set(0, 3.86, .307); group.add(sign);
        });
        // Central brass / marble table, fully modeled. Position stays fixed while walking.
        const table = new T.Group(); table.name = 'central-table'; table.position.z = -1.3; scene.add(table);
        cylinder(table, 1.58, 1.65, .10, .055, blackStone);
        cylinder(table, 1.08, 1.28, .70, .48, blackStone);
        cylinder(table, 1.45, 1.45, .13, .89, trim);
        cylinder(table, 1.43, 1.43, .06, .985, blackStone);
        for (const [r, y, mat] of [[1.62,.10,cyan],[1.27,.17,trim],[1.10,.78,glow],[1.45,.96,glow],[.63,1.035,cyan]]) ring(table, r, .019, y, mat);
        const globe = new T.Group(); globe.position.y = 1.83; table.add(globe);
        const globeMat = material(0x1b78cf, { transparent: true, opacity: .32, metalness: .25, roughness: .2, emissive: 0x146ac1, emissiveIntensity: .7, depthWrite: false });
        globe.add(new T.Mesh(new T.SphereGeometry(.70, 40, 24), globeMat));
        const grid = new T.LineBasicMaterial({ color: 0x8bdcff, transparent: true, opacity: .8 });
        for (let lat = -60; lat <= 60; lat += 30) {
            const p = lat * Math.PI / 180, pts = Array.from({length:65},(_,k)=>new T.Vector3(.705*Math.cos(p)*Math.sin(k/64*Math.PI*2),.705*Math.sin(p),.705*Math.cos(p)*Math.cos(k/64*Math.PI*2)));
            globe.add(new T.Line(new T.BufferGeometry().setFromPoints(pts),grid));
        }
        for(let i=0;i<8;i++) { const pts=Array.from({length:65},(_,k)=>new T.Vector3(.705*Math.sin(k/64*Math.PI*2)*Math.cos(i*Math.PI/8),.705*Math.cos(k/64*Math.PI*2),.705*Math.sin(k/64*Math.PI*2)*Math.sin(i*Math.PI/8))); globe.add(new T.Line(new T.BufferGeometry().setFromPoints(pts),grid)); }
        const stars=[];
        for(let i=0;i<460;i++) { const a=i*2.399963, y=1-2*(i+.5)/460, r=Math.sqrt(1-y*y); if(Math.sin(a*3+y*9)+Math.cos(a*2-y*13)>.15) stars.push(.712*r*Math.cos(a),.712*y,.712*r*Math.sin(a)); }
        const dots=new T.BufferGeometry(); dots.setAttribute('position',new T.Float32BufferAttribute(stars,3)); globe.add(new T.Points(dots,new T.PointsMaterial({color:0xc5edff,size:.025})));
        const blueLight = new T.PointLight(0x459eff, 5, 5, 2); blueLight.position.set(0, 1.5, -1.3); scene.add(blueLight);
        const lampAngle = 3 * step + .25, lamp = new T.Group(); lamp.name = 'end-of-row-lamp';
        lamp.position.set(shelfRadius * Math.sin(lampAngle), 0, -shelfRadius * Math.cos(lampAngle)); scene.add(lamp);
        cylinder(lamp,.32,.38,.10,.05,trim); cylinder(lamp,.035,.035,2.4,1.25,trim);
        cylinder(lamp,.26,.48,.55,2.55,material(0xffe2a4,{emissive:0xffbe58,emissiveIntensity:.7,side:T.DoubleSide}));
        const light = new T.PointLight(0xffc573, 18, 7, 2); light.position.y = 2.3; lamp.add(light);
        // Foliage uses instanced leaves, keeping the mobile draw budget bounded.
        const leaves = [], leafMat = material(0x3e642a, { side: T.DoubleSide, roughness: .8 });
        for (const side of [-1,1]) {
            const plant = new T.Group(); plant.position.set(side*5.7,0,-3.1); scene.add(plant);
            cylinder(plant,.30,.21,.55,.275,trim);
            for(let i=0;i<28;i++) {
                const a=i*2.399, y=.55+(i%7)*.13, r=.20+(i%4)*.11;
                const leaf=new T.Mesh(new T.SphereGeometry(1,8,6),leafMat); leaf.scale.set(.09,.30,.035); leaf.position.set(Math.cos(a)*r,y,Math.sin(a)*r); leaf.rotation.set(.5,a,Math.sin(a)*.8); plant.add(leaf); leaves.push(leaf);
            }
        }
        // Batch repeated boxes by material. Scene groups retain their positions for collision/debug.
        scene.updateMatrixWorld(true);
        const batches = new Map();
        for(const mesh of boxMeshes) { if(!batches.has(mesh.material)) batches.set(mesh.material,[]); batches.get(mesh.material).push(mesh); }
        for(const [mat,meshes] of batches) { const batch=new T.InstancedMesh(cube,mat,meshes.length); meshes.forEach((mesh,i)=>{batch.setMatrixAt(i,mesh.matrixWorld);mesh.removeFromParent();}); batch.computeBoundingSphere(); scene.add(batch); }
        const leafBatch = new T.InstancedMesh(new T.SphereGeometry(1,8,6),leafMat,leaves.length);
        leaves.forEach((leaf,i)=>{leafBatch.setMatrixAt(i,leaf.matrixWorld);leaf.geometry.dispose();leaf.removeFromParent();}); leafBatch.computeBoundingSphere();scene.add(leafBatch);
        let yaw = 0, pitch = -.025, targetYaw = 0, targetPitch = -.025;
        const keys = new Set(), held = new Map(); let drag = null;
        const presets = {
            front: [0, 2, 0], center: [0, 2, 0],
            left: [-2, 0, .90], right: [2, 0, -.90]
        };
        document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
            const [x, z, a] = presets[button.dataset.view]; camera.position.set(x, 2.05, z);
            targetYaw = yaw = a; targetPitch = pitch = -.025;
            keys.clear(); held.clear(); viewport.focus({ preventScroll: true });
        }));
        const handled = ['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'];
        window.addEventListener('keydown', event => {
            if (handled.includes(event.code) && !event.ctrlKey && !event.metaKey && !event.altKey) { event.preventDefault(); keys.add(event.code); }
        });
        window.addEventListener('keyup', event => keys.delete(event.code));
        const clearInput = () => { keys.clear(); held.clear(); drag = null; };
        window.addEventListener('blur', clearInput);
        document.addEventListener('visibilitychange', clearInput);
        viewport.addEventListener('pointerdown', event => {
            if (drag || event.button !== 0) return;
            drag = { id: event.pointerId, x: event.clientX, y: event.clientY };
            viewport.setPointerCapture(event.pointerId); viewport.focus({ preventScroll: true });
        });
        viewport.addEventListener('pointermove', event => {
            if (drag?.id !== event.pointerId) return;
            targetYaw -= (event.clientX - drag.x) * .004;
            targetPitch = T.MathUtils.clamp(targetPitch - (event.clientY - drag.y) * .003, -.65, 1.1);
            drag.x = event.clientX; drag.y = event.clientY;
        });
        for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) viewport.addEventListener(name, event => { if (drag?.id === event.pointerId) drag = null; });
        document.querySelectorAll('[data-move]').forEach(button => {
            button.addEventListener('pointerdown', event => { event.preventDefault(); held.set(event.pointerId, button.dataset.move); button.setPointerCapture(event.pointerId); });
            for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) button.addEventListener(name, event => held.delete(event.pointerId));
        });
        function canStand(x, z) {
            if (Math.hypot(x, z + 1.3) < 1.9) return false;
            if (Math.hypot(x, z) > R - .45) return false;
            if (Math.hypot(x - lamp.position.x, z - lamp.position.z) < .65) return false;
            return shelves.every(shelf => {
                const local = shelf.worldToLocal(new T.Vector3(x, 0, z));
                return Math.abs(local.x) > width / 2 + .24 || Math.abs(local.z) > depth / 2 + .24;
            });
        }
        scene.updateMatrixWorld(true);
        const map = document.querySelector('#minimap'), m = map.getContext('2d');
        function minimap() {
            m.clearRect(0, 0, 240, 240); m.save(); m.translate(120, 120); m.scale(10, 10);
            m.fillStyle = '#1d3540'; m.strokeStyle = '#8ca7b5'; m.lineWidth = .07;
            m.beginPath(); m.arc(0, 0, R, 0, Math.PI * 2); m.fill(); m.stroke();
            shelves.forEach(shelf => { m.save(); m.translate(shelf.position.x, shelf.position.z); m.rotate(-shelf.rotation.y); m.fillStyle = '#b7844d'; m.fillRect(-width / 2, -depth / 2, width, depth); m.restore(); });
            m.fillStyle = '#327c99'; m.beginPath(); m.arc(0, -1.3, 1.65, 0, Math.PI * 2); m.fill();
            m.fillStyle = '#ffdc84'; m.beginPath(); m.arc(lamp.position.x, lamp.position.z, .3, 0, Math.PI * 2); m.fill();
            m.translate(camera.position.x, camera.position.z); m.rotate(-yaw);
            m.fillStyle = '#69bfff30'; m.beginPath(); m.moveTo(0, 0); m.arc(0, 0, 3, -Math.PI / 2 - .59, -Math.PI / 2 + .59); m.closePath(); m.fill();
            m.fillStyle = '#67bdff'; m.beginPath(); m.moveTo(0, -.5); m.lineTo(.3, .3); m.lineTo(-.3, .3); m.closePath(); m.fill(); m.restore();
            document.querySelector('#coordinates').value = `x ${camera.position.x.toFixed(1)} · z ${camera.position.z.toFixed(1)} · giro ${T.MathUtils.radToDeg(yaw).toFixed(0)}°`;
        }
        function resize() { const w = viewport.clientWidth, h = viewport.clientHeight; renderer.setSize(w, h); camera.aspect = w / h; camera.updateProjectionMatrix(); }
        window.addEventListener('resize', resize); resize();
        renderer.domElement.addEventListener('webglcontextlost', event => { event.preventDefault(); renderer.setAnimationLoop(null); clearInput(); status.hidden = false; status.textContent = 'Se interrumpió el contexto gráfico. Recarga para continuar.'; });
        let previous = performance.now();
        renderer.setAnimationLoop(now => {
            const dt = Math.min((now - previous) / 1000, .05); previous = now;
            if (document.hidden) return;
            targetYaw += ((keys.has('ArrowLeft') ? 1 : 0) - (keys.has('ArrowRight') ? 1 : 0)) * dt;
            targetPitch = T.MathUtils.clamp(targetPitch + ((keys.has('ArrowUp') ? 1 : 0) - (keys.has('ArrowDown') ? 1 : 0)) * dt, -.65, 1.1);
            yaw = T.MathUtils.damp(yaw, targetYaw, 12, dt); pitch = T.MathUtils.damp(pitch, targetPitch, 12, dt);
            camera.rotation.set(pitch, yaw, 0);
            const active = new Set(held.values());
            let f = +(keys.has('KeyW') || active.has('forward')) - +(keys.has('KeyS') || active.has('back'));
            let s = +(keys.has('KeyD') || active.has('right')) - +(keys.has('KeyA') || active.has('left'));
            const length = Math.hypot(f, s) || 1; f /= length; s /= length;
            const dx = (-Math.sin(yaw) * f + Math.cos(yaw) * s) * dt * 2.2;
            const dz = (-Math.cos(yaw) * f - Math.sin(yaw) * s) * dt * 2.2;
            if (canStand(camera.position.x + dx, camera.position.z)) camera.position.x += dx;
            if (canStand(camera.position.x, camera.position.z + dz)) camera.position.z += dz;
            renderer.render(scene, camera); minimap();
        });
        status.hidden = true;
        // Read-only diagnostics for reproducible spatial verification (no user data).
        window.drive3dLab = Object.freeze({ snapshot: () => ({
            camera: camera.position.toArray(), yaw, pitch, panorama: panorama.position.toArray(),
            shelves: shelves.map(s => ({ position: s.position.toArray(), rotation: s.rotation.y, width, depth, height })),
            lamp: lamp.position.toArray(), table: table.position.toArray(), environmentReady, domeRadius: R, calls: renderer.info.render.calls
        }) });
    }
}

// Isolated spatial laboratory. No storage, API calls or production scene mutations.
const status = document.querySelector('#status');
try {
    const THREE = await import('../three-lab/vendor/three.module.min.js');
    const { Reflector } = await import('../three-lab/vendor/Reflector.js');
    new Drive3DThreeLab(THREE, Reflector);
} catch (error) {
    status.hidden = false;
    status.textContent = 'No fue posible iniciar WebGL 2. Prueba con aceleración gráfica activa y recarga la página.';
    console.error('Drive 3D lab:', error);
}
