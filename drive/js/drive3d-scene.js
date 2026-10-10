import { Drive3DSurfacePlacements } from './drive3d-surface-placements.js';

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
        // Project the 360º wallpaper directly BEHIND the physical dome ribs.
        // A remote sphere (R*4) created a parallax gap: its texture seam
        // drifted away from the wood as the player moved. Both surfaces now
        // share the same center/radius, with the glass a hair behind the ribs.
        const panoramaRadius = R + .085;
        // Viewing sphere UVs from inside reverses their horizontal direction.
        // Reverse U once so photographs keep their original left/right orientation.
        const panoramaGeometry = new T.SphereGeometry(panoramaRadius, 96, 48);
        const panoramaUV = panoramaGeometry.attributes.uv;
        for (let i = 0; i < panoramaUV.count; i++) panoramaUV.setX(i, 1 - panoramaUV.getX(i));
        const panorama = new T.Mesh(
            panoramaGeometry,
            // No milky acrylic tint or cinematic desaturation. The panorama
            // *is* the outdoor view, not an opaque sheet in front of it.
            new T.MeshBasicMaterial({
                color:0xffffff,side:T.BackSide,depthWrite:false,
                transparent:false,toneMapped:false,fog:false
            })
        );
        panorama.name = 'fixed-360-panorama';
        // Three's sphere UV seam at phi=0 lies on -X; rotate it to +X,
        // precisely where rib index 4/16 already frames the glass.
        panorama.rotation.y = Math.PI;
        panorama.renderOrder = -2;
        scene.add(panorama);
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
            }), i === 4 ? .115 : .065, i === 4 ? wood : trim);
        }
        // The fourth wooden meridian masks the exact longitudinal joining
        // line of the wrapped image for every viewing angle from the room.
        // Four authentic glass rows: pane UV limits must match the wood
        // seen by the visitor, including the previously missing .24 beam.
        for (const elevation of [.24, .47, .72, 1.05]) {
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
            const entries = Array.isArray(state.folders)
                ? state.folders.slice(0, 18).map(item => ({item, folder:true}))
                : [];
            // A selected shelf's files are now shown at 3× preview size
            // in the dedicated four-row dome gallery, never as tiny frames
            // attached to the shelf crown. Only one shelf gallery exists.
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
            if (focusedShelfIndex === index && !focusedFullData) rebuildFocusedGallery(state?.files || []);
            needsRender = true;
        };

        const sharedMaterials = new Set([wood, trim, darkWood, glow, bounce, pages, ...books]);

        // Current-folder files live beside the cabinets as independent thumbnail-sized cards.
        // They are not books and they are never inserted into a folder cabinet.
        const fileGallery = new T.Group();
        fileGallery.name = 'current-folder-files';
        scene.add(fileGallery);
        // Only ONE selected shelf contributes a four-row image gallery.
        // It uses the whole 360° ring, not the 2-metre shelf face.
        const focusedGallery = new T.Group();
        focusedGallery.name = 'focused-shelf-files';
        scene.add(focusedGallery);
        let focusedPanels = [], focusedShelfIndex = -1, focusRevision = 0, focusedFullData = false;
        let filePanels = [];
        let selectedFileCard = null;
        let galleryRevision = 0;

        // Same icon taxonomy used by bloque_archivos.php through FileIconResolver.
        const fileIconGlyphs = {
            'fa-file-pdf':String.fromCodePoint(0xf1c1),
            'fa-file-word':String.fromCodePoint(0xf1c2),
            'fa-file-excel':String.fromCodePoint(0xf1c3),
            'fa-file-powerpoint':String.fromCodePoint(0xf1c4),
            'fa-file-image':String.fromCodePoint(0xf1c5),
            'fa-file-archive':String.fromCodePoint(0xf1c6),
            'fa-file-audio':String.fromCodePoint(0xf1c7),
            'fa-file-video':String.fromCodePoint(0xf1c8),
            'fa-file-code':String.fromCodePoint(0xf1c9),
            'fa-file-lines':String.fromCodePoint(0xf15c),
            'fa-database':String.fromCodePoint(0xf1c0),
            'fa-book':String.fromCodePoint(0xf02d),
            'fa-envelope':String.fromCodePoint(0xf0e0),
            'fa-font':String.fromCodePoint(0xf031),
            'fa-key':String.fromCodePoint(0xf084),
            'fa-cube':String.fromCodePoint(0xf1b2),
            'fa-cubes':String.fromCodePoint(0xf1b3),
            'fa-pen-ruler':String.fromCodePoint(0xf5ae),
            'fa-lock':String.fromCodePoint(0xf023),
            'fa-file':String.fromCodePoint(0xf15b)
        };
        const fileCategoryColors = {
            pdf:'#a83f3f', word:'#356aaf', excel:'#2d7c55', powerpoint:'#b85c32',
            archive:'#786245', text:'#53667a', code:'#5f4b8b', image:'#237a71',
            audio:'#1d7aa7', video:'#7a3ba0', database:'#496c7e', ebook:'#80613a',
            mail:'#356a8f', font:'#6a5f8f', certificate:'#8b6b32', package:'#596474',
            design:'#8a4f72', model:'#3d7181', locked:'#805049', generic:'#53667a'
        };
        const fileKindStyle = (item) => {
            const extension = String(item?.extension || String(item?.name || '').split('.').pop()).toUpperCase().slice(0,8);
            // The API's FileIconResolver is authoritative. Older payloads
            // lacking its metadata still receive a type-specific icon.
            const kinds = {
                image:'fa-file-image', pdf:'fa-file-pdf', audio:'fa-file-audio',
                video:'fa-file-video', text:'fa-file-lines', code:'fa-file-code'
            };
            const extensions = {
                PDF:'fa-file-pdf', DOC:'fa-file-word', DOCX:'fa-file-word', XLS:'fa-file-excel', XLSX:'fa-file-excel',
                PPT:'fa-file-powerpoint', PPTX:'fa-file-powerpoint', ZIP:'fa-file-archive', RAR:'fa-file-archive',
                TXT:'fa-file-lines', MD:'fa-file-lines', JSON:'fa-file-code', HTML:'fa-file-code',
                PHP:'fa-file-code', JS:'fa-file-code', SQL:'fa-database', MP3:'fa-file-audio',
                MP4:'fa-file-video', JPG:'fa-file-image', JPEG:'fa-file-image', PNG:'fa-file-image',
                WEBP:'fa-file-image', SVG:'fa-file-image'
            };
            const icon = String(item?.icon || extensions[extension] || kinds[item?.kind] || 'fa-file');
            const category = String(item?.icon_category || item?.kind || 'generic');
            const label = String(item?.icon_label || extension || 'Archivo');
            return {
                icon,
                glyph:fileIconGlyphs[icon] || fileIconGlyphs['fa-file'],
                label:label.toUpperCase().slice(0,22),
                extension:extension || 'ARCHIVO',
                color:fileCategoryColors[category] || fileCategoryColors.generic
            };
        };

        function cardLabelTexture(item) {
            const canvas = document.createElement('canvas');
            canvas.width = 512; canvas.height = 128;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#071722'; ctx.fillRect(0,0,512,128);
            ctx.fillStyle = '#eafaff'; ctx.font = '700 34px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            const name = String(item?.name || 'Archivo');
            ctx.fillText(name.length > 28 ? name.slice(0,27) + '…' : name,256,64,476);
            const tex = new T.CanvasTexture(canvas); tex.colorSpace = T.SRGBColorSpace;
            return tex;
        }

        function placeholderTexture(item) {
            const style = fileKindStyle(item);
            const canvas = document.createElement('canvas');
            canvas.width = 512; canvas.height = 384;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#06121b'; ctx.fillRect(0,0,512,384);
            ctx.fillStyle = style.color; ctx.fillRect(22,22,468,340);
            ctx.fillStyle = 'rgba(2,13,22,.58)'; ctx.fillRect(38,38,436,308);
            ctx.fillStyle = '#e9fbff'; ctx.textAlign='center'; ctx.textBaseline='middle';
            // A vector document symbol with a type badge works even before
            // the external icon font has loaded (or if it is unavailable).
            ctx.fillStyle = '#e9fbff';
            ctx.beginPath();
            ctx.moveTo(193,84); ctx.lineTo(287,84); ctx.lineTo(321,118);
            ctx.lineTo(321,238); ctx.lineTo(193,238); ctx.closePath();
            ctx.fill();
            ctx.fillStyle = style.color;
            ctx.beginPath(); ctx.moveTo(288,85); ctx.lineTo(288,119); ctx.lineTo(320,119); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#071722';
            ctx.font = '900 58px "Font Awesome 6 Free", "Font Awesome 5 Free", sans-serif';
            ctx.fillText(style.glyph,256,155,106);
            ctx.font = '900 32px sans-serif';
            ctx.fillText(style.extension.slice(0,5),256,210,116);
            ctx.font = '800 30px sans-serif'; ctx.fillStyle='#aeeeff'; ctx.fillText(style.label,256,278,430);
            ctx.font = '700 24px sans-serif'; ctx.fillStyle='#dff8ff'; ctx.fillText(style.extension,256,324,260);
            const tex = new T.CanvasTexture(canvas); tex.colorSpace = T.SRGBColorSpace;
            return tex;
        }

        function disposeFileGallery() {
            fileGallery.traverse(obj => {
                // Cabinet decorations reuse the same cube/trim resources. A
                // gallery refresh must never dispose those shared assets.
                if (obj.geometry && obj.geometry !== cube) obj.geometry.dispose();
                if (obj.material && !sharedMaterials.has(obj.material)) {
                    obj.material.map?.dispose?.();
                    obj.material.dispose?.();
                }
            });
            fileGallery.clear();
            filePanels = [];
            selectedFileCard = null;
        }

        function setFileSelection(card) {
            if (selectedFileCard?.userData?.selection) selectedFileCard.userData.selection.visible = false;
            selectedFileCard = card || null;
            if (selectedFileCard?.userData?.selection) selectedFileCard.userData.selection.visible = true;
            needsRender = true;
        }

        function makeFileCard(item, revision, isCurrent = () => revision === galleryRevision) {
            const card = new T.Group();
            card.userData.driveItem = item;
            card.userData.driveFolder = false;
            card.userData.fileCard = true;

            const backing = new T.Mesh(new T.BoxGeometry(.86,.72,.05), material(0x06131d,{metalness:.12,roughness:.5}));
            backing.position.z = 0; card.add(backing);
            // Two thin hangers make the file read as a suspended thumbnail, not as a tiny cabinet.
            for (const x of [-.27,.27]) {
                const cord = new T.Mesh(cube, trim);
                cord.scale.set(.010,.22,.010);
                cord.position.set(x,.47,0);
                card.add(cord);
            }
            // Slightly larger invisible hit surface makes finger selection reliable
            // without visually enlarging the thumbnail.
            const hitSurface = new T.Mesh(
                new T.PlaneGeometry(.98,.84),
                new T.MeshBasicMaterial({transparent:true,opacity:0,depthWrite:false})
            );
            hitSurface.position.z = .055; card.add(hitSurface);

            const mediaMaterial = new T.MeshBasicMaterial({map:placeholderTexture(item),side:T.DoubleSide});
            const media = new T.Mesh(new T.PlaneGeometry(.78,.52), mediaMaterial);
            media.position.set(0,.08,.031); card.add(media);

            if (item?.kind === 'image' && item?.thumbnail_href && !item?.locked) {
                new T.TextureLoader().load(item.thumbnail_href, map => {
                    if (!isCurrent()) { map.dispose(); return; }
                    map.colorSpace = T.SRGBColorSpace;
                    const old = mediaMaterial.map;
                    mediaMaterial.map = map;
                    mediaMaterial.color.set(0xffffff);
                    mediaMaterial.needsUpdate = true;
                    old?.dispose?.();
                    needsRender = true;
                }, undefined, () => {});
            }

            const labelMat = new T.MeshBasicMaterial({map:cardLabelTexture(item),side:T.DoubleSide});
            const label = new T.Mesh(new T.PlaneGeometry(.78,.15), labelMat);
            label.position.set(0,-.255,.032); card.add(label);

            const outline = new T.LineSegments(
                new T.EdgesGeometry(new T.PlaneGeometry(.90,.76)),
                new T.LineBasicMaterial({color:0x78e9ff,transparent:true,opacity:.95})
            );
            outline.position.z = .04; outline.visible = false; card.add(outline);
            card.userData.selection = outline;
            return card;
        }

        function rebuildFileGallery(files) {
            const revision = ++galleryRevision;
            disposeFileGallery();
            const items = Array.isArray(files) ? files.filter(Boolean) : [];
            if (!items.length) { needsRender = true; return; }

            const columns = innerWidth < 700 ? 4 : 5;
            const rows = 4;
            const panelCapacity = columns * rows;
            const panelCount = Math.ceil(items.length / panelCapacity);
            const panelStep = innerWidth < 700 ? .42 : .36;
            const halfShelfSpan = titles.length > 0 ? Math.max(0,(titles.length - 1) * step / 2) : 0;

            // Files float well inside the cabinet radius so shelves can never hide them.
            // With no subfolders they are centered in front of the user. With subfolders,
            // the first panels sit visibly beside the middle cabinets and later panels fan outward.
            const hasCabinets = titles.length > 0;
            // With cabinets, every card occupies free air ABOVE the 4.2m
            // shelf crowns on the same ring. With no cabinets, retain the
            // original gallery directly in front of the viewer.
            const fileRadius = hasCabinets ? shelfRadius - .10 : 4.65;
            const visibleSideAngle = hasCabinets
                ? Math.min(.48, Math.max(.30, halfShelfSpan * .48))
                : 0;

            for (let p = 0; p < panelCount; p++) {
                let angle;
                if (!titles.length) {
                    angle = (p - (panelCount - 1) / 2) * panelStep;
                } else {
                    const side = p % 2 === 0 ? -1 : 1;
                    const rank = Math.floor(p / 2);
                    angle = side * (visibleSideAngle + rank * panelStep);
                }

                const panel = new T.Group();
                panel.name = `file-panel-${p}`;
                panel.position.set(fileRadius * Math.sin(angle), .18, -fileRadius * Math.cos(angle));
                panel.rotation.y = -angle;
                fileGallery.add(panel);
                filePanels.push(panel);

                const start = p * panelCapacity;
                const end = Math.min(items.length, start + panelCapacity);
                for (let index = start; index < end; index++) {
                    const local = index - start;
                    const row = Math.floor(local / columns);
                    const col = local % columns;
                    const card = makeFileCard(items[index], revision);
                    // Non-overlapping targets: the old .96/.83 grid intersected
                    // adjacent .98/.84 hit surfaces and confused touch selection.
                    card.position.set((col - (columns - 1) / 2) * 1.06,
                        hasCabinets ? 7.56 - row * .84 : 3.28 - row * .92, .12);
                    panel.add(card);
                }
            }
            needsRender = true;
        }

        // Dispose only instance-owned resources; cube/wood/trim are shared
        // with cabinets. A stale async thumbnail never resurrects an old shelf.
        function disposeFocusedGallery() {
            focusRevision++;
            focusedGallery.traverse(obj => {
                if (obj.geometry && obj.geometry !== cube) obj.geometry.dispose();
                if (obj.material && !sharedMaterials.has(obj.material)) {
                    obj.material.map?.dispose?.();
                    obj.material.dispose?.();
                }
            });
            focusedGallery.clear();
            focusedPanels = [];
        }

        function rebuildFocusedGallery(files) {
            disposeFocusedGallery();
            if (focusedShelfIndex < 0) return;
            const entries = Array.isArray(files) ? files.filter(Boolean) : [];
            if (!entries.length) {needsRender = true; return;}
            const revision = focusRevision;
            const rows = 4, gap = .24, cardWidth = .86 * 1.88;
            const shelvesAngle = (focusedShelfIndex - Math.floor(titles.length / 2)) * step;
            const bands = Array.from({length:rows}, (_, row) => {
                const y = height + 1.27 + row * 1.44;
                // Every corner remains inside the real dome roof,
                // including the highest of the four rows.
                const roof = Math.sqrt(Math.max(1,(R-.9)**2 - (y+.72)**2)) - .22;
                const radius = Math.max(2.3,Math.min(shelfRadius - .55,roof));
                const capacity = Math.max(4,Math.floor((Math.PI*2*radius)/(cardWidth+gap)));
                return {y,radius,capacity,items:[]};
            });
            // Fill like a four-row keyboard: row 1, row 2, row 3, row 4,
            // then the next column. All rows expand across the circular wall.
            entries.forEach(item => {
                const available = bands.filter(band=>band.items.length<band.capacity);
                if (!available.length) return;
                available.sort((a,b)=>a.items.length-b.items.length);
                available[0].items.push(item);
            });
            bands.forEach((band,row) => {
                const count=band.items.length;
                if(!count)return;
                const interval=(cardWidth+gap)/band.radius;
                const panel = new T.Group();
                panel.name = 'shelf-gallery-row-' + row;
                focusedGallery.add(panel);
                focusedPanels.push(panel);
                band.items.forEach((item,col) => {
                    const angle=shelvesAngle+(col-(count-1)/2)*interval;
                    const card=makeFileCard(item,revision,()=>focusRevision===revision);
                    card.scale.set(1.88,1.88,1);
                    card.position.set(band.radius*Math.sin(angle),band.y,-band.radius*Math.cos(angle));
                    card.rotation.y=-angle;
                    panel.add(card);
                });
            });
            needsRender = true;
        }

        this.focusedGalleryCapacity = () => {
            const width = .86*1.88+.24;
            return Array.from({length:4}, (_,row)=>{
                const y=height+1.27+row*1.44;
                const r=Math.max(2.3,Math.min(shelfRadius-.55,
                    Math.sqrt(Math.max(1,(R-.9)**2-(y+.72)**2))-.22));
                return Math.max(4,Math.floor(Math.PI*2*r/width));
            }).reduce((sum,value)=>sum+value,0);
        };
        this.selectShelfGallery = index => {
            if (!Number.isInteger(index) || index < 0 || index >= shelves.length) {
                focusedShelfIndex=-1;
                focusedFullData=false;
                disposeFocusedGallery();
                fileGallery.visible=true;
                needsRender=true;
                return;
            }
            if (focusedShelfIndex!==index) {
                focusedShelfIndex=index;
                focusedFullData=false;
                disposeFocusedGallery();
            }
            fileGallery.visible=false;
            rebuildFocusedGallery(shelfContents.get(index)?.files || []);
            needsRender=true;
        };
        this.setFocusedShelfFiles = (index, files) => {
            if(index!==focusedShelfIndex)return false;
            focusedFullData=true;
            rebuildFocusedGallery(files);
            return true;
        };
        this.setCurrentFiles = files => rebuildFileGallery(files);
        function unloadShelf(group) {
            group.traverse(mesh => {
                if (mesh.isInstancedMesh) mesh.dispose();
                if (mesh.geometry && mesh.geometry !== cube) mesh.geometry.dispose();
                if (mesh.material && !sharedMaterials.has(mesh.material)) { mesh.material.map?.dispose(); mesh.material.dispose(); }
            });
            group.clear();
        }
        // The center of the dome is intentionally open. Files are selected from the wall gallery.
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
            targetPitch = T.MathUtils.clamp(targetPitch - (event.clientY - drag.y) * .003, -1.48, 1.48);
            drag.x = event.clientX; drag.y = event.clientY;
        });
        let surfacePlacements = null;
        const raycaster = new T.Raycaster();
        function pointerHit(event) {
            // Match the actual WebGL canvas instead of its outer container.
            const rect = renderer.domElement.getBoundingClientRect();
            if (!rect.width || !rect.height) return null;
            const x = (event.clientX - rect.left) / rect.width;
            const y = (event.clientY - rect.top) / rect.height;
            if (x < 0 || x > 1 || y < 0 || y > 1) return null;
            camera.updateMatrixWorld();
            raycaster.setFromCamera(new T.Vector2(x * 2 - 1, 1 - y * 2), camera);
            return raycaster.intersectObjects([...shelves, ...(fileGallery.visible ? [fileGallery] : []), focusedGallery], true)[0] || null;
        }
        function driveItemFromHit(hit) {
            let node = hit?.object || null;
            while (node) {
                if (node.userData?.driveItem) return {item:node.userData.driveItem, folder:Boolean(node.userData.driveFolder), node};
                if (shelves.includes(node)) break;
                node = node.parent;
            }
            return null;
        }
        // Each file is selectable only inside its actual projected 3D card.
        // Selecting the nearest center within a broad circular radius used to
        // jump to a diagonal / higher item. The raw raycaster can also hit the
        // back of a neighboring card when projected cards nearly touch.
        // Bound the pointer to the projected quadrilateral of its own card.
        function fileSelectionFromPointer(event, directHit = null) {
            if (!filePanels.length && !focusedPanels.length) return null;
            const rect = renderer.domElement.getBoundingClientRect();
            const x = event.clientX, y = event.clientY;
            if (!rect.width || !rect.height || x < rect.left || x > rect.right ||
                y < rect.top || y > rect.bottom) return null;
            camera.updateMatrixWorld();
            const project = (card, vx, vy) => {
                const p = card.localToWorld(new T.Vector3(vx, vy, .06)).project(camera);
                return {
                    x:rect.left + (p.x + 1) * rect.width / 2,
                    y:rect.top + (1 - p.y) * rect.height / 2,
                    z:p.z
                };
            };
            const insideQuad = (corners, px, py) => {
                let sign = 0;
                for (let i = 0; i < 4; i++) {
                    const a = corners[i], b = corners[(i+1)%4];
                    const cross = (b.x-a.x)*(py-a.y)-(b.y-a.y)*(px-a.x);
                    if (Math.abs(cross) < .01) continue;
                    const next = Math.sign(cross);
                    if (sign && sign !== next) return false;
                    sign = next;
                }
                return Boolean(sign);
            };
            let best = null, distance = Infinity;
            for (const panel of (focusedShelfIndex >= 0 ? focusedPanels : filePanels)) {
                for (const card of panel.children) {
                    if (!card.userData?.fileCard) continue;
                    const center = project(card, 0, 0);
                    if (center.z <= -1 || center.z >= 1) continue;
                    const corners = [
                        project(card, -.49, -.42),
                        project(card, .49, -.42),
                        project(card, .49, .42),
                        project(card, -.49, .42)
                    ];
                    if (!insideQuad(corners, x, y)) continue;
                    const d = Math.hypot(center.x-x, center.y-y);
                    if (d < distance) { best = card; distance = d; }
                }
            }
            return best ? {card:best, item:best.userData.driveItem} : null;
        }
        viewport.addEventListener('pointerup', event => {
            if (!drag || drag.id !== event.pointerId) return;
            if (Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > 7) {
                return;
            }
            const hit = pointerHit(event);
            // Anchored artwork and placement taps take priority over shelves.
            if (surfacePlacements?.pending && surfacePlacements.choose(raycaster.ray)) {
                needsRender = true;
                return;
            }
            if (surfacePlacements?.select(raycaster)) {
                needsRender = true;
                return;
            }
            const fileSelection = fileSelectionFromPointer(event, hit);
            if (fileSelection) {
                setFileSelection(fileSelection.card);
                options.onItemSelect?.(fileSelection.item, false, false);
                needsRender = true;
                return;
            }
            const dataBook = driveItemFromHit(hit);
            if (dataBook) {
                options.onItemSelect?.(dataBook.item, dataBook.folder, false);
                needsRender = true;
                return;
            }
            if (!hit) {
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
        // A real pointerup is the one and only selection trigger. A later
        // synthetic click must not select a different tile if the animated
        // camera moved between those events.
        viewport.addEventListener('dblclick', event => {
            const hit = pointerHit(event);
            const fileSelection = fileSelectionFromPointer(event, hit);
            const dataBook = fileSelection ? {item:fileSelection.item, folder:false} : driveItemFromHit(hit);
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
            m.fillStyle = '#56d8f2';
            filePanels.forEach(panel => { m.save(); m.translate(panel.position.x,panel.position.z); m.rotate(-panel.rotation.y); m.fillRect(-.6,-.08,1.2,.16); m.restore(); });
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
        this.look = (degrees, vertical) => { targetYaw = -degrees * Math.PI / 180; targetPitch = T.MathUtils.clamp(-vertical * Math.PI / 180, -1.48, 1.48); needsRender = true; };
        this.move = (side, forward) => {
            const x = camera.position.x + Math.cos(yaw) * side - Math.sin(yaw) * forward;
            const z = camera.position.z - Math.sin(yaw) * side - Math.cos(yaw) * forward;
            if (canStand(x,z)) camera.position.set(x,2.7,z);
            needsRender = true;
        };
        this.home = () => { camera.position.set(0,2.7,5.25); targetYaw = 0; targetPitch = -.12; selectedIndex = -1; this.selectShelfGallery(-1); needsRender = true; };
        this.focus = index => {
            const shelf = shelves[index]; if (!shelf) return;
            selectedIndex = index;
            this.selectShelfGallery(index);
            // Aim from the current position. Selection does not teleport through furniture.
            targetYaw = Math.atan2(camera.position.x - shelf.position.x, camera.position.z - shelf.position.z);
            targetPitch = -.04; needsRender = true;
        };

        // Spatial media is anchored in world coordinates near the glass, not to the screen.
        // Multiple anchors allow image windows to behave like persistent pictures in the room.
        const spatialAnchors = new Map();
        surfacePlacements = new Drive3DSurfacePlacements(T,scene,camera,R,{
            onPlaced:placement => options.onSurfacePlaced?.(placement),
            onSelected:id => options.onSurfaceSelected?.(id),
            onHint:message => options.onSurfaceHint?.(message),
            onRender:()=>{needsRender=true;}
        });
        this.armSpatialSurface = (id,mode) => surfacePlacements.prepare(id,mode);
        this.cancelSpatialSurface = () => surfacePlacements.cancel();
        this.setSpatialSurface = entry => {
            const world=surfacePlacements.set(entry);
            if(Array.isArray(world)){
                const point=new T.Vector3(...world);
                spatialAnchors.set(String(entry.id),{point,baseDistance:Math.max(1,point.distanceTo(camera.position))});
                needsRender=true;
            }
            return world;
        };
        this.clearSpatialSurface = id => { surfacePlacements.remove(id); needsRender=true; };
        this.surfacePlacementState = id => surfacePlacements.snapshot().find(entry=>entry.id===id) || null;
        const spatialCenter = new T.Vector3(0, 0, 0);
        // Free images may be positioned throughout the interior, up to
        // the roof and down to floor level, not at the obsolete 6.6m cap.
        const spatialSphere = new T.Sphere(spatialCenter, Math.max(4, R - .45));
        const spatialId = (value) => String(value || 'singleton');

        function normalizeSpatialPoint(world) {
            if (!Array.isArray(world) || world.length !== 3 || world.some(value => !Number.isFinite(Number(value)))) return null;
            const point = new T.Vector3(Number(world[0]), Number(world[1]), Number(world[2]));
            point.y = T.MathUtils.clamp(point.y, .12, R - .48);
            const local = point.clone().sub(spatialCenter);
            if (local.length() > spatialSphere.radius) {
                local.setLength(spatialSphere.radius);
                point.copy(spatialCenter).add(local);
            }
            return point;
        }

        function placeSpatialInView(id = 'singleton', world = null) {
            const key = spatialId(id);
            camera.updateMatrixWorld(true);
            let point = normalizeSpatialPoint(world);
            if (!point) {
                const direction = new T.Vector3();
                camera.getWorldDirection(direction);
                const ray = new T.Ray(camera.position.clone(), direction.normalize());
                point = ray.intersectSphere(spatialSphere, new T.Vector3());
                if (!point) point = camera.position.clone().add(direction.multiplyScalar(Math.max(4.5, R * .62)));
                point.y = T.MathUtils.clamp(point.y, .18, R - .48);
            }
            const baseDistance = Math.max(1, point.distanceTo(camera.position));
            spatialAnchors.set(key, {point, baseDistance});
            needsRender = true;
            return point.toArray();
        }

        function projectSpatial() {
            if (!spatialAnchors.size) {
                options.onSpatialProjection?.(null);
                return;
            }
            spatialAnchors.forEach((entry, id) => {
                const projected = entry.point.clone().project(camera);
                const distance = entry.point.distanceTo(camera.position);
                const visible = projected.z > -1 && projected.z < 1 && Math.abs(projected.x) < 1.22 && Math.abs(projected.y) < 1.22;
                options.onSpatialProjection?.({
                    id,
                    visible,
                    x:(projected.x * .5 + .5) * viewport.clientWidth,
                    y:(-.5 * projected.y + .5) * viewport.clientHeight,
                    scale:T.MathUtils.clamp(entry.baseDistance / Math.max(.1, distance), .42, 1.35),
                    distance,
                    world:entry.point.toArray()
                });
            });
        }

        this.placeSpatialMedia = (id = 'singleton', world = null) => placeSpatialInView(id, world);
        this.clearSpatialMedia = (id = 'singleton') => {
            const key = spatialId(id);
            spatialAnchors.delete(key);
            options.onSpatialProjection?.({id:key, visible:false, removed:true});
            needsRender = true;
        };
        this.moveSpatialMedia = (id, dx, dy) => {
            let key = id, moveX = dx, moveY = dy;
            if (typeof id !== 'string') {
                key = 'singleton';
                moveX = id;
                moveY = dx;
            }
            key = spatialId(key);
            if (!spatialAnchors.has(key)) placeSpatialInView(key);
            const entry = spatialAnchors.get(key);
            const local = entry.point.clone().sub(spatialCenter);
            const radius = Math.max(1, Math.hypot(local.x, local.z));
            let angle = Math.atan2(local.x, local.z);
            angle -= Number(moveX || 0) * .0035;
            local.x = Math.sin(angle) * radius;
            local.z = Math.cos(angle) * radius;
            local.y = T.MathUtils.clamp(local.y - Number(moveY || 0) * .012, .12, R - .48);
            if (local.length() > spatialSphere.radius) local.setLength(spatialSphere.radius);
            entry.point.copy(spatialCenter).add(local);
            needsRender = true;
        };
        this.spatialMediaState = (id = 'singleton') => {
            const entry = spatialAnchors.get(spatialId(id));
            return entry ? {world:entry.point.toArray(),baseDistance:entry.baseDistance} : null;
        };

        let surfaceRevision = {glass:0, floor:0};
        // Locally generated, lightweight 2:1 panoramas and floor materials.
        // The bundled photographic castle remains the untouched default.
        let presetPanorama = null, presetFloor = null, currentGround = 'original';
        const landscapeColors = {
            'alpine-spring':['#80cefa','#e5f6ff','#639b65','#f8e5a4'],
            'alpine-summer':['#279de6','#ffe2a5','#286b43','#74c9a4'],
            'alpine-autumn':['#597daa','#f4c69a','#a65b2c','#e5ab4d'],
            'alpine-winter':['#8099bd','#e7f2ff','#f0f6fb','#acbfd4'],
            sunset:['#312d5f','#ffac70','#714b73','#ffca7e'],
            night:['#090e30','#293763','#273e59','#7b9abb'],
            prehistoric:['#936d54','#e7b98e','#496747','#b7a678'],
            future:['#122145','#7a4caa','#305e90','#7bd7df']
        };
        function landscapeTexture(key) {
            const palette = landscapeColors[key]; if(!palette) return null;
            const c=document.createElement('canvas'); c.width=1024;c.height=512;
            const ctx=c.getContext('2d'), sky=ctx.createLinearGradient(0,0,0,512);
            sky.addColorStop(0,palette[0]);sky.addColorStop(.58,palette[1]);sky.addColorStop(1,palette[2]);
            ctx.fillStyle=sky;ctx.fillRect(0,0,1024,512);
            if(key==='night') {
                ctx.fillStyle='#f4f7ff';
                for(let i=0;i<130;i++){const x=(i*163.7)%1024,y=(i*71.9)%260;ctx.globalAlpha=.35+(i%5)/9;ctx.fillRect(x,y,1.5,1.5);}
                ctx.globalAlpha=1;
            }
            for(let layer=0;layer<3;layer++){
                ctx.beginPath();ctx.moveTo(0,380+layer*38);
                for(let x=0;x<=1024;x+=8){
                    const phase=x/1024*Math.PI*2;
                    const ridge=Math.abs(Math.sin(phase*(layer+2)+1.4))* (96-layer*22)
                      +Math.abs(Math.sin(phase*7+layer))* (25-layer*5);
                    ctx.lineTo(x,340+layer*49-ridge);
                }
                ctx.lineTo(1024,512);ctx.lineTo(0,512);ctx.closePath();
                ctx.fillStyle=[palette[3],palette[2],key==='alpine-winter'?'#b7cada':palette[2]][layer];ctx.fill();
            }
            const t=new T.CanvasTexture(c);t.colorSpace=T.SRGBColorSpace;return t;
        }
        function groundTexture(kind) {
            const c=document.createElement('canvas');c.width=c.height=512;
            const ctx=c.getContext('2d');
            const colors={water:['#155473','#4ca6c8'],grass:['#397d35','#91b563'],clouds:['#9ccee7','#f4fcff'],sand:['#bb945e','#f3d8a1'],snow:['#d5ebf6','#ffffff']};
            const colorsFor=colors[kind];if(!colorsFor)return null;
            ctx.fillStyle=colorsFor[0];ctx.fillRect(0,0,512,512);
            for(let i=0;i<900;i++){
                const x=(i*159.73)%512,y=(i*79.19)%512;
                ctx.strokeStyle=colorsFor[1];ctx.globalAlpha=.08+(i%7)*.04;
                ctx.lineWidth=kind==='clouds'?12:kind==='water'?2:1;
                ctx.beginPath();ctx.moveTo(x,y);
                ctx.lineTo(x+(kind==='water'?30:kind==='clouds'?45:4),y+(kind==='grass'?12:kind==='clouds'?9:1));ctx.stroke();
            }
            ctx.globalAlpha=1;
            const t=new T.CanvasTexture(c);t.colorSpace=T.SRGBColorSpace;
            t.wrapS=t.wrapT=T.RepeatWrapping;t.repeat.set(4,4);return t;
        }
        // Prefer the real photographic assets when deployed; retain the existing
        // procedural renderer as a safe fallback when an asset is unavailable.
        const environmentAssetBase = new URL('../three-lab/assets/environments/', import.meta.url);
        const panoramas = new Set(['alpine-spring','alpine-summer','alpine-autumn','alpine-winter','sunset','night','prehistoric','future']);
        const grounds = new Set(['water','grass','clouds','sand','snow']);
        const presetLoader = new T.TextureLoader();
        this.setEnvironmentPreset = (key) => {
            const next = panoramas.has(String(key)) ? String(key) : 'original';
            const revision = ++surfaceRevision.glass;
            customPanorama = next !== 'original';
            const assign = (map, owned) => {
                if (revision !== surfaceRevision.glass) { if (owned) map?.dispose(); return; }
                const previous = panorama.material.map;
                presetPanorama = owned ? map : null;
                customPanorama = next !== 'original';
                panorama.material.map = map;
                panorama.material.color.set(0xffffff);
                panorama.material.needsUpdate = true; needsRender = true;
                if (previous && previous !== map && previous !== defaultPanoramaMap && previous !== marble) previous.dispose();
            };
            if (next === 'original') { assign(defaultPanoramaMap, false); return; }
            const fallback = () => assign(landscapeTexture(next), true);
            presetLoader.load(new URL('panoramas/' + next + '.jpg', environmentAssetBase).href, (map) => {
                map.colorSpace = T.SRGBColorSpace;
                assign(map, true);
            }, undefined, fallback);
        };
        this.setGroundPreset = (key) => {
            const next = grounds.has(String(key)) ? String(key) : 'original';
            const revision = ++surfaceRevision.floor;
            const assign = (map, owned) => {
                if (revision !== surfaceRevision.floor) { if (owned) map?.dispose(); return; }
                const previous = floor.material.map;
                presetFloor = owned ? map : null;
                currentGround = next;
                floor.material.map = map;
                floor.material.color.set(0xffffff);
                floor.material.opacity = next === 'original' ? .66 : next === 'water' ? .48 : 1;
                floor.material.roughness = next === 'water' ? .18 : next === 'original' ? .32 : .9;
                floor.material.needsUpdate = true;
                if (floorMirror) floorMirror.visible = next === 'original' || next === 'water';
                needsRender = true;
                if (previous && previous !== map && previous !== defaultPanoramaMap && previous !== marble) previous.dispose();
            };
            if (next === 'original') { assign(marble, false); return; }
            const fallback = () => assign(groundTexture(next), true);
            presetLoader.load(new URL('floors/' + next + '.jpg', environmentAssetBase).href, (map) => {
                map.colorSpace = T.SRGBColorSpace;
                map.wrapS = map.wrapT = T.RepeatWrapping;
                map.repeat.set(4, 4);
                assign(map, true);
            }, undefined, fallback);
        };
        this.surface = (surface, url) => {
            const revision = ++surfaceRevision[surface];
            if (surface === 'glass') customPanorama = Boolean(url);
            const material = surface === 'glass' ? panorama.material : floor.material;
            if (!url) {
                if (surface === 'glass') this.setEnvironmentPreset('original');
                else this.setGroundPreset('original');
                return;
            }
            new T.TextureLoader().load(url, map => {
                if (surfaceRevision[surface] !== revision) { map.dispose(); return; }
                map.colorSpace = T.SRGBColorSpace;
                const mat = surface === 'glass' ? panorama.material : floor.material;
                if (mat.map && mat.map !== marble && mat.map !== defaultPanoramaMap) mat.map.dispose();
                if (surface === 'glass') presetPanorama = null; else presetFloor = null;
                mat.map = map; mat.color.set(0xffffff); mat.needsUpdate = true;
                needsRender = true;
            }, undefined, () => { status.hidden = false; status.textContent = 'No se pudo cargar el fondo seleccionado.'; });
        };
        this.snapshot = () => ({camera: camera.position.toArray(), yaw, pitch, visible: visibleIndices, selected: selectedIndex,
            pickPoints: shelves.map(s=> { const p = s.localToWorld(new T.Vector3(0,3.86,.32)).project(camera); return [p.x,p.y]; }),
            shelves: shelves.map(s=>({
                position:s.position.toArray(),rotation:s.rotation.y,width,depth,height,loaded:!!s.children.length,
                realBooks:s.getObjectByName('real-drive-books')?.children.length || 0,
                overheadImages:s.getObjectByName('real-drive-books')?.children.filter(child => child.name === 'shelf-overhead-picture').map(child => ({
                    name:child.userData.driveItem?.name || '',
                    world:child.getWorldPosition(new T.Vector3()).toArray()
                })) || [],
                realItems:Array.from(s.getObjectByName('real-drive-books')?.children || []).map(book => ({
                    name:book.userData?.driveItem?.name || '',
                    kind:book.userData?.driveItem?.kind || (book.userData?.driveFolder ? 'folder' : 'file'),
                    open:book.userData?.driveItem?.open_href || ''
                }))
            })),
            lamp:lamp.position.toArray(),
            filePanels:filePanels.map(panel=>panel.position.toArray()),
            galleryLayout:titles.length ? 'overhead' : 'front',
            currentFiles:fileGallery.children.reduce((sum,panel)=>sum+panel.children.length,0),
            focusedShelfIndex,focusedGalleryRows:focusedPanels.length,
            focusedFiles:focusedPanels.flatMap(panel=>panel.children.map(card=>({
                name:card.userData.driveItem?.name || '',
                kind:card.userData.driveItem?.kind || 'file',
                position:card.getWorldPosition(new T.Vector3()).toArray(),
                scale:card.scale.x,
                point:card.localToWorld(new T.Vector3(0,0,.06)).project(camera).toArray()
            }))),
            fileItems:fileGallery.children.flatMap(panel=>panel.children.map(card=>({
                name:card.userData?.driveItem?.name || '',
                kind:card.userData?.driveItem?.kind || 'file',
                open:card.userData?.driveItem?.open_href || '',
                icon:card.userData?.driveItem?.icon || 'fa-file',
                iconCategory:card.userData?.driveItem?.icon_category || 'generic',
                position:card.getWorldPosition(new T.Vector3()).toArray(),
                hitSize:[.98,.84],
                point:(()=>{const p=card.localToWorld(new T.Vector3(0,0,.05)).project(camera);return [p.x,p.y,p.z];})()
            }))),
            visibleFileCards:fileGallery.children.flatMap(panel=>panel.children).filter(card=>{
                const p=card.localToWorld(new T.Vector3(0,0,.05)).project(camera);
                return p.z>-1 && p.z<1 && Math.abs(p.x)<.92 && Math.abs(p.y)<.92;
            }).length,
            domeRadius:R,panorama:panorama.position.toArray(),
            environmentTextures:{
                glass:panorama.material.map?.image?.src || '',
                floor:floor.material.map?.image?.src || '',
                glassSize:[panorama.material.map?.image?.width || 0,panorama.material.map?.image?.height || 0],
                floorSize:[floor.material.map?.image?.width || 0,floor.material.map?.image?.height || 0],
                panoramaU:panoramaUV.getX(97),
                ground:currentGround
            },
            panoramaSeam:{radius:panoramaRadius,woodenRib:4,angle:Math.PI/2},environmentReady,
            glassView:{toneMapped:panorama.material.toneMapped,
                tint:panorama.material.color.getHex(),opacity:panorama.material.opacity,
                paintedWoodBands:[.24,.47,.72,1.05]},
            spatial:this.spatialMediaState('singleton'),
            spatialImages:Array.from(spatialAnchors.entries()).filter(([id]) => id !== 'singleton').map(([id,entry]) => ({id,world:entry.point.toArray(),baseDistance:entry.baseDistance})),
            surfaceImages:surfacePlacements?.snapshot() || [],
            pendingSurface:surfacePlacements?.pending?.mode || null,
            calls:renderer.info.render.calls, geometries:renderer.info.memory.geometries, textures:renderer.info.memory.textures});
        function resize() { const w = viewport.clientWidth, h = viewport.clientHeight; renderer.setSize(w, h); camera.fov = w < 700 ? 75 : 50; camera.aspect = w / h; camera.updateProjectionMatrix(); needsRender = true; }
        window.addEventListener('resize', resize); resize();
        renderer.domElement.addEventListener('webglcontextlost', event => { event.preventDefault(); renderer.setAnimationLoop(null); clearInput(); status.hidden = false; status.textContent = 'Se interrumpió el contexto gráfico. Recarga para continuar.'; });
        let previous = performance.now(), lastView = '';
        renderer.setAnimationLoop(now => {
            const dt = Math.min((now - previous) / 1000, .05); previous = now;
            if (document.hidden) return;
            targetYaw += ((keys.has('ArrowLeft') ? 1 : 0) - (keys.has('ArrowRight') ? 1 : 0)) * dt;
            targetPitch = T.MathUtils.clamp(targetPitch + ((keys.has('ArrowUp') ? 1 : 0) - (keys.has('ArrowDown') ? 1 : 0)) * dt, -1.48, 1.48);
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
            if (needsRender || view !== lastView) {
                zones();
                renderer.render(scene, camera);
                minimap();
                projectSpatial();
                options.onCamera?.(-yaw * 180 / Math.PI, -pitch * 180 / Math.PI);
                lastView = view; needsRender = false;
            }
        });
        status.hidden = true;
        // Read-only diagnostics for reproducible spatial verification (no user data).
        if (!production) window.drive3dLab = Object.freeze({ snapshot: this.snapshot });
    }
}

export { Drive3DScene };
