class ArcadeCloudOsFolderActions {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.context = doc.getElementById('folderContextMenu');
    this.contextName = doc.getElementById('folderContextName');
    this.current = this.normalizeFolder(
      win.ARCADECLOUD_OS_CURRENT_FOLDER || {}
    );
    this.activeFolder = null;
    this.pendingMove = null;
  }

  init() {
    this.bindFolderEntries();
    this.bindCurrentFolderToolbar();
    this.bindBlankAreaContext();
    this.bindContextMenu();
    this.bindMutationEvents();
    this.bindDismiss();
    return this;
  }

  rebind(root = this.document) {
    this.current = this.currentFolderFromDom(root);
    this.bindFolderEntries(root);
    this.bindCurrentFolderToolbar(root);
    this.bindBlankAreaContext(root);
    return this;
  }

  normalizeRoute(value) {
    let route = String(value || '')
      .replace(/\\/g, '/')
      .replace(/\/+/g, '/')
      .replace(/^\/+/, '')
      .trim();

    if (route !== '' && !route.endsWith('/')) route += '/';
    return route;
  }

  normalizeFolder(folder) {
    const route = this.normalizeRoute(folder.route || '');
    const name = String(folder.name || 'Carpeta').trim();
    return {
      route,
      name,
      isRoot: folder.isRoot === true || folder.is_root === true || String(folder.is_root || '') === '1',
      sourceWindowId: String(folder.sourceWindowId || '')
    };
  }

  baseName(route) {
    const clean = this.normalizeRoute(route).replace(/\/$/, '');
    const parts = clean.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : '';
  }

  parentRoute(route) {
    const clean = this.normalizeRoute(route).replace(/\/$/, '');
    const pos = clean.lastIndexOf('/');
    if (pos < 0) return this.normalizeRoute(this.window.ARCADECLOUD_OS_ROOT_ROUTE || '');
    return this.normalizeRoute(clean.slice(0, pos + 1));
  }

  sameRoute(a, b) {
    return this.normalizeRoute(a) === this.normalizeRoute(b);
  }

  currentFolderFromDom(root = this.document) {
    const host = root.querySelector('.os-explorer-body[data-current-folder-route]');
    if (!host) return this.current;

    const folder = this.normalizeFolder({
      route: host.dataset.currentFolderRoute || this.current.route,
      name: host.dataset.currentFolderName || this.current.name,
      is_root: host.dataset.currentFolderRoot || (this.current.isRoot ? '1' : '0')
    });
    folder.sourceWindowId = host.closest('.os-window')?.dataset.windowId || '';
    return folder;
  }

  folderFromEntry(entry) {
    const folder = this.normalizeFolder({
      route: entry?.dataset?.folderRoute || '',
      name: entry?.dataset?.folderName || '',
      is_root: entry?.dataset?.folderRoot || '0'
    });
    folder.sourceWindowId = entry?.closest?.('.os-window')?.dataset?.windowId || '';
    return folder;
  }

  bindFolderEntries(root = this.document) {
    root.querySelectorAll('.os-folder-entry').forEach((entry) => {
      if (entry.dataset.osFolderBound === '1') return;
      entry.dataset.osFolderBound = '1';

      entry.addEventListener('click', (event) => {
        if (event.target.closest('.os-folder-entry-menu')) return;
        event.preventDefault();
        this.navigate(this.folderFromEntry(entry).route, false);
      });

      entry.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        event.stopPropagation();
        this.showContext(this.folderFromEntry(entry), event.clientX, event.clientY, false);
      });

      entry.addEventListener('click', (event) => {
        const menu = event.target.closest('.os-folder-entry-menu');
        if (!menu) return;

        event.preventDefault();
        event.stopPropagation();

        const rect = menu.getBoundingClientRect();
        this.showContext(
          this.folderFromEntry(entry),
          rect.right - 12,
          rect.top + 24,
          false
        );
      });
    });
  }

  bindBlankAreaContext(root = this.document) {
    const body = root.querySelector('.os-explorer-body');
    if (!body || body.dataset.osFolderBlankBound === '1') return;
    body.dataset.osFolderBlankBound = '1';

    const showCurrentFolderMenu = (event) => {
      if (event.target.closest('.os-entry')) return;

      event.preventDefault();
      event.stopPropagation();

      this.window.ArcadeCloudOsShell?.clearFileSelection?.();

      this.showContext(
        this.currentFolderFromDom(),
        event.clientX,
        event.clientY,
        true
      );
    };

    body.addEventListener('contextmenu', showCurrentFolderMenu);
    body.addEventListener('click', (event) => {
      if (event.button !== undefined && event.button !== 0) return;
      showCurrentFolderMenu(event);
    });
  }

  bindCurrentFolderToolbar(root = this.document) {
    root.querySelectorAll('[data-current-folder-action]').forEach((button) => {
      if (button.dataset.osFolderActionBound === '1') return;
      button.dataset.osFolderActionBound = '1';

      button.addEventListener('click', async (event) => {
        event.preventDefault();
        if (button.disabled) return;

        const folder = this.currentFolderFromDom();
        await this.runAction(
          String(button.dataset.currentFolderAction || ''),
          folder,
          true
        );
      });
    });
  }

  bindContextMenu() {
    if (!this.context) return;

    this.context.querySelectorAll('[data-folder-action]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!this.activeFolder) return;

        const action = String(button.dataset.folderAction || '');
        const folder = this.activeFolder;
        const isCurrent = button.dataset.folderCurrent === '1';

        this.hideContext();
        await this.runAction(action, folder, isCurrent);
      });
    });
  }

  showContext(folder, x, y, isCurrent) {
    if (!this.context) return;

    folder = this.normalizeFolder(folder);
    if (!folder.route) return;

    const fileContext = this.document.getElementById('fileContextMenu');
    if (fileContext) fileContext.hidden = true;

    this.activeFolder = folder;
    if (this.contextName) {
      this.contextName.textContent = folder.name + (isCurrent ? ' · carpeta actual' : '');
    }

    const open = this.context.querySelector('[data-folder-action="open"]');
    if (open) open.hidden = isCurrent;

    this.context.querySelectorAll('[data-folder-mutating]').forEach((button) => {
      button.hidden = folder.isRoot;
      button.dataset.folderCurrent = isCurrent ? '1' : '0';
    });

    this.context.querySelectorAll('[data-folder-action]').forEach((button) => {
      button.dataset.folderCurrent = isCurrent ? '1' : '0';
    });

    const divider = this.context.querySelector('[data-folder-mutating-divider]');
    if (divider) divider.hidden = folder.isRoot;

    this.context.hidden = false;
    const rect = this.context.getBoundingClientRect();
    this.context.style.left = Math.max(4, Math.min(x, this.window.innerWidth - rect.width - 4)) + 'px';
    this.context.style.top = Math.max(4, Math.min(y, this.window.innerHeight - rect.height - 52)) + 'px';
  }

  hideContext() {
    if (!this.context) return;
    this.context.hidden = true;
    this.activeFolder = null;
  }

  bindDismiss() {
    this.document.addEventListener('click', (event) => {
      if (
        !event.target.closest('#folderContextMenu') &&
        !event.target.closest('.os-folder-entry-menu')
      ) {
        this.hideContext();
      }
    });
  }

  async runAction(action, folder, isCurrent) {
    folder = this.normalizeFolder(folder);

    if (!folder.route) {
      this.notify('No se pudo identificar la carpeta.', 'warning');
      return;
    }

    if (['move', 'rename', 'delete'].includes(action) && folder.isRoot) {
      this.notify('La carpeta raíz del usuario no permite esta acción.', 'warning');
      return;
    }

    if (action === 'open') {
      this.navigate(folder.route, false, folder.sourceWindowId);
      return;
    }

    if (action === 'open-new') {
      this.window.ArcadeCloudDesktop?.openExplorer?.(folder.route, { forceNew: true });
      return;
    }

    if (action === 'sync') {
      if (typeof this.window.triggerSyncFolderS3 !== 'function') {
        this.notify('La sincronización de carpetas no está disponible.', 'warning');
        return;
      }

      const completed = await this.window.triggerSyncFolderS3(folder.route, folder.name);
      if (completed === true && this.sameRoute(folder.route, this.current.route)) {
        this.navigate(this.current.route);
      }
      return;
    }

    if (action === 'create-document') {
      if (typeof this.window.openFolderDocumentCreator !== 'function') {
        this.notify('El creador de archivos todavía no está disponible.', 'warning');
        return;
      }

      this.window.openFolderDocumentCreator(folder.route, folder.name);
      return;
    }

    const api = this.window.ArcadeFolderActions;
    if (!api) {
      this.notify('Las acciones de carpeta todavía no están disponibles.', 'warning');
      return;
    }

    if (action === 'create-folder' && typeof api.openCreateFolder === 'function') {
      api.openCreateFolder(folder.route);
      return;
    }

    if (action === 'move' && typeof api.openMove === 'function') {
      api.openMove(folder.route, folder.name);
      return;
    }

    if (action === 'rename' && typeof api.openRename === 'function') {
      api.openRename(folder.route, folder.name);
      return;
    }

    if (action === 'delete' && typeof api.openDelete === 'function') {
      api.openDelete(folder.route, folder.name);
      return;
    }

    this.notify('La acción seleccionada todavía no está disponible.', 'warning');
  }

  bindMutationEvents() {
    this.document.addEventListener('drive:folder-document-created', (event) => {
      if (this.window.ArcadeCloudDesktop) return;
      const route = event.detail && event.detail.route
        ? this.normalizeRoute(event.detail.route)
        : '';

      if (route && this.sameRoute(route, this.current.route)) {
        this.window.setTimeout(() => this.navigate(this.current.route), 750);
      }
    });

    this.document.addEventListener('drive:folder-mutated', (event) => {
      if (this.window.ArcadeCloudDesktop) return;
      const detail = event.detail || {};
      const kind = String(detail.kind || '');
      const route = this.normalizeRoute(detail.route || '');

      if (kind === 'create') {
        if (this.sameRoute(route, this.current.route)) {
          this.window.setTimeout(() => this.navigate(this.current.route), 250);
        }
        return;
      }

      if (kind === 'rename') {
        if (this.sameRoute(route, this.current.route)) {
          this.window.setTimeout(() => this.navigate(this.current.route), 200);
        } else {
          this.window.setTimeout(() => this.navigate(this.current.route), 200);
        }
        return;
      }

      if (kind === 'delete') {
        if (this.sameRoute(route, this.current.route)) {
          this.window.setTimeout(() => this.navigate(this.parentRoute(route)), 200);
        } else {
          this.window.setTimeout(() => this.navigate(this.current.route), 200);
        }
      }
    });

    this.document.addEventListener('drive:folder-move-scheduled', (event) => {
      const detail = event.detail || {};
      const route = this.normalizeRoute(detail.route || '');
      const destination = this.normalizeRoute(detail.destination || '');
      if (!route || !destination) return;

      this.pendingMove = {
        route,
        destination,
        finalRoute: this.normalizeRoute(destination + this.baseName(route))
      };
    });

    this.document.addEventListener('drive:move-task-completed', () => {
      if (this.window.ArcadeCloudDesktop) return;
      if (!this.pendingMove) return;

      const pending = this.pendingMove;
      this.pendingMove = null;

      if (this.sameRoute(pending.route, this.current.route)) {
        this.navigate(pending.finalRoute);
      } else {
        this.navigate(this.current.route);
      }
    });

    this.document.addEventListener('drive:move-task-failed', () => {
      this.pendingMove = null;
    });

    this.document.addEventListener('drive:move-task-cancelled', () => {
      this.pendingMove = null;
    });
  }

  navigate(route, replaceHistory = true, sourceWindowId = '') {
    route = this.normalizeRoute(route || this.current.route);
    if (!route) return;

    const sourceId = sourceWindowId || this.document.querySelector('.os-explorer-window.is-active')?.dataset.windowId;
    const explorer = sourceId ? this.window.ArcadeCloudDesktop?.explorers?.get(sourceId) : null;
    if (explorer?.navigate) {
      explorer.navigate(route, { replace: replaceHistory });
      return;
    }
    if (
      this.window.ArcadeCloudOsShell &&
      typeof this.window.ArcadeCloudOsShell.refreshExplorer === 'function'
    ) {
      this.window.ArcadeCloudOsShell
        .refreshExplorer(route, { replaceHistory })
        .then((ok) => {
          if (ok) this.current = this.currentFolderFromDom();
        });
      return;
    }

    this.notify('No hay una ventana de Mis datos disponible para navegar.', 'warning');
  }

  notify(message, type) {
    if (
      this.window.ArcadeCloudOsShell &&
      typeof this.window.ArcadeCloudOsShell.notify === 'function'
    ) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
      return;
    }

    if (this.window.console) {
      this.window.console.log('[Carpetas/' + String(type || 'info') + '] ' + String(message || ''));
    }
  }
}

window.ArcadeCloudOsFolders = new ArcadeCloudOsFolderActions(window, document).init();
