import { Drive3DScene } from './drive3d-scene.js';
import * as THREE from '../three-lab/vendor/three.module.min.js';
import { Reflector } from '../three-lab/vendor/Reflector.js';

// Data/actions stay in the authenticated Drive controller; this adapter owns no routes.
class Drive3DProduction {
  static start(app) {
    const doc = app.document, status = doc.querySelector('[data-three-status]');
    const viewport = doc.getElementById('dwThreeViewport');
    const map = doc.createElement('canvas'); map.width = map.height = 240;
    map.setAttribute('aria-label', 'Plano real: libreros, archivos, lámpara, posición y dirección de cámara');
    const coordinates = doc.createElement('output');
    let zoneKey = '', visible = [], near = [];
    // The selected folder's files are paged independently from its small
    // near-shelf preview. Each page occupies at most four complete dome rows.
    let selectedGalleryIndex=-1, selectedGalleryPage=1, selectedGalleryPages=1;
    let galleryAbort=null, galleryTicket=0;
    const pager=doc.createElement('nav');
    pager.className='dw-dome-gallery-pager';
    pager.hidden=true;
    pager.setAttribute('aria-label','Páginas de archivos del librero');
    const before=doc.createElement('button'), after=doc.createElement('button'), label=doc.createElement('span');
    before.type=after.type='button';
    before.textContent='‹ Anterior';
    after.textContent='Siguiente ›';
    label.setAttribute('aria-live','polite');
    pager.append(before,label,after);
    app.world.append(pager);

    function clearGallery() {
        galleryTicket++;
        galleryAbort?.abort(); galleryAbort=null;
        selectedGalleryIndex=-1;selectedGalleryPage=selectedGalleryPages=1;
        pager.hidden=true;
        app.three?.selectShelfGallery?.(-1);
    }

    async function loadFocusedPage(index,page=1) {
        const shelf=app.shelves[index];
        if(!shelf)return;
        galleryAbort?.abort();
        const controller=new AbortController();
        galleryAbort=controller;
        const ticket=++galleryTicket;
        const href=String(shelf.dataset.previewHref||'');
        if(!href)return;
        const url=new URL(href,app.window.location.href);
        url.searchParams.set('api','files');
        url.searchParams.set('galeria','1');
        url.searchParams.set('pagina',String(page));
        pager.hidden=false;
        label.textContent='Cargando archivos…';
        before.disabled=after.disabled=true;
        try {
            const response=await fetch(url.href,{
                credentials:'same-origin',cache:'no-store',signal:controller.signal,
                headers:{Accept:'application/json'}
            });
            if(!response.ok)throw new Error('HTTP '+response.status);
            const json=await response.json();
            if(!json?.ok || !json?.state)throw new Error('Respuesta de archivos inválida.');
            if(controller.signal.aborted || ticket!==galleryTicket || selectedGalleryIndex!==index)return;
            selectedGalleryPage=Math.max(1,Number(json.state.file_page||page));
            selectedGalleryPages=Math.max(1,Number(json.state.file_pages||1));
            const files=Array.isArray(json.state.files)?json.state.files:[];
            app.three?.setFocusedShelfFiles?.(index,files);
            label.textContent='Archivos '+selectedGalleryPage+' / '+selectedGalleryPages+
                ' · cuatro filas · gira para ver todo el domo';
            before.disabled=selectedGalleryPage<=1;
            after.disabled=selectedGalleryPage>=selectedGalleryPages;
            pager.hidden=files.length===0 && selectedGalleryPages===1;
        } catch(error) {
            if(controller.signal.aborted || ticket!==galleryTicket)return;
            label.textContent='No se pudieron cargar los archivos. Toca Siguiente para reintentar.';
            before.disabled=selectedGalleryPage<=1;
            after.disabled=false;
        } finally {
            if(galleryAbort===controller)galleryAbort=null;
        }
    }

    before.addEventListener('click',()=>loadFocusedPage(selectedGalleryIndex,Math.max(1,selectedGalleryPage-1)));
    after.addEventListener('click',()=>loadFocusedPage(selectedGalleryIndex,Math.min(selectedGalleryPages,selectedGalleryPage+1)));
    app.clearThreeFocusedGallery=clearGallery;

    function syncZones() {
        const focus = app.shelves.indexOf(app.focusedShelf);
        const detail = [...new Set([...(visible.includes(focus) ? [focus] : []), ...near])].slice(0,3);
        const key = visible.join(',') + '/' + detail.join(',');
        if (key === zoneKey) return;
        if (focus < 0) {
            if(app.deskFocus) app.deskFocus.hidden = true;
            if(selectedGalleryIndex>=0)clearGallery();
        }
        zoneKey = key; clearTimeout(app.zoneTimer);
        app.visibleShelves = new Set(visible.map(i => app.shelves[i]));
        app.shelves.forEach((shelf,index) => {
            if (!detail.includes(index)) {
                const href = shelf.dataset.previewHref;
                app.previewRequests.get(href)?.abort(); app.previewRequests.delete(href); app.previewCache.delete(href);
            }
            shelf.dataset.lod = detail.includes(index) ? 'detail' : visible.includes(index) ? 'overview' : 'unloaded';
        });
        if (app.focusedShelf && !visible.includes(focus)) {
            app.focusedShelf = null;
            app.selected = null;
            app.hud.open.disabled = true; app.hud.desk.hidden = true; app.hud.play.hidden = true; app.hud.download.hidden = true;
            app.hud.previewImage?.removeAttribute('src');
        }
        app.zoneTimer = setTimeout(() => detail.forEach(i => app.loadShelfPreview(app.shelves[i], app.shelves[i].dataset.previewHref)),160);
    }
    app.showThreeContents = (shelf,state) => {
        const shelfIndex = app.shelves.indexOf(shelf);
        if (shelfIndex >= 0) app.three?.setShelfContents?.(shelfIndex, state);
        // Data belongs to the cabinet itself; no extra middle content window.
    };
    app.chooseThreeShelf = index => {
        const shelf = app.shelves[index]; if (!shelf) return;
        const changed=selectedGalleryIndex!==index;
        app.focusedShelf = shelf; app.three.focus(index);
        if (changed) {
            selectedGalleryIndex=index;
            selectedGalleryPage=selectedGalleryPages=1;
            loadFocusedPage(index,1);
        }
        if (!visible.includes(index)) visible.push(index);
        syncZones(); app.selectShelf(shelf,true);
    };
    doc.querySelectorAll('[data-camera-strafe]').forEach(button => { button.dataset.move = Number(button.dataset.cameraStrafe) < 0 ? 'left' : 'right'; });
    doc.querySelectorAll('[data-camera-forward]').forEach(button => { button.dataset.move = Number(button.dataset.cameraForward) < 0 ? 'back' : 'forward'; });
    app.three = new Drive3DScene(THREE, Reflector, {
        viewport, map, coordinates, status, items:app.shelves,
        onSelect:index => app.chooseThreeShelf(index),
        onItemSelect:(item, folder, open) => {
            const node = app.bookNode(item, folder);
            app.selectElement(node);
            if (open) app.openSelected();
        },
        onSpatialProjection:projection => app.updateSpatialProjection?.(projection),
        onSurfacePlaced:placement => app.onSpatialSurfacePlaced?.(placement),
        onSurfaceSelected:id => app.onSpatialSurfaceSelected?.(id),
        onSurfaceHint:message => app.showSurfacePlacementHint?.(message),
        onCamera:(yaw,pitch) => { app.camera.yaw = yaw; app.camera.pitch = pitch; if(app.pitchRange) app.pitchRange.value = String(pitch); },
        onView:view => { visible = view.visible; near = view.near; if(view.selected < 0) app.focusedShelf = null; syncZones(); }
    });

    // Only replace production UI after the WebGL scene constructed successfully.
    doc.querySelector('.dw-radar-room').replaceChildren(map);
    app.radar.append(coordinates);
    app.restoreSpatialImages?.();
    const initialFiles = Array.isArray(app.config?.initialFiles) ? app.config.initialFiles : [];
    if (initialFiles.length) app.three?.setCurrentFiles?.(initialFiles);
    app.loadCurrentFolderFiles?.().then(state => {
        if (!state?.error) {
            app.three?.setCurrentFiles?.(Array.isArray(state.files) ? state.files : []);
        } else if (!initialFiles.length && status) {
            status.hidden = false;
            status.textContent = 'No se pudieron cargar los archivos de esta carpeta. Recarga para intentarlo de nuevo.';
        }
    });

    // The map selects the actual cabinet nearest the touched world coordinate.
    map.addEventListener('click', event => {
        event.stopPropagation(); const rect = map.getBoundingClientRect(), state = app.three.snapshot();
        const x = ((event.clientX-rect.left)/rect.width*240-120)*state.domeRadius/100;
        const z = ((event.clientY-rect.top)/rect.height*240-120)*state.domeRadius/100;
        const candidate = state.shelves.map((s,i)=>({i,d:Math.hypot(s.position[0]-x,s.position[2]-z)})).sort((a,b)=>a.d-b.d)[0];
        if(candidate && candidate.d < 2) app.chooseThreeShelf(candidate.i);
    });
    app.applyRoomPreferences();
}

}
export { Drive3DProduction };
