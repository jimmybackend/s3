import { Drive3DScene } from './drive3d-scene.js';
import * as THREE from '../three-lab/vendor/three.module.min.js';
import { Reflector } from '../three-lab/vendor/Reflector.js';

// Data/actions stay in the authenticated Drive controller; this adapter owns no routes.
class Drive3DProduction {
  static start(app) {
    const doc = app.document, status = doc.querySelector('[data-three-status]');
    const viewport = doc.getElementById('dwThreeViewport');
    const map = doc.createElement('canvas'); map.width = map.height = 240;
    map.setAttribute('aria-label', 'Plano real: libreros, mesa, lámpara, posición y dirección de cámara');
    doc.querySelector('.dw-radar-room').replaceChildren(map);
    const coordinates = doc.createElement('output'); app.radar.append(coordinates);
    // Keep the existing file strip accessible above the canvas, outside the retired CSS scene.
    if (app.deskFiles) app.world.append(app.deskFiles);
    const rootFiles = Array.from(app.deskFiles?.children || []);
    if (app.deskFocus) { app.world.append(app.deskFocus); app.deskFocus.hidden = true; }
    const panel = doc.querySelector('[data-three-content]'), items = doc.querySelector('[data-three-items]');
    doc.querySelector('[data-three-close]').onclick = () => { panel.hidden = true; };
    items.addEventListener('click', event => { const node = event.target.closest('[data-dw-item]'); if(node) app.selectElement(node); });
    items.addEventListener('dblclick', event => { const node = event.target.closest('[data-dw-item]'); if(node) { app.selectElement(node); app.openSelected(); } });
    let zoneKey = '', visible = [], near = [];
    function syncZones() {
        const focus = app.shelves.indexOf(app.focusedShelf);
        const detail = [...new Set([...(visible.includes(focus) ? [focus] : []), ...near])].slice(0,3);
        const key = visible.join(',') + '/' + detail.join(',');
        if (key === zoneKey) return;
        if (focus < 0) { panel.hidden = true; items.replaceChildren(); app.deskFiles?.replaceChildren(...rootFiles); if(app.deskFocus) app.deskFocus.hidden = true; }
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
            app.focusedShelf = null; panel.hidden = true; items.replaceChildren();
            app.deskFiles?.replaceChildren(); app.selected = null;
            app.hud.open.disabled = true; app.hud.play.hidden = true; app.hud.download.hidden = true;
            app.hud.previewImage?.removeAttribute('src');
        }
        app.zoneTimer = setTimeout(() => detail.forEach(i => app.loadShelfPreview(app.shelves[i], app.shelves[i].dataset.previewHref)),160);
    }
    app.showThreeContents = (shelf,state) => {
        if (shelf !== app.focusedShelf) return;
        doc.querySelector('[data-three-title]').textContent = shelf.dataset.itemName;
        items.replaceChildren();
        (state.folders || []).slice(0,6).forEach(item => items.append(app.bookNode(item,true)));
        (state.files || []).slice(0,20).forEach(item => items.append(app.bookNode(item,false)));
        if (!items.children.length) items.textContent = 'Carpeta vacía';
        panel.hidden = false;
    };
    app.chooseThreeShelf = index => {
        const shelf = app.shelves[index]; if (!shelf) return;
        app.focusedShelf = shelf; app.three.focus(index);
        if (!visible.includes(index)) visible.push(index);
        syncZones(); app.selectShelf(shelf,true);
    };
    doc.querySelectorAll('[data-camera-strafe]').forEach(button => { button.dataset.move = Number(button.dataset.cameraStrafe) < 0 ? 'left' : 'right'; });
    doc.querySelectorAll('[data-camera-forward]').forEach(button => { button.dataset.move = Number(button.dataset.cameraForward) < 0 ? 'back' : 'forward'; });
    app.three = new Drive3DScene(THREE, Reflector, {
        viewport, map, coordinates, status, items:app.shelves,
        onSelect:index => app.chooseThreeShelf(index),
        onCamera:(yaw,pitch) => { app.camera.yaw = yaw; app.camera.pitch = pitch; if(app.pitchRange) app.pitchRange.value = String(pitch); },
        onView:view => { visible = view.visible; near = view.near; if(view.selected < 0) app.focusedShelf = null; syncZones(); }
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
