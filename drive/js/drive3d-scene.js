class Drive3DScene {
    constructor(T, Reflector, options = {}) {
        const status = options.status || document.querySelector("#status");
        const production = Boolean(options.items);
        const titles = options.items?.map(item => item.dataset.itemName || "Carpeta") || ["Proyectos", "Documentos", "Imágenes", "Biblioteca", "Música", "Videos", "Archivo"];
        const width = 2.05, depth = .6, height = 4.2;
        const shelfRadius = Math.max(8.6, (width + .08) / (2 * Math.tan(Math.PI / (titles.length + 3))) + .85);
        const R = shelfRadius + 4.0;
        const step = 2 * Math.atan((width / 2 + .025) / (shelfRadius - depth / 2));
        const viewport = options.viewport || document.querySelector('#viewport');
        let renderer;
        try {
            renderer = new T.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
        } catch (primaryError) {
            console.warn('Drive 3D: WebGL normal mode failed; retrying basic mode.', primaryError);
            try {
                renderer = new T.WebGLRenderer({ antialias: false, powerPreference: 'default' });
            } catch (fallbackError) {
                const error = new Error('WebGL no pudo inicializarse en este navegador o GPU. Revisa la aceleración por hardware.');
                error.cause = fallbackError;
                throw error;
            }
        }
        renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 1.5));
        renderer.outputColorSpace = T.SRGBColorSpace;
        renderer.toneMapping = T.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;
        viewport.append(renderer.domElement);
        const scene = new T.Scene();
        const camera = new T.PerspectiveCamera(50, 1, .08, Math.max(150, R * 8));
        camera.rotation.order = 'YXZ';
        camera.position.set(0, 2.7, 5.25);
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
                if (kind === 'wood') v = 95 + 7 * Math.sin(x * .9 + Math.sin(y * .021) * .7 + Math.sin(x * .037)) + (n - Math.floor(n)) * 5;
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
        const panorama = new T.Mesh(new T.SphereGeometry(Math.max(65, R * 4), 64, 32), new T.MeshBasicMaterial({ color: 0x93b4c9, side: T.BackSide }));
        panorama.name = 'fixed-360-panorama'; panorama.rotation.y = Math.PI; panorama.position.y = 3; panorama.scale.y = .55; scene.add(panorama);
        if (scene.environmentRotation) scene.environmentRotation.y = Math.PI;
        let environmentReady = false, needsRender = true, defaultPanoramaMap = null, customPanorama = false;
        new T.TextureLoader().load(new URL('../three-lab/assets/alpine-panorama.jpg', import.meta.url).href, map => {
            map.colorSpace = T.SRGBColorSpace;
            defaultPanoramaMap = map;
            if (!customPanorama) { panorama.material.map = map; panorama.material.color.set(0xffffff); panorama.material.needsUpdate = true; }
            const env = map.clone(); env.mapping = T.EquirectangularReflectionMapping; env.needsUpdate = true;
            scene.environment = env; scene.environmentIntensity = .55; environmentReady = true; needsRender = true;
        }, undefined, () => {
            status.hidden = false; status.textContent = 'El paisaje no pudo cargarse. La escena sigue disponible; recarga para intentarlo de nuevo.';
        });
        let floorMirror = null;
        try {
            floorMirror = new Reflector(new T.CircleGeometry(R, 96), {
                color: 0x8894a0,
                textureWidth: innerWidth < 700 ? 256 : 512,
                textureHeight: innerWidth < 700 ? 256 : 512,
                multisample: 0
            });
            floorMirror.rotation.x = -Math.PI / 2;
            floorMirror.position.y = -.015;
            scene.add(floorMirror);
        } catch (error) {
            console.warn('Drive 3D: reflective floor disabled; continuing with standard floor.', error);
        }
        const floor = new T.Mesh(new T.CircleGeometry(R, 96), material(0x9aa1ad, { map: marble, transparent: true, opacity: .66, metalness: .2, roughness: .32 }));
        floor.rotation.x = -Math.PI / 2; floor.renderOrder = 1; scene.add(floor);
        for (const radius of [2.55, 2.62, 6.1, 6.17, R - .45]) ring(scene, radius, .016, .015, radius === 2.55 || radius === 6.1 ? glow : trim);
        for (let i = 0; i < 24; i++) {
            const a = i / 24 * Math.PI * 2;
            tube([new T.Vector3(2.65 * Math.sin(a), .006, 2.65 * Math.cos(a)), new T.Vector3((R - .4) * Math.sin(a), .006, (R - .4) * Math.cos(a))], .007, trim);
        }
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
        titles.forEach((title, i) => {
            const angle = (i - Math.floor(titles.length / 2)) * step, group = new T.Group();
            group.name = `shelf-${i}`; group.userData.index = i;
            group.position.set(shelfRadius * Math.sin(angle), 0, -shelfRadius * Math.cos(angle)); group.rotation.y = -angle;
            scene.add(group); shelves.push(group);
        });
        function buildShelf(group) {
            const i = group.userData.index, title = titles[i];
            boxMeshes.length = 0;
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
                    box(group, .045, .06, .008, x, y + h / 2, .249, trim);
                }
                if ((row + i) % 3 === 0) {
                    const ornament = new T.Group(); ornament.position.set(.58, y, .08); group.add(ornament);
                    cylinder(ornament, .13, .18, .05, .025, trim);
                    const orb = new T.Mesh(new T.SphereGeometry(.16, 16, 12), material(0x4e8390, { metalness: .65, roughness: .2 })); orb.position.y = .27; ornament.add(orb);
                    const hoop = new T.Mesh(new T.TorusGeometry(.23, .012, 6, 32), trim); hoop.position.y = .27; hoop.rotation.z = -.4; ornament.add(hoop);
                }
            }
            const label = document.createElement('canvas'); label.width = 512; label.height = 96;
            const c = label.getContext('2d'); c.clearRect(0, 0, 512, 96); c.fillStyle = '#ffe6b2'; c.textAlign = 'center'; c.font = 'bold 56px Georgia'; c.fillText(title.length > 26 ? title.slice(0,25) + '…' : title, 256, 65, 480);
            const tex = new T.CanvasTexture(label); tex.colorSpace = T.SRGBColorSpace;
            const sign = new T.Mesh(new T.PlaneGeometry(1.65, .30), new T.MeshBasicMaterial({ map: tex, transparent: true })); sign.position.set(0, 3.86, .307); group.add(sign);
            // Each cabinet owns its instances so invisible cabinets can be unloaded.
            group.updateMatrixWorld(true);
            const inverse = group.matrixWorld.clone().invert(), batches = new Map();
            for (const mesh of boxMeshes) {
                if (!batches.has(mesh.material)) batches.set(mesh.material, []);
                batches.get(mesh.material).push(mesh);
            }
            for (const [mat, meshes] of batches) {
                const batch = new T.InstancedMesh(cube, mat, meshes.length);
                meshes.forEach((mesh, index) => { batch.setMatrixAt(index, inverse.clone().multiply(mesh.matrixWorld)); mesh.removeFromParent(); });
                batch.computeBoundingSphere(); group.add(batch);
            }
            boxMeshes.length = 0;
            addRealDriveBooks(group);
        }
        const shelfContents = new Map();

        function disposeDataBooks(group) {
            const old = group.getObjectByName('real-drive-books');
            if (!old) return;
            old.traverse(obj => {
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material && !sharedMaterials.has(obj.material)) {
                    obj.material.map?.dispose();
                    obj.material.dispose();
                }
            });
            old.removeFromParent();
        }

        function addRealDriveBooks(group) {
            const state = shelfContents.get(group.userData.index);
            if (!state) return;
            disposeDataBooks(group);
            const host = new T.Group();
            host.name = 'real-drive-books';
            group.add(host);
            const entries = [
                ...(Array.isArray(state.folders) ? state.folders.slice(0, 6).map(item => ({item, folder:true})) : []),
                ...(Array.isArray(state.files) ? state.files.slice(0, 18).map(item => ({item, folder:false})) : [])
            ].slice(0, 24);
            entries.forEach(({item, folder}, n) => {
                const row = Math.floor(n / 6);
                const col = n % 6;
                const h = folder ? .60 : .52;
                const x = -.78 + col * .31;
                const y = .22 + row * .86 + h / 2;
                const spineCanvas = document.createElement('canvas');
                spineCanvas.width = 192; spineCanvas.height = 512;
                const sc = spineCanvas.getContext('2d');
                sc.fillStyle = folder ? '#467a88' : ['#173653','#5b3025','#294a34','#363352'][n % 4];
                sc.fillRect(0,0,192,512);
                sc.fillStyle = '#f7e8be';
                sc.font = 'bold 27px sans-serif';
                sc.textAlign = 'center';
                sc.textBaseline = 'middle';
                const label = String(item.name || (folder ? 'Carpeta' : 'Archivo')).slice(0,34);
                sc.save(); sc.translate(96,256); sc.rotate(-Math.PI/2); sc.fillText(label,0,0,450); sc.restore();
                const tex = new T.CanvasTexture(spineCanvas); tex.colorSpace = T.SRGBColorSpace;
                const mat = new T.MeshStandardMaterial({map:tex, roughness:.58, emissive: folder ? 0x102830 : 0x090909, emissiveIntensity:.25});
                const mesh = new T.Mesh(new T.BoxGeometry(.245,h,.34), mat);
                mesh.position.set(x,y,.36);
                mesh.userData.driveItem = item;
                mesh.userData.driveFolder = folder;
                host.add(mesh);
            });
        }

        this.setShelfContents = (index, state) => {
            shelfContents.set(index, state || {});
            const group = shelves[index];
            if (group?.children.length) addRealDriveBooks(group);
            needsRender = true;
        };

        const sharedMaterials = new Set([wood, trim, darkWood, glow, bounce, pages, ...books]);
        function unloadShelf(group) {
            group.traverse(mesh => {
                if (mesh.isInstancedMesh) mesh.dispose();
                if (mesh.geometry && mesh.geometry !== cube) mesh.geometry.dispose();
                if (mesh.material && !sharedMaterials.has(mesh.material)) { mesh.material.map?.dispose(); mesh.material.dispose(); }
            });
            group.clear();
        }
        // Central brass / marble table, fully modeled. Position stays fixed while walking.
        const table = new T.Group(); table.name = 'central-table'; table.position.z = -1.3; scene.add(table);
        cylinder(table, 1.58, 1.65, .10, .055, blackStone);
        cylinder(table, 1.08, 1.28, .70, .48, blackStone);
        cylinder(table, 1.45, 1.45, .13, .89, trim);
        cylinder(table, 1.43, 1.43, .06, .985, blackStone);
        for (const [r, y, mat] of [[1.62,.10,cyan],[1.27,.17,trim],[1.10,.78,glow],[1.45,.96,glow],[.63,1.035,cyan]]) ring(table, r, .019, y, mat);
        const globe = new T.Group(); globe.position.y = 1.65; globe.scale.setScalar(.72); table.add(globe);
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
        const lampAngle = (titles.length - 1 - Math.floor(titles.length / 2)) * step + .25, lamp = new T.Group(); lamp.name = 'end-of-row-lamp';
        lamp.position.set(shelfRadius * Math.sin(lampAngle), 0, -shelfRadius * Math.cos(lampAngle)); scene.add(lamp);
        cylinder(lamp,.32,.38,.10,.05,trim); cylinder(lamp,.035,.035,2.4,1.25,trim);
        cylinder(lamp,.26,.48,.55,2.55,material(0xffe2a4,{emissive:0xffbe58,emissiveIntensity:.7,side:T.DoubleSide}));
        const light = new T.PointLight(0xffc573, 18, 7, 2); light.position.y = 2.3; lamp.add(light);
        // Plants intentionally removed from Drive 3D.
        let yaw = 0, pitch = -.12, targetYaw = 0, targetPitch = -.12;
        let selectedIndex = -1;
        const highlight = new T.Group(); highlight.visible = false; scene.add(highlight);
        for (const x of [-width/2,width/2]) box(highlight,.025,height,.025,x,height/2,.33,cyan);
        for (const y of [0,height]) box(highlight,width,.025,.025,0,y,.33,cyan);
        const keys = new Set(), held = new Map(); let drag = null;
        const presets = {
            front: [0, 5.25, 0], center: [0, 5.25, 0],
            left: [-2, 0, .90], right: [2, 0, -.90]
        };
        document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => {
            const [x, z, a] = presets[button.dataset.view]; camera.position.set(x, 2.7, z);
            targetYaw = yaw = a; targetPitch = pitch = -.12;
            keys.clear(); held.clear(); viewport.focus({ preventScroll: true });
        }));
        const handled = ['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'];
        window.addEventListener('keydown', event => {
            if (!event.target.closest('input,textarea,select,[contenteditable=true]') && handled.includes(event.code) && !event.ctrlKey && !event.metaKey && !event.altKey) { event.preventDefault(); keys.add(event.code); }
        });
        window.addEventListener('keyup', event => keys.delete(event.code));
        const clearInput = () => { keys.clear(); held.clear(); drag = null; };
        window.addEventListener('blur', clearInput);
        document.addEventListener('visibilitychange', clearInput);
        viewport.addEventListener('pointerdown', event => {
            if (drag || event.button !== 0) return;
            drag = { id: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY };
            viewport.setPointerCapture(event.pointerId); viewport.focus({ preventScroll: true });
        });
        viewport.addEventListener('pointermove', event => {
            if (drag?.id !== event.pointerId) return;
            targetYaw -= (event.clientX - drag.x) * .004;
            targetPitch = T.MathUtils.clamp(targetPitch - (event.clientY - drag.y) * .003, -.65, 1.1);
            drag.x = event.clientX; drag.y = event.clientY;
        });
        const raycaster = new T.Raycaster();
        function pointerHit(event) {
            const rect = viewport.getBoundingClientRect();
            raycaster.setFromCamera(new T.Vector2((event.clientX - rect.left) / rect.width * 2 - 1, 1 - (event.clientY - rect.top) / rect.height * 2), camera);
            return raycaster.intersectObjects(shelves, true)[0] || null;
        }
        function driveItemFromHit(hit) {
            let node = hit?.object || null;
            while (node) {
                if (node.userData?.driveItem) return {item:node.userData.driveItem, folder:Boolean(node.userData.driveFolder)};
                if (shelves.includes(node)) break;
                node = node.parent;
            }
            return null;
        }
        viewport.addEventListener('pointerup', event => {
            if (!drag || Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > 7) return;
            const hit = pointerHit(event);
            const dataBook = driveItemFromHit(hit);
            if (dataBook) {
                options.onItemSelect?.(dataBook.item, dataBook.folder, false);
                needsRender = true;
                return;
            }
            if (!hit) {
                if (raycaster.intersectObject(table, true).length) return;
                const ground = raycaster.intersectObject(floor)[0];
                if (ground) {
                    const direction = ground.point.clone().sub(camera.position); direction.y = 0;
                    direction.normalize().multiplyScalar(.75);
                    const x = camera.position.x + direction.x, z = camera.position.z + direction.z;
                    if (canStand(x,z)) camera.position.set(x,2.7,z);
                    needsRender = true;
                }
                return;
            }
            let group = hit.object;
            while (group.parent && !shelves.includes(group)) group = group.parent;
            selectedIndex = group.userData.index;
            options.onSelect?.(selectedIndex);
            needsRender = true;
        });
        viewport.addEventListener('dblclick', event => {
            const dataBook = driveItemFromHit(pointerHit(event));
            if (dataBook) {
                event.preventDefault();
                options.onItemSelect?.(dataBook.item, dataBook.folder, true);
            }
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
        const map = options.map || document.querySelector('#minimap'), m = map.getContext('2d');
        function minimap() {
            m.clearRect(0, 0, 240, 240); m.save(); m.translate(120, 120); m.scale(100 / R, 100 / R);
            m.fillStyle = '#1d3540'; m.strokeStyle = '#8ca7b5'; m.lineWidth = .07;
            m.beginPath(); m.arc(0, 0, R, 0, Math.PI * 2); m.fill(); m.stroke();
            shelves.forEach(shelf => { m.save(); m.translate(shelf.position.x, shelf.position.z); m.rotate(-shelf.rotation.y); m.fillStyle = '#b7844d'; m.fillRect(-width / 2, -depth / 2, width, depth); m.restore(); });
            m.fillStyle = '#327c99'; m.beginPath(); m.arc(0, -1.3, 1.65, 0, Math.PI * 2); m.fill();
            m.fillStyle = '#ffdc84'; m.beginPath(); m.arc(lamp.position.x, lamp.position.z, .3, 0, Math.PI * 2); m.fill();
            m.translate(camera.position.x, camera.position.z); m.rotate(-yaw);
            m.fillStyle = '#69bfff30'; m.beginPath(); m.moveTo(0, 0); m.arc(0, 0, 3, -Math.PI / 2 - .59, -Math.PI / 2 + .59); m.closePath(); m.fill();
            m.fillStyle = '#67bdff'; m.beginPath(); m.moveTo(0, -.5); m.lineTo(.3, .3); m.lineTo(-.3, .3); m.closePath(); m.fill(); m.restore();
            (options.coordinates || document.querySelector('#coordinates')).value = `x ${camera.position.x.toFixed(1)} · z ${camera.position.z.toFixed(1)} · giro ${T.MathUtils.radToDeg(yaw).toFixed(0)}°`;
        }
        const frustum = new T.Frustum(), projection = new T.Matrix4();
        let visibleKey = '', visibleIndices = [];
        function zones() {
            camera.updateMatrixWorld();
            frustum.setFromProjectionMatrix(projection.multiplyMatrices(camera.projectionMatrix, camera.matrixWorldInverse));
            const candidates = shelves.map((s, i) => ({i, s, distance: s.position.distanceTo(camera.position), score: Math.abs(s.position.clone().add(new T.Vector3(0,height / 2,0)).project(camera).x)}))
                .filter(({s}) => frustum.intersectsSphere(new T.Sphere(s.position.clone().add(new T.Vector3(0, height / 2, 0)), 2.4)))
                .sort((a,b) => a.score - b.score).slice(0, 9);
            visibleIndices = candidates.map(v => v.i);
            const key = visibleIndices.slice().sort((a,b)=>a-b).join(',');
            if (key !== visibleKey) {
                shelves.forEach((s,i) => { if (visibleIndices.includes(i)) { if (!s.children.length) buildShelf(s); } else if(s.children.length) unloadShelf(s); });
                visibleKey = key; needsRender = true;
            }
            highlight.visible = visibleIndices.includes(selectedIndex);
            if (highlight.visible) { highlight.position.copy(shelves[selectedIndex].position); highlight.rotation.copy(shelves[selectedIndex].rotation); }
            options.onView?.({visible: visibleIndices, near: candidates.filter(v => v.distance < 5.6).slice(0,3).map(v=>v.i), selected: selectedIndex});
        }
        this.look = (degrees, vertical) => { targetYaw = -degrees * Math.PI / 180; targetPitch = -vertical * Math.PI / 180; needsRender = true; };
        this.move = (side, forward) => {
            const x = camera.position.x + Math.cos(yaw) * side - Math.sin(yaw) * forward;
            const z = camera.position.z - Math.sin(yaw) * side - Math.cos(yaw) * forward;
            if (canStand(x,z)) camera.position.set(x,2.7,z);
            needsRender = true;
        };
        this.home = () => { camera.position.set(0,2.7,4.2); targetYaw = 0; targetPitch = -.12; selectedIndex = -1; needsRender = true; };
        this.focus = index => {
            const shelf = shelves[index]; if (!shelf) return;
            selectedIndex = index;
            // Aim from the current position. Selection does not teleport through furniture.
            targetYaw = Math.atan2(camera.position.x - shelf.position.x, camera.position.z - shelf.position.z);
            targetPitch = -.04; needsRender = true;
        };
        let surfaceRevision = {glass:0, floor:0};
        this.surface = (surface, url) => {
            const revision = ++surfaceRevision[surface];
            if (surface === 'glass') customPanorama = Boolean(url);
            const material = surface === 'glass' ? panorama.material : floor.material;
            if (!url) {
                if (material.map && material.map !== marble && material.map !== defaultPanoramaMap) material.map.dispose();
                material.map = surface === 'glass' ? defaultPanoramaMap : marble;
                material.needsUpdate = true; needsRender = true; return;
            }
            new T.TextureLoader().load(url, map => {
                if (surfaceRevision[surface] !== revision) { map.dispose(); return; }
                map.colorSpace = T.SRGBColorSpace;
                const mat = surface === 'glass' ? panorama.material : floor.material;
                if (mat.map && mat.map !== marble && mat.map !== defaultPanoramaMap) mat.map.dispose();
                mat.map = map; mat.color.set(0xffffff); mat.needsUpdate = true;
                needsRender = true;
            }, undefined, () => { status.hidden = false; status.textContent = 'No se pudo cargar el fondo seleccionado.'; });
        };
        this.snapshot = () => ({camera: camera.position.toArray(), yaw, pitch, visible: visibleIndices, selected: selectedIndex,
            pickPoints: shelves.map(s=> { const p = s.localToWorld(new T.Vector3(0,3.86,.32)).project(camera); return [p.x,p.y]; }),
            shelves: shelves.map(s=>({position:s.position.toArray(),rotation:s.rotation.y,width,depth,height,loaded:!!s.children.length})),
            lamp:lamp.position.toArray(),table:table.position.toArray(),domeRadius:R,panorama:panorama.position.toArray(),environmentReady,
            calls:renderer.info.render.calls, geometries:renderer.info.memory.geometries, textures:renderer.info.memory.textures});
        function resize() { const w = viewport.clientWidth, h = viewport.clientHeight; renderer.setSize(w, h); camera.fov = w < 700 ? 75 : 50; camera.aspect = w / h; camera.updateProjectionMatrix(); needsRender = true; }
        window.addEventListener('resize', resize); resize();
        renderer.domElement.addEventListener('webglcontextlost', event => { event.preventDefault(); renderer.setAnimationLoop(null); clearInput(); status.hidden = false; status.textContent = 'Se interrumpió el contexto gráfico. Recarga para continuar.'; });
        let previous = performance.now(), lastView = '';
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
            const view = [camera.position.x, camera.position.z, yaw, pitch].map(n => n.toFixed(5)).join(',');
            if (needsRender || view !== lastView) { zones(); renderer.render(scene, camera); minimap(); options.onCamera?.(-yaw * 180 / Math.PI, -pitch * 180 / Math.PI); lastView = view; needsRender = false; }
        });
        status.hidden = true;
        // Read-only diagnostics for reproducible spatial verification (no user data).
        if (!production) window.drive3dLab = Object.freeze({ snapshot: this.snapshot });
    }
}

export { Drive3DScene };
