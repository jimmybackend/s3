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
    const coordinates = doc.createElement('output');
    let zoneKey = '', visible = [], near = [];
    function syncZones() {
        const focus = app.shelves.indexOf(app.focusedShelf);
        const detail = [...new Set([...(visible.includes(focus) ? [focus] : []), ...near])].slice(0,3);
        const key = visible.join(',') + '/' + detail.join(',');
        if (key === zoneKey) return;
        if (focus < 0) {
            if (!app.deskCurrentFolderOpen) app.hideDeskCarousel?.();
            if(app.deskFocus) app.deskFocus.hidden = true;
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
            if (!app.deskCurrentFolderOpen) app.hideDeskCarousel?.();
            app.selected = null;
            app.hud.open.disabled = true; app.hud.play.hidden = true; app.hud.download.hidden = true;
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
        if (app.focusedShelf !== shelf && !app.deskCurrentFolderOpen) app.hideDeskCarousel?.();
        app.focusedShelf = shelf; app.three.focus(index);
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
        onDeskProjection:projection => app.updateDeskProjection?.(projection),
        onCamera:(yaw,pitch) => { app.camera.yaw = yaw; app.camera.pitch = pitch; if(app.pitchRange) app.pitchRange.value = String(pitch); },
        onView:view => { visible = view.visible; near = view.near; if(view.selected < 0) app.focusedShelf = null; syncZones(); }
    });

    // Only replace production UI after the WebGL scene constructed successfully.
    doc.querySelector('.dw-radar-room').replaceChildren(map);
    app.radar.append(coordinates);
    if (app.deskCarousel) {
        app.world.append(app.deskCarousel);
        app.deskCarousel.hidden = true;
    }
    if (app.deskFocus) { app.world.append(app.deskFocus); app.deskFocus.hidden = true; }
    app.restoreSpatialImages?.();

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
