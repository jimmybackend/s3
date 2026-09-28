class ArcadeCloudOsShell {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.windows = Array.from(doc.querySelectorAll('.os-window'));
    this.desktop = doc.getElementById('osDesktop');
    this.taskButtons = doc.getElementById('osTaskButtons');
    this.launcher = doc.getElementById('osLauncher');
    this.startButton = doc.getElementById('osStart');
    this.context = doc.getElementById('fileContextMenu');
    this.contextName = doc.getElementById('fileContextName');
    this.taskContext = doc.getElementById('osTaskContext');
    this.taskContextName = doc.getElementById('osTaskContextName');
    this.activeTaskWindow = null;
    this.zCounter = 200;
    this.viewerCounter = 0;
    this.activeFile = null;
    this.mediaOverlay = null;
    this.explorerLoading = false;
    this.fileTapState = new WeakMap();
    this.fileSecondClickMs = 320;
  }

  init() {
    this.bindLaunchers();
    this.windows.forEach((win) => this.bindWindow(win));
    this.bindStartMenu();
    this.bindExplorerNavigation();
    this.bindHistoryNavigation();
    this.bindFiles();
    this.bindContextActions();
    this.bindFolderInfo();
    this.bindSelectionActions();
    this.bindTaskContext();
    this.bindDocumentDismiss();
    this.bindTaskRefresh();
    this.updateClock();
    this.window.setInterval(() => this.updateClock(), 1000);
    this.syncTaskbar();
    return this;
  }

  bindWindow(win) {
    if (!win || win.dataset.osBound === '1') return;
    win.dataset.osBound = '1';

    win.addEventListener('pointerdown', () => this.activateWindow(win));
    win.querySelector('[data-window-close]')?.addEventListener('click', () => this.closeWindow(win));
    win.querySelector('[data-window-minimize]')?.addEventListener('click', () => this.minimizeWindow(win));
    win.querySelector('[data-window-maximize]')?.addEventListener('click', () => this.toggleMaximize(win));

    const handle = win.querySelector('[data-window-drag-handle]');
    if (handle) {
      handle.addEventListener('pointerdown', (event) => this.beginDrag(event, win, handle));
    }
  }

  registerWindow(win) {
    if (!win) return;
    if (!this.windows.includes(win)) this.windows.push(win);
    this.bindWindow(win);
    this.activateWindow(win);
  }

  activateWindow(win) {
    if (!win) return;
    this.windows.forEach((item) => item.classList.remove('is-active'));
    win.classList.add('is-active', 'is-open');
    win.style.zIndex = String(++this.zCounter);
    this.syncTaskbar();
  }

  openWindow(id) {
    const win = this.document.getElementById(id);
    if (!win) return;
    win.dataset.minimized = '0';
    win.classList.add('is-open');
    win.hidden = false;
    this.activateWindow(win);
    if (id === 'nodeWindow') {
      this.window.ArcadeCloudOsNodeMonitor?.refresh?.();
    }
    this.closeLauncher();
  }

  closeWindow(win) {
    if (!win) return;
    win.dataset.minimized = '0';
    win.classList.remove('is-open', 'is-active');

    if (win.dataset.dynamicWindow === '1') {
      const media = win.querySelector('audio,video');
      if (media) {
        try { media.pause(); } catch (_) {}
      }
      win.remove();
      this.windows = this.windows.filter((item) => item !== win);
    }

    this.syncTaskbar();
  }

  minimizeWindow(win) {
    if (!win) return;
    win.classList.remove('is-open', 'is-active');
    win.dataset.minimized = '1';

    const media = win.querySelector('audio,video');
    if (media) {
      try { media.pause(); } catch (_) {}
    }

    this.syncTaskbar();
  }

  toggleMaximize(win) {
    if (!win) return;
    win.classList.toggle('is-maximized');
    this.activateWindow(win);
  }

  syncTaskbar() {
    if (!this.taskButtons) return;
    this.taskButtons.innerHTML = '';

    this.windows = this.windows.filter((win) => win && win.isConnected);

    this.windows.forEach((win) => {
      const isVisible = win.classList.contains('is-open');
      const hasHistory = isVisible || win.dataset.minimized === '1';
      if (!hasHistory) return;

      const item = this.document.createElement('div');
      item.className = 'os-task-item' + (win.classList.contains('is-active') ? ' is-active' : '');

      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'os-task-button';
      button.innerHTML = '<i class="far fa-window-maximize"></i><span></span>';

      const label = button.querySelector('span');
      if (label) label.textContent = win.dataset.windowTitle || 'Ventana';

      button.addEventListener('click', () => {
        this.hideTaskContext();

        if (win.classList.contains('is-open') && win.classList.contains('is-active')) {
          this.minimizeWindow(win);
          return;
        }

        win.dataset.minimized = '0';
        win.classList.add('is-open');
        this.activateWindow(win);
        if (win.id === 'nodeWindow') {
          this.window.ArcadeCloudOsNodeMonitor?.refresh?.();
        }
      });

      button.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        this.showTaskContext(win, event.clientX, event.clientY);
      });

      const more = this.document.createElement('button');
      more.type = 'button';
      more.className = 'os-task-more';
      more.setAttribute('aria-label', 'Opciones de ventana');
      more.title = 'Opciones de ventana';
      more.innerHTML = '<i class="fas fa-ellipsis-vertical"></i>';
      more.addEventListener('click', (event) => {
        event.stopPropagation();
        const rect = more.getBoundingClientRect();
        this.showTaskContext(win, rect.right - 8, rect.top - 6);
      });

      item.appendChild(button);
      item.appendChild(more);
      this.taskButtons.appendChild(item);
    });
  }

  closeLauncher() {
    if (!this.launcher || !this.startButton) return;
    this.launcher.hidden = true;
    this.startButton.classList.remove('is-open');
    this.startButton.setAttribute('aria-expanded', 'false');
  }

  hideTaskContext() {
    if (!this.taskContext) return;
    this.taskContext.hidden = true;
    this.activeTaskWindow = null;
  }

  showTaskContext(win, x, y) {
    if (!this.taskContext || !win) return;

    this.activeTaskWindow = win;
    if (this.taskContextName) {
      this.taskContextName.textContent = win.dataset.windowTitle || 'Ventana';
    }

    const minimized = win.dataset.minimized === '1' || !win.classList.contains('is-open');
    const minimize = this.taskContext.querySelector('[data-task-action="minimize"]');
    const maximize = this.taskContext.querySelector('[data-task-action="maximize"]');
    if (minimize) minimize.hidden = minimized;
    if (maximize) maximize.hidden = !minimized;

    const maximizeLabel = maximize?.querySelector('span');
    if (maximizeLabel) maximizeLabel.textContent = 'Maximizar';

    this.taskContext.hidden = false;
    const rect = this.taskContext.getBoundingClientRect();
    this.taskContext.style.left = Math.max(4, Math.min(x, this.window.innerWidth - rect.width - 4)) + 'px';
    this.taskContext.style.top = Math.max(4, Math.min(y, this.window.innerHeight - rect.height - 52)) + 'px';
  }

  bindTaskContext() {
    if (!this.taskContext) return;

    this.taskContext.querySelector('[data-task-action="minimize"]')?.addEventListener('click', () => {
      const win = this.activeTaskWindow;
      if (!win) return;
      this.minimizeWindow(win);
      this.hideTaskContext();
    });

    this.taskContext.querySelector('[data-task-action="maximize"]')?.addEventListener('click', () => {
      const win = this.activeTaskWindow;
      if (!win) return;
      win.dataset.minimized = '0';
      win.classList.add('is-open', 'is-maximized');
      this.activateWindow(win);
      this.hideTaskContext();
    });

    this.taskContext.querySelector('[data-task-action="close"]')?.addEventListener('click', () => {
      const win = this.activeTaskWindow;
      if (!win) return;
      this.closeWindow(win);
      this.hideTaskContext();
    });
  }

  bindLaunchers() {
    this.document.querySelectorAll('[data-window-open]').forEach((control) => {
      control.addEventListener('click', (event) => {
        if (control.tagName === 'A') return;
        event.preventDefault();
        this.openWindow(control.dataset.windowOpen || '');
      });
    });
  }

  beginDrag(event, win, handle) {
    if (event.target.closest('.os-window-controls')) return;
    if (this.window.matchMedia('(max-width: 800px), (pointer: coarse)').matches) return;
    if (win.classList.contains('is-maximized')) return;

    this.activateWindow(win);

    const startX = event.clientX;
    const startY = event.clientY;
    const rect = win.getBoundingClientRect();

    try { handle.setPointerCapture(event.pointerId); } catch (_) {}

    const move = (moveEvent) => {
      const maxLeft = Math.max(4, this.window.innerWidth - 120);
      const maxTop = Math.max(4, this.window.innerHeight - 110);
      win.style.left = Math.min(maxLeft, Math.max(4, rect.left + moveEvent.clientX - startX)) + 'px';
      win.style.top = Math.min(maxTop, Math.max(4, rect.top + moveEvent.clientY - startY)) + 'px';
    };

    const stop = () => {
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', stop);
      handle.removeEventListener('pointercancel', stop);
    };

    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
  }

  bindStartMenu() {
    if (!this.startButton || !this.launcher) return;

    this.startButton.addEventListener('click', () => {
      const shouldOpen = this.launcher.hidden;
      this.launcher.hidden = !shouldOpen;
      this.startButton.classList.toggle('is-open', shouldOpen);
      this.startButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    });

    this.launcher.querySelector('[data-os-reload]')?.addEventListener('click', (event) => {
      event.preventDefault();
      this.closeLauncher();
      this.window.location.reload();
    });

    this.launcher.querySelector('[data-os-about]')?.addEventListener('click', () => {
      this.closeLauncher();
    });
  }

  hideContext() {
    if (!this.context) return;
    this.context.hidden = true;
    this.activeFile = null;
  }

  setContextAction(action, visible) {
    if (!this.context) return;
    const control = this.context.querySelector('[data-file-action="' + action + '"]');
    if (control) control.hidden = !visible;
  }

  selectedFileEntries() {
    return Array.from(this.document.querySelectorAll('.os-file-entry.is-selected'));
  }

  setFileSelected(entry, selected) {
    if (!entry) return;
    const on = Boolean(selected);
    entry.classList.toggle('is-selected', on);
    entry.setAttribute('aria-pressed', on ? 'true' : 'false');
    this.updateSelectionActions();
  }

  toggleFileSelection(entry) {
    if (!entry) return false;
    const selected = !entry.classList.contains('is-selected');
    this.setFileSelected(entry, selected);
    return selected;
  }

  clearFileSelection() {
    this.selectedFileEntries().forEach((entry) => this.setFileSelected(entry, false));
    this.fileTapState = new WeakMap();
  }

  prepareFileContext(entry) {
    const selected = this.selectedFileEntries();
    if (!entry.classList.contains('is-selected')) {
      selected.forEach((item) => this.setFileSelected(item, false));
      this.setFileSelected(entry, true);
    }
    return this.selectedFileEntries();
  }

  showContext(entry, x, y) {
    if (!this.context || !entry || !this.contextName) return;

    const selected = this.prepareFileContext(entry);
    const count = selected.length;
    const multi = count > 1;

    this.activeFile = entry;
    this.contextName.textContent = multi
      ? count + ' archivos seleccionados'
      : (entry.dataset.name || 'Archivo');

    const locked = entry.dataset.locked === '1';
    const ext = String(entry.dataset.ext || '').toLowerCase();

    this.setContextAction('open', !multi && !locked && Boolean(entry.dataset.openUrl));
    const openControl = this.context.querySelector('[data-file-action="open"]');
    const openLabel = openControl?.querySelector('span');
    const openIcon = openControl?.querySelector('i');
    const mediaFile = entry.dataset.audio === '1' || entry.dataset.video === '1';
    if (openLabel) openLabel.textContent = mediaFile ? 'Reproducir' : 'Abrir en ventana';
    if (openIcon) openIcon.className = mediaFile ? 'fas fa-play' : 'fas fa-eye';

    this.setContextAction('edit', !multi && !locked && Boolean(entry.dataset.editUrl));
    this.setContextAction('download', !multi && !locked && Boolean(entry.dataset.downloadUrl));
    this.setContextAction('classic', !multi);

    this.setContextAction('textract', !multi && !locked && entry.dataset.textract === '1');
    this.setContextAction('transcribe', !multi && !locked && entry.dataset.transcribe === '1');
    this.setContextAction('polly', !multi && !locked && entry.dataset.polly === '1');
    this.setContextAction('translate', !multi && !locked && entry.dataset.translate === '1');
    this.setContextAction('rekognition', !multi && !locked && entry.dataset.rekognition === '1');
    this.setContextAction('comprehend', !multi && !locked && entry.dataset.comprehend === '1');
    this.setContextAction('split-video', !multi && !locked && entry.dataset.video === '1');
    this.setContextAction('extract-mp3', !multi && !locked && entry.dataset.video === '1');
    this.setContextAction('split-audio', !multi && !locked && entry.dataset.audio === '1');

    const hasSecurity = entry.dataset.hasSecurity === '1';
    this.setContextAction('security-unlock', !multi && locked);
    this.setContextAction('security-relock', !multi && !locked && hasSecurity);
    this.setContextAction('security-lock', !multi && !locked && !hasSecurity);

    const copy = this.context.querySelector('[data-os-clipboard-action="copy"][data-os-clipboard-kind="file"]');
    const cut = this.context.querySelector('[data-os-clipboard-action="cut"][data-os-clipboard-kind="file"]');
    const share = this.context.querySelector('[data-os-clipboard-action="share"][data-os-clipboard-kind="file"]');
    const remove = this.context.querySelector('[data-os-clipboard-action="delete"][data-os-clipboard-kind="file"]');

    if (copy) {
      const label = copy.querySelector('span');
      if (label) label.textContent = multi ? 'Copiar ' + count + ' archivos' : 'Copiar';
    }
    if (cut) {
      const label = cut.querySelector('span');
      if (label) label.textContent = multi ? 'Cortar / mover ' + count + ' archivos' : 'Cortar / mover';
    }
    if (share) share.hidden = multi;
    if (remove) remove.hidden = multi;

    const serviceButtons = Array.from(this.context.querySelectorAll('[data-service-action]'));
    const divider = this.context.querySelector('[data-service-divider]');
    if (divider) divider.hidden = !serviceButtons.some((button) => !button.hidden);

    if (!multi) {
      if (locked) {
        this.contextName.textContent = (entry.dataset.name || 'Archivo') + ' · protegido';
      } else if (ext) {
        this.contextName.textContent = (entry.dataset.name || 'Archivo') + ' · .' + ext;
      }
    }

    this.context.hidden = false;
    const rect = this.context.getBoundingClientRect();
    this.context.style.left = Math.max(4, Math.min(x, this.window.innerWidth - rect.width - 4)) + 'px';
    this.context.style.top = Math.max(4, Math.min(y, this.window.innerHeight - rect.height - 52)) + 'px';
  }

  bindFiles() {
    this.document.querySelectorAll('.os-file-entry').forEach((entry) => {
      if (entry.dataset.osFileBound === '1') return;
      entry.dataset.osFileBound = '1';
      entry.setAttribute('aria-pressed', entry.classList.contains('is-selected') ? 'true' : 'false');

      entry.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        this.showContext(entry, event.clientX, event.clientY);
      });

      entry.addEventListener('click', (event) => {
        const menu = event.target.closest('.os-entry-menu');
        if (menu) {
          event.preventDefault();
          const rect = entry.getBoundingClientRect();
          this.showContext(entry, rect.right - 20, rect.top + 30);
          return;
        }

        event.preventDefault();

        const now = Date.now();
        const previous = Number(this.fileTapState.get(entry) || 0);
        if (previous > 0 && (now - previous) <= this.fileSecondClickMs) {
          this.fileTapState.delete(entry);
          if (!entry.classList.contains('is-selected')) this.setFileSelected(entry, true);
          this.openFileEntry(entry, false);
          return;
        }

        this.fileTapState.set(entry, now);
        this.toggleFileSelection(entry);
      });
    });
    this.updateSelectionActions();
  }

  bindExplorerNavigation() {
    this.document.addEventListener('click', (event) => {
      const link = event.target.closest('#explorerWindow a[data-explorer-route]');
      if (!link) return;

      event.preventDefault();
      const route = String(link.dataset.explorerRoute || '').trim();
      const page = Math.max(1, parseInt(String(link.dataset.explorerPage || '1'), 10) || 1);
      if (route) this.refreshExplorer(route, { page });
    });
  }

  bindHistoryNavigation() {
    this.window.addEventListener('popstate', () => {
      const url = new URL(this.window.location.href);
      const route = String(url.searchParams.get('ruta') || this.window.rutaActual || '').trim();
      const page = Math.max(1, parseInt(String(url.searchParams.get('pagina') || '1'), 10) || 1);
      if (route) {
        this.refreshExplorer(route, { updateHistory: false, page });
      }
    });
  }

  async refreshExplorer(route, options = {}) {
    if (this.explorerLoading) return false;

    route = String(route || '').trim();
    if (!route) return false;

    const current = this.document.getElementById('osExplorerLive');
    if (!current) return false;

    const currentRoute = String(current.dataset.explorerRoute || '').trim();
    const currentPage = Math.max(1, parseInt(String(current.dataset.explorerPage || '1'), 10) || 1);
    const requestedPage = options.page !== undefined
      ? Math.max(1, parseInt(String(options.page), 10) || 1)
      : (route === currentRoute ? currentPage : 1);

    this.explorerLoading = true;
    current.classList.add('is-loading');

    try {
      const url = new URL('so.php', this.window.location.href);
      url.searchParams.set('ruta', route);
      url.searchParams.set('pagina', String(requestedPage));
      url.searchParams.set('_os_fragment', 'explorer');

      const response = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-ArcadeCloud-OS-Fragment': 'explorer'
        }
      });

      if (!response.ok) {
        throw new Error('No se pudo abrir la carpeta.');
      }

      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const next = parsed.getElementById('osExplorerLive');
      if (!next) {
        throw new Error('La respuesta de Mis datos no es válida.');
      }

      current.replaceWith(next);

      const nextRoute = String(next.dataset.explorerRoute || route).trim();
      const nextPage = Math.max(1, parseInt(String(next.dataset.explorerPage || requestedPage), 10) || 1);
      this.window.rutaActual = nextRoute;

      if (options.updateHistory !== false) {
        const browserUrl = new URL(this.window.location.href);
        browserUrl.searchParams.set('ruta', nextRoute);
        if (nextPage > 1) browserUrl.searchParams.set('pagina', String(nextPage));
        else browserUrl.searchParams.delete('pagina');
        browserUrl.searchParams.delete('_os_fragment');
        const state = { arcadeRoute: nextRoute, arcadePage: nextPage };
        if (options.replaceHistory === false) {
          this.window.history.pushState(state, '', browserUrl.toString());
        } else {
          this.window.history.replaceState(state, '', browserUrl.toString());
        }
      }

      this.bindFiles();

      if (
        this.window.ArcadeCloudOsFolders &&
        typeof this.window.ArcadeCloudOsFolders.rebind === 'function'
      ) {
        this.window.ArcadeCloudOsFolders.rebind();
      }

      this.document.dispatchEvent(new CustomEvent('arcadeos:explorer-updated', {
        detail: { route: nextRoute, page: nextPage }
      }));

      return true;
    } catch (error) {
      this.notify(error && error.message ? error.message : 'No se pudo actualizar Mis datos.', 'warning');
      return false;
    } finally {
      this.explorerLoading = false;
      const live = this.document.getElementById('osExplorerLive');
      if (live) live.classList.remove('is-loading');
    }
  }

  viewerIcon(ext) {
    if (['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff','svg'].includes(ext)) return 'fa-file-image';
    if (['mp3','wav','ogg','opus','m4a','aac','flac'].includes(ext)) return 'fa-file-audio';
    if (['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg'].includes(ext)) return 'fa-file-video';
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml'].includes(ext)) return 'fa-file-lines';
    return 'fa-file';
  }

  openFileEntry(entry, forceEdit) {
    if (!entry) return;

    if (entry.dataset.locked === '1') {
      this.notify('Este archivo está protegido. Ábrelo desde el Drive clásico para desbloquearlo.', 'warning');
      return;
    }

    const openUrl = String(entry.dataset.openUrl || '');
    const editUrl = String(entry.dataset.editUrl || '');
    const name = String(entry.dataset.name || 'Archivo');
    const ext = String(entry.dataset.ext || '').toLowerCase();

    if (!openUrl && !editUrl) {
      this.notify('Este archivo no tiene un visor disponible todavía.', 'warning');
      return;
    }

    const useEditor = Boolean(editUrl) && (forceEdit || this.isTextExtension(ext));
    const sourceUrl = useEditor ? editUrl : openUrl;

    if (!useEditor && this.isAudioExtension(ext)) {
      this.openMediaOverlay(name, ext, sourceUrl, 'audio');
      this.hideContext();
      return;
    }

    if (!useEditor && this.isVideoExtension(ext)) {
      this.openMediaOverlay(name, ext, sourceUrl, 'video');
      this.hideContext();
      return;
    }

    const win = this.createViewerWindow(name, ext, sourceUrl, useEditor);
    this.registerWindow(win);
    this.hideContext();
  }

  isTextExtension(ext) {
    return ['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml'].includes(ext);
  }

  isAudioExtension(ext) {
    return ['mp3','wav','ogg','opus','m4a','aac','flac'].includes(ext);
  }

  isVideoExtension(ext) {
    return ['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg'].includes(ext);
  }

  closeMediaOverlay() {
    if (!this.mediaOverlay) return;
    const media = this.mediaOverlay.querySelector('audio,video');
    if (media) {
      try { media.pause(); } catch (_) {}
      media.removeAttribute('src');
      try { media.load(); } catch (_) {}
    }
    this.mediaOverlay.remove();
    this.mediaOverlay = null;
  }

  openMediaOverlay(name, ext, sourceUrl, kind) {
    this.closeMediaOverlay();

    const overlay = this.document.createElement('section');
    overlay.className = 'os-media-overlay is-' + kind;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-label', name);

    const header = this.document.createElement('div');
    header.className = 'os-media-overlay-header';

    const title = this.document.createElement('div');
    title.className = 'os-media-overlay-title';
    title.innerHTML = '<i class="fas ' + (kind === 'video' ? 'fa-film' : 'fa-wave-square') + '"></i><span></span>';
    const titleText = title.querySelector('span');
    if (titleText) titleText.textContent = name;

    const close = this.document.createElement('button');
    close.type = 'button';
    close.className = 'os-media-overlay-close';
    close.setAttribute('aria-label', 'Cerrar reproductor');
    close.innerHTML = '<i class="fas fa-xmark"></i>';
    close.addEventListener('click', () => this.closeMediaOverlay());

    header.appendChild(title);
    header.appendChild(close);
    overlay.appendChild(header);

    const media = this.document.createElement(kind);
    media.className = 'os-media-overlay-player';
    media.src = sourceUrl;
    media.controls = true;
    media.preload = 'metadata';

    if (kind === 'video') {
      media.playsInline = true;
    }

    overlay.appendChild(media);
    this.document.body.appendChild(overlay);
    this.mediaOverlay = overlay;
    this.bindMediaOverlayDrag(overlay, header);

    try {
      const promise = media.play();
      if (promise && typeof promise.catch === 'function') promise.catch(() => {});
    } catch (_) {}
  }

  bindMediaOverlayDrag(overlay, handle) {
    if (!overlay || !handle) return;

    handle.addEventListener('pointerdown', (event) => {
      if (event.target.closest('button')) return;
      if (this.window.matchMedia('(max-width: 700px)').matches) return;

      const rect = overlay.getBoundingClientRect();
      const startX = event.clientX;
      const startY = event.clientY;

      try { handle.setPointerCapture(event.pointerId); } catch (_) {}

      const move = (moveEvent) => {
        const left = Math.max(6, Math.min(
          this.window.innerWidth - rect.width - 6,
          rect.left + moveEvent.clientX - startX
        ));
        const top = Math.max(6, Math.min(
          this.window.innerHeight - rect.height - 54,
          rect.top + moveEvent.clientY - startY
        ));

        overlay.style.left = left + 'px';
        overlay.style.top = top + 'px';
        overlay.style.right = 'auto';
        overlay.style.bottom = 'auto';
      };

      const stop = () => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', stop);
        handle.removeEventListener('pointercancel', stop);
      };

      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', stop);
      handle.addEventListener('pointercancel', stop);
    });
  }

  createViewerWindow(name, ext, sourceUrl, useEditor) {
    this.viewerCounter += 1;
    const id = 'documentWindow' + this.viewerCounter;
    const win = this.document.createElement('section');

    win.id = id;
    win.className = 'os-window os-document-window is-open';
    if (ext === 'pdf') win.classList.add('os-pdf-window');
    win.dataset.windowTitle = name;
    win.dataset.dynamicWindow = '1';

    const offset = (this.viewerCounter % 6) * 24;
    win.style.left = (7 + offset / 12) + 'vw';
    win.style.top = (5 + offset / 14) + 'vh';
    win.style.width = ext === 'pdf' ? 'min(820px, 72vw)' : 'min(920px, 78vw)';
    win.style.height = ext === 'pdf' ? 'min(610px, 68vh)' : 'min(650px, 72vh)';

    const titlebar = this.document.createElement('div');
    titlebar.className = 'os-window-titlebar';
    titlebar.setAttribute('data-window-drag-handle', '');

    const title = this.document.createElement('div');
    title.className = 'os-window-title';
    title.innerHTML = '<i class="fas ' + this.viewerIcon(ext) + '"></i><span></span>';
    const titleText = title.querySelector('span');
    if (titleText) titleText.textContent = name;

    const controls = this.document.createElement('div');
    controls.className = 'os-window-controls';
    controls.innerHTML =
      '<button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>' +
      '<button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>' +
      '<button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>';

    titlebar.appendChild(title);
    titlebar.appendChild(controls);

    const body = this.document.createElement('div');
    body.className = 'os-window-body os-viewer-body';
    body.appendChild(this.viewerContent(name, ext, sourceUrl, useEditor));

    const status = this.document.createElement('div');
    status.className = 'os-statusbar';
    status.innerHTML = '<span></span><span>ArcadeCloud OS</span>';
    const statusName = status.querySelector('span');
    if (statusName) statusName.textContent = useEditor ? 'Editor · ' + name : 'Visor · ' + name;

    win.appendChild(titlebar);
    win.appendChild(body);
    win.appendChild(status);

    if (this.desktop) this.desktop.appendChild(win);
    else this.document.body.appendChild(win);

    return win;
  }

  viewerContent(name, ext, sourceUrl, useEditor) {
    const wrapper = this.document.createElement('div');
    wrapper.className = 'os-viewer-content';

    if (useEditor) {
      const iframe = this.document.createElement('iframe');
      iframe.className = 'os-viewer-frame';
      iframe.src = sourceUrl;
      iframe.title = 'Editor de ' + name;
      wrapper.appendChild(iframe);
      return wrapper;
    }

    if (['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff','svg'].includes(ext)) {
      const img = this.document.createElement('img');
      img.className = 'os-viewer-image';
      img.src = sourceUrl;
      img.alt = name;
      wrapper.appendChild(img);
      return wrapper;
    }

    const iframe = this.document.createElement('iframe');
    iframe.className = 'os-viewer-frame';
    iframe.src = sourceUrl;
    iframe.title = 'Visor de ' + name;
    wrapper.appendChild(iframe);
    return wrapper;
  }

  bindFolderInfo() {
    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      const button = target.closest('[data-folder-info]');
      if (button) {
        event.preventDefault();
        event.stopPropagation();
        const live = button.closest('#osExplorerLive');
        const panel = live?.querySelector('[data-folder-info-panel]');
        if (!panel) return;
        const open = panel.hidden;
        panel.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
      }

      const close = target.closest('[data-folder-info-close]');
      if (close) {
        const panel = close.closest('[data-folder-info-panel]');
        if (panel) panel.hidden = true;
        const live = close.closest('#osExplorerLive');
        live?.querySelector('[data-folder-info]')?.setAttribute('aria-expanded', 'false');
        return;
      }

      if (!target.closest('[data-folder-info-panel]')) {
        this.document.querySelectorAll('[data-folder-info-panel]').forEach((panel) => {
          panel.hidden = true;
        });
        this.document.querySelectorAll('[data-folder-info]').forEach((control) => {
          control.setAttribute('aria-expanded', 'false');
        });
      }
    });
  }

  bindSelectionActions() {
    this.document.addEventListener('click', async (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const button = target?.closest('[data-selection-action]');
      if (!button) return;

      event.preventDefault();
      event.stopPropagation();

      const action = String(button.dataset.selectionAction || '');
      if (action === 'download') {
        this.downloadSelectedFiles();
      } else if (action === 'delete') {
        await this.deleteSelectedFiles();
      }
    });
  }

  updateSelectionActions() {
    const selected = this.selectedFileEntries();
    const host = this.document.querySelector('#osExplorerLive [data-selection-actions]');
    if (!host) return;
    host.hidden = selected.length === 0;

    const count = host.querySelector('[data-selection-count]');
    if (count) count.textContent = String(selected.length);

    const hasLocked = selected.some((entry) => entry.dataset.locked === '1');
    host.querySelectorAll('button').forEach((button) => {
      button.disabled = selected.length === 0 || hasLocked;
    });
  }

  downloadSelectedFiles() {
    const selected = this.selectedFileEntries();
    if (!selected.length) return;
    if (selected.some((entry) => entry.dataset.locked === '1')) {
      this.notify('Desbloquea los archivos protegidos antes de descargarlos en grupo.', 'warning');
      return;
    }

    const form = this.document.createElement('form');
    form.method = 'POST';
    form.action = 'download_multiple.php';
    form.target = '_blank';
    form.hidden = true;

    selected.forEach((entry) => {
      const key = String(entry.dataset.key || '');
      if (!key) return;
      const input = this.document.createElement('input');
      input.type = 'hidden';
      input.name = 'archivos[]';
      input.value = key;
      form.appendChild(input);
    });

    this.document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  async deleteSelectedFiles() {
    const selected = this.selectedFileEntries();
    if (!selected.length) return;
    if (selected.some((entry) => entry.dataset.locked === '1')) {
      this.notify('Desbloquea los archivos protegidos antes de eliminarlos.', 'warning');
      return;
    }

    const keys = selected.map((entry) => String(entry.dataset.key || '')).filter(Boolean);
    if (!keys.length) return;
    if (!this.window.confirm('¿Eliminar ' + keys.length + ' archivo(s) seleccionados?')) return;

    try {
      const response = await this.window.fetch('delete_multiple.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({ archivos_json: JSON.stringify(keys) }).toString()
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.error || data?.mensaje || 'No se pudieron eliminar los archivos.');
      }
      this.notify('Archivos eliminados correctamente.', 'success');
      const live = this.document.getElementById('osExplorerLive');
      const route = String(live?.dataset.explorerRoute || this.window.DRIVE_INITIAL_ROUTE || '');
      const page = Math.max(1, parseInt(String(live?.dataset.explorerPage || '1'), 10) || 1);
      await this.refreshExplorer(route, { page, replaceHistory: true });
    } catch (error) {
      this.notify(error?.message || 'No se pudieron eliminar los archivos.', 'danger');
    }
  }

  async protectFile(entry) {
    const key = String(entry?.dataset?.key || '');
    if (!key) return;

    const password = this.window.prompt('Contraseña para proteger este archivo:');
    if (password === null) return;
    if (password.length < 4) {
      this.notify('La contraseña debe tener al menos 4 caracteres.', 'warning');
      return;
    }

    const confirm = this.window.prompt('Repite la contraseña:');
    if (confirm === null || confirm !== password) {
      this.notify('Las contraseñas no coinciden.', 'warning');
      return;
    }

    const hint = this.window.prompt('Pista opcional para recordar la contraseña:', '') ?? '';
    await this.postFileSecurity('set_file_security.php', {
      mode: 'secure',
      key,
      password,
      secure_hint: hint
    }, 'Archivo protegido.');
  }

  async unlockFile(entry) {
    const key = String(entry?.dataset?.key || '');
    if (!key) return;
    const password = this.window.prompt('Contraseña para desbloquear este archivo:');
    if (password === null) return;

    await this.postFileSecurity('unlock_file.php', {
      key,
      password
    }, 'Archivo desbloqueado temporalmente.');
  }

  async relockFile(entry) {
    const key = String(entry?.dataset?.key || '');
    if (!key) return;
    await this.postFileSecurity('relock_file.php', { key }, 'Archivo bloqueado de nuevo.');
  }

  async postFileSecurity(endpoint, payload, successMessage) {
    try {
      const response = await this.window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(payload).toString()
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.msg || data?.error || 'No se pudo cambiar la seguridad del archivo.');
      }

      this.notify(successMessage, 'success');
      this.hideContext();

      const live = this.document.getElementById('osExplorerLive');
      const route = String(live?.dataset.explorerRoute || this.window.DRIVE_INITIAL_ROUTE || '');
      const page = Math.max(1, parseInt(String(live?.dataset.explorerPage || '1'), 10) || 1);
      await this.refreshExplorer(route, { page, replaceHistory: true });
    } catch (error) {
      this.notify(error?.message || 'No se pudo cambiar la seguridad del archivo.', 'danger');
    }
  }

  bindContextActions() {
    this.context?.querySelectorAll('[data-file-action]').forEach((button) => {
      button.addEventListener('click', () => this.runFileAction(button.dataset.fileAction || ''));
    });
  }

  runFileAction(action) {
    if (!this.activeFile) return;
    const entry = this.activeFile;
    const key = String(entry.dataset.key || '');
    const name = String(entry.dataset.name || key);

    if (action === 'open') {
      this.openFileEntry(entry, false);
      return;
    }

    if (action === 'edit') {
      this.openFileEntry(entry, true);
      return;
    }

    if (action === 'download' && entry.dataset.downloadUrl) {
      this.window.location.href = entry.dataset.downloadUrl;
      this.hideContext();
      return;
    }

    if (action === 'security-lock') {
      this.protectFile(entry);
      return;
    }

    if (action === 'security-unlock') {
      this.unlockFile(entry);
      return;
    }

    if (action === 'security-relock') {
      this.relockFile(entry);
      return;
    }

    if (action === 'classic') {
      this.window.location.href = entry.dataset.classicUrl || 's3.php';
      return;
    }

    const serviceActions = {
      textract: () => this.invokeGlobal('extraerTexto', [key]),
      transcribe: () => this.invokeGlobal('abrirModalTranscribir', [key, name]),
      polly: () => this.invokeGlobal('abrirModalPolly', [key, name]),
      translate: () => this.invokeGlobal('abrirModalTraducir', [key, name]),
      rekognition: () => this.invokeGlobal('abrirModalRekognition', [key]),
      comprehend: () => this.invokeComprehend(key, name),
      'split-video': () => this.invokeMediaProcessing(entry, 'split_video'),
      'extract-mp3': () => this.invokeMediaProcessing(entry, 'extract_mp3'),
      'split-audio': () => this.invokeMediaProcessing(entry, 'split_audio')
    };

    const runner = serviceActions[action];
    if (runner) {
      runner();
      this.hideContext();
    }
  }

  invokeGlobal(name, args) {
    const fn = this.window[name];
    if (typeof fn !== 'function') {
      this.notify('La herramienta todavía no está disponible en este nodo: ' + name, 'warning');
      return false;
    }

    fn.apply(this.window, args || []);
    return true;
  }

  invokeComprehend(key, name) {
    const module = this.window.ArcadeCloudDrive &&
      this.window.ArcadeCloudDrive.modules &&
      this.window.ArcadeCloudDrive.modules['aws-comprehend'];

    if (!module || typeof module.analyze !== 'function') {
      this.notify('Amazon Comprehend todavía no está disponible.', 'warning');
      return false;
    }

    module.analyze(key, name);
    return true;
  }

  invokeMediaProcessing(entry, operation) {
    const module = this.window.ArcadeCloudDrive &&
      this.window.ArcadeCloudDrive.modules &&
      this.window.ArcadeCloudDrive.modules.mediaProcessing;

    if (!module || typeof module.openModal !== 'function') {
      this.notify('El procesamiento multimedia todavía no está disponible.', 'warning');
      return false;
    }

    module.openModal(entry, operation);
    return true;
  }

  bindDocumentDismiss() {
    this.document.addEventListener('click', (event) => {
      if (!event.target.closest('#fileContextMenu') && !event.target.closest('.os-file-entry')) {
        this.hideContext();
      }

      if (!event.target.closest('#osLauncher') && !event.target.closest('#osStart')) {
        this.closeLauncher();
      }

      if (!event.target.closest('#osTaskContext') && !event.target.closest('.os-task-more')) {
        this.hideTaskContext();
      }
    });
  }

  bindTaskRefresh() {
    [
      'drive:storage-changed',
      'drive:transcribe-completed',
      'drive:polly-task-completed',
      'drive:move-task-completed'
    ].forEach((eventName) => {
      this.document.addEventListener(eventName, () => this.markExplorerStale());
    });
  }

  markExplorerStale() {
    const refresh = this.document.querySelector('.os-explorer-toolbar a[title="Actualizar"]');
    if (!refresh) return;
    refresh.classList.add('has-update');
    refresh.title = 'Hay cambios nuevos. Actualizar carpeta';
  }

  notify(message, type) {
    let box = this.document.getElementById('osNotice');
    if (!box) {
      box = this.document.createElement('div');
      box.id = 'osNotice';
      box.className = 'os-notice';
      box.setAttribute('role', 'status');
      this.document.body.appendChild(box);
    }

    box.className = 'os-notice is-' + String(type || 'info');
    box.textContent = String(message || '');
    box.hidden = false;
    this.window.setTimeout(() => {
      if (box) box.hidden = true;
    }, 6000);
  }

  updateClock() {
    const clock = this.document.getElementById('osClock');
    if (!clock) return;

    const now = new Date();
    const time = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    }).format(now);
    const date = new Intl.DateTimeFormat(undefined, {
      weekday: 'short',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric'
    }).format(now);

    clock.innerHTML = '<strong></strong><small></small>';
    const timeNode = clock.querySelector('strong');
    const dateNode = clock.querySelector('small');
    if (timeNode) timeNode.textContent = time;
    if (dateNode) dateNode.textContent = date;
  }
}

window.ArcadeCloudOsShell = new ArcadeCloudOsShell(window, document).init();
