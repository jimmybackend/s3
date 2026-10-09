(() => {
  'use strict';

  class ArcadeCloudDrive3D {
    constructor(win, doc) {
      this.window = win;
      this.document = doc;
      this.config = win.ARCADECLOUD_DRIVE3D || {};
      this.useThree = this.config.renderer === 'three';
      this.world = doc.getElementById('dwWorld');
      this.cameraScene = doc.querySelector('[data-dw-camera-scene]');
      this.ring = doc.getElementById('dwShelfRing');
      this.shelves = Array.from(doc.querySelectorAll('.dw-shelf'));
      this.selected = null;
      this.previewCache = new Map();
      this.previewRequests = new Map();
      this.visibleShelves = new Set();
      this.focusedShelf = null;
      this.shelfTemplate = doc.getElementById('dwShelfTemplate') || doc.createElement('template');
      if (!this.shelfTemplate.content.firstElementChild) this.shelfTemplate.innerHTML = this.shelves[0]?.innerHTML || '';
      this.zoneTimer = null;
      this.dragStart = null;
      this.dragMoved = false;
      this.saveTimer = null;
      const savedModel = String(this.config.preferences?.cameraModel || '');
      this.camera = {
        yaw: savedModel === 'player-v3' ? Number(this.config.preferences?.cameraYaw || 0) : 0,
        pitch: savedModel === 'player-v3' ? Number(this.config.preferences?.cameraPitch || 0) : 0,
        lateral: savedModel === 'player-v3' ? Number(this.config.preferences?.cameraLateral || 0) : 0,
        forward: savedModel === 'player-v3' ? Number(this.config.preferences?.cameraForward || 0) : 0,
        distance: 0,
        target: savedModel === 'player-v3' ? String(this.config.preferences?.cameraTarget || '') : '',
        model: 'player-v3',
      };
      this.room = {
        environment: String(this.config.preferences?.environment || 'future'),
        glassBackground: String(this.config.preferences?.glassBackground || ''),
        floorBackground: String(this.config.preferences?.floorBackground || ''),
        ceilingBackground: String(this.config.preferences?.ceilingBackground || ''),
        furniturePreset: String(this.config.preferences?.furniturePreset || 'default'),
        windowPreset: String(this.config.preferences?.windowPreset || 'panoramic'),
        plantsPreset: String(this.config.preferences?.plantsPreset || 'orchids'),
      };
      this.hud = {
        name: doc.querySelector('[data-hud-name]'),
        type: doc.querySelector('[data-hud-type]'),
        format: doc.querySelector('[data-hud-format]'),
        size: doc.querySelector('[data-hud-size]'),
        date: doc.querySelector('[data-hud-date]'),
        path: doc.querySelector('[data-hud-path]'),
        counts: doc.querySelector('[data-hud-counts]'),
        open: doc.querySelector('[data-hud-open]'),
        desk: doc.querySelector('[data-hud-desk]'),
        play: doc.querySelector('[data-hud-play]'),
        download: doc.querySelector('[data-hud-download]'),
        previewIcon: doc.querySelector('[data-hud-preview-icon]'),
        previewImage: doc.querySelector('[data-hud-preview-image]'),
        previewLabel: doc.querySelector('[data-hud-preview-label]'),
      };
      this.deskFocus = doc.querySelector('[data-dw-desk-focus]');
      this.deskIcon = doc.querySelector('[data-dw-desk-icon]');
      this.deskImage = doc.querySelector('[data-dw-desk-image]');
      this.deskName = doc.querySelector('[data-dw-desk-name]');
      this.deskMeta = doc.querySelector('[data-dw-desk-meta]');
      this.deskFiles = doc.querySelector('[data-dw-current-files]');
      this.deskCarousel = doc.querySelector('[data-dw-desk-carousel]');
      this.deskCarouselTitle = doc.querySelector('[data-desk-carousel-title]');
      this.deskCarouselCount = doc.querySelector('[data-desk-carousel-count]');
      this.deskPrev = doc.querySelector('[data-desk-prev]');
      this.deskNext = doc.querySelector('[data-desk-next]');
      this.deskProjection = null;
      this.deskCurrentFolderOpen = false;
      this.deskCarouselPage = 0;
      this.mediaStage = doc.querySelector('[data-dw-media-stage]');
      this.mediaContent = doc.querySelector('[data-dw-media-content]');
      this.mediaTitle = doc.querySelector('[data-dw-media-title]');
      this.spatialActions = doc.querySelector('[data-spatial-actions]');
      this.spatialDragHandle = doc.querySelector('[data-spatial-drag-handle]');
      this.spatialDrag = null;
      this.spatialPictureLayer = doc.querySelector('[data-dw-spatial-picture-layer]');
      this.spatialPictures = new Map();
      this.spatialPictureState = this.normalizeSpatialImagePreferences(this.config.preferences?.spatialImages);
      this.spatialPictureZ = 80;
      this.preDomeCamera = null;
      this.environmentPanel = doc.querySelector('[data-dw-environment-panel]');
      this.glassImage = doc.querySelector('[data-dw-glass-image]');
      this.domeImage = doc.querySelector('[data-dw-dome-image]');
      this.floorImage = doc.querySelector('[data-dw-floor-image]');
      this.edgeLamps = Array.from(doc.querySelectorAll('[data-dw-edge-lamp]'));
      this.environmentSelected = doc.querySelector('[data-environment-selected]');
      this.backgroundGallery = doc.querySelector('[data-background-gallery]');
      this.radar = doc.querySelector('[data-dw-radar]');
      this.radarView = doc.querySelector('[data-radar-view]');
      this.radarPoints = doc.querySelector('[data-radar-points]');
      this.radarPlayer = doc.querySelector('.dw-radar-center');
      this.pitchRange = doc.querySelector('[data-camera-pitch-range]');
      this.floorNav = doc.querySelector('[data-dw-floor-nav]');
      this.backgroundChoice = null;
    }

    normalizeSpatialImagePreferences(value) {
      if (!Array.isArray(value)) return [];
      return value.map((entry) => {
        const world = Array.isArray(entry?.world) && entry.world.length === 3
          ? entry.world.map(Number)
          : null;
        if (!entry || !entry.id || !entry.openHref || !world || world.some((number) => !Number.isFinite(number))) return null;
        const size = Array.isArray(entry?.size) && entry.size.length === 2
          ? entry.size.map(Number)
          : null;
        return {
          id:String(entry.id),
          name:String(entry.name || 'Imagen'),
          path:String(entry.path || ''),
          openHref:String(entry.openHref),
          world,
          size:size && size.every((number) => Number.isFinite(number)) ? size : null,
          mode:['floor','ceiling','window'].includes(entry.mode) ? entry.mode : 'free',
          panelId:/^[wc]-\d{1,2}-\d$/.test(String(entry.panelId || '')) ? String(entry.panelId) : '',
          surfaceScale:this.clamp(Number(entry.surfaceScale) || .70,.30,2.6),
        };
      }).filter(Boolean);
    }

    spatialImageId(item) {
      const source = String(item?.path || item?.openHref || item?.name || 'image');
      let hash = 2166136261;
      for (let i=0;i<source.length;i++) {
        hash ^= source.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
      }
      return 'img_' + (hash >>> 0).toString(36);
    }

    init() {
      if (this.useThree) {
        this.bindControls();
        import('./drive3d-production.js').then(module => {
          module.Drive3DProduction.start(this);
        }).catch(error => {
          this.activateCompatible3DFallback(error);
        });
        return this;
      }
      this.startCompatible3D(false);
      return this;
    }

    startCompatible3D(bindControls = true) {
      this.assignWorldAngles();
      this.layoutFixedShelves(false);
      if (bindControls) this.bindControls();
      this.applyRoomPreferences();
      this.renderRadar();
      this.renderCamera(false);
      const target = this.findShelfByName(this.camera.target) || this.frontShelf();
      if (target) this.selectShelf(target, false);
    }

    activateCompatible3DFallback(error) {
      const detail = error?.message || String(error || 'Error desconocido');
      console.error('Drive 3D startup; activating compatible renderer', error);

      this.useThree = false;
      this.three = null;
      this.document.body.classList.remove('dw-real');
      this.document.body.classList.add('dw-compatible');

      const viewport = this.document.getElementById('dwThreeViewport');
      if (viewport) {
        viewport.replaceChildren();
        viewport.hidden = true;
      }

      const content = this.document.querySelector('[data-three-content]');
      if (content) content.hidden = true;

      const status = this.document.querySelector('[data-three-status]');
      if (status) {
        status.hidden = true;
        status.textContent = '';
        status.title = detail;
      }

      this.startCompatible3D(false);

      const notice = this.document.createElement('div');
      notice.className = 'dw-renderer-notice';
      notice.setAttribute('role', 'status');
      notice.textContent = 'Vista 3D compatible activada automáticamente.';
      this.world?.append(notice);
      this.window.setTimeout(() => notice.remove(), 4200);
    }

    assignWorldAngles() {
      const count = Math.max(1, this.shelves.length);
      const center = (count - 1) / 2;

      this.shelves.forEach((shelf, index) => {
        const slot = index - center;
        shelf.dataset.worldSlot = String(slot);
      });
    }

    layoutFixedShelves(animate = false) {
      if (this.useThree) return;
      const width = this.world?.clientWidth || this.window.innerWidth;
      const compact = width < 620;
      const tablet = width < 1000;
      const shelfWidth = this.shelves[0]?.offsetWidth || (compact ? 206 : tablet ? 232 : 252);

      // Same world angles for scene and minimap: distributed around the room.
      const gap = 1;
      const tangentWidth = shelfWidth + gap;
      const stepDegrees = 360 / Math.max(8, this.shelves.length);
      const stepRad = stepDegrees * Math.PI / 180;
      const radius = Math.max(360, tangentWidth / (2 * Math.tan(stepRad / 2)) + 40);
      const center = Math.floor(this.shelves.length / 2);

      this.shelfLayout = { shelfWidth, gap, tangentWidth, radius, stepRad, stepDegrees };
      if (this.ring) this.ring.style.perspective = `${radius}px`;

      this.shelves.forEach((shelf, index) => {
        const slot = index - center;
        shelf.dataset.worldSlot = String(slot);
        shelf.dataset.worldAngle = String(slot * stepDegrees);
        shelf.classList.toggle('is-row-first', index === 0);
        shelf.classList.toggle('is-row-last', index === this.shelves.length - 1);
      });

      this.positionShelvesForCamera(this.camera?.yaw || 0, animate);
    }

    positionShelvesForCamera(yaw, animate = false) {
      const radius = this.shelfLayout?.radius || 7600;
      const stepDegrees = this.shelfLayout?.stepDegrees || 2;
      const firstAngle = Number(this.shelves[0]?.dataset.worldAngle || 0);
      const lastAngle = Number(this.shelves[this.shelves.length - 1]?.dataset.worldAngle || 0);
      const edgeWindow = Math.max(stepDegrees * .72, .85);
      const showLeftEdge = this.shelves.length > 0 && yaw <= firstAngle + edgeWindow;
      const showRightEdge = this.shelves.length > 0 && yaw >= lastAngle - edgeWindow;

      this.shelves.forEach((shelf, index) => {
        const worldAngle = Number(shelf.dataset.worldAngle || 0);
        const relative = this.normalizeAngle(worldAngle - yaw);
        const theta = relative * Math.PI / 180;

        // La cámara recorre la pared; los muebles no se ladean. Así el frente
        // sigue siendo un rectángulo completo y el volumen sólo aparece al final.
        const x = Math.sin(theta) * radius;
        const z = (1 - Math.cos(theta)) * radius;
        const shelfYaw = -relative;

        shelf.style.transitionDuration = animate ? '' : '0ms';
        shelf.style.transform = `translate3d(${x}px,0,${z}px) rotateY(${shelfYaw}deg)`;
        shelf.style.opacity = '1';
        shelf.style.pointerEvents = '';
        shelf.style.zIndex = String(Math.max(20, 220 - Math.round(Math.abs(relative) * 2)));

        shelf.classList.toggle('is-edge-visible-left', index === 0 && showLeftEdge);
        shelf.classList.toggle('is-edge-visible-right', index === this.shelves.length - 1 && showRightEdge);
        shelf.dataset.viewAngle = String(relative.toFixed(2));
        shelf.dataset.worldX = String(x);
        shelf.dataset.worldZ = String(z);
        shelf.dataset.worldYaw = String(shelfYaw);
      });

      this.positionEndMarkersForCamera(yaw, showLeftEdge, showRightEdge);
      this.updateVisibleZones();
    }

    mountShelf(shelf) {
      if (!shelf.firstElementChild) {
        shelf.append(this.shelfTemplate.content.cloneNode(true));
        const title = shelf.querySelector('.dw-shelf-crown strong');
        if (title) title.textContent = shelf.dataset.itemName || 'Carpeta';
        const counts = shelf.querySelector('[data-preview-counts]');
        if (counts) counts.textContent = 'Acércate para explorar';
      }
      if (!shelf.isConnected) this.ring.append(shelf);
    }

    releaseShelf(shelf) {
      const href = shelf.dataset.previewHref;
      const pending = this.previewRequests.get(href);
      if (pending) { pending.abort(); this.previewRequests.delete(href); }
      this.previewCache.delete(href);
      shelf.remove();
      shelf.replaceChildren();
      shelf.classList.remove('is-loading-preview');
      shelf.dataset.lod = 'unloaded';
    }

    updateVisibleZones() {
      clearTimeout(this.zoneTimer);
      const viewportWidth = this.world?.clientWidth || this.window.innerWidth;
      const radius = this.shelfLayout?.radius || 360;
      const lateral = this.camera.lateral * (viewportWidth < 620 ? 120 : viewportWidth < 1000 ? 180 : 240);
      const candidates = this.shelves.filter(s => {
        const angle = Number(s.dataset.viewAngle) * Math.PI / 180;
        if (Math.abs(angle) >= 85 * Math.PI / 180) return false;
        const projectedX = Math.tan(angle) * radius - lateral;
        return Math.abs(projectedX) < viewportWidth / 2 + (this.shelfLayout?.shelfWidth || 252);
      })
        .sort((a,b) => Math.abs(Number(a.dataset.viewAngle)) - Math.abs(Number(b.dataset.viewAngle)));
      // Hard resource budget independent of the number of folders in the room.
      this.visibleShelves = new Set(candidates.slice(0, 7));
      const detail = this.camera.forward >= .45 ? candidates.slice(0, 3) : [];
      if (this.focusedShelf && this.visibleShelves.has(this.focusedShelf)) detail.unshift(this.focusedShelf);
      const detailed = new Set(detail.slice(0, 3));
      if (!detailed.size) this.deskFiles?.replaceChildren();
      this.shelves.forEach(shelf => {
        if (!this.visibleShelves.has(shelf)) { this.releaseShelf(shelf); return; }
        this.mountShelf(shelf);
        if (!detailed.has(shelf) && shelf.dataset.lod === 'detail') {
          this.releaseShelf(shelf); this.mountShelf(shelf);
        }
        shelf.dataset.lod = detailed.has(shelf) ? 'detail' : 'overview';
      });
      if (this.selected?.element && !this.selected.element.isConnected) {
        this.selected = null;
        this.deskFiles?.replaceChildren();
        this.hud.previewImage?.removeAttribute('src');
        this.deskImage?.removeAttribute('src');
        this.hud.open.disabled = true; this.hud.play.hidden = true; this.hud.download.hidden = true;
      }
      this.zoneTimer = setTimeout(() => detailed.forEach(shelf => {
        if (this.visibleShelves.has(shelf)) this.loadShelfPreview(shelf, shelf.dataset.previewHref);
      }), 160);
    }

    positionEndMarkersForCamera(yaw, showLeftEdge, showRightEdge) {
      if (!this.edgeLamps?.length || !this.shelves.length) return;
      const radius = this.shelfLayout?.radius || 7600;
      const stepDegrees = this.shelfLayout?.stepDegrees || 2;
      const firstAngle = Number(this.shelves[0]?.dataset.worldAngle || 0);
      const lastAngle = Number(this.shelves[this.shelves.length - 1]?.dataset.worldAngle || 0);

      this.edgeLamps.forEach((lamp) => {
        const side = lamp.dataset.dwEdgeLamp || 'right';
        const worldAngle = side === 'left'
          ? firstAngle - stepDegrees * .92
          : lastAngle + stepDegrees * .92;
        const relative = this.normalizeAngle(worldAngle - yaw);
        const theta = relative * Math.PI / 180;
        const x = Math.sin(theta) * radius;
        const z = (1 - Math.cos(theta)) * radius;
        const visible = side === 'left' ? showLeftEdge : showRightEdge;

        lamp.style.transform = `translate3d(${x}px,0,${z}px)`;
        lamp.classList.toggle('is-visible', visible);
      });
    }

    bindControls() {
      this.document.querySelector('[data-camera-home]')?.addEventListener('click', () => this.centerCamera());
      this.document.querySelector('[data-camera-exit-focus]')?.addEventListener('click', () => {
        if (this.useThree) { this.centerCamera(); return; }
        this.camera.target = '';
        this.camera.yaw = 0;
        this.camera.pitch = 0;
        this.camera.lateral = 0;
        this.camera.forward = 0;
        this.renderCamera();
        this.schedulePreferenceSave();
      });
      this.document.querySelectorAll('[data-camera-pitch]').forEach((button) => {
        button.addEventListener('click', () => this.lookVertical(Number(button.dataset.cameraPitch || 0)));
      });
      this.pitchRange?.addEventListener('input', () => {
        this.camera.pitch = this.clamp(Number(this.pitchRange.value || 0), this.useThree ? -85 : -42, this.useThree ? 85 : 42);
        this.renderCamera(false);
      });
      this.pitchRange?.addEventListener('change', () => this.schedulePreferenceSave());
      this.document.querySelectorAll('[data-camera-strafe]').forEach((button) => {
        button.addEventListener('click', event => { if (!this.useThree || event.detail === 0) this.movePlayer(Number(button.dataset.cameraStrafe || 0) * .16, 0); });
      });
      this.document.querySelectorAll('[data-camera-forward]').forEach((button) => {
        button.addEventListener('click', event => { if (!this.useThree || event.detail === 0) this.movePlayer(0, Number(button.dataset.cameraForward || 0) * .14); });
      });
      this.floorNav?.addEventListener('click', (event) => this.navigateByFloorTap(event));

      this.hud.open?.addEventListener('click', () => this.openSelected());
      this.hud.desk?.addEventListener('click', () => this.bringToDesk());
      this.hud.play?.addEventListener('click', () => this.playSelected());
      this.deskCarousel?.addEventListener('click', (event) => {
        const previous = event.target.closest('[data-desk-prev]');
        const next = event.target.closest('[data-desk-next]');
        if (!previous && !next) return;
        event.preventDefault();
        event.stopPropagation();
        this.scrollDeskCarousel(next ? 1 : -1);
      });
      this.deskFiles?.addEventListener('wheel', (event) => {
        if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
        event.preventDefault();
        this.scrollDeskCarousel(event.deltaY > 0 ? 1 : -1);
      }, {passive:false});
      this.window.addEventListener('resize', () => {
        if (!this.deskCarousel?.hidden) this.renderDeskCarouselPage();
      });
      this.document.querySelector('[data-dw-media-close]')?.addEventListener('click', () => this.closeMedia());
      this.document.querySelector('[data-spatial-front]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        this.three?.placeSpatialMedia?.();
      });
      this.spatialDragHandle?.addEventListener('pointerdown', (event) => {
        if (!this.useThree || this.mediaStage?.hidden || event.target.closest('button,a,input')) return;
        this.spatialDrag = {id:event.pointerId,x:event.clientX,y:event.clientY};
        this.spatialDragHandle.setPointerCapture?.(event.pointerId);
        event.preventDefault();
      });
      this.spatialDragHandle?.addEventListener('pointermove', (event) => {
        if (!this.spatialDrag || this.spatialDrag.id !== event.pointerId) return;
        const dx = event.clientX - this.spatialDrag.x;
        const dy = event.clientY - this.spatialDrag.y;
        this.spatialDrag.x = event.clientX; this.spatialDrag.y = event.clientY;
        this.three?.moveSpatialMedia?.(dx,dy);
      });
      for (const eventName of ['pointerup','pointercancel','lostpointercapture']) {
        this.spatialDragHandle?.addEventListener(eventName, (event) => {
          if (this.spatialDrag?.id === event.pointerId) this.spatialDrag = null;
        });
      }
      this.document.querySelector('[data-dw-fullscreen]')?.addEventListener('click', () => this.toggleFullscreen());
      this.window.addEventListener('pagehide', () => this.persistPreferences());

      this.document.querySelector('[data-dw-environment]')?.addEventListener('click', () => {
        if (this.environmentPanel) this.environmentPanel.hidden = !this.environmentPanel.hidden;
      });
      this.document.querySelector('[data-dw-environment-close]')?.addEventListener('click', () => {
        if (this.environmentPanel) this.environmentPanel.hidden = true;
      });
      this.document.querySelector('[data-environment-reset-glass]')?.addEventListener('click', () => {
        this.restoreDefaultGlassBackground();
      });

      this.backgroundGallery?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-background-option]');
        if (!button) return;
        this.chooseBackground(button.dataset.backgroundPath || '', button.dataset.backgroundEnvironment || '', button);
      });
      this.document.querySelectorAll('[data-environment-use]').forEach((button) => {
        button.addEventListener('click', () => this.useChosenBackground(button.dataset.environmentUse || ''));
      });
      this.document.querySelector('[data-background-upload]')?.addEventListener('change', (event) => {
        const file = event.target.files?.[0];
        if (file) this.uploadBackground(file, event.target);
      });

      this.document.querySelector('[data-furniture-preset]')?.addEventListener('change', (event) => {
        this.room.furniturePreset = event.target.value || 'default';
        this.persistPreferences();
      });
      this.document.querySelector('[data-window-preset]')?.addEventListener('change', (event) => {
        this.room.windowPreset = event.target.value || 'panoramic';
        this.persistPreferences();
      });
      this.document.querySelector('[data-plants-preset]')?.addEventListener('change', (event) => {
        this.room.plantsPreset = event.target.value || 'orchids';
        this.applyRoomPreferences();
        this.persistPreferences();
      });

      this.ring?.addEventListener('click', (event) => {
        if (this.dragMoved) return;
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        if (item.classList.contains('dw-shelf')) {
          this.focusShelf(item);
          return;
        }
        this.selectElement(item);
      });

      this.ring?.addEventListener('dblclick', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item);
        this.openSelected();
      });

      this.deskFiles?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item);
      });
      this.deskFiles?.addEventListener('dblclick', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item);
        this.openSelected();
      });

      this.world?.addEventListener('pointerdown', (event) => {
        if (this.useThree) return;
        if (event.target.closest('button,a,input,select,.dw-hud,.dw-desk,.dw-environment-panel,.dw-media-stage,.dw-spatial-picture-window,.dw-radar')) return;
        this.dragMoved = false;
        this.dragStart = { x:event.clientX, y:event.clientY, yaw:this.camera.yaw, pitch:this.camera.pitch };
        this.world.setPointerCapture?.(event.pointerId);
      });
      this.world?.addEventListener('pointermove', (event) => {
        if (!this.dragStart) return;
        const dx = event.clientX - this.dragStart.x;
        const dy = event.clientY - this.dragStart.y;
        if (Math.abs(dx) + Math.abs(dy) > 7) this.dragMoved = true;
        if (!this.dragMoved) return;
        this.camera.yaw = this.normalizeAngle(this.dragStart.yaw - dx * .18);
        this.camera.pitch = this.clamp(this.dragStart.pitch + dy * .13, -42, 42);
        this.renderCamera(false);
      });
      const finishLookGesture = () => {
        if (!this.dragStart) return;
        this.dragStart = null;
        if (this.dragMoved) this.schedulePreferenceSave();
        this.window.setTimeout(() => { this.dragMoved = false; }, 0);
      };
      this.world?.addEventListener('pointerup', finishLookGesture);
      this.world?.addEventListener('pointercancel', finishLookGesture);

      this.radar?.addEventListener('click', (event) => {
        if (event.target.closest('button,input,label,.dw-radar-look-controls,.dw-radar-actions,.dw-radar-move-controls')) return;
        if (this.useThree) return;
        const rect = this.radar.querySelector('.dw-radar-room')?.getBoundingClientRect();
        if (!rect) return;
        const x = event.clientX - rect.left - rect.width / 2;
        const y = event.clientY - rect.top - rect.height / 2;
        this.camera.yaw = this.normalizeAngle(Math.atan2(x, -y) * 180 / Math.PI);
        const vertical = (y / Math.max(1, rect.height / 2));
        this.camera.pitch = this.clamp(vertical * 34, -34, 34);
        this.renderCamera();
        this.schedulePreferenceSave();
      });

      this.document.addEventListener('keydown', (event) => {
        if (event.target.matches('input,textarea,select')) return;
        if (this.useThree && event.key.startsWith('Arrow')) return;
        if (event.key === 'ArrowLeft') { event.preventDefault(); this.turnCamera(-10); }
        if (event.key === 'ArrowRight') { event.preventDefault(); this.turnCamera(10); }
        if (event.key === 'ArrowUp') { event.preventDefault(); this.lookVertical(-5); }
        if (event.key === 'ArrowDown') { event.preventDefault(); this.lookVertical(5); }
        if (event.key === 'Home') { event.preventDefault(); this.centerCamera(); }
        if (event.key === 'Enter' && this.selected) { event.preventDefault(); this.openSelected(); }
        if (event.key === 'Escape') {
          if (this.mediaStage && !this.mediaStage.hidden) { this.closeMedia(); return; }
          const parent = this.config.parentHref || '';
          this.window.location.href = parent || 'so.php';
        }
      });

      this.window.addEventListener('resize', () => {
        this.layoutFixedShelves(false);
        this.renderCamera(false);
        this.renderRadar();
      });
    }

    normalizeAngle(value) {
      let angle = Number(value) || 0;
      while (angle > 180) angle -= 360;
      while (angle < -180) angle += 360;
      return angle;
    }

    clamp(value, min, max) {
      return Math.max(min, Math.min(max, Number(value) || 0));
    }

    turnCamera(delta) {
      this.camera.yaw = this.normalizeAngle(this.camera.yaw + delta);
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    lookVertical(delta) {
      this.camera.pitch = this.clamp(this.camera.pitch + delta, this.useThree ? -85 : -42, this.useThree ? 85 : 42);
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    centerCamera() {
      if (this.useThree) {
        this.focusedShelf = null;
        this.clearThreeFocusedGallery?.();
        this.three?.home();
        return;
      }
      this.camera.yaw = 0;
      this.camera.pitch = 0;
      this.camera.lateral = 0;
      this.camera.forward = 0;
      this.camera.distance = 0;
      this.camera.target = '';
      this.focusedShelf = null;
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    movePlayer(lateralDelta, forwardDelta) {
      if (this.useThree) { this.three?.move(lateralDelta * 5, forwardDelta * 5); return; }
      this.camera.lateral = this.clamp(this.camera.lateral + lateralDelta, -1, 1);
      this.camera.forward = this.clamp(this.camera.forward + forwardDelta, 0, 1);
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    navigateByFloorTap(event) {
      if (this.dragMoved) return;
      const rect = this.floorNav?.getBoundingClientRect();
      if (!rect || rect.width <= 0 || rect.height <= 0) return;
      const nx = this.clamp((event.clientX - rect.left) / rect.width, 0, 1);
      const ny = this.clamp((event.clientY - rect.top) / rect.height, 0, 1);
      const lateralDelta = (nx - .5) * .34;
      const forwardDelta = (.62 - ny) * .22;
      this.movePlayer(lateralDelta, forwardDelta);
    }

    renderCamera(animate = true) {
      if (this.useThree) { this.three?.look(this.camera.yaw, this.camera.pitch); return; }
      const yaw = this.normalizeAngle(this.camera.yaw);
      const pitch = this.clamp(this.camera.pitch, -42, 42);
      const width = this.world?.clientWidth || this.window.innerWidth;
      const compact = width < 620;
      const tablet = width < 1000;
      const lateralPx = this.camera.lateral * (compact ? 120 : tablet ? 180 : 240);
      const forwardScale = 1 + this.camera.forward * (compact ? .10 : .13);
      const pitchPan = pitch * (compact ? 4.0 : tablet ? 4.8 : 5.4);

      if (this.cameraScene) {
        this.cameraScene.style.transitionDuration = animate ? '' : '0ms';
        this.cameraScene.style.transform = `translate3d(${-lateralPx}px,${pitchPan}px,0) scale(${forwardScale})`;
      }

      // Girar la mirada no arrastra los muebles por la pantalla. Se recalcula
      // su posición relativa a la cámara dentro de la misma pared circular.
      this.positionShelvesForCamera(yaw, false);

      const stepDegrees = this.shelfLayout?.stepDegrees || 3;
      this.shelves.forEach((shelf) => {
        const relative = Number(shelf.dataset.viewAngle || 0);
        shelf.classList.toggle('is-looked-at', Math.abs(relative) < stepDegrees * .48);
      });

      if (this.pitchRange) this.pitchRange.value = String(Math.round(pitch));
      if (this.world) {
        this.world.style.setProperty('--dw-camera-pitch', `${pitch}deg`);
        this.world.style.setProperty('--dw-camera-yaw', String(yaw));

        // El paisaje vive detrás del cristal como una envoltura de 360 grados.
        // Al girar la mirada se revela otra porción del panorama, mientras las
        // divisiones estructurales del domo permanecen delante del paisaje.
        const panoramaShift = -(yaw / 180) * Math.max(1, width);
        this.world.style.setProperty('--dw-panorama-shift', `${panoramaShift}px`);
      }
      this.updateRadarView();
      this.updateRadarPlayer();
    }

    updateRadarPlayer() {
      if (this.useThree) return;
      if (!this.radarPlayer) return;
      this.radarPlayer.style.left = `${50 + this.camera.lateral * 24}%`;
      this.radarPlayer.style.top = `${52 - this.camera.forward * 24}%`;
    }

    frontShelf() {
      return this.shelves
        .map((shelf) => ({ shelf, diff:Math.abs(this.normalizeAngle(Number(shelf.dataset.worldAngle || 0) - this.camera.yaw)) }))
        .sort((a,b) => a.diff - b.diff)[0]?.shelf || null;
    }

    findShelfByName(name) {
      const wanted = String(name || '').trim();
      if (!wanted) return null;
      return this.shelves.find((shelf) => shelf.dataset.itemName === wanted) || null;
    }

    focusShelf(shelf) {
      if (this.useThree) { this.chooseThreeShelf?.(this.shelves.indexOf(shelf)); return; }
      this.focusedShelf = shelf;
      this.camera.forward = Math.max(.5, this.camera.forward);
      const worldAngle = Number(shelf.dataset.worldAngle || 0);
      this.camera.yaw = this.normalizeAngle(worldAngle);
      this.camera.target = shelf.dataset.itemName || '';
      this.renderCamera();
      this.selectShelf(shelf, true);
      this.schedulePreferenceSave();
    }

    selectShelf(shelf, load = true) {
      this.shelves.forEach((item) => item.classList.toggle('is-active', item === shelf));
      this.selectElement(shelf, { loadPreview:load });
    }

    selectElement(element, options = {}) {
      if (!element) return;
      if (!element.classList.contains('dw-shelf')) {
        this.document.querySelectorAll('[data-dw-item].is-active:not(.dw-shelf)').forEach((el) => el.classList.remove('is-active'));
        element.classList.add('is-active');
      }
      this.selected = this.itemFromElement(element);
      this.updateHud(this.selected);
      this.renderDeskPreview(this.selected, false);
      if (options.loadPreview && this.selected.previewHref) this.loadShelfPreview(element, this.selected.previewHref);
    }

    itemFromElement(element) {
      const d = element.dataset;
      return {
        element,
        type:d.itemType || 'file',
        kind:d.itemKind || (d.itemType === 'folder' ? 'folder' : 'file'),
        name:d.itemName || 'Elemento',
        path:d.itemPath || '',
        size:d.itemSize || '—',
        date:d.itemDate || '—',
        format:d.itemFormat || (d.itemType === 'folder' ? 'CARPETA' : '—'),
        key:d.itemKey || '',
        mime:d.itemMime || '',
        route:d.itemRoute || '',
        openHref:d.openHref || '',
        downloadHref:d.downloadHref || '',
        previewHref:d.previewHref || '',
        thumbHref:d.itemThumb || '',
        environmentHref:d.itemEnvironment || '',
        locked:d.itemLocked === '1',
      };
    }


    updateHud(item, state = null) {
      if (!item) return;
      const isFolder = item.type === 'folder';
      this.hud.name.textContent = item.name;
      this.hud.type.textContent = isFolder ? 'Carpeta' : this.kindLabel(item.kind);
      this.hud.format.textContent = isFolder ? 'CARPETA' : (item.format || '—');
      this.hud.size.textContent = state?.folder_bytes || item.size || '—';
      this.hud.date.textContent = state?.latest_date || item.date || '—';
      this.hud.path.textContent = item.path || 'Mi Drive/';
      this.hud.counts.textContent = state
        ? `${state.folder_count || 0} carpetas · ${state.file_count || 0} archivos`
        : (isFolder ? 'Acércate para explorar' : 'Archivo');

      const canShowThumb = item.kind === 'image' && Boolean(item.thumbHref) && !item.locked;
      if (this.hud.previewIcon && this.hud.previewImage) {
        this.hud.previewIcon.className = `fas ${this.iconFor(item.kind)}`;
        this.hud.previewIcon.hidden = canShowThumb;
        this.hud.previewImage.hidden = !canShowThumb;
        if (canShowThumb) {
          this.hud.previewImage.alt = item.name;
          this.hud.previewImage.src = item.thumbHref;
          this.hud.previewImage.onerror = () => {
            this.hud.previewImage.hidden = true;
            this.hud.previewIcon.hidden = false;
          };
        } else {
          this.hud.previewImage.removeAttribute('src');
        }
      }
      if (this.hud.previewLabel) this.hud.previewLabel.textContent = isFolder ? 'Carpeta' : this.kindLabel(item.kind);
      this.hud.open.disabled = !item.openHref || item.locked;
      this.hud.open.innerHTML = item.locked
        ? '<i class="fas fa-lock"></i> Protegido'
        : `<i class="fas ${isFolder ? 'fa-folder-open' : 'fa-arrow-up-right-from-square'}"></i> Abrir`;

      if (this.hud.desk) {
        this.hud.desk.hidden = isFolder;
        this.hud.desk.disabled = Boolean(item.locked || !item.openHref);
        this.hud.desk.innerHTML = '<i class="fas fa-hand"></i> Traer al escritorio';
      }

      const playable = !item.locked && ['audio','video'].includes(item.kind) && item.openHref;
      this.hud.play.hidden = !playable;
      if (item.downloadHref && !item.locked) {
        this.hud.download.hidden = false;
        this.hud.download.href = item.downloadHref;
      } else {
        this.hud.download.hidden = true;
        this.hud.download.removeAttribute('href');
      }
    }

    kindLabel(kind) {
      return ({image:'Imagen',audio:'Audio',video:'Video',pdf:'PDF',document:'Documento',folder:'Carpeta',file:'Archivo'})[kind] || 'Archivo';
    }

    async loadShelfPreview(shelf, href) {
      if (!shelf || !href || !this.visibleShelves.has(shelf) || shelf.dataset.lod !== 'detail') return;
      if (this.previewRequests.has(href)) return;
      const controller = new AbortController();
      this.previewRequests.set(href, controller);
      shelf.classList.add('is-loading-preview');
      try {
        let state = this.previewCache.get(href);
        if (!state) {
          const response = await fetch(href, {signal:controller.signal, credentials:'same-origin',headers:{Accept:'application/json'}});
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          if (!payload?.ok || !payload?.state) throw new Error('Respuesta inválida');
          state = payload.state;
          if (controller.signal.aborted || !this.visibleShelves.has(shelf) || shelf.dataset.lod !== 'detail') return;
          this.previewCache.set(href, state);
          while (this.previewCache.size > 3) this.previewCache.delete(this.previewCache.keys().next().value);
        }
        if (controller.signal.aborted || !this.visibleShelves.has(shelf) || shelf.dataset.lod !== 'detail') return;
        if (this.useThree) this.showThreeContents?.(shelf, state);
        else this.renderShelfPreview(shelf, state);
        if (this.selected?.element === shelf) {
          this.updateHud(this.selected, state);
        }
      } catch (_) {
        if (controller.signal.aborted) return;
        const counts = shelf.querySelector('[data-preview-counts]');
        if (counts) counts.textContent = 'No se pudo cargar';
      } finally {
        if (this.previewRequests.get(href) === controller) this.previewRequests.delete(href);
        shelf.classList.remove('is-loading-preview');
      }
    }

    renderShelfPreview(shelf, state) {
      const folderHost = shelf.querySelector('[data-preview-folders]');
      const fileHost = shelf.querySelector('[data-preview-files]');
      const fileHostSecondary = shelf.querySelector('[data-preview-files-secondary]');
      const counts = shelf.querySelector('[data-preview-counts]');

      if (folderHost) {
        folderHost.replaceChildren();
        const folders = Array.isArray(state.folders) ? state.folders.slice(0, 6) : [];
        if (!folders.length) folderHost.append(this.emptyNode('Sin subcarpetas'));
        folders.forEach((item) => folderHost.append(this.bookNode(item, true)));
      }
      if (fileHost) {
        fileHost.replaceChildren();
        const ornament = this.document.createElement('span');
        ornament.className = 'dw-shelf-ornament';
        ornament.setAttribute('aria-hidden', 'true');
        ornament.innerHTML = '<i class="fas fa-folder-tree"></i>';
        fileHost.append(ornament);
      }
      if (fileHostSecondary) {
        fileHostSecondary.replaceChildren();
        const ornament = this.document.createElement('span');
        ornament.className = 'dw-shelf-ornament';
        ornament.setAttribute('aria-hidden', 'true');
        ornament.innerHTML = '<i class="fas fa-box-archive"></i>';
        fileHostSecondary.append(ornament);
      }
      if (counts) {
        counts.textContent = `${state.folder_count || 0} carpetas · entra para ver ${state.file_count || 0} archivos`;
      }
    }

    bookNode(item, folder) {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = `dw-book ${folder ? 'dw-book-large dw-book-folder-real' : 'dw-book-small'} dw-book-real`;
      button.dataset.dwItem = '';
      button.dataset.itemType = folder ? 'folder' : 'file';
      button.dataset.itemKind = item.kind || (folder ? 'folder' : 'file');
      button.dataset.itemName = item.name || 'Elemento';
      button.dataset.itemPath = item.visible_path || '';
      button.dataset.itemSize = item.size || '—';
      button.dataset.itemDate = item.date || '—';
      button.dataset.itemFormat = item.extension || (folder ? 'CARPETA' : '—');
      button.dataset.itemKey = item.media_key || '';
      button.dataset.itemMime = item.mime || '';
      button.dataset.itemRoute = item.media_route || '';
      button.dataset.openHref = item.open_href || '';
      button.dataset.downloadHref = item.download_href || '';
      button.dataset.previewHref = item.preview_href || '';
      button.dataset.itemThumb = item.thumbnail_href || '';
      button.dataset.itemEnvironment = item.environment_href || '';
      button.dataset.itemLocked = item.locked ? '1' : '0';
      button.title = item.name || 'Elemento';

      if (!folder && item.kind === 'image' && item.thumbnail_href) {
        const img = this.document.createElement('img');
        img.src = item.thumbnail_href;
        img.alt = '';
        img.loading = 'lazy';
        img.className = 'dw-book-thumb';
        button.append(img);
      }
      const label = this.document.createElement('span');
      label.textContent = item.name || 'Elemento';
      button.append(label);
      return button;
    }

    renderDeskFileStrip(files, folder = null) {
      if (!this.deskFiles) return;
      this.deskFiles.replaceChildren();
      const visible = Array.isArray(files) ? files : [];
      if (this.deskCarouselTitle) this.deskCarouselTitle.textContent = folder?.name || 'Archivos en el escritorio';
      if (!visible.length) {
        const empty = this.document.createElement('span');
        empty.className = 'dw-desk-empty';
        empty.textContent = 'Sin archivos directos en esta carpeta.';
        this.deskFiles.append(empty);
      } else {
        visible.forEach((item) => this.deskFiles.append(this.deskFileNode(item)));
      }
      this.deskCarouselPage = 0;
      this.deskFiles.scrollLeft = 0;
      if (this.deskCarousel) {
        this.deskCarousel.hidden = false;
        this.deskCarousel.classList.add('is-on-desk');
      }
      this.document.body.classList.add('has-desk-carousel');
      this.applyDeskProjection();
      this.window.requestAnimationFrame(() => this.renderDeskCarouselPage());
    }

    deskFileNode(item) {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'dw-desk-book';
      button.dataset.dwItem = '';
      button.dataset.itemType = 'file';
      button.dataset.itemKind = item.kind || 'file';
      button.dataset.itemName = item.name || 'Archivo';
      button.dataset.itemPath = item.visible_path || '';
      button.dataset.itemSize = item.size || '—';
      button.dataset.itemDate = item.date || '—';
      button.dataset.itemFormat = item.extension || 'ARCHIVO';
      button.dataset.itemKey = item.media_key || '';
      button.dataset.itemMime = item.mime || '';
      button.dataset.itemRoute = item.media_route || '';
      button.dataset.openHref = item.open_href || '';
      button.dataset.downloadHref = item.download_href || '';
      button.dataset.itemThumb = item.thumbnail_href || '';
      button.dataset.itemEnvironment = item.environment_href || '';
      button.dataset.itemLocked = item.locked ? '1' : '0';
      button.title = item.name || 'Archivo';

      const media = this.document.createElement('span');
      media.className = 'dw-desk-book-media';
      if (item.kind === 'image' && item.thumbnail_href && !item.locked) {
        const img = this.document.createElement('img');
        img.src = item.thumbnail_href;
        img.alt = `Miniatura de ${item.name || 'imagen'}`;
        img.loading = 'lazy';
        img.decoding = 'async';
        img.onerror = () => media.replaceChildren(this.fileIconNode(item.kind || 'file'));
        media.append(img);
      } else {
        media.append(this.fileIconNode(item.kind || 'file'));
      }

      const label = this.document.createElement('span');
      label.className = 'dw-desk-book-label';
      label.textContent = item.name || 'Archivo';
      button.append(media,label);
      return button;
    }

    fileIconNode(kind) {
      const icon = this.document.createElement('i');
      icon.className = `fas ${this.iconFor(kind)}`;
      return icon;
    }

    emptyNode(text) {
      const node = this.document.createElement('div');
      node.className = 'dw-empty-message';
      node.textContent = text;
      return node;
    }

    async bringToDesk() {
      const item = this.selected;
      if (!item || item.type === 'folder' || item.locked || !item.openHref) return;

      if (['audio','video'].includes(item.kind)) {
        this.playCloudMedia(item);
        return;
      }
      this.showFileInDome(item);
    }

    async loadCurrentFolderFiles() {
      const baseHref = String(this.config.filesApiHref || '');
      if (!baseHref) return {files:[],file_count:0};
      const files = [];
      let firstState = null;
      let page = 1;
      let pages = 1;
      try {
        do {
          const url = new URL(baseHref, this.window.location.href);
          url.searchParams.set('pagina', String(page));
          const response = await fetch(url.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          if (!payload?.ok || !payload?.state) throw new Error('Respuesta inválida');
          const state = payload.state;
          if (!firstState) firstState = state;
          if (Array.isArray(state.files)) files.push(...state.files);
          pages = Math.max(1, Number(state.file_pages || 1));
          page += 1;
        } while (page <= pages);

        return {...(firstState || {}), files, file_count:files.length};
      } catch (_) {
        return {files:[],file_count:0,error:true};
      }
    }

    hideDeskCarousel() {
      if (this.deskCarousel) {
        this.deskCarousel.hidden = true;
        this.deskCarousel.classList.remove('is-on-desk');
        this.deskCarousel.style.visibility = '';
      }
      this.document.body.classList.remove('has-desk-carousel');
    }

    updateDeskProjection(projection) {
      this.deskProjection = projection && typeof projection === 'object' ? projection : null;
      this.applyDeskProjection();
    }

    applyDeskProjection() {
      if (!this.deskCarousel || this.deskCarousel.hidden) return;
      const projection = this.deskProjection;
      if (!projection || !Number.isFinite(Number(projection.x)) || !Number.isFinite(Number(projection.y))) {
        this.deskCarousel.style.setProperty('--dw-desk-screen-x','50%');
        this.deskCarousel.style.setProperty('--dw-desk-screen-y','74%');
        this.deskCarousel.style.visibility = 'visible';
        return;
      }
      // Keep the carousel available once the user explicitly brought the
      // shelf to the desk. The projected table point can sit just outside the
      // camera frustum while its near edge is still visible, so clamp that
      // projection to the desk area instead of making the carousel disappear.
      const worldWidth = this.world?.clientWidth || this.window.innerWidth;
      const worldHeight = this.world?.clientHeight || this.window.innerHeight;
      const x = this.clamp(Number(projection.x), 130, Math.max(130, worldWidth - 130));
      const y = this.clamp(Number(projection.y) - 18, Math.max(125, worldHeight * .48), Math.max(150, worldHeight - 78));
      this.deskCarousel.style.setProperty('--dw-desk-screen-x',x+'px');
      this.deskCarousel.style.setProperty('--dw-desk-screen-y',y+'px');
      this.deskCarousel.style.visibility = 'visible';
    }

    deskCarouselPageSize() {
      const width = this.world?.clientWidth || this.window.innerWidth;
      if (width <= 480) return 4;
      if (width <= 700) return 5;
      return 6;
    }

    renderDeskCarouselPage() {
      if (!this.deskFiles) return;
      const items = Array.from(this.deskFiles.querySelectorAll('[data-dw-item]'));
      const total = items.length;
      if (!total) {
        if (this.deskCarouselCount) this.deskCarouselCount.textContent = '0 archivos';
        return;
      }

      const pageSize = this.deskCarouselPageSize();
      const pages = Math.max(1, Math.ceil(total / pageSize));
      this.deskCarouselPage = ((Number(this.deskCarouselPage || 0) % pages) + pages) % pages;
      const first = this.deskCarouselPage * pageSize;
      const lastExclusive = Math.min(total, first + pageSize);

      items.forEach((item,index) => {
        item.hidden = index < first || index >= lastExclusive;
      });
      if (this.deskCarouselCount) this.deskCarouselCount.textContent = `${first + 1}–${lastExclusive} de ${total}`;
      if (this.deskPrev) this.deskPrev.title = this.deskCarouselPage === 0 ? 'Ir al final del carrusel' : 'Archivos anteriores';
      if (this.deskNext) this.deskNext.title = this.deskCarouselPage === pages - 1 ? 'Volver al inicio del carrusel' : 'Archivos siguientes';
    }

    updateDeskCarouselCounter() {
      this.renderDeskCarouselPage();
    }

    scrollDeskCarousel(direction) {
      if (!this.deskFiles) return;
      const total = this.deskFiles.querySelectorAll('[data-dw-item]').length;
      if (!total) return;
      const pages = Math.max(1, Math.ceil(total / this.deskCarouselPageSize()));
      this.deskCarouselPage = (this.deskCarouselPage + (direction >= 0 ? 1 : -1) + pages) % pages;
      this.renderDeskCarouselPage();
    }

    renderDeskPreview(item, animate = false) {
      if (!item || !this.deskFocus || !this.deskIcon || !this.deskImage || !this.deskName || !this.deskMeta) return;
      const canShowThumb = item.kind === 'image' && Boolean(item.thumbHref) && !item.locked;
      this.deskIcon.className = `fas ${this.iconFor(item.kind)}`;
      this.deskIcon.hidden = canShowThumb;
      this.deskImage.hidden = !canShowThumb;
      if (canShowThumb) {
        this.deskImage.alt = `Miniatura de ${item.name}`;
        this.deskImage.src = item.thumbHref;
        this.deskImage.onerror = () => {
          this.deskImage.hidden = true;
          this.deskIcon.hidden = false;
        };
      } else {
        this.deskImage.removeAttribute('src');
      }
      this.deskName.textContent = item.name;
      this.deskMeta.textContent = item.type === 'folder' ? (item.path || 'Carpeta') : `${this.kindLabel(item.kind)} · ${item.size || '—'}`;
      this.deskFocus.classList.add('is-live');
      this.deskFocus.classList.toggle('is-image-preview', canShowThumb);
      if (animate) {
        this.deskFocus.animate([{transform:'translateY(10px) scale(.95)',opacity:.35},{transform:'translateY(0) scale(1)',opacity:1}],{duration:360,easing:'cubic-bezier(.2,.8,.2,1)'});
      }
    }

    iconFor(kind) {
      return ({folder:'fa-folder-open',image:'fa-image',audio:'fa-music',video:'fa-film',pdf:'fa-file-pdf',document:'fa-file-lines'})[kind] || 'fa-file';
    }

    renderRadar() {
      if (this.useThree) return;
      if (!this.radarPoints) return;
      this.radarPoints.replaceChildren();
      this.shelves.forEach((shelf) => {
        const angle = Number(shelf.dataset.worldAngle || 0) * Math.PI / 180;
        const dot = this.document.createElement('button');
        dot.type = 'button';
        dot.className = 'dw-radar-point';
        const radarWidth = this.radarPoints.parentElement?.clientWidth || 130;
        const dotSize = Math.max(4, Math.min(12, Math.floor(radarWidth * .78 * Math.PI / Math.max(1, this.shelves.length) * .7)));
        dot.style.setProperty('--dw-dot-size', `${dotSize}px`);
        dot.title = shelf.dataset.itemName || 'Estante';
        dot.style.left = `${50 + Math.sin(angle) * 39}%`;
        dot.style.top = `${50 - Math.cos(angle) * 39}%`;
        dot.addEventListener('click', (event) => {
          event.stopPropagation();
          this.focusShelf(shelf, false);
        });
        this.radarPoints.append(dot);
      });
      this.updateRadarView();
    }

    updateRadarView() {
      if (this.useThree) return;
      if (!this.radarView) return;
      this.radarView.style.transform = `translate(-50%,-92%) rotate(${this.camera.yaw}deg)`;
    }

    chooseBackground(path, environmentUrl, button = null) {
      this.backgroundChoice = {path:String(path || ''),url:String(environmentUrl || '')};
      this.document.querySelectorAll('[data-background-option]').forEach((item) => item.classList.toggle('is-selected', item === button));
      if (this.environmentSelected) this.environmentSelected.textContent = this.backgroundChoice.path || 'Selecciona una miniatura';
      this.document.querySelectorAll('[data-environment-use]').forEach((item) => item.disabled = !this.backgroundChoice.url);
    }

    async uploadBackground(file, input) {
      const form = new FormData();
      form.append('background', file);
      try {
        const response = await fetch(this.config.uploadEndpoint || 'drive3d-background-upload.php', {
          method:'POST',
          credentials:'same-origin',
          headers:{'X-Drive-CSRF':this.config.csrf || ''},
          body:form,
        });
        const payload = await response.json();
        if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'No se pudo subir.');
        const option = this.backgroundOptionNode(payload);
        this.backgroundGallery?.prepend(option);
        this.chooseBackground(payload.visiblePath || `${payload.route || ''}${payload.name || ''}`, payload.environment || '', option);
        if (this.environmentSelected) {
          this.environmentSelected.textContent = `${payload.folder || 'Imagenes/fondos3D/'}${payload.name || ''}`;
        }
      } catch (error) {
        this.window.alert(error?.message || 'No se pudo subir el fondo 3D.');
      } finally {
        if (input) input.value = '';
      }
    }

    backgroundOptionNode(payload) {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'dw-background-option';
      button.dataset.backgroundOption = '';
      button.dataset.backgroundPath = payload.visiblePath || `${payload.route || ''}${payload.name || ''}`;
      button.dataset.backgroundEnvironment = payload.environment || '';
      const img = this.document.createElement('img');
      img.src = payload.thumbnail || '';
      img.alt = payload.name || 'Fondo';
      const span = this.document.createElement('span');
      span.textContent = payload.name || 'Fondo';
      button.append(img,span);
      return button;
    }

    useChosenBackground(surface) {
      if (!this.backgroundChoice?.url || !['glass','floor'].includes(surface)) return;
      if (surface === 'glass') this.room.glassBackground = this.backgroundChoice.path;
      if (surface === 'floor') this.room.floorBackground = this.backgroundChoice.path;
      this.applySurfaceImage(surface, this.backgroundChoice.url);
      this.persistPreferences();
    }

    applySurfaceImage(surface, url) {
      if (this.useThree) { this.three?.surface(surface, url); return; }
      const targets = surface === 'glass'
        ? [this.glassImage, this.domeImage].filter(Boolean)
        : [this.floorImage].filter(Boolean);
      if (!targets.length) return;

      const imageValue = url ? `url("${String(url).replace(/"/g,'%22')}")` : '';
      targets.forEach((target) => {
        target.style.backgroundImage = imageValue;
        target.classList.toggle('is-customized', Boolean(url));
      });
    }

    applyRoomPreferences() {
      this.document.body.dataset.environment = this.room.environment || 'future';
      this.document.body.dataset.furniture = this.room.furniturePreset || 'default';
      this.document.body.dataset.windows = this.room.windowPreset || 'panoramic';
      this.document.body.dataset.plants = this.room.plantsPreset || 'orchids';
      this.applyStoredBackground('glass', this.room.glassBackground);
      this.applyStoredBackground('floor', this.room.floorBackground);

      const furniture = this.document.querySelector('[data-furniture-preset]');
      const windows = this.document.querySelector('[data-window-preset]');
      const plants = this.document.querySelector('[data-plants-preset]');
      if (furniture) furniture.value = this.room.furniturePreset || 'default';
      if (windows) windows.value = this.room.windowPreset || 'panoramic';
      if (plants) plants.value = this.room.plantsPreset || 'orchids';
    }

    applyStoredBackground(surface, path) {
      const normalized = String(path || '');
      if (!normalized) { this.applySurfaceImage(surface,''); return; }
      const match = Array.from(this.document.querySelectorAll('[data-background-option]'))
        .find((button) => button.dataset.backgroundPath === normalized);
      if (match?.dataset.backgroundEnvironment) this.applySurfaceImage(surface, match.dataset.backgroundEnvironment);
    }

    restoreDefaultGlassBackground() {
      // An empty glass URL restores the bundled 360° panorama in Three.js.
      // Preserve floor artwork, positioned pictures, furniture and camera.
      this.room.glassBackground = '';
      if (!this.useThree) {
        this.room.environment = 'future';
        this.document.body.dataset.environment = 'future';
      }
      this.applySurfaceImage('glass', '');
      this.persistPreferences();
    }

    schedulePreferenceSave() {
      this.window.clearTimeout(this.saveTimer);
      this.saveTimer = this.window.setTimeout(() => this.persistPreferences(), 350);
    }

    async persistPreferences() {
      const endpoint = this.config.preferencesEndpoint || 'os-preferences.php';
      const payload = {
        drive3dPreference:{
          environment:this.room.environment,
          glassBackground:this.room.glassBackground,
          floorBackground:this.room.floorBackground,
          ceilingBackground:this.room.ceilingBackground,
          cameraYaw:this.camera.yaw,
          cameraPitch:this.camera.pitch,
          cameraDistance:0,
          cameraLateral:this.camera.lateral,
          cameraForward:this.camera.forward,
          cameraModel:this.camera.model,
          cameraTarget:this.camera.target,
          furniturePreset:this.room.furniturePreset,
          windowPreset:this.room.windowPreset,
          plantsPreset:this.room.plantsPreset,
          spatialImages:this.spatialPictureState.map((entry) => ({
            id:entry.id,
            name:entry.name,
            path:entry.path,
            openHref:entry.openHref,
            world:Array.isArray(entry.world) ? entry.world.map(Number) : [],
            size:Array.isArray(entry.size) ? entry.size.map(Number) : [],
            mode:entry.mode || 'free',
            panelId:entry.panelId || '',
            surfaceScale:Number(entry.surfaceScale) || .70,
          })),
        }
      };
      try {
        await fetch(endpoint,{
          method:'POST',
          credentials:'same-origin',
          keepalive:true,
          headers:{'Content-Type':'application/json','X-CSRF-Token':this.config.csrf || ''},
          body:JSON.stringify(payload),
        });
      } catch (_) {}
    }

    openSelected() {
      const item = this.selected;
      if (!item || item.locked || !item.openHref) return;
      if (item.type === 'folder') {
        this.window.location.href = item.openHref;
        return;
      }
      if (['audio','video'].includes(item.kind)) {
        this.playCloudMedia(item);
        return;
      }
      this.showFileInDome(item);
    }

    playSelected() {
      const item = this.selected;
      if (!item || item.locked || !item.openHref) return;
      if (['audio','video'].includes(item.kind)) {
        this.playCloudMedia(item);
        return;
      }
      this.showFileInDome(item);
    }

    playCloudMedia(item) {
      const player = this.window.ArcadeCloudMediaCloud;
      if (!player?.open) {
        this.showFileInDome(item);
        return;
      }
      const mime = item.mime || (item.kind === 'video' ? 'video/mp4' : 'audio/mpeg');
      player.open({
        key:item.key || '',
        name:item.name || 'Multimedia',
        mime,
        openUrl:item.openHref || '',
        route:item.route || '',
      },{
        type:item.kind === 'video' ? 'video' : 'audio',
        route:item.route || '',
      });
    }

    showFileInDome(item) {
      if (!this.mediaStage || !this.mediaContent) return;
      if (this.useThree && this.three) {
        this.showSpatialFile(item);
        return;
      }

      this.closeMedia(false);
      this.preDomeCamera = {
        yaw:this.camera.yaw,
        pitch:this.camera.pitch,
        distance:this.camera.distance,
        target:this.camera.target,
      };
      this.camera.pitch = -18;
      this.camera.distance = Math.max(.08, Math.min(.32, this.camera.distance));
      this.renderCamera();
      this.document.body.classList.add('is-dome-viewing');
      this.mediaStage.hidden = false;
      if (this.mediaTitle) this.mediaTitle.textContent = item.name || 'Archivo';
      this.mediaContent.replaceChildren(this.createMediaViewer(item, false));
      this.schedulePreferenceSave();
    }

    showSpatialFile(item) {
      if (item?.kind === 'image') {
        this.openSpatialImage(item);
        return;
      }

      this.closeMedia(false);
      this.document.body.classList.add('is-dome-viewing','is-spatial-viewing');
      this.mediaStage.classList.add('is-spatial');
      this.mediaStage.hidden = false;
      this.mediaStage.style.visibility = 'hidden';
      if (this.spatialActions) this.spatialActions.hidden = false;
      if (this.mediaTitle) this.mediaTitle.textContent = item.name || 'Archivo';

      const viewer = this.createMediaViewer(item, true);
      this.mediaContent.replaceChildren(viewer);
      this.fitSpatialViewer(viewer, item);
      this.three.placeSpatialMedia?.();
    }

    createMediaViewer(item, spatial = false) {
      let viewer;
      if (item.kind === 'image') {
        viewer = this.document.createElement('img');
        viewer.className = spatial ? 'dw-spatial-image' : '';
        viewer.alt = item.name;
        viewer.src = item.openHref;
        if (spatial) viewer.addEventListener('load', () => this.fitSpatialViewer(viewer, item), {once:true});
      } else if (item.kind === 'video') {
        viewer = this.document.createElement('video');
        viewer.className = spatial ? 'dw-spatial-video' : '';
        viewer.controls = true;
        viewer.autoplay = true;
        viewer.playsInline = true;
        viewer.preload = 'metadata';
        viewer.src = item.openHref;
        if (spatial) viewer.addEventListener('loadedmetadata', () => this.fitSpatialViewer(viewer, item), {once:true});
      } else if (item.kind === 'audio') {
        const shell = this.document.createElement('div');
        shell.className = spatial ? 'dw-spatial-audio-shell' : 'dw-dome-audio-shell';
        const icon = this.document.createElement('i');
        icon.className = 'fas fa-music';
        const label = this.document.createElement('strong');
        label.textContent = item.name;
        const audio = this.document.createElement('audio');
        audio.controls = true;
        audio.autoplay = true;
        audio.preload = 'metadata';
        audio.src = item.openHref;
        shell.append(icon,label,audio);
        viewer = shell;
      } else if (spatial && this.isTextDocument(item)) {
        viewer = this.document.createElement('div');
        viewer.className = 'dw-spatial-text-shell';
        const pre = this.document.createElement('pre');
        pre.textContent = 'Cargando…';
        const nav = this.document.createElement('div');
        nav.className = 'dw-spatial-text-nav';
        const previous = this.document.createElement('button');
        const counter = this.document.createElement('span');
        const next = this.document.createElement('button');
        previous.type = next.type = 'button';
        previous.textContent = '‹';
        next.textContent = '›';
        nav.append(previous,counter,next);
        viewer.append(pre,nav);
        fetch(item.openHref,{credentials:'same-origin'})
          .then(response => {
            if (!response.ok) throw new Error('HTTP '+response.status);
            return response.text();
          })
          .then(text => {
            const lines = String(text).replace(/\r\n/g,'\n').split('\n');
            const pageSize = 42;
            const pages = [];
            for (let i=0;i<lines.length;i+=pageSize) pages.push(lines.slice(i,i+pageSize).join('\n'));
            if (!pages.length) pages.push('');
            let page = 0;
            const render = () => {
              pre.textContent = pages[page];
              counter.textContent = `${page+1} / ${pages.length}`;
              previous.disabled = page === 0;
              next.disabled = page === pages.length-1;
            };
            previous.addEventListener('click', () => { if (page>0) { page--; render(); } });
            next.addEventListener('click', () => { if (page<pages.length-1) { page++; render(); } });
            render();
          })
          .catch(() => { pre.textContent = 'No se pudo cargar el texto.'; counter.textContent=''; });
      } else {
        viewer = this.document.createElement('iframe');
        viewer.className = spatial ? 'dw-spatial-document-frame' : 'dw-dome-document-frame';
        const fitSuffix = item.kind === 'pdf' ? '#view=Fit&toolbar=0&navpanes=0' : '';
        viewer.src = item.openHref + fitSuffix;
        viewer.title = item.name || 'Documento';
        viewer.setAttribute('loading','eager');
        viewer.setAttribute('referrerpolicy','same-origin');
        if (spatial) viewer.setAttribute('scrolling','no');
      }
      return viewer;
    }

    isTextDocument(item) {
      const format = String(item?.format || '').toUpperCase();
      return ['TXT','MD','MARKDOWN','JSON','XML','HTML','HTM','PHP','JS','CSS','PY','SQL','CSV'].includes(format);
    }

    fitSpatialViewer(viewer, item) {
      if (!this.mediaStage?.classList.contains('is-spatial')) return;
      const maxW = Math.min(this.window.innerWidth * .66, 1080);
      const maxH = Math.min(this.window.innerHeight * .68, 720);
      let ratio = 16/9;
      let bodyW = maxW, bodyH = maxH;

      if (item.kind === 'image' && viewer.naturalWidth && viewer.naturalHeight) {
        ratio = viewer.naturalWidth / viewer.naturalHeight;
      } else if (item.kind === 'video' && viewer.videoWidth && viewer.videoHeight) {
        ratio = viewer.videoWidth / viewer.videoHeight;
      }

      if (['image','video'].includes(item.kind)) {
        bodyW = Math.min(maxW, maxH * ratio);
        bodyH = bodyW / ratio;
        if (bodyH > maxH) { bodyH = maxH; bodyW = bodyH * ratio; }
      } else if (item.kind === 'audio') {
        bodyW = Math.min(560, this.window.innerWidth * .62);
        bodyH = 150;
      } else if (this.isTextDocument(item)) {
        bodyW = Math.min(900, this.window.innerWidth * .66);
        bodyH = Math.min(620, this.window.innerHeight * .66);
      }

      this.mediaStage.style.setProperty('--dw-spatial-width', Math.max(280,bodyW)+'px');
      this.mediaStage.style.setProperty('--dw-spatial-body-height', Math.max(150,bodyH)+'px');
    }

    updateSpatialProjection(projection) {
      const projectionId = String(projection?.id || 'singleton');
      if (projectionId !== 'singleton') {
        const picture = this.spatialPictures.get(projectionId);
        if (!picture?.window) return;
        if (!projection || projection.removed || !projection.visible) {
          picture.window.style.visibility = 'hidden';
          return;
        }
        picture.window.style.visibility = 'visible';
        picture.window.style.left = projection.x+'px';
        picture.window.style.top = projection.y+'px';
        picture.window.style.setProperty('--dw-picture-scale', String(projection.scale || 1));
        if (Array.isArray(projection.world)) {
          this.updateSpatialImageState(projectionId, projection.world, false);
        }
        return;
      }

      if (!this.mediaStage?.classList.contains('is-spatial') || this.mediaStage.hidden) return;
      if (!projection || !projection.visible) {
        this.mediaStage.style.visibility = 'hidden';
        return;
      }
      this.mediaStage.style.visibility = 'visible';
      this.mediaStage.style.left = projection.x+'px';
      this.mediaStage.style.top = projection.y+'px';
      this.mediaStage.style.setProperty('--dw-spatial-scale', String(projection.scale || 1));
      this.mediaStage.dataset.worldPosition = Array.isArray(projection.world) ? projection.world.map(n=>Number(n).toFixed(2)).join(',') : '';
    }

    openSpatialImage(item) {
      if (!this.useThree || !this.three || !this.spatialPictureLayer || !item?.openHref) return;
      const id = this.spatialImageId(item);
      const existing = this.spatialPictures.get(id);
      if (existing?.window) {
        this.bringSpatialPictureToFront(id);
        return;
      }
      const saved = this.spatialPictureState.find((entry) => entry.id === id);
      if (saved) {
        saved.name = String(item.name || saved.name || 'Imagen');
        saved.path = String(item.path || saved.path || '');
        saved.openHref = String(item.openHref || saved.openHref || '');
        this.mountSpatialImage(saved, true);
        this.bringSpatialPictureToFront(id);
        return;
      }

      const entry = {
        id,
        name:String(item.name || 'Imagen'),
        path:String(item.path || ''),
        openHref:String(item.openHref),
        world:null,
        size:null,
        mode:'free',
        panelId:'',
        surfaceScale:.70,
      };
      this.spatialPictureState.push(entry);
      this.mountSpatialImage(entry, false);
    }

    restoreSpatialImages() {
      if (!this.useThree || !this.three || !this.spatialPictureLayer) return;
      this.spatialPictureState.forEach((entry) => this.mountSpatialImage(entry, true));
    }

    mountSpatialImage(entry, restoring = false) {
      if (!entry?.id || !entry.openHref || this.spatialPictures.has(entry.id)) return;
      const win = this.document.createElement('section');
      win.className = 'dw-spatial-picture-window';
      win.dataset.spatialPictureId = entry.id;
      win.style.visibility = 'hidden';
      win.style.zIndex = String(++this.spatialPictureZ);

      const header = this.document.createElement('header');
      header.className = 'dw-spatial-picture-titlebar';
      const icon = this.document.createElement('i');
      icon.className = 'fas fa-image';
      const title = this.document.createElement('strong');
      title.textContent = entry.name || 'Imagen';
      const actions = this.document.createElement('span');
      actions.className = 'dw-spatial-picture-actions';
      const front = this.document.createElement('button');
      front.type = 'button';
      front.title = 'Colocar frente a mí';
      front.setAttribute('aria-label','Colocar frente a mí');
      front.innerHTML = '<i class="fas fa-crosshairs"></i>';
      const resize = this.document.createElement('button');
      resize.type = 'button';
      resize.title = 'Redimensionar cuadro';
      resize.setAttribute('aria-label','Redimensionar cuadro');
      resize.setAttribute('aria-pressed','false');
      resize.innerHTML = '<i class="fas fa-expand"></i>';
      const close = this.document.createElement('button');
      close.type = 'button';
      close.title = 'Quitar cuadro de la sala';
      close.setAttribute('aria-label','Quitar cuadro de la sala');
      close.innerHTML = '<i class="fas fa-xmark"></i>';
      const mode = this.document.createElement('select');
      mode.className = 'dw-spatial-placement-mode';
      mode.setAttribute('aria-label','Colocar imagen en el domo');
      mode.title = 'Elegir dónde colocar esta imagen';
      [
        ['free','Cuadro libre'],
        ['floor','Tapete en piso'],
        ['ceiling','Techo del domo'],
        ['window','Ventana del domo']
      ].forEach(([value,label]) => {
        const option = this.document.createElement('option');
        option.value=value; option.textContent=label; mode.append(option);
      });
      mode.value=entry.mode || 'free';
      const move = this.document.createElement('button');
      move.type='button'; move.title='Cambiar la posición sobre la superficie';
      move.setAttribute('aria-label','Mover imagen a otra posición del domo');
      move.innerHTML='<i class="fas fa-arrows-up-down-left-right"></i>';
      const smaller = this.document.createElement('button');
      smaller.type='button'; smaller.title='Reducir imagen';
      smaller.setAttribute('aria-label','Reducir imagen colocada');
      smaller.textContent='−';
      const bigger = this.document.createElement('button');
      bigger.type='button'; bigger.title='Ampliar imagen';
      bigger.setAttribute('aria-label','Ampliar imagen colocada');
      bigger.textContent='+';
      actions.append(mode,front,move,smaller,bigger,resize,close);
      header.append(icon,title,actions);

      const body = this.document.createElement('div');
      body.className = 'dw-spatial-picture-body';
      const image = this.document.createElement('img');
      image.alt = entry.name || 'Imagen';
      image.src = entry.openHref;
      image.draggable = false;
      body.append(image);
      const resizeHandle = this.document.createElement('button');
      resizeHandle.type = 'button';
      resizeHandle.className = 'dw-spatial-picture-resize-handle';
      resizeHandle.title = 'Jala esta esquina para cambiar el tamaño';
      resizeHandle.setAttribute('aria-label','Jala esta esquina para redimensionar');
      resizeHandle.hidden = true;
      resizeHandle.innerHTML = '<i class="fas fa-up-right-and-down-left-from-center"></i>';
      win.append(header,body,resizeHandle);
      this.spatialPictureLayer.append(win);
      this.spatialPictures.set(entry.id,{window:win,header,image,entry,resize,resizeHandle,mode,move,smaller,bigger,front,autoHideTimer:null});
      this.syncSpatialPictureMode(entry.id);

      image.addEventListener('load', () => {
        this.fitSpatialPicture(win,image,entry);
        if (entry.mode && entry.mode !== 'free') this.three?.setSpatialSurface?.({...entry,aspect:image.naturalWidth/Math.max(1,image.naturalHeight)});
      }, {once:true});
      if (image.complete) this.fitSpatialPicture(win,image,entry);

      win.addEventListener('pointerdown', () => {
        this.bringSpatialPictureToFront(entry.id);
        if (entry.mode && entry.mode !== 'free') this.revealSpatialPictureControls(entry.id);
      });
      mode.addEventListener('change', event => {
        event.stopPropagation();
        this.changeSpatialImageMode(entry.id,mode.value);
      });
      move.addEventListener('click', event => {
        event.stopPropagation();
        this.changeSpatialImageMode(entry.id,entry.mode || 'free');
      });
      smaller.addEventListener('click', event => {
        event.stopPropagation();
        this.resizePlacedImage(entry.id,-.12);
      });
      bigger.addEventListener('click', event => {
        event.stopPropagation();
        this.resizePlacedImage(entry.id,+.12);
      });
      front.addEventListener('click', (event) => {
        event.stopPropagation();
        this.changeSpatialImageMode(entry.id,'free');
      });
      resize.addEventListener('click', (event) => {
        event.stopPropagation();
        const active = !win.classList.contains('is-resizing-enabled');
        win.classList.toggle('is-resizing-enabled', active);
        resize.setAttribute('aria-pressed', active ? 'true' : 'false');
        resizeHandle.hidden = !active;
        if (active) this.bringSpatialPictureToFront(entry.id);
      });
      close.addEventListener('click', (event) => {
        event.stopPropagation();
        this.removeSpatialImage(entry.id);
      });
      this.bindSpatialPictureDrag(entry.id, header);
      this.bindSpatialPictureResize(entry.id, win, image, resizeHandle);

      const world = restoring && Array.isArray(entry.world) ? entry.world : null;
      if (entry.mode && entry.mode !== 'free') {
        const placed = this.three?.setSpatialSurface?.(entry);
        if (Array.isArray(placed)) this.updateSpatialImageState(entry.id, placed, !restoring);
        this.revealSpatialPictureControls(entry.id);
      } else {
        const placed = this.three?.placeSpatialMedia?.(entry.id, world);
        if (Array.isArray(placed)) this.updateSpatialImageState(entry.id, placed, !restoring);
      }
    }

    // Placement is a two-step mobile interaction: choose a surface in the
    // picture header, then touch the exact part of the 3D room to use it.
    showSurfacePlacementHint(message = '') {
      let hint = this.document.querySelector('[data-dw-placement-hint]');
      if (!hint) {
        hint = this.document.createElement('output');
        hint.className = 'dw-placement-hint';
        hint.dataset.dwPlacementHint = '';
        hint.setAttribute('role','status');
        hint.setAttribute('aria-live','polite');
        this.document.body.append(hint);
      }
      hint.textContent = String(message);
      hint.hidden = !message;
    }

    syncSpatialPictureMode(id) {
      const picture = this.spatialPictures.get(id);
      if (!picture) return;
      const mode = ['floor','ceiling','window'].includes(picture.entry.mode) ? picture.entry.mode : 'free';
      const anchored = mode !== 'free';
      picture.window.classList.toggle('is-surface-placed', anchored);
      picture.window.dataset.pictureMode = mode;
      picture.mode.value = mode;
      picture.resize.hidden = anchored;
      picture.front.hidden = !anchored;
      picture.move.hidden = !anchored;
      picture.smaller.hidden = !anchored;
      picture.bigger.hidden = !anchored;
      if (!anchored) {
        this.window.clearTimeout(picture.autoHideTimer);
        picture.window.classList.remove('is-controls-hidden');
      }
      if (anchored) {
        picture.window.classList.remove('is-resizing-enabled');
        picture.resizeHandle.hidden = true;
        picture.resize.setAttribute('aria-pressed','false');
      }
    }

    // Anchored pictures are REAL WebGL textures, so their DOM toolbars
    // should leave the view after five seconds and reappear only when the
    // user taps the actual image mesh again.
    revealSpatialPictureControls(id, autoHide=true) {
      const picture=this.spatialPictures.get(id);
      if(!picture) return;
      this.window.clearTimeout(picture.autoHideTimer);
      picture.window.classList.remove('is-controls-hidden');
      if(autoHide && picture.entry.mode && picture.entry.mode!=='free'){
        picture.autoHideTimer=this.window.setTimeout(()=>{
          if(this.three?.surfacePlacementState?.(id)){
            picture.window.classList.add('is-controls-hidden');
          }
        },5000);
      }
    }

    changeSpatialImageMode(id, mode) {
      const picture = this.spatialPictures.get(id);
      if (!picture) return;
      const next = ['floor','window','ceiling'].includes(mode) ? mode : 'free';
      if (next === 'free') {
        this.three?.cancelSpatialSurface?.();
        this.three?.clearSpatialSurface?.(id);
        picture.entry.mode = 'free';
        picture.entry.panelId = '';
        const world = this.three?.placeSpatialMedia?.(id);
        if (Array.isArray(world)) this.updateSpatialImageState(id,world,true);
        this.syncSpatialPictureMode(id);
        this.showSurfacePlacementHint('');
        this.schedulePreferenceSave();
        return;
      }
      // Keep controls visible while the user is choosing the surface.
      this.revealSpatialPictureControls(id,false);
      this.three?.armSpatialSurface?.(id,next);
      this.showSurfacePlacementHint(next === 'floor'
        ? 'Tapete: toca el lugar del piso donde irá la imagen.'
        : next === 'ceiling'
          ? 'Techo: mira hacia arriba y toca un panel superior del domo.'
          : 'Ventana: toca el cristal exacto entre dos costillas de madera.');
      this.bringSpatialPictureToFront(id);
    }

    onSpatialSurfacePlaced(placement) {
      if (!placement?.id) return;
      const picture = this.spatialPictures.get(placement.id);
      if (!picture) return;
      Object.assign(picture.entry,{
        mode:placement.mode,
        panelId:placement.panelId || '',
        surfaceScale:placement.surfaceScale,
        world:placement.world,
      });
      const image = picture.image;
      const aspect = image?.naturalWidth && image?.naturalHeight ? image.naturalWidth/image.naturalHeight : 4/3;
      const world = this.three?.setSpatialSurface?.({...picture.entry,aspect});
      if (Array.isArray(world)) this.updateSpatialImageState(placement.id,world,false);
      this.syncSpatialPictureMode(placement.id);
      this.revealSpatialPictureControls(placement.id);
      this.showSurfacePlacementHint('');
      this.schedulePreferenceSave();
    }

    resizePlacedImage(id,step) {
      const picture = this.spatialPictures.get(id);
      if (!picture || !['floor','ceiling','window'].includes(picture.entry.mode)) return;
      const maximum = picture.entry.mode === 'floor' ? 2.6 : .94;
      picture.entry.surfaceScale = this.clamp((Number(picture.entry.surfaceScale) || .70)+step,.30,maximum);
      const {image,entry} = picture;
      this.three?.setSpatialSurface?.({
        ...entry,aspect:image.naturalWidth && image.naturalHeight ? image.naturalWidth/image.naturalHeight : 4/3
      });
      this.schedulePreferenceSave();
    }

    onSpatialSurfaceSelected(id) {
      const picture = this.spatialPictures.get(id);
      if (!picture) return;
      this.bringSpatialPictureToFront(id);
      this.revealSpatialPictureControls(id);
      picture.window.classList.add('is-surface-selected');
      this.window.clearTimeout(picture.selectedTimer);
      picture.selectedTimer = this.window.setTimeout(() => picture.window.classList.remove('is-surface-selected'),1200);
    }

    fitSpatialPicture(win, image, entry = null) {
      const saved = Array.isArray(entry?.size) && entry.size.length === 2 ? entry.size.map(Number) : null;
      if (saved && saved.every((value) => Number.isFinite(value))) {
        const width = this.clamp(saved[0], 160, 1200);
        const height = this.clamp(saved[1], 100, 900);
        win.style.setProperty('--dw-picture-width', width+'px');
        win.style.setProperty('--dw-picture-height', height+'px');
        entry.size = [width,height];
        return;
      }

      const ratio = image.naturalWidth && image.naturalHeight ? image.naturalWidth / image.naturalHeight : 4/3;
      const maxW = Math.min(this.window.innerWidth < 700 ? 320 : 520, this.window.innerWidth * .48);
      const maxH = Math.min(this.window.innerHeight * .48, 520);
      let width = maxW;
      let height = width / Math.max(.2, ratio);
      if (height > maxH) {
        height = maxH;
        width = height * ratio;
      }
      width = Math.max(180,width);
      height = Math.max(120,height);
      win.style.setProperty('--dw-picture-width', width+'px');
      win.style.setProperty('--dw-picture-height', height+'px');
      if (entry) entry.size = [width,height];
    }

    bindSpatialPictureResize(id, win, image, handle) {
      let resize = null;
      handle.addEventListener('pointerdown', (event) => {
        if (handle.hidden) return;
        const picture = this.spatialPictures.get(id);
        const entry = picture?.entry;
        if (!entry) return;
        const style = this.window.getComputedStyle(win);
        const width = parseFloat(style.getPropertyValue('--dw-picture-width')) || win.getBoundingClientRect().width;
        const height = parseFloat(style.getPropertyValue('--dw-picture-height')) || Math.max(100, win.getBoundingClientRect().height - 36);
        const aspect = image.naturalWidth && image.naturalHeight
          ? image.naturalWidth / image.naturalHeight
          : Math.max(.2, width / Math.max(1,height));
        const projectionScale = Math.max(.25, parseFloat(style.getPropertyValue('--dw-picture-scale')) || 1);
        resize = {pointerId:event.pointerId,x:event.clientX,y:event.clientY,width,height,aspect,projectionScale};
        handle.setPointerCapture?.(event.pointerId);
        this.bringSpatialPictureToFront(id);
        event.preventDefault();
        event.stopPropagation();
      });
      handle.addEventListener('pointermove', (event) => {
        if (!resize || resize.pointerId !== event.pointerId) return;
        const dx = (event.clientX - resize.x) / resize.projectionScale;
        const dy = (event.clientY - resize.y) / resize.projectionScale;
        const fromX = resize.width + dx;
        const fromY = (resize.height + dy) * resize.aspect;
        let width = Math.abs(fromX - resize.width) >= Math.abs(fromY - resize.width) ? fromX : fromY;
        width = this.clamp(width, 160, 1200);
        let height = width / Math.max(.2, resize.aspect);
        if (height < 100) {
          height = 100;
          width = height * resize.aspect;
        }
        if (height > 900) {
          height = 900;
          width = height * resize.aspect;
        }
        win.style.setProperty('--dw-picture-width', width+'px');
        win.style.setProperty('--dw-picture-height', height+'px');
        const entry = this.spatialPictures.get(id)?.entry;
        if (entry) entry.size = [width,height];
      });
      for (const eventName of ['pointerup','pointercancel','lostpointercapture']) {
        handle.addEventListener(eventName, (event) => {
          if (!resize || resize.pointerId !== event.pointerId) return;
          resize = null;
          this.schedulePreferenceSave();
        });
      }
    }

    bindSpatialPictureDrag(id, handle) {
      let drag = null;
      handle.addEventListener('pointerdown', (event) => {
        // A window/ceiling image belongs to a fixed cell; only 'Mover'
        // can reassign it to another panel. Never drag its HUD away.
        const picture = this.spatialPictures.get(id);
        if (picture?.entry?.mode && picture.entry.mode !== 'free') return;
        if (event.target.closest('button,a,input,select,label')) return;
        drag = {pointerId:event.pointerId,x:event.clientX,y:event.clientY};
        handle.setPointerCapture?.(event.pointerId);
        this.bringSpatialPictureToFront(id);
        event.preventDefault();
        event.stopPropagation();
      });
      handle.addEventListener('pointermove', (event) => {
        if (!drag || drag.pointerId !== event.pointerId) return;
        const dx = event.clientX - drag.x;
        const dy = event.clientY - drag.y;
        drag.x = event.clientX;
        drag.y = event.clientY;
        this.three?.moveSpatialMedia?.(id,dx,dy);
      });
      for (const eventName of ['pointerup','pointercancel','lostpointercapture']) {
        handle.addEventListener(eventName, (event) => {
          if (!drag || drag.pointerId !== event.pointerId) return;
          drag = null;
          const state = this.three?.spatialMediaState?.(id);
          if (Array.isArray(state?.world)) this.updateSpatialImageState(id,state.world,true);
        });
      }
    }

    bringSpatialPictureToFront(id) {
      const picture = this.spatialPictures.get(id);
      if (!picture?.window) return;
      picture.window.style.zIndex = String(++this.spatialPictureZ);
    }

    updateSpatialImageState(id, world, persist = false) {
      if (!Array.isArray(world) || world.length !== 3) return;
      const entry = this.spatialPictureState.find((candidate) => candidate.id === id);
      if (!entry) return;
      entry.world = world.map(Number);
      if (persist) this.schedulePreferenceSave();
    }

    removeSpatialImage(id) {
      const picture = this.spatialPictures.get(id);
      if (picture?.autoHideTimer) this.window.clearTimeout(picture.autoHideTimer);
      picture?.window?.remove();
      this.spatialPictures.delete(id);
      this.spatialPictureState = this.spatialPictureState.filter((entry) => entry.id !== id);
      this.showSurfacePlacementHint('');
      this.three?.clearSpatialSurface?.(id);
      this.three?.cancelSpatialSurface?.();
      this.three?.clearSpatialMedia?.(id);
      this.schedulePreferenceSave();
    }


    closeMedia(restoreCamera = true) {
      if (!this.mediaStage || !this.mediaContent) return;
      this.mediaContent.querySelectorAll('audio,video').forEach((media) => {
        try { media.pause(); } catch (_) {}
        media.removeAttribute('src');
        try { media.load(); } catch (_) {}
      });
      this.mediaContent.replaceChildren();
      this.mediaStage.hidden = true;
      this.mediaStage.style.visibility = '';
      this.mediaStage.style.left = '';
      this.mediaStage.style.top = '';
      this.mediaStage.classList.remove('is-spatial');
      this.mediaStage.removeAttribute('data-world-position');
      if (this.spatialActions) this.spatialActions.hidden = true;
      this.three?.clearSpatialMedia?.();
      this.document.body.classList.remove('is-dome-viewing','is-spatial-viewing');

      if (restoreCamera && this.preDomeCamera) {
        this.camera.yaw = this.preDomeCamera.yaw;
        this.camera.pitch = this.preDomeCamera.pitch;
        this.camera.distance = this.preDomeCamera.distance;
        this.camera.target = this.preDomeCamera.target;
        this.preDomeCamera = null;
        this.renderCamera();
        this.schedulePreferenceSave();
      }
    }

    async toggleFullscreen() {
      try {
        if (!this.document.fullscreenElement) await this.document.documentElement.requestFullscreen();
        else await this.document.exitFullscreen();
      } catch (_) {}
    }
  }

  window.addEventListener('DOMContentLoaded', () => {
    window.ArcadeCloudDrive3D = new ArcadeCloudDrive3D(window, document).init();
  });
})();
