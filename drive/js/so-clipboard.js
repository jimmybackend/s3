class ArcadeCloudOsClipboard {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.storageKey = 'arcadecloud.osClipboard';
    this.transferKey = 'arcadecloud.osClipboardTransfer';
    this.longPressMs = 560;
    this.moveTolerance = 12;
    this.clipboard = this.restoreClipboard();
    this.activeTransfer = this.restoreTransfer();
    this.pressState = new WeakMap();
    this.suppressUntil = new WeakMap();
    this.actionsBound = false;
    this.eventsBound = false;
  }

  init() {
    this.ensureStyles();
    this.ensureUi();
    this.bindActions();
    this.bindGlobalEvents();
    this.bindEntries();
    this.updatePasteControls();
    this.reconcileClipboardJob();

    if (this.activeTransfer && this.activeTransfer.jobId) {
      this.showTransfer({
        operation: this.activeTransfer.operation,
        name: this.activeTransfer.name,
        progress: null,
        message: 'Recuperando estado de la transferencia…'
      });
    }

    return this;
  }

  ensureStyles() {
    if (this.document.querySelector('link[data-os-clipboard-style]')) return;
    const link = this.document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'css/so-clipboard.css?v=20260927-2';
    link.setAttribute('data-os-clipboard-style', '1');
    this.document.head.appendChild(link);
  }

  ensureUi() {
    this.injectFileActions();
    this.injectFolderActions();
    this.injectPasteToolbar();
    this.ensureTransferHud();
  }

  makeContextButton(action, kind, icon, label, extraClass) {
    const button = this.document.createElement('button');
    button.type = 'button';
    button.dataset.osClipboardAction = action;
    if (kind) button.dataset.osClipboardKind = kind;
    if (extraClass) button.className = extraClass;

    const i = this.document.createElement('i');
    i.className = icon;
    const span = this.document.createElement('span');
    span.textContent = label;
    button.appendChild(i);
    button.appendChild(span);
    return button;
  }

  injectFileActions() {
    const menu = this.document.getElementById('fileContextMenu');
    if (!menu || menu.querySelector('[data-os-clipboard-action="copy"][data-os-clipboard-kind="file"]')) return;

    const divider = menu.querySelector('[data-service-divider]');
    const anchor = divider || null;
    const copy = this.makeContextButton('copy', 'file', 'fas fa-copy', 'Copiar');
    const cut = this.makeContextButton('cut', 'file', 'fas fa-scissors', 'Mover');
    const share = this.makeContextButton('share', 'file', 'fas fa-share-nodes', 'Compartir');
    const remove = this.makeContextButton('delete', 'file', 'fas fa-trash', 'Eliminar', 'is-danger');

    [copy, cut, share, remove].forEach((button) => menu.insertBefore(button, anchor));
  }

  injectFolderActions() {
    const menu = this.document.getElementById('folderContextMenu');
    if (!menu || menu.querySelector('[data-os-clipboard-action="copy"][data-os-clipboard-kind="folder"]')) return;

    const copy = this.makeContextButton('copy', 'folder', 'fas fa-copy', 'Copiar carpeta');
    const cut = this.makeContextButton('cut', 'folder', 'fas fa-scissors', 'Mover carpeta');
    const paste = this.makeContextButton('paste', 'folder', 'fas fa-paste', 'Pegar dentro');
    paste.classList.add('os-clipboard-paste');
    paste.dataset.osPasteTarget = 'folder';

    menu.appendChild(copy);
    menu.appendChild(cut);
    menu.appendChild(paste);
  }

  injectPasteToolbar(root = this.document) {
    const toolbars = root.querySelectorAll('.os-explorer-live .os-explorer-toolbar');
    toolbars.forEach((toolbar) => this.injectPasteButton(toolbar));
  }

  injectPasteButton(toolbar) {
    if (!toolbar || toolbar.querySelector('[data-os-paste-current]')) return;

    const button = this.document.createElement('button');
    button.type = 'button';
    button.className = 'os-toolbar-paste os-clipboard-paste';
    button.dataset.osClipboardAction = 'paste';
    button.dataset.osPasteCurrent = '1';
    button.hidden = false;
    button.disabled = true;

    const icon = this.document.createElement('i');
    icon.className = 'fas fa-paste';
    const label = this.document.createElement('span');
    label.textContent = 'Pegar aquí';

    button.appendChild(icon);
    button.appendChild(label);

    const pager = toolbar.querySelector('.os-folder-pagination');
    toolbar.insertBefore(button, pager || null);
  }

  ensureTransferHud() {
    let hud = this.document.getElementById('osTransferHud');
    if (hud) return hud;

    hud = this.document.createElement('section');
    hud.id = 'osTransferHud';
    hud.className = 'os-transfer-hud';
    hud.hidden = true;
    hud.setAttribute('role', 'status');
    hud.setAttribute('aria-live', 'polite');

    const head = this.document.createElement('div');
    head.className = 'os-transfer-head';
    const icon = this.document.createElement('i');
    icon.className = 'fas fa-cloud-arrow-up';
    const title = this.document.createElement('strong');
    title.dataset.osTransferTitle = '1';
    const percent = this.document.createElement('span');
    percent.className = 'os-transfer-percent';
    percent.dataset.osTransferPercent = '1';
    head.appendChild(icon);
    head.appendChild(title);
    head.appendChild(percent);

    const detail = this.document.createElement('div');
    detail.className = 'os-transfer-detail';
    detail.dataset.osTransferDetail = '1';

    const row = this.document.createElement('div');
    row.className = 'os-transfer-row';
    const track = this.document.createElement('div');
    track.className = 'os-transfer-track';
    track.dataset.osTransferTrack = '1';
    const bar = this.document.createElement('div');
    bar.className = 'os-transfer-bar';
    bar.dataset.osTransferBar = '1';
    track.appendChild(bar);
    row.appendChild(track);

    hud.appendChild(head);
    hud.appendChild(detail);
    hud.appendChild(row);
    this.document.body.appendChild(hud);
    return hud;
  }

  bindActions() {
    if (this.actionsBound) return;
    this.actionsBound = true;

    this.document.addEventListener('click', async (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const button = target ? target.closest('[data-os-clipboard-action]') : null;
      if (!button) return;

      event.preventDefault();
      event.stopPropagation();

      const action = String(button.dataset.osClipboardAction || '');
      const kind = String(button.dataset.osClipboardKind || '');

      if (action === 'paste') {
        // Resolve the destination from the Explorer that owns the pressed
        // toolbar button, rather than whichever window happened to be active.
        let destination = String(button.closest('.os-explorer-live')?.dataset.explorerRoute || this.currentRoute());
        if (button.dataset.osPasteTarget === 'folder') {
          const folder = this.window.ArcadeCloudOsFolders?.activeFolder;
          if (folder && folder.route) destination = String(folder.route);
          this.window.ArcadeCloudOsFolders?.hideContext?.();
        }
        const destinationWindowId = button.closest('.os-window')?.dataset.windowId
          || (button.dataset.osPasteTarget === 'folder' ? this.window.ArcadeCloudOsFolders?.activeFolder?.sourceWindowId : '')
          || '';
        await this.paste(destination, { destinationWindowId });
        return;
      }

      if (kind === 'file') {
        const entry = this.window.ArcadeCloudOsShell?.activeFile;
        if (!entry) return;

        if (action === 'copy' || action === 'cut') {
          this.captureFiles(entry, action === 'copy' ? 'copy' : 'move');
        } else if (action === 'share') {
          await this.shareFile(entry);
        } else if (action === 'delete') {
          await this.deleteFile(entry);
        }
        this.window.ArcadeCloudOsShell?.hideContext?.();
        return;
      }

      if (kind === 'folder') {
        const folder = this.window.ArcadeCloudOsFolders?.activeFolder;
        if (!folder) return;
        if (action === 'copy' || action === 'cut') {
          this.captureFolder(folder, action === 'copy' ? 'copy' : 'move');
        }
        this.window.ArcadeCloudOsFolders?.hideContext?.();
      }
    });
  }

  bindGlobalEvents() {
    if (this.eventsBound) return;
    this.eventsBound = true;

    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const entry = target ? target.closest('.os-file-entry, .os-folder-entry') : null;
      if (!entry) return;
      const until = Number(this.suppressUntil.get(entry) || 0);
      if (until > Date.now()) {
        event.preventDefault();
        event.stopImmediatePropagation();
        this.suppressUntil.delete(entry);
      }
    }, true);

    this.document.addEventListener('arcadeos:explorer-updated', () => {
      this.ensureUi();
      this.bindEntries();
      this.updatePasteControls();
    });

    this.document.addEventListener('drive:move-task-progress', (event) => {
      this.onTransferProgress(event.detail || {});
    });

    this.document.addEventListener('drive:move-task-completed', (event) => {
      this.onTransferCompleted(event.detail || {});
    });

    this.document.addEventListener('drive:move-task-failed', (event) => {
      this.onTransferFailed(event.detail || {}, false);
    });

    this.document.addEventListener('drive:move-task-cancelled', (event) => {
      this.onTransferFailed(event.detail || {}, true);
    });
  }

  bindEntries(root = this.document) {
    root.querySelectorAll('.os-file-entry').forEach((entry) => {
      if (entry.dataset.osClipboardBound === '1') return;
      entry.dataset.osClipboardBound = '1';
      this.bindLongPress(entry, 'file');
    });

    root.querySelectorAll('.os-folder-entry').forEach((entry) => {
      if (entry.dataset.osClipboardBound === '1') return;
      entry.dataset.osClipboardBound = '1';
      this.bindLongPress(entry, 'folder');
    });
  }

  bindLongPress(entry, kind) {
    const clear = () => {
      const state = this.pressState.get(entry);
      if (!state) return;
      if (state.timer) this.window.clearTimeout(state.timer);
      entry.classList.remove('is-os-pressed');
      this.pressState.delete(entry);
    };

    entry.addEventListener('pointerdown', (event) => {
      if (event.button !== undefined && event.button !== 0) return;
      const target = event.target instanceof Element ? event.target : null;
      if (target?.closest('.os-entry-menu, .os-folder-entry-menu')) return;

      clear();
      const state = {
        x: event.clientX,
        y: event.clientY,
        pointerId: event.pointerId,
        fired: false,
        timer: null
      };

      state.timer = this.window.setTimeout(() => {
        state.fired = true;
        entry.classList.add('is-os-pressed');
        this.suppressUntil.set(entry, Date.now() + 900);

        if (kind === 'file') {
          this.window.ArcadeCloudOsShell?.showContext?.(entry, state.x, state.y);
        } else {
          const folders = this.window.ArcadeCloudOsFolders;
          if (folders && typeof folders.folderFromEntry === 'function') {
            folders.showContext(folders.folderFromEntry(entry), state.x, state.y, false);
          }
        }
      }, this.longPressMs);

      this.pressState.set(entry, state);
    });

    entry.addEventListener('pointermove', (event) => {
      const state = this.pressState.get(entry);
      if (!state || state.pointerId !== event.pointerId || state.fired) return;
      const dx = Math.abs(event.clientX - state.x);
      const dy = Math.abs(event.clientY - state.y);
      if (dx > this.moveTolerance || dy > this.moveTolerance) clear();
    });

    entry.addEventListener('dragstart', clear);

    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((name) => {
      entry.addEventListener(name, () => clear());
    });
  }

  captureFiles(entry, mode, context = {}) {
    const operation = mode === 'copy' ? 'copy' : 'cut';
    mode = operation === 'copy' ? 'copy' : 'move';
    const shell = this.window.ArcadeCloudOsShell;
    const selected = Array.isArray(context.entries) ? context.entries : typeof shell?.selectedFileEntries === 'function'
      ? shell.selectedFileEntries()
      : [];

    const entries = entry.classList.contains('is-selected') && selected.length > 0
      ? selected
      : [entry];

    const locked = entries.find((item) => String(item.dataset.locked || '0') === '1');
    if (locked) {
      this.notify('Desbloquea los archivos protegidos antes de copiarlos o moverlos.', 'warning');
      return;
    }

    const keys = Array.from(new Set(entries
      .map((item) => String(item.dataset.key || '').trim())
      .filter(Boolean)));

    if (keys.length === 0) {
      this.notify('No se pudieron identificar los archivos seleccionados.', 'warning');
      return;
    }

    const name = keys.length === 1
      ? String(entries[0]?.dataset?.name || keys[0])
      : keys.length + ' archivos';

    this.setClipboard({
      kind: 'file',
      mode,
      operation,
      key: keys[0],
      keys,
      items: keys,
      name,
      count: keys.length,
      sourceWindowId: context.sourceWindowId || entry.closest('.os-window')?.dataset.windowId || '',
      sourceRoute: context.sourceRoute || entry.closest('.os-explorer-live')?.dataset.explorerRoute || this.currentRoute()
    });
  }

  captureFolder(folder, mode, context = {}) {
    const operation = mode === 'copy' ? 'copy' : 'cut';
    mode = operation === 'copy' ? 'copy' : 'move';
    const root = String(this.window.ARCADECLOUD_OS_ROOT_ROUTE || '').trim();
    const route = String(folder.route || '').trim();
    if (!route || folder.isRoot || (root && this.sameRoute(route, root))) {
      this.notify('La carpeta raíz no se puede copiar ni mover.', 'warning');
      return;
    }

    this.setClipboard({
      kind: 'folder',
      mode,
      operation,
      route,
      items: [route],
      name: String(folder.name || 'Carpeta'),
      sourceWindowId: context.sourceWindowId || folder.sourceWindowId || '',
      sourceRoute: String(context.sourceRoute || folder.parent || this.currentRoute())
    });
  }

  setClipboard(item) {
    item.operation = item.operation || item.mode;
    item.items = Array.isArray(item.items) ? item.items : (item.keys || (item.route ? [item.route] : []));
    const immutableItems = Object.freeze([...(Array.isArray(item.items) ? item.items : [])]);
    this.clipboard = Object.freeze(Object.assign(
      { version: 2, createdAt: Date.now(), transferJobId: '', transferPending: false },
      item,
      { items: immutableItems, keys: Object.freeze([...(item.keys || [])]) }
    ));
    try {
      this.window.sessionStorage.setItem(this.storageKey, JSON.stringify(this.clipboard));
    } catch (_) {}

    this.updatePasteControls();
    this.dispatchClipboardChanged();
    const verb = item.mode === 'copy' ? 'copiar' : 'mover';
    this.notify(
      String(item.name || 'Elemento') + ' listo para ' + verb + '. Navega a la carpeta destino y pulsa Pegar.',
      'success'
    );
  }

  clearClipboard() {
    this.clipboard = null;
    try { this.window.sessionStorage.removeItem(this.storageKey); } catch (_) {}
    this.updatePasteControls();
    this.dispatchClipboardChanged();
  }

  restoreClipboard() {
    try {
      const raw = this.window.sessionStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : null;
      if (!parsed || Number(parsed.version || 0) !== 2 || !['file', 'folder'].includes(parsed.kind) || !['copy', 'move'].includes(parsed.mode)) {
        return null;
      }
      return parsed;
    } catch (_) {
      return null;
    }
  }

  updatePasteControls() {
    const hasClipboard = Boolean(this.clipboard);
    const transferPending = Boolean(this.clipboard?.transferPending);

    this.document.querySelectorAll('.os-clipboard-paste').forEach((button) => {
      const isToolbarPaste = button.dataset.osPasteCurrent === '1';
      button.hidden = false;
      button.disabled = !hasClipboard || transferPending;

      const icon = button.querySelector('i');
      const label = button.querySelector('span');

      if (!hasClipboard) {
        button.title = 'No hay nada pendiente para pegar o mover';
        if (isToolbarPaste) {
          if (icon) icon.className = 'fas fa-paste';
          if (label) label.textContent = 'Pegar aquí';
        }
        return;
      }

      const moving = String(this.clipboard.mode || '') === 'move';
      const actionLabel = moving ? 'Mover aquí' : 'Copiar aquí';
      const name = String(this.clipboard.name || 'elemento');

      button.title = transferPending
        ? 'Transferencia en curso: ' + name
        : actionLabel + ': ' + name;

      if (isToolbarPaste) {
        if (icon) icon.className = moving ? 'fas fa-arrow-right-to-bracket' : 'fas fa-paste';
        if (label) label.textContent = transferPending ? 'En proceso…' : actionLabel;
      }
    });
  }

  markClipboardTransfer(jobId = '') {
    if (!this.clipboard) return;
    const next = {
      ...this.clipboard,
      transferJobId: String(jobId || ''),
      transferPending: Boolean(jobId)
    };
    next.items = Array.isArray(next.items) ? [...next.items] : [];
    next.keys = Array.isArray(next.keys) ? [...next.keys] : [];
    this.clipboard = Object.freeze({
      ...next,
      items: Object.freeze(next.items),
      keys: Object.freeze(next.keys)
    });
    try {
      this.window.sessionStorage.setItem(this.storageKey, JSON.stringify(this.clipboard));
    } catch (_) {}
    this.updatePasteControls();
    this.dispatchClipboardChanged();
  }

  dispatchClipboardChanged() {
    try {
      this.document.dispatchEvent(new CustomEvent('arcadeos:clipboard-changed', {
        detail: {
          hasClipboard: Boolean(this.clipboard),
          mode: String(this.clipboard?.mode || ''),
          transferPending: Boolean(this.clipboard?.transferPending)
        }
      }));
    } catch (_) {}
  }

  async reconcileClipboardJob() {
    const jobId = String(this.clipboard?.transferJobId || '').trim();
    if (!jobId) return;
    try {
      const url = new URL('move_task_status.php', this.window.location.href);
      url.searchParams.set('job_id', jobId);
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await response.json();
      if (!response.ok || json?.ok !== true) return;
      const status = String(json.estado || '').toLowerCase();
      if (status === 'completed') {
        this.clearClipboard();
      } else if (['failed', 'cancelled'].includes(status)) {
        this.markClipboardTransfer('');
      } else {
        this.markClipboardTransfer(jobId);
      }
    } catch (_) {
      // Si el estado no puede consultarse, no se habilita una segunda transferencia
      // cuando el portapapeles ya estaba marcado como pendiente.
      this.updatePasteControls();
    }
  }

  async paste(destination, context = {}) {
    const item = this.clipboard;
    if (!item) {
      this.notify('Primero copia o corta un archivo o carpeta.', 'warning');
      return;
    }
    if (item.transferPending) {
      this.notify('La transferencia anterior todavía está en proceso.', 'info');
      return;
    }

    destination = String(destination || this.currentRoute()).trim();
    if (!destination) {
      this.notify('No se pudo identificar la carpeta destino.', 'warning');
      return;
    }

    if (item.mode === 'move' && this.sameRoute(item.sourceRoute, destination)) {
      this.notify('El elemento ya está en esta carpeta.', 'warning');
      return;
    }

    const operations = this.window.ArcadeCloudFilesystemOperations;
    if (!operations || typeof operations.startTransfer !== 'function') {
      this.notify('El servicio de transferencias no está disponible.', 'danger');
      return;
    }

    const payload = {
      type: item.kind === 'file' ? 'files' : 'folder',
      operation: item.mode,
      ruta_actual: String(item.sourceRoute || this.currentRoute())
    };

    if (item.kind === 'file') {
      const keys = Array.isArray(item.keys) && item.keys.length > 0
        ? item.keys.map((key) => String(key || '').trim()).filter(Boolean)
        : [String(item.key || '').trim()].filter(Boolean);

      if (keys.length === 0) {
        this.notify('No hay archivos válidos para transferir.', 'warning');
        return;
      }

      payload.archivos_json = JSON.stringify(keys);
      payload.nueva_ruta = destination;
    } else {
      payload.origen = item.route;
      payload.destino = destination;
    }

    this.showTransfer({
      operation: item.mode,
      name: item.name,
      progress: 0,
      message: item.mode === 'copy' ? 'Preparando copia…' : 'Preparando movimiento…'
    });

    try {
      const { result: started } = await operations.startTransfer({
        type: item.mode,
        items: Array.isArray(item.items) ? [...item.items] : [],
        sourceRoute: String(item.sourceRoute || ''),
        destinationRoute: destination,
        sourceWindowId: String(item.sourceWindowId || ''),
        destinationWindowId: String(context.destinationWindowId || ''),
        progress: null
      }, payload);
      this.activeTransfer = {
        jobId: String(started.job_id),
        operation: item.mode,
        kind: item.kind,
        name: item.name,
        sourceRoute: String(item.sourceRoute || ''),
        sourceWindowId: String(item.sourceWindowId || ''),
        destinationRoute: destination,
        destinationWindowId: String(context.destinationWindowId || ''),
        items: Array.isArray(item.items) ? [...item.items] : [],
        clearClipboardOnSuccess: true
      };
      this.markClipboardTransfer(String(started.job_id));
      this.persistTransfer();
    } catch (error) {
      this.hideTransferSoon(2500);
      this.notify('No se pudo iniciar la transferencia: ' + (error?.message || error), 'danger');
    }
  }

  onTransferProgress(detail) {
    if (!this.activeTransfer || String(detail.job_id || '') !== String(this.activeTransfer.jobId || '')) {
      return;
    }

    const raw = detail.progress;
    const progress = raw === null || raw === undefined || raw === ''
      ? null
      : Math.max(0, Math.min(100, Number(raw)));

    this.showTransfer({
      operation: String(detail.operation || this.activeTransfer.operation || 'move'),
      name: this.activeTransfer.name,
      progress: Number.isFinite(progress) ? progress : null,
      message: String(detail.mensaje || ''),
      processed: Number(detail.processed_items || 0),
      requested: Number(detail.requested_items || 0)
    });
  }

  async onTransferCompleted(detail) {
    const jobId = String(detail.job_id || '');
    if (this.clipboard && jobId && jobId === String(this.clipboard.transferJobId || '')) {
      this.clearClipboard();
    }
    if (!this.activeTransfer || jobId !== String(this.activeTransfer.jobId || '')) {
      return;
    }

    const finished = this.activeTransfer;
    this.activeTransfer = null;
    this.persistTransfer();

    this.showTransfer({
      operation: String(detail.operation || finished.operation || 'move'),
      name: finished.name,
      progress: 100,
      message: String(detail.mensaje || 'Transferencia completada.')
    });

    if (finished.clearClipboardOnSuccess && this.clipboard) this.clearClipboard();

    this.window.ArcadeCloudDesktop?.emitFilesystemChanged?.({
      operation: finished.operation,
      sourceRoute: finished.sourceRoute,
      destinationRoute: finished.destinationRoute,
      sourceWindowId: finished.sourceWindowId,
      destinationWindowId: finished.destinationWindowId,
      items: finished.items || []
    });

    this.hideTransferSoon(1600);
  }

  onTransferFailed(detail, cancelled) {
    const jobId = String(detail.job_id || '');
    if (this.clipboard && jobId && jobId === String(this.clipboard.transferJobId || '')) {
      this.markClipboardTransfer('');
    }
    if (!this.activeTransfer || jobId !== String(this.activeTransfer.jobId || '')) {
      return;
    }

    const failed = this.activeTransfer;
    this.activeTransfer = null;
    this.persistTransfer();

    this.showTransfer({
      operation: failed.operation,
      name: failed.name,
      progress: null,
      message: cancelled
        ? String(detail.mensaje || 'Transferencia cancelada.')
        : String(detail.error || detail.mensaje || 'La transferencia falló.'),
      processed: Number(detail.processed_items || 0),
      requested: Number(detail.requested_items || 0)
    });
    this.hideTransferSoon(4500);
  }

  persistTransfer() {
    try {
      if (this.activeTransfer) {
        this.window.sessionStorage.setItem(this.transferKey, JSON.stringify(this.activeTransfer));
      } else {
        this.window.sessionStorage.removeItem(this.transferKey);
      }
    } catch (_) {}
  }

  restoreTransfer() {
    try {
      const raw = this.window.sessionStorage.getItem(this.transferKey);
      const parsed = raw ? JSON.parse(raw) : null;
      return parsed && parsed.jobId ? parsed : null;
    } catch (_) {
      return null;
    }
  }

  showTransfer(state) {
    const hud = this.ensureTransferHud();
    const title = hud.querySelector('[data-os-transfer-title]');
    const detail = hud.querySelector('[data-os-transfer-detail]');
    const percent = hud.querySelector('[data-os-transfer-percent]');
    const track = hud.querySelector('[data-os-transfer-track]');
    const bar = hud.querySelector('[data-os-transfer-bar]');

    const copying = String(state.operation || 'move') === 'copy';
    if (title) title.textContent = (copying ? 'Copiando · ' : 'Moviendo · ') + String(state.name || 'elemento');

    let message = String(state.message || '');
    const requested = Number(state.requested || 0);
    const processed = Number(state.processed || 0);
    if (requested > 0 && processed >= 0) {
      message += (message ? ' · ' : '') + processed + ' de ' + requested;
    }
    if (detail) detail.textContent = message;

    const value = state.progress;
    const determinate = value !== null && value !== undefined && Number.isFinite(Number(value));
    if (track) track.classList.toggle('is-indeterminate', !determinate);
    if (bar) bar.style.width = determinate ? Math.max(0, Math.min(100, Number(value))) + '%' : '38%';
    if (percent) percent.textContent = determinate ? Math.round(Number(value)) + '%' : '…';

    hud.hidden = false;
  }

  hideTransferSoon(delay) {
    this.window.setTimeout(() => {
      const hud = this.document.getElementById('osTransferHud');
      if (hud && !this.activeTransfer) hud.hidden = true;
    }, Math.max(0, Number(delay || 0)));
  }

  async shareFile(entry) {
    if (String(entry.dataset.locked || '0') === '1') {
      this.notify('Desbloquea el archivo antes de compartirlo.', 'warning');
      return;
    }

    if (this.window.ArcadeCloudOsShare?.open) {
      this.window.ArcadeCloudOsShare.open(entry);
      return;
    }

    this.notify('El panel completo de compartir todavía no está disponible. Actualiza ArcadeCloud OS.', 'warning');
  }

  async deleteFile(entry) {
    if (String(entry.dataset.locked || '0') === '1') {
      this.notify('Desbloquea el archivo antes de eliminarlo.', 'warning');
      return;
    }

    const key = String(entry.dataset.key || '').trim();
    const name = String(entry.dataset.name || key);
    if (!key) return;
    const operations = this.window.ArcadeCloudFilesystemOperations;
    if (!operations) {
      this.notify('El servicio de operaciones no está disponible.', 'danger');
      return;
    }
    const confirmed = await operations.confirm({
      title: 'Eliminar archivo',
      message: 'Se eliminará “' + name + '” de forma permanente.',
      confirmLabel: 'Eliminar',
      danger: true
    });
    if (!confirmed) return;

    try {
      const route = String(entry.closest('.os-explorer-live')?.dataset.explorerRoute || this.currentRoute());
      await operations.run({ type: 'delete', items: [key], sourceRoute: route },
        () => operations.request('eliminar_archivo.php', { archivo: key }));
      this.notify('Archivo eliminado correctamente.', 'success');
    } catch (error) {
      this.notify('No se pudo eliminar: ' + (error?.message || error), 'danger');
    }
  }

  shareTypeForExtension(ext) {
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff'].includes(ext)) return 'imagen';
    if (['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'ogg', 'opus', 'm4a', 'flac', 'amr'].includes(ext)) return 'audio';
    return 'otro';
  }

  async copyText(value) {
    try {
      if (this.window.navigator.clipboard?.writeText) {
        await this.window.navigator.clipboard.writeText(value);
        return true;
      }
    } catch (_) {}

    try {
      const area = this.document.createElement('textarea');
      area.value = value;
      area.style.position = 'fixed';
      area.style.opacity = '0';
      this.document.body.appendChild(area);
      area.focus();
      area.select();
      const ok = this.document.execCommand('copy');
      area.remove();
      return Boolean(ok);
    } catch (_) {
      return false;
    }
  }

  currentRoute() {
    const explorer = this.document.querySelector('.os-explorer-window.is-active .os-explorer-live');
    return String(explorer?.dataset.explorerRoute || this.window.DRIVE_INITIAL_ROUTE || '').trim();
  }

  sameRoute(a, b) {
    const normalize = (value) => {
      value = String(value || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/^\/+/, '');
      return value ? value.replace(/\/+$/, '') + '/' : '';
    };
    return normalize(a) === normalize(b);
  }

  notify(message, type) {
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
      return;
    }
    console[type === 'danger' ? 'error' : 'log']('[ArcadeCloud OS]', message);
  }
  static boot(win = window, doc = document) {
    if (win.ArcadeCloudOsClipboard instanceof ArcadeCloudOsClipboard) return win.ArcadeCloudOsClipboard;

    // The OS starts without an Explorer DOM node and creates Explorer windows
    // dynamically. Clipboard state must therefore boot with the desktop, not
    // wait for .os-explorer-live to already exist.
    if (!win.ArcadeCloudOsShell || !win.ArcadeCloudOsFolders || !win.DriveMoveTasks) {
      return null;
    }

    win.ArcadeCloudOsClipboard = new ArcadeCloudOsClipboard(win, doc).init();
    return win.ArcadeCloudOsClipboard;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudOsClipboard };
if (typeof window !== 'undefined' && typeof document !== 'undefined') ArcadeCloudOsClipboard.boot(window, document);
