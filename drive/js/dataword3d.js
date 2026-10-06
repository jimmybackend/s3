(() => {
  'use strict';

  class ArcadeCloudDrive3D {
    constructor(win, doc) {
      this.window = win;
      this.document = doc;
      this.world = doc.getElementById('dwWorld');
      this.ring = doc.getElementById('dwShelfRing');
      this.shelves = Array.from(doc.querySelectorAll('.dw-shelf'));
      this.activeIndex = 0;
      this.selected = null;
      this.previewCache = new Map();
      this.dragStartX = null;
      this.dragMoved = false;
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
        previewLabel: doc.querySelector('[data-hud-preview-label]'),
      };
      this.deskFocus = doc.querySelector('[data-dw-desk-focus]');
      this.mediaStage = doc.querySelector('[data-dw-media-stage]');
      this.mediaContent = doc.querySelector('[data-dw-media-content]');
      this.environmentPanel = doc.querySelector('[data-dw-environment-panel]');
    }

    init() {
      this.bindControls();
      this.restoreEnvironment();
      this.arrangeShelves(false);
      const first = this.shelves[0];
      if (first) {
        this.selectElement(first, { loadPreview: true, keepShelfIndex: true });
      }
      return this;
    }

    bindControls() {
      this.document.querySelector('[data-dw-prev]')?.addEventListener('click', () => this.rotate(-1));
      this.document.querySelector('[data-dw-next]')?.addEventListener('click', () => this.rotate(1));
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

      this.ring?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        if (item.classList.contains('dw-shelf')) {
          const index = this.shelves.indexOf(item);
          if (index >= 0) {
            this.activeIndex = index;
            this.arrangeShelves();
          }
        }
        this.selectElement(item, { loadPreview: item.classList.contains('dw-shelf'), keepShelfIndex: true });
      });

      this.ring?.addEventListener('dblclick', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item, { loadPreview: false, keepShelfIndex: true });
        this.openSelected();
      });

      this.document.querySelector('[data-dw-current-files]')?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item, { loadPreview: false });
      });

      this.document.querySelector('[data-dw-current-files]')?.addEventListener('dblclick', (event) => {
        const item = event.target.closest('[data-dw-item]');
        if (!item) return;
        this.selectElement(item, { loadPreview: false });
        this.openSelected();
      });

      this.world?.addEventListener('wheel', (event) => {
        if (event.target.closest('.dw-hud,.dw-desk,.dw-environment-panel,.dw-media-stage')) return;
        const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
        if (Math.abs(delta) < 8) return;
        event.preventDefault();
        this.rotate(delta > 0 ? 1 : -1);
      }, { passive: false });

      this.world?.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button,a,.dw-hud,.dw-desk,.dw-environment-panel,.dw-media-stage')) return;
        this.dragStartX = event.clientX;
        this.dragMoved = false;
      });
      this.world?.addEventListener('pointermove', (event) => {
        if (this.dragStartX === null) return;
        if (Math.abs(event.clientX - this.dragStartX) > 18) this.dragMoved = true;
      });
      this.world?.addEventListener('pointerup', (event) => {
        if (this.dragStartX === null) return;
        const delta = event.clientX - this.dragStartX;
        this.dragStartX = null;
        if (this.dragMoved && Math.abs(delta) > 42) this.rotate(delta < 0 ? 1 : -1);
      });

      this.document.addEventListener('keydown', (event) => {
        if (event.target.matches('input,textarea,select')) return;
        if (event.key === 'ArrowLeft') { event.preventDefault(); this.rotate(-1); }
        if (event.key === 'ArrowRight') { event.preventDefault(); this.rotate(1); }
        if (event.key === 'Enter' && this.selected) { event.preventDefault(); this.openSelected(); }
        if (event.key === 'Escape') {
          if (this.mediaStage && !this.mediaStage.hidden) { this.closeMedia(); return; }
          const parent = this.window.ARCADECLOUD_DRIVE3D?.parentHref || '';
          this.window.location.href = parent || 'so.php';
        }
      });

      this.window.addEventListener('resize', () => this.arrangeShelves(false));
    }

    rotate(direction) {
      if (this.shelves.length <= 1) return;
      this.activeIndex = (this.activeIndex + direction + this.shelves.length) % this.shelves.length;
      this.arrangeShelves();
      const shelf = this.shelves[this.activeIndex];
      if (shelf) this.selectElement(shelf, { loadPreview: true, keepShelfIndex: true });
    }

    circularDifference(index) {
      const total = this.shelves.length;
      if (total <= 1) return 0;
      let diff = index - this.activeIndex;
      if (diff > total / 2) diff -= total;
      if (diff < -total / 2) diff += total;
      return diff;
    }

    arrangeShelves(animate = true) {
      const width = this.world?.clientWidth || this.window.innerWidth;
      const compact = width < 620;
      const tablet = width < 1000;
      const desktop = !compact && !tablet;
      const spacing = compact ? 170 : tablet ? 205 : Math.min(268, Math.max(218, width / 5.45));
      const maxVisible = compact ? 2 : tablet ? 2 : 3;

      this.shelves.forEach((shelf, index) => {
        const diff = this.circularDifference(index);
        const abs = Math.abs(diff);
        const x = diff * spacing;
        const y = abs * (compact ? 12 : tablet ? 14 : 16) + abs * abs * (compact ? 2 : 3);
        const z = -abs * (compact ? 42 : tablet ? 52 : 62);
        const yaw = -diff * (compact ? 8 : tablet ? 7 : 6.5);
        const baseScale = compact ? .88 : tablet ? .9 : .93;
        const scale = index === this.activeIndex
          ? (desktop ? 1.035 : 1.02)
          : Math.max(compact ? .74 : .76, baseScale - abs * (compact ? .08 : .065));

        shelf.style.transitionDuration = animate ? '' : '0ms';
        shelf.style.transform = `translate3d(${x}px, ${y}px, ${z}px) rotateY(${yaw}deg) scale(${scale})`;
        shelf.style.zIndex = String(40 - Math.round(abs * 4));
        shelf.style.opacity = abs > maxVisible
          ? '0'
          : String(Math.max(.42, 1 - abs * (compact ? .2 : .11)));
        shelf.style.pointerEvents = abs > maxVisible ? 'none' : '';
        shelf.setAttribute('aria-hidden', abs > maxVisible ? 'true' : 'false');
        shelf.classList.toggle('is-active', index === this.activeIndex);
      });
    }

    itemFromElement(element) {
      const d = element.dataset;
      return {
        element,
        type: d.itemType || 'file',
        kind: d.itemKind || (d.itemType === 'folder' ? 'folder' : 'file'),
        name: d.itemName || 'Elemento',
        path: d.itemPath || '',
        size: d.itemSize || '—',
        date: d.itemDate || '—',
        format: d.itemFormat || (d.itemType === 'folder' ? 'CARPETA' : '—'),
        openHref: d.openHref || '',
        downloadHref: d.downloadHref || '',
        previewHref: d.previewHref || '',
        locked: d.itemLocked === '1',
      };
    }

    selectElement(element, options = {}) {
      if (!element) return;
      if (!element.classList.contains('dw-shelf')) {
        this.document.querySelectorAll('[data-dw-item].is-active:not(.dw-shelf)').forEach((el) => el.classList.remove('is-active'));
        element.classList.add('is-active');
      }

      this.selected = this.itemFromElement(element);
      this.updateHud(this.selected);

      if (options.loadPreview && this.selected.previewHref) {
        this.loadShelfPreview(element, this.selected.previewHref);
      }
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
      this.hud.counts.textContent = state ? `${state.folder_count || 0} carpetas · ${state.file_count || 0} archivos` : (isFolder ? 'Selecciona para leer contenido' : 'Archivo');
      if (this.hud.previewIcon) {
        this.hud.previewIcon.className = `fas ${this.iconFor(item.kind)}`;
      }
      if (this.hud.previewLabel) {
        this.hud.previewLabel.textContent = isFolder ? 'Carpeta' : this.kindLabel(item.kind);
      }
      this.hud.open.disabled = !item.openHref || item.locked;
      this.hud.open.innerHTML = item.locked ? '<i class="fas fa-lock"></i> Protegido' : `<i class="fas ${isFolder ? 'fa-folder-open' : 'fa-arrow-up-right-from-square'}"></i> Abrir`;

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
      return ({ image:'Imagen', audio:'Audio', video:'Video', pdf:'PDF', document:'Documento', folder:'Carpeta', file:'Archivo' })[kind] || 'Archivo';
    }

    async loadShelfPreview(shelf, href) {
      if (!shelf || !href) return;
      shelf.classList.add('is-loading-preview');
      try {
        let state = this.previewCache.get(href);
        if (!state) {
          const response = await fetch(href, { credentials:'same-origin', headers:{ Accept:'application/json' } });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          if (!payload?.ok || !payload?.state) throw new Error('Respuesta inválida');
          state = payload.state;
          this.previewCache.set(href, state);
        }
        this.renderShelfPreview(shelf, state);
        if (this.selected?.element === shelf) this.updateHud(this.selected, state);
      } catch (error) {
        const info = shelf.querySelector('[data-preview-info]');
        if (info) info.textContent = 'No se pudo cargar la vista previa.';
      } finally {
        shelf.classList.remove('is-loading-preview');
      }
    }

    renderShelfPreview(shelf, state) {
      const folderHost = shelf.querySelector('[data-preview-folders]');
      const fileHost = shelf.querySelector('[data-preview-files]');
      const infoHost = shelf.querySelector('[data-preview-info]');
      const counts = shelf.querySelector('[data-preview-counts]');

      if (folderHost) {
        folderHost.replaceChildren();
        const folders = Array.isArray(state.folders) ? state.folders.slice(0, 6) : [];
        if (!folders.length) folderHost.append(this.emptyNode('Sin subcarpetas'));
        folders.forEach((item) => folderHost.append(this.bookNode(item, true)));
      }

      if (fileHost) {
        fileHost.replaceChildren();
        const files = Array.isArray(state.files) ? state.files.slice(0, 12) : [];
        if (!files.length) fileHost.append(this.emptyNode('Sin archivos'));
        files.forEach((item) => fileHost.append(this.bookNode(item, false)));
      }

      if (infoHost) {
        infoHost.replaceChildren();
        const span = this.document.createElement('span');
        span.innerHTML = '<i class="fas fa-wave-square"></i> ';
        span.append(this.document.createTextNode(`${state.folder_bytes || '0 B'} · ${state.visible_path || ''}`));
        infoHost.append(span);
      }
      if (counts) counts.textContent = `${state.folder_count || 0} carpetas · ${state.file_count || 0} archivos`;
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
      button.dataset.itemLocked = item.locked ? '1' : '0';
      button.title = item.name || 'Elemento';
      const label = this.document.createElement('span');
      label.textContent = item.name || 'Elemento';
      button.append(label);
      return button;
    }

    emptyNode(text) {
      const node = this.document.createElement('div');
      node.className = 'dw-empty-message';
      node.textContent = text;
      return node;
    }

    bringToDesk() {
      if (!this.selected || !this.deskFocus) return;
      const icon = this.iconFor(this.selected.kind);
      this.deskFocus.replaceChildren();
      const i = this.document.createElement('i');
      i.className = `fas ${icon}`;
      const span = this.document.createElement('span');
      span.textContent = this.selected.name;
      this.deskFocus.append(i, span);
      this.deskFocus.classList.add('is-live');
      this.deskFocus.animate([
        { transform:'translateY(8px) scale(.96)', opacity:.4 },
        { transform:'translateY(0) scale(1)', opacity:1 }
      ], { duration:320, easing:'cubic-bezier(.2,.8,.2,1)' });
    }

    iconFor(kind) {
      return ({folder:'fa-folder-open',image:'fa-image',audio:'fa-music',video:'fa-film',pdf:'fa-file-pdf',document:'fa-file-lines'})[kind] || 'fa-file';
    }

    openSelected() {
      const item = this.selected;
      if (!item || item.locked || !item.openHref) return;
      if (item.type === 'folder') {
        this.window.location.href = item.openHref;
        return;
      }
      if (['image','audio','video'].includes(item.kind)) {
        this.showMedia(item);
        return;
      }
      this.window.open(item.openHref, '_blank', 'noopener');
    }

    playSelected() {
      const item = this.selected;
      if (!item || item.locked || !item.openHref || !['audio','video'].includes(item.kind)) return;
      this.showMedia(item);
    }

    showMedia(item) {
      if (!this.mediaStage || !this.mediaContent) return;
      this.closeMedia();
      let media;
      if (item.kind === 'image') {
        media = this.document.createElement('img');
        media.alt = item.name;
        media.src = item.openHref;
      } else if (item.kind === 'video') {
        media = this.document.createElement('video');
        media.controls = true;
        media.autoplay = true;
        media.playsInline = true;
        media.src = item.openHref;
      } else {
        media = this.document.createElement('audio');
        media.controls = true;
        media.autoplay = true;
        media.src = item.openHref;
      }
      this.mediaContent.append(media);
      this.mediaStage.hidden = false;
    }

    closeMedia() {
      if (!this.mediaStage || !this.mediaContent) return;
      this.mediaContent.querySelectorAll('audio,video').forEach((media) => {
        try { media.pause(); } catch (_) {}
        media.removeAttribute('src');
        try { media.load(); } catch (_) {}
      });
      this.mediaContent.replaceChildren();
      this.mediaStage.hidden = true;
    }

    setEnvironment(environment) {
      const allowed = new Set(['future','mountain','prehistoric','ocean']);
      const next = allowed.has(environment) ? environment : 'future';
      this.document.body.dataset.environment = next;
      try { localStorage.setItem('arcadecloud-drive3d-environment', next); } catch (_) {}
      if (this.environmentPanel) this.environmentPanel.hidden = true;
    }

    restoreEnvironment() {
      let environment = 'future';
      try { environment = localStorage.getItem('arcadecloud-drive3d-environment') || environment; } catch (_) {}
      this.setEnvironment(environment);
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
