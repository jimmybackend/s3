class Drive3DThreeLab {
    constructor(T) {
        const R = 10, shelfRadius = 7.25, width = 2.05, depth = .6, height = 3.2;
        // Back corners determine angular spacing: no overlapping rectangular cabinets.
        const step = 2 * Math.atan((width / 2 + .025) / (shelfRadius - depth / 2));
        const viewport = document.querySelector('#viewport');
        const renderer = new T.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
        renderer.setPixelRatio(Math.min(devicePixelRatio, 1.75));
        renderer.outputColorSpace = T.SRGBColorSpace;
        renderer.toneMapping = T.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.25;
        viewport.append(renderer.domElement);
        const scene = new T.Scene();
        const camera = new T.PerspectiveCamera(68, 1, .08, 150);
        camera.rotation.order = 'YXZ';
        camera.position.set(0, 1.65, 1.4);
        scene.add(new T.HemisphereLight(0xd5eaff, 0x7b5034, 2.4));
        const sun = new T.DirectionalLight(0xffe0b2, 2.5);
        sun.position.set(-6, 10, 3); scene.add(sun);
        const material = (color, extra = {}) => new T.MeshStandardMaterial({ color, roughness: .55, ...extra });
        const wood = material(0x724126), trim = material(0xb18a4d, { metalness: .65, roughness: .3 });
        const darkWood = material(0x30251f);
        function box(parent, w, h, d, x, y, z, mat) {
            const mesh = new T.Mesh(new T.BoxGeometry(w, h, d), mat);
            mesh.position.set(x, y, z); parent.add(mesh); return mesh;
        }
        function tube(points, radius = .045) {
            const curve = new T.CatmullRomCurve3(points);
            const mesh = new T.Mesh(new T.TubeGeometry(curve, 80, radius, 6, false), trim);
            scene.add(mesh); return mesh;
        }
        // Fixed exterior sphere: full periodic equirectangular panorama, not a screen backdrop.
        const canvas = document.createElement('canvas'); canvas.width = 2048; canvas.height = 1024;
        const ctx = canvas.getContext('2d');
        const sky = ctx.createLinearGradient(0, 0, 0, 1024);
        sky.addColorStop(0, '#24476c'); sky.addColorStop(.48, '#c3d9de'); sky.addColorStop(.52, '#b8d2c1'); sky.addColorStop(1, '#183b48');
        ctx.fillStyle = sky; ctx.fillRect(0, 0, 2048, 1024);
        for (let layer = 0; layer < 3; layer++) {
            ctx.beginPath(); ctx.moveTo(0, 650);
            for (let x = 0; x <= 2048; x += 2) {
                const a = x / 2048 * Math.PI * 2;
                const y = 470 + layer * 35 - Math.abs(Math.sin(a * 5 + layer) * 95 + Math.sin(a * 13) * 30 + Math.cos(a * 3) * 40);
                ctx.lineTo(x, y);
            }
            ctx.lineTo(2048, 650); ctx.closePath(); ctx.fillStyle = ['#647f99', '#446778', '#2b5159'][layer]; ctx.fill();
        }
        ctx.fillStyle = '#ffefbe'; ctx.beginPath(); ctx.arc(1420, 270, 43, 0, Math.PI * 2); ctx.fill();
        // Distinct landmarks prove that rotation reveals different parts of the panorama.
        ['NORTE · MONTAÑAS', 'ESTE · AMANECER', 'SUR · LAGO', 'OESTE · BOSQUE'].forEach((label, i) => {
            ctx.font = 'bold 26px sans-serif'; ctx.textAlign = 'center'; ctx.fillStyle = '#eff6ed'; ctx.fillText(label, 256 + i * 512, 540);
        });
        const panoramaTexture = new T.CanvasTexture(canvas); panoramaTexture.colorSpace = T.SRGBColorSpace;
        panoramaTexture.wrapS = T.RepeatWrapping;
        panoramaTexture.repeat.x = -1; // Read the panorama from inside without mirrored landmarks.
        const panorama = new T.Mesh(new T.SphereGeometry(65, 64, 32), new T.MeshBasicMaterial({ map: panoramaTexture, side: T.BackSide }));
        panorama.name = 'fixed-360-panorama'; scene.add(panorama);
        const floor = new T.Mesh(new T.CircleGeometry(R, 96), material(0x535650, { metalness: .25, roughness: .3 }));
        floor.rotation.x = -Math.PI / 2; scene.add(floor);
        for (const radius of [3, 6, 9.8]) {
            const ring = new T.Mesh(new T.RingGeometry(radius - .018, radius + .018, 96), trim);
            ring.rotation.x = -Math.PI / 2; ring.position.y = .012; scene.add(ring);
        }
        // True hemisphere. At the outermost shelf corner, roof clearance exceeds 3 m.
        const dome = new T.Mesh(new T.SphereGeometry(R, 64, 24, 0, Math.PI * 2, 0, Math.PI / 2), material(0xb9dce4, { transparent: true, opacity: .10, side: T.DoubleSide, depthWrite: false, metalness: .15 }));
        dome.name = 'glass-hemisphere'; scene.add(dome);
        for (let i = 0; i < 16; i++) {
            const a = i / 16 * Math.PI * 2;
            tube(Array.from({ length: 33 }, (_, k) => {
                const p = k / 32 * Math.PI / 2;
                return new T.Vector3(R * Math.sin(p) * Math.sin(a), R * Math.cos(p), R * Math.sin(p) * Math.cos(a));
            }));
        }
        for (const elevation of [.25, .55, .9]) {
            tube(Array.from({ length: 97 }, (_, k) => {
                const a = k / 96 * Math.PI * 2;
                return new T.Vector3(R * Math.cos(elevation) * Math.sin(a), R * Math.sin(elevation), R * Math.cos(elevation) * Math.cos(a));
            }), .035);
        }
        const shelves = [];
        const books = [0x284e65, 0x754232, 0x526044, 0xc2a274].map(color => material(color));
        const glow = new T.MeshBasicMaterial({ color: 0xffd295 });
        const titles = ['Proyectos', 'Documentos', 'Imágenes', 'Biblioteca', 'Música', 'Videos', 'Archivo'];
        titles.forEach((title, i) => {
            const angle = (i - 3) * step;
            const group = new T.Group(); group.name = `shelf-${i}`;
            group.position.set(shelfRadius * Math.sin(angle), 0, -shelfRadius * Math.cos(angle));
            group.rotation.y = -angle; scene.add(group); shelves.push(group);
            box(group, width, height, .09, 0, height / 2, -depth / 2 + .045, darkWood);
            for (const x of [-width / 2 + .065, width / 2 - .065]) box(group, .13, height, depth, x, height / 2, 0, wood);
            for (const y of [.09, .81, 1.53, 2.25, 2.97, height]) box(group, width, .1, depth, 0, y, 0, wood);
            for (const y of [.86, 1.58, 2.30, 3.02]) box(group, width - .27, .018, .02, 0, y, .29, glow);
            for (let row = 0; row < 4; row++) {
                for (let j = 0; j < 8; j++) {
                    const h = .39 + ((i + row + j) % 3) * .065;
                    const x = -.78 + j * .215;
                    box(group, .16, h, .29, x, .15 + row * .72 + h / 2, .07, books[(i + row + j) % 4]);
                    for (const offset of [-.12, .12]) box(group, .13, .016, .006, x, .15 + row * .72 + h / 2 + offset, .218, trim);
                }
            }
            const label = document.createElement('canvas'); label.width = 512; label.height = 96;
            const c = label.getContext('2d'); c.fillStyle = '#38271d'; c.fillRect(0, 0, 512, 96);
            c.fillStyle = '#ffe4b5'; c.textAlign = 'center'; c.font = '36px Georgia'; c.fillText(title, 256, 61);
            const tex = new T.CanvasTexture(label); tex.colorSpace = T.SRGBColorSpace;
            const sign = new T.Mesh(new T.PlaneGeometry(1.65, .30), new T.MeshBasicMaterial({ map: tex }));
            sign.position.set(0, 3.08, .307); group.add(sign);
        });
        const lampAngle = 3 * step + .25;
        const lamp = new T.Group(); lamp.name = 'end-of-row-lamp';
        lamp.position.set(shelfRadius * Math.sin(lampAngle), 0, -shelfRadius * Math.cos(lampAngle)); scene.add(lamp);
        function cylinder(rt, rb, h, y, mat) {
            const mesh = new T.Mesh(new T.CylinderGeometry(rt, rb, h, 24), mat); mesh.position.y = y; lamp.add(mesh);
        }
        cylinder(.32, .38, .10, .05, trim); cylinder(.035, .035, 1.95, 1.05, trim);
        cylinder(.26, .48, .55, 2.15, material(0xffe2a4, { emissive: 0xffbe58, emissiveIntensity: .65, side: T.DoubleSide }));
        const light = new T.PointLight(0xffc573, 18, 7, 2); light.position.y = 1.9; lamp.add(light);
        let yaw = 0, pitch = .06, targetYaw = 0, targetPitch = .06;
        const keys = new Set(), held = new Map(); let drag = null;
        const presets = {
            front: [0, 1.4, 0], center: [0, 0, 0],
            left: [-2, 0, .90], right: [2, 0, -.90]
        };
        document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
            const [x, z, a] = presets[button.dataset.view]; camera.position.set(x, 1.65, z);
            targetYaw = yaw = a; targetPitch = pitch = .06;
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
            lamp: lamp.position.toArray(), domeRadius: R, calls: renderer.info.render.calls
        }) });
    }
}

// Isolated spatial laboratory. No storage, API calls or production scene mutations.
const status = document.querySelector('#status');
try {
    const THREE = await import('../three-lab/vendor/three.module.min.js');
    new Drive3DThreeLab(THREE);
} catch (error) {
    status.hidden = false;
    status.textContent = 'No fue posible iniciar WebGL 2. Prueba con aceleración gráfica activa y recarga la página.';
    console.error('Drive 3D lab:', error);
}
