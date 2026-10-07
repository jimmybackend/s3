(() => {
  'use strict';

  class ArcadeCloudDrive3D {
    constructor(win, doc) {
      this.window = win;
      this.document = doc;
      this.config = win.ARCADECLOUD_DRIVE3D || {};
      this.world = doc.getElementById('dwWorld');
      this.cameraScene = doc.querySelector('[data-dw-camera-scene]');
      this.ring = doc.getElementById('dwShelfRing');
      this.shelves = Array.from(doc.querySelectorAll('.dw-shelf'));
      this.selected = null;
      this.previewCache = new Map();
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
      this.mediaStage = doc.querySelector('[data-dw-media-stage]');
      this.mediaContent = doc.querySelector('[data-dw-media-content]');
      this.mediaTitle = doc.querySelector('[data-dw-media-title]');
      this.preDomeCamera = null;
      this.environmentPanel = doc.querySelector('[data-dw-environment-panel]');
      this.glassImage = doc.querySelector('[data-dw-glass-image]');
      this.floorImage = doc.querySelector('[data-dw-floor-image]');
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

    init() {
      this.assignWorldAngles();
      this.layoutFixedShelves(false);
      this.bindControls();
      this.applyRoomPreferences();
      this.renderRadar();
      this.renderCamera(false);
      const target = this.findShelfByName(this.camera.target) || this.frontShelf();
      if (target) this.selectShelf(target, false);
      return this;
    }

    assignWorldAngles() {
      const count = Math.max(1, this.shelves.length);
      const center = (count - 1) / 2;
      const compact = (this.world?.clientWidth || this.window.innerWidth) < 620;
      const stepDegrees = compact ? 10.5 : 11.5;

      this.shelves.forEach((shelf, index) => {
        const slot = index - center;
        shelf.dataset.worldSlot = String(slot);
        shelf.dataset.worldAngle = String(slot * stepDegrees);
      });
    }

    layoutFixedShelves(animate = false) {
      const width = this.world?.clientWidth || this.window.innerWidth;
      const compact = width < 620;
      const tablet = width < 1000;
      const shelfWidth = this.shelves[0]?.offsetWidth || (compact ? 202 : tablet ? 232 : 252);
      const gap = compact ? 32 : tablet ? 38 : 44;
      const spacing = shelfWidth + gap;
      const yawStep = compact ? 7.2 : tablet ? 6.8 : 6.4;
      const depthStep = compact ? 16 : tablet ? 20 : 24;
      const depthCurve = compact ? 4 : tablet ? 5 : 6;
      const center = (this.shelves.length - 1) / 2;

      this.shelfLayout = { spacing, yawStep, stepDegrees: compact ? 10.5 : 11.5 };

      this.shelves.forEach((shelf, index) => {
        const slot = index - center;
        const abs = Math.abs(slot);
        const x = slot * spacing;
        const z = -(abs * depthStep + slot * slot * depthCurve);
        const yaw = -slot * yawStep;

        shelf.style.transitionDuration = animate ? '' : '0ms';
        shelf.style.transform = `translate3d(${x}px,0,${z}px) rotateY(${yaw}deg)`;
        shelf.style.opacity = '1';
        shelf.style.pointerEvents = '';
        shelf.style.zIndex = String(Math.max(20, 90 - Math.round(abs * 4)));
        shelf.dataset.worldX = String(x);
        shelf.dataset.worldZ = String(z);
        shelf.dataset.worldYaw = String(yaw);
      });
    }

    bindControls() {
      this.document.querySelector('[data-camera-home]')?.addEventListener('click', () => this.centerCamera());
      this.document.querySelector('[data-camera-exit-focus]')?.addEventListener('click', () => {
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
        this.camera.pitch = this.clamp(Number(this.pitchRange.value || 0), -42, 42);
        this.renderCamera(false);
      });
      this.pitchRange?.addEventListener('change', () => this.schedulePreferenceSave());
      this.document.querySelectorAll('[data-camera-strafe]').forEach((button) => {
        button.addEventListener('click', () => this.movePlayer(Number(button.dataset.cameraStrafe || 0) * .16, 0));
      });
      this.document.querySelectorAll('[data-camera-forward]').forEach((button) => {
        button.addEventListener('click', () => this.movePlayer(0, Number(button.dataset.cameraForward || 0) * .14));
      });
      this.floorNav?.addEventListener('click', (event) => this.navigateByFloorTap(event));

      this.hud.open?.addEventListener('click', () => this.openSelected());
      this.hud.desk?.addEventListener('click', () => this.bringToDesk());
      this.hud.play?.addEventListener('click', () => this.playSelected());
      this.document.querySelector('[data-dw-media-close]')?.addEventListener('click', () => this.closeMedia());
      this.document.querySelector('[data-dw-fullscreen]')?.addEventListener('click', () => this.toggleFullscreen());

      this.document.querySelector('[data-dw-environment]')?.addEventListener('click', () => {
        if (this.environmentPanel) this.environmentPanel.hidden = !this.environmentPanel.hidden;
      });
      this.document.querySelector('[data-dw-environment-close]')?.addEventListener('click', () => {
        if (this.environmentPanel) this.environmentPanel.hidden = true;
      });
      this.document.querySelectorAll('[data-environment-choice]').forEach((button) => {
        button.addEventListener('click', () => this.setEnvironment(button.dataset.environmentChoice || 'future'));
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
        if (item) this.selectElement(item);
      });
      this.deskFiles?.addEventListener('dblclick', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item);
        this.openSelected();
      });

      this.world?.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button,a,input,select,.dw-hud,.dw-desk,.dw-environment-panel,.dw-media-stage,.dw-radar')) return;
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
      this.camera.pitch = this.clamp(this.camera.pitch + delta, -42, 42);
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    centerCamera() {
      this.camera.yaw = 0;
      this.camera.pitch = 0;
      this.camera.lateral = 0;
      this.camera.forward = 0;
      this.camera.distance = 0;
      this.camera.target = '';
      this.renderCamera();
      this.schedulePreferenceSave();
    }

    movePlayer(lateralDelta, forwardDelta) {
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
      const yaw = this.normalizeAngle(this.camera.yaw);
      const pitch = this.clamp(this.camera.pitch, -42, 42);
      const width = this.world?.clientWidth || this.window.innerWidth;
      const compact = width < 620;
      const tablet = width < 1000;
      const spacing = this.shelfLayout?.spacing || (compact ? 234 : tablet ? 270 : 296);
      const stepDegrees = this.shelfLayout?.stepDegrees || (compact ? 10.5 : 11.5);
      const lateralPx = this.camera.lateral * (compact ? 120 : tablet ? 180 : 240);
      const forwardScale = 1 + this.camera.forward * (compact ? .10 : .13);
      const yawPan = (yaw / stepDegrees) * spacing;
      const pitchPan = pitch * (compact ? 4.0 : tablet ? 4.8 : 5.4);

      if (this.cameraScene) {
        this.cameraScene.style.transitionDuration = animate ? '' : '0ms';
        this.cameraScene.style.transform = `translate3d(${-(yawPan + lateralPx)}px,${pitchPan}px,0) scale(${forwardScale})`;
      }

      this.shelves.forEach((shelf) => {
        const worldAngle = Number(shelf.dataset.worldAngle || 0);
        const relative = this.normalizeAngle(worldAngle - yaw);
        const abs = Math.abs(relative);
        shelf.dataset.viewAngle = String(relative.toFixed(2));
        shelf.classList.toggle('is-looked-at', abs < stepDegrees * .48);
      });

      if (this.pitchRange) this.pitchRange.value = String(Math.round(pitch));
      if (this.world) {
        this.world.style.setProperty('--dw-camera-pitch', `${pitch}deg`);
        this.world.style.setProperty('--dw-camera-yaw', String(yaw));
      }
      this.updateRadarView();
      this.updateRadarPlayer();
    }

    updateRadarPlayer() {
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
      if (!shelf || !href) return;
      shelf.classList.add('is-loading-preview');
      try {
        let state = this.previewCache.get(href);
        if (!state) {
          const response = await fetch(href, {credentials:'same-origin',headers:{Accept:'application/json'}});
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          if (!payload?.ok || !payload?.state) throw new Error('Respuesta inválida');
          state = payload.state;
          this.previewCache.set(href, state);
        }
        this.renderShelfPreview(shelf, state);
        if (this.selected?.element === shelf) {
          this.updateHud(this.selected, state);
          this.renderDeskFileStrip(Array.isArray(state.files) ? state.files : []);
        }
      } catch (_) {
        const counts = shelf.querySelector('[data-preview-counts]');
        if (counts) counts.textContent = 'No se pudo cargar';
      } finally {
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

      const files = Array.isArray(state.files) ? state.files : [];
      if (fileHost) {
        fileHost.replaceChildren();
        const primary = files.slice(0, 10);
        if (!primary.length) fileHost.append(this.emptyNode('Sin archivos'));
        primary.forEach((item) => fileHost.append(this.bookNode(item, false)));
      }
      if (fileHostSecondary) {
        fileHostSecondary.replaceChildren();
        const secondary = files.slice(10, 20);
        if (!secondary.length) {
          const ornament = this.document.createElement('span');
          ornament.className = 'dw-shelf-ornament';
          ornament.setAttribute('aria-hidden', 'true');
          ornament.innerHTML = '<i class="fas fa-globe"></i>';
          fileHostSecondary.append(ornament);
        } else {
          secondary.forEach((item) => fileHostSecondary.append(this.bookNode(item, false)));
        }
      }
      if (counts) {
        counts.textContent = `${state.folder_count || 0} carpetas · ${state.file_count || 0} archivos`;
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

    renderDeskFileStrip(files) {
      if (!this.deskFiles) return;
      this.deskFiles.replaceChildren();
      const visible = Array.isArray(files) ? files.slice(0, 24) : [];
      if (!visible.length) {
        const empty = this.document.createElement('span');
        empty.className = 'dw-desk-empty';
        empty.textContent = 'Sin archivos directos en este estante.';
        this.deskFiles.append(empty);
        return;
      }
      visible.forEach((item) => this.deskFiles.append(this.deskFileNode(item)));
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

    bringToDesk() {
      if (this.selected) this.renderDeskPreview(this.selected, true);
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
      if (!this.radarPoints) return;
      this.radarPoints.replaceChildren();
      this.shelves.forEach((shelf) => {
        const angle = Number(shelf.dataset.worldAngle || 0) * Math.PI / 180;
        const dot = this.document.createElement('button');
        dot.type = 'button';
        dot.className = 'dw-radar-point';
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
      const target = surface === 'glass' ? this.glassImage : this.floorImage;
      if (!target) return;
      target.style.backgroundImage = url ? `url("${String(url).replace(/"/g,'%22')}")` : '';
      target.classList.toggle('is-customized', Boolean(url));
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

    setEnvironment(environment) {
      const allowed = new Set(['future','mountain','prehistoric','ocean']);
      this.room.environment = allowed.has(environment) ? environment : 'future';
      this.document.body.dataset.environment = this.room.environment;
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
        }
      };
      try {
        await fetch(endpoint,{
          method:'POST',
          credentials:'same-origin',
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
      this.showFileInDome(item);
    }

    playSelected() {
      const item = this.selected;
      if (!item || item.locked || !item.openHref) return;
      this.showFileInDome(item);
    }

    showFileInDome(item) {
      if (!this.mediaStage || !this.mediaContent) return;
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

      let viewer;
      if (item.kind === 'image') {
        viewer = this.document.createElement('img');
        viewer.alt = item.name;
        viewer.src = item.openHref;
      } else if (item.kind === 'video') {
        viewer = this.document.createElement('video');
        viewer.controls = true;
        viewer.autoplay = true;
        viewer.playsInline = true;
        viewer.src = item.openHref;
      } else if (item.kind === 'audio') {
        const shell = this.document.createElement('div');
        shell.className = 'dw-dome-audio-shell';
        const icon = this.document.createElement('i');
        icon.className = 'fas fa-music';
        const label = this.document.createElement('strong');
        label.textContent = item.name;
        const audio = this.document.createElement('audio');
        audio.controls = true;
        audio.autoplay = true;
        audio.src = item.openHref;
        shell.append(icon,label,audio);
        viewer = shell;
      } else {
        viewer = this.document.createElement('iframe');
        viewer.className = 'dw-dome-document-frame';
        viewer.src = item.openHref;
        viewer.title = item.name || 'Documento';
        viewer.setAttribute('loading','eager');
        viewer.setAttribute('referrerpolicy','same-origin');
      }

      this.mediaContent.replaceChildren(viewer);
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
      this.document.body.classList.remove('is-dome-viewing');

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
