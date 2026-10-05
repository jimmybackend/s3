/* ArcadeCloud OS window runtime. Storage operations intentionally stay in the
 * existing Drive controllers; this module owns window/application state. */
class ArcadeCloudEventBus extends EventTarget {
  emit(type, detail = {}) { this.dispatchEvent(new CustomEvent(type, { detail })); }
  on(type, listener, options) {
    this.addEventListener(type, listener, options);
    return () => this.removeEventListener(type, listener, options);
  }
}

/* Single source of truth for every normal window. Values are viewport ratios,
 * not CSS scattered through factories/templates. Maximized geometry is a
 * transient state and is deliberately absent from this configuration. */
class ArcadeCloudWindowLayoutConfig {
  static MOBILE_BREAKPOINT = 700;
  static DESKTOP_PERSISTENCE_BREAKPOINT = 1180;
  static DEFINITIONS = Object.freeze({
    explorer: { width: .42, height: .42, minWidth: 320, minHeight: 260 },
    tool:     { width: .42, height: .42, minWidth: 320, minHeight: 260 },
    node:     { width: .46, height: .50, minWidth: 420, minHeight: 320 },
    settings: { width: .40, height: .45, minWidth: 320, minHeight: 260 },
    terminal: { width: .45, height: .42, minWidth: 360, minHeight: 260 },
    viewer:   { width: .42, height: .42, minWidth: 320, minHeight: 260 },
    default:  { width: .42, height: .42, minWidth: 320, minHeight: 260 }
  });

  static key(app) {
    if (/^(image|pdf|text|text-preview|audio|video|viewer)$/.test(app)) return 'viewer';
    if (app === 'tool' || app.startsWith('tool-')) return 'tool';
    return this.DEFINITIONS[app] ? app : 'default';
  }

  static definition(app) { return this.DEFINITIONS[this.key(app)]; }
}

class ArcadeCloudWindowManager {
  constructor(win, doc, bus) {
    this.window = win;
    this.document = doc;
    this.bus = bus;
    this.registry = new Map();
    this.apps = new Map();
    this.counter = 0;
    this.zCounter = 200;
    this.taskbar = doc.getElementById('osTaskButtons');
    this.preferences = Object.assign({}, win.ARCADECLOUD_OS_APPEARANCE?.preferences?.windowPreferences || {});
    this.preferenceTimers = new Map();
    this.closeGuards = new Map();
    this.layoutSequence = 0;
  }

  registerApp(app, definition) {
    this.apps.set(app, Object.assign({ multiInstance: false, lifecycle: 'persistent', title: app, icon: 'fa-window-maximize' }, definition));
  }

  registerCloseGuard(app, callback) {
    if (typeof callback === 'function') this.closeGuards.set(app, callback);
  }

  async requestClose(elementOrId) {
    const record = this.record(elementOrId);
    if (!record) return false;
    const guard = this.closeGuards.get(record.app);
    if (guard) {
      try {
        const allowed = await guard(record);
        if (allowed === false) return false;
      } catch (_) {
        return false;
      }
    }
    this.close(record.id);
    return true;
  }

  nextId(app) {
    this.counter += 1;
    return `${app}-${Date.now().toString(36)}-${this.counter.toString(36)}`;
  }

  record(elementOrId) {
    const id = typeof elementOrId === 'string' ? elementOrId : elementOrId?.dataset?.windowId;
    return id ? this.registry.get(id) : null;
  }

  last(app) {
    return [...this.registry.values()].filter(record => record.app === app)
      .sort((a, b) => b.lastFocused - a.lastFocused)[0] || null;
  }

  get activeId() {
    return [...this.registry.values()].find(record => record.focused && record.status === 'open')?.id || null;
  }

  openRecords() {
    return [...this.registry.values()].filter(record => ['open', 'minimized'].includes(record.status));
  }

  focusMostRecent(excludeId = '') {
    const next = this.openRecords().filter(record => record.id !== excludeId && !record.minimized)
      .sort((a, b) => b.lastFocused - a.lastFocused)[0];
    if (next) this.focus(next.id);
  }

  register(element, app = 'window', state = {}, cleanup) {
    if (!element) return null;
    const definition = this.apps.get(app);
    if (definition && !definition.multiInstance) {
      const existing = this.last(app);
      if (existing && existing.element !== element) return existing;
    }
    const id = element.dataset.windowId || this.nextId(app);
    element.dataset.windowId = id;
    element.dataset.appId = app;
    const lifecycle = element.dataset.windowLifecycle || definition?.lifecycle || (element.dataset.dynamicWindow === '1' ? 'dynamic' : 'persistent');
    element.dataset.windowLifecycle = lifecycle;
    let record = this.registry.get(id);
    const isNew = !record;
    if (!record) {
      record = {
        id, app, element, state, cleanup: new Set(), taskButton: null,
        lifecycle, multiInstance: Boolean(definition?.multiInstance), status: 'registered', open: false,
        focused: false, minimized: false, maximized: false, compact: false,
        lastFocused: 0, geometry: null, preferredGeometry: null
      };
      this.registry.set(id, record);
    } else {
      record.element = element;
      record.state = state || record.state;
    }
    if (typeof cleanup === 'function') record.cleanup.add(cleanup);
    if (!isNew) return record;
    // Registration is deliberately inert: templates and persistent applications
    // never become visible, focused, or represented in the taskbar until open().
    element.classList.remove('is-open', 'is-active', 'is-maximized');
    element.dataset.minimized = '0';
    record.preferredGeometry = this.applyInitialGeometry(app, element);
    this.observeResize(record);
    this.syncTaskbar();
    return record;
  }

  addCleanup(id, callback) {
    const record = this.registry.get(id);
    if (record && typeof callback === 'function') record.cleanup.add(callback);
  }

  focus(elementOrId) {
    const record = this.record(elementOrId);
    if (!record || !['open', 'minimized'].includes(record.status)) return;
    record.minimized = false;
    record.status = 'open';
    record.open = true;
    record.element.dataset.minimized = '0';
    record.element.hidden = false;
    record.element.classList.add('is-open');
    this.registry.forEach(item => {
      item.focused = item.id === record.id;
      item.element.classList.toggle('is-active', item.focused);
    });
    record.lastFocused = Date.now();
    if (++this.zCounter > 900) this.compactZ();
    record.element.style.zIndex = String(++this.zCounter);
    this.syncTaskbar();
    this.bus.emit('window-focused', { windowId: record.id, app: record.app });
  }

  open(elementOrId) {
    const record = this.record(elementOrId);
    if (!record) return null;
    const wasClosed = !['open', 'minimized'].includes(record.status);
    record.status = 'open';
    record.minimized = false;
    record.element.dataset.minimized = '0';
    record.element.hidden = false;
    record.element.classList.add('is-open');
    this.focus(record.id);
    if (wasClosed) {
      const detail = { windowId: record.id, app: record.app, lifecycle: record.lifecycle };
      this.bus.emit('window-opened', detail);
      this.document.dispatchEvent(new CustomEvent('arcadeos:window-opened', { detail }));
    }
    return record;
  }

  compactZ() {
    [...this.registry.values()].sort((a, b) => (+a.element.style.zIndex || 0) - (+b.element.style.zIndex || 0))
      .forEach((record, index) => { record.element.style.zIndex = String(200 + index); });
    this.zCounter = 200 + this.registry.size;
  }

  minimize(elementOrId) {
    const record = this.record(elementOrId);
    if (!record || record.status !== 'open') return;
    record.minimized = true;
    record.open = true;
    record.status = 'minimized';
    record.focused = false;
    record.element.dataset.minimized = '1';
    record.element.classList.remove('is-open', 'is-active');
    this.syncTaskbar();
    this.bus.emit('window-minimized', { windowId: record.id, app: record.app });
    this.focusMostRecent(record.id);
  }

  toggleMaximize(elementOrId) {
    const record = this.record(elementOrId);
    if (!record) return;
    if (!record.maximized) {
      const rect = record.element.getBoundingClientRect?.() || {};
      record.geometry = {
        left: record.element.style.left, top: record.element.style.top,
        width: record.element.style.width || `${Math.round(rect.width)}px`,
        height: record.element.style.height || `${Math.round(rect.height)}px`
      };
    }
    record.maximized = !record.maximized;
    record.element.classList.toggle('is-maximized', record.maximized);
    if (!record.maximized && record.geometry) {
      Object.assign(record.element.style, record.geometry);
      record.preferredGeometry = { ...record.geometry };
    }
    this.focus(record.id);
  }

  minimum(app) {
    const definition = ArcadeCloudWindowLayoutConfig.definition(app);
    return { width: definition.minWidth, height: definition.minHeight };
  }

  preferenceKey(app) { return ArcadeCloudWindowLayoutConfig.key(app); }

  usesDesktopPersistence() {
    return this.window.innerWidth > ArcadeCloudWindowLayoutConfig.DESKTOP_PERSISTENCE_BREAKPOINT;
  }

  defaultGeometry(app) {
    const definition = ArcadeCloudWindowLayoutConfig.definition(app);
    const availableHeight = Math.max(320, this.window.innerHeight - 52);
    const tablet = this.window.innerWidth > ArcadeCloudWindowLayoutConfig.MOBILE_BREAKPOINT && this.window.innerWidth <= 1180;
    const ratio = tablet ? Math.min(.52, Math.max(.45, definition.width + .06)) : definition.width;
    return {
      width: Math.max(definition.minWidth, Math.round(this.window.innerWidth * ratio)),
      height: Math.max(definition.minHeight, Math.round(availableHeight * definition.height))
    };
  }

  preferred(app) {
    if (!this.usesDesktopPersistence()) return this.defaultGeometry(app);
    const key = this.preferenceKey(app);
    const saved = this.preferences[key] || this.preferences[app];
    return saved && !this.isLegacyOversize(app, saved) ? this.clampGeometry(app, saved) : this.defaultGeometry(app);
  }

  isLegacyOversize(app, saved) {
    if (this.window.innerWidth <= ArcadeCloudWindowLayoutConfig.MOBILE_BREAKPOINT || !saved) return false;
    return Number(saved.width) > this.window.innerWidth * .72 || Number(saved.height) > (this.window.innerHeight - 52) * .72;
  }

  clampGeometry(app, geometry) {
    const min = this.minimum(app);
    const width = Math.max(min.width, Math.min(Number(geometry.width) || min.width, Math.round(this.window.innerWidth * .70)));
    const height = Math.max(min.height, Math.min(Number(geometry.height) || min.height, Math.round((this.window.innerHeight - 52) * .70)));
    const titleVisible = Math.min(120, Math.max(56, width));
    const maxLeft = this.window.innerWidth - titleVisible;
    const minLeft = titleVisible - width;
    const maxTop = Math.max(0, this.window.innerHeight - 52 - 32);
    return {
      left: Math.round(Math.min(maxLeft, Math.max(minLeft, Number(geometry.left) || 0))),
      top: Math.round(Math.min(maxTop, Math.max(0, Number(geometry.top) || 0))),
      width,
      height
    };
  }

  applyInitialGeometry(app, element) {
    element.classList.remove('is-maximized', 'is-compact');
    if (this.window.innerWidth <= ArcadeCloudWindowLayoutConfig.MOBILE_BREAKPOINT) return null;
    const geometry = this.preferred(app);
    const saved = this.usesDesktopPersistence()
      ? (this.preferences[this.preferenceKey(app)] || this.preferences[app])
      : null;
    const step = (this.layoutSequence++ % 7) * 32;
    const baseLeft = Math.round(this.window.innerWidth * .04);
    const baseTop = Math.round(this.window.innerHeight * .06);
    const left = saved ? geometry.left : Math.min(baseLeft + step, this.window.innerWidth - geometry.width - 8);
    const top = saved ? geometry.top : Math.min(baseTop + step, this.window.innerHeight - geometry.height - 58);
    element.style.width = `${geometry.width}px`;
    element.style.height = `${geometry.height}px`;
    element.style.left = `${Math.round(left)}px`;
    element.style.top = `${Math.round(top)}px`;
    return { left: element.style.left, top: element.style.top, width: element.style.width, height: element.style.height };
  }

  observeResize(record) {
    if (typeof ResizeObserver === 'undefined' || record.element.dataset.resizeObserved === '1') return;
    record.element.dataset.resizeObserved = '1';
    let initial = true;
    const observer = new ResizeObserver(entries => {
      if (initial) { initial = false; return; }
      if (record.maximized || !record.element.classList.contains('is-open')) return;
      const box = entries[0]?.contentRect; if (!box?.width || !box?.height) return;
      clearTimeout(this.preferenceTimers.get(record.id));
      if (!this.usesDesktopPersistence()) return;
      const rect = record.element.getBoundingClientRect();
      const preferred = this.clampGeometry(record.app, {
        left: rect.left, top: rect.top,
        width: Math.round(box.width), height: Math.round(box.height)
      });
      record.preferredGeometry = {
        left: `${preferred.left}px`, top: `${preferred.top}px`,
        width: `${preferred.width}px`, height: `${preferred.height}px`
      };
      this.preferenceTimers.set(record.id, setTimeout(() => this.savePreference(record.app, preferred), 400));
    });
    observer.observe(record.element); this.addCleanup(record.id, () => { observer.disconnect(); delete record.element.dataset.resizeObserved; });
  }

  async savePreference(app, geometry) {
    if (!this.usesDesktopPersistence()) return;
    const key = this.preferenceKey(app);
    const normalized = this.clampGeometry(app, geometry || {});
    this.preferences[key] = normalized;
    const config = this.window.ARCADECLOUD_OS_APPEARANCE || {};
    if (!config.endpoint || !config.csrf) return;
    try {
      await this.window.fetch(config.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
        body: JSON.stringify({ windowPreference: { app: key, ...normalized } })
      });
    } catch (_) {}
  }

  persistCurrentGeometry(record) {
    if (!record || record.maximized || !this.usesDesktopPersistence()) return;
    const rect = record.element.getBoundingClientRect?.();
    if (!rect?.width || !rect?.height) return;
    const geometry = this.clampGeometry(record.app, {
      left: rect.left, top: rect.top, width: rect.width, height: rect.height
    });
    record.preferredGeometry = {
      left: `${geometry.left}px`, top: `${geometry.top}px`,
      width: `${geometry.width}px`, height: `${geometry.height}px`
    };
    clearTimeout(this.preferenceTimers.get(record.id));
    this.preferenceTimers.set(record.id, setTimeout(() => this.savePreference(record.app, geometry), 120));
  }

  setTitle(elementOrId, title) {
    const record = this.record(elementOrId);
    if (!record) return;
    record.element.dataset.windowTitle = title;
    const label = record.element.querySelector('.os-window-title span');
    if (label) label.textContent = title;
    this.syncTaskbar();
  }

  close(elementOrId) {
    const record = this.record(elementOrId);
    if (!record) return;
    this.persistCurrentGeometry(record);
    const dynamic = record.lifecycle === 'dynamic';
    if (dynamic) {
      record.cleanup.forEach(callback => { try { callback(); } catch (_) {} });
      record.cleanup.clear();
    }
    record.element.querySelectorAll('audio,video').forEach(media => {
      try { media.pause(); if (dynamic) { media.removeAttribute('src'); media.load(); } } catch (_) {}
    });
    clearTimeout(this.preferenceTimers.get(record.id)); this.preferenceTimers.delete(record.id);
    record.status = 'closed'; record.open = false; record.focused = false; record.minimized = false;
    record.element.classList.remove('is-open', 'is-active'); record.element.dataset.minimized = '0';
    if (dynamic) {
      record.element.remove(); this.registry.delete(record.id); record.taskButton = null;
    }
    this.syncTaskbar();
    const detail = { windowId: record.id, app: record.app, lifecycle: record.lifecycle };
    this.bus.emit('window-closed', detail);
    this.document.dispatchEvent(new CustomEvent('arcadeos:window-closed', { detail }));
    this.focusMostRecent(record.id);
  }

  syncTaskbar() {
    if (!this.taskbar) return;
    this.taskbar.replaceChildren();
    this.registry.forEach(record => {
      if (!['open', 'minimized'].includes(record.status)) return;
      const item = this.document.createElement('div');
      item.className = `os-task-item${record.focused ? ' is-active' : ''}${record.minimized ? ' is-minimized' : ''}`;
      item.dataset.windowId = record.id;
      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'os-task-button';
      button.dataset.windowId = record.id;
      const icon = this.apps.get(record.app)?.icon || 'fa-window-maximize';
      button.innerHTML = `<i class="fas ${icon}"></i><span></span>`;
      const title = record.element.dataset.windowTitle || this.apps.get(record.app)?.title || 'Ventana';
      button.querySelector('span').textContent = title;
      button.title = title;
      button.setAttribute?.('aria-label', `${title}${record.minimized ? ', minimizada' : record.focused ? ', activa' : ', abierta'}`);
      button.setAttribute?.('aria-current', record.focused ? 'true' : 'false');
      button.addEventListener('click', () => {
        if (record.focused && !record.minimized) this.minimize(record.id);
        else this.open(record.id);
      });
      button.addEventListener('contextmenu', event => {
        event.preventDefault();
        this.window.ArcadeCloudOsShell?.showTaskContext?.(record.element, event.clientX, event.clientY);
      });
      item.append(button);
      const more = this.document.createElement('button');
      more.type = 'button'; more.className = 'os-task-more'; more.title = 'Opciones de ventana';
      more.setAttribute?.('aria-label', 'Opciones de ventana');
      more.innerHTML = '<i class="fas fa-ellipsis-vertical"></i>';
      more.addEventListener('click', event => {
        event.stopPropagation();
        const rect = more.getBoundingClientRect?.() || { right: 8, top: 8 };
        this.window.ArcadeCloudOsShell?.showTaskContext?.(record.element, rect.right - 8, rect.top - 6);
      });
      item.append(more);
      this.taskbar.append(item);
      record.taskButton = button;
    });
  }
}

class ExplorerWindowFactory {
  constructor(runtime) { this.runtime = runtime; }

  create(route, options = {}) {
    const doc = this.runtime.document;
    const element = doc.createElement('section');
    element.className = 'os-window os-explorer-window';
    element.dataset.windowTitle = 'Mis datos — /';
    element.dataset.minimized = '0';
    element.dataset.dynamicWindow = '1';
    element.innerHTML = '<div class="os-window-titlebar" data-window-drag-handle><div class="os-window-title"><i class="fas fa-folder-open"></i><span>Mis datos — /</span></div><div class="os-window-controls"><button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button><button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button><button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button></div></div><div class="os-explorer-live" data-explorer-route=""><div class="os-explorer-pathrow"></div><div class="os-explorer-loading">Cargando…</div></div>';
    this.runtime.document.getElementById('osDesktop')?.append(element);
    const controller = this.runtime.attachExplorer(element, route);
    this.runtime.bindWindowChrome(element);
    this.runtime.manager.open(element);
    controller.ready = controller.navigate(route || this.runtime.root, {
      replace: true,
      page: Math.max(1, parseInt(String(options.page || '1'), 10) || 1)
    });
    return controller;
  }
}

class ArcadeCloudExplorerWindow {
  constructor(runtime, element, route) {
    this.runtime = runtime;
    this.win = element;
    this.id = element.dataset.windowId;
    this.live = element.querySelector('.os-explorer-live');
    this.route = this.normalize(route || this.live?.dataset.explorerRoute || runtime.root);
    this.visibleRoute = String(this.live?.dataset.explorerVisibleRoute || 'Mi Drive/').trim() || 'Mi Drive/';
    this.breadcrumbs = this.readBreadcrumbs();
    this.page = +(this.live?.dataset.explorerPage || 1);
    this.history = [];
    this.future = [];
    this.selection = new Set();
    this.scroll = 0;
    this.loading = false;
    this.error = null;
    this.controller = null;
    this.sequence = 0;
    this.suggestionSequence = 0;
    this.suggestionTimer = null;
    this.suggestionController = null;
    this.suggestionCache = new Map();
    this.suggestionIndex = -1;
    this.cleanup = [];
    this.installChrome();
    this.bind();
    this.updateTitle();
  }

  normalize(value) {
    let path = String(value || '').trim().replace(/\\/g, '/');
    if (/\0|(^|\/)\.\.?(\/|$)/.test(path)) throw new Error('La ruta contiene segmentos no permitidos.');
    path = path.replace(/\/+/g, '/');
    const root = this.runtime.root.replace(/^\/+|\/+$/g, '');
    if (!path || path === '/') return `${root}/`;
    path = path.replace(/^\/+|\/+$/g, '');
    if (path !== root && !path.startsWith(`${root}/`)) path = `${root}/${path}`;
    return `${path.replace(/\/+/g, '/')}/`;
  }

  virtual(route = this.route) {
    const root = this.runtime.root.replace(/^\/+|\/+$/g, '');
    let path = String(route).replace(/^\/+|\/+$/g, '');
    if (path === root) return '/';
    if (path.startsWith(`${root}/`)) path = path.slice(root.length + 1);
    return `/${path}`;
  }

  readBreadcrumbs() {
    try {
      const rows = JSON.parse(String(this.live?.dataset.explorerBreadcrumbs || '[]'));
      return Array.isArray(rows) ? rows.filter(row => row && row.label && row.route) : [];
    } catch (_) {
      return [];
    }
  }

  installChrome() {
    const row = this.live?.querySelector('.os-explorer-pathrow');
    if (!row) return;
    row.replaceChildren();
    row.classList.add('os-explorer-navigation');
    [['back','fa-arrow-left','Atrás'],['forward','fa-arrow-right','Adelante'],['up','fa-arrow-up','Subir'],['refresh','fa-rotate','Actualizar'],['new','fa-window-restore','Nueva ventana']].forEach(([action, icon, label]) => {
      const button = this.runtime.document.createElement('button');
      button.type = 'button'; button.className = 'os-address-action'; button.dataset.explorerAction = action;
      button.title = label; button.setAttribute('aria-label', label); button.innerHTML = `<i class="fas ${icon}"></i>`;
      row.append(button);
    });
    const address = this.runtime.document.createElement('div');
    address.className = 'os-address os-address-editable';
    address.dataset.explorerAddress = '';
    address.innerHTML = '<i class="fas fa-folder"></i><div class="os-breadcrumb" data-explorer-breadcrumb></div><input type="text" data-explorer-address-input aria-label="Dirección de carpeta" autocomplete="off" spellcheck="false" hidden><div class="os-address-suggestions" data-explorer-suggestions role="listbox" hidden></div>';
    row.append(address);
    this.address = address.querySelector('input');
    this.breadcrumb = address.querySelector('[data-explorer-breadcrumb]');
    this.suggestions = address.querySelector('[data-explorer-suggestions]');
    this.renderAddress();
  }

  renderAddress() {
    if (!this.breadcrumb) return;
    this.breadcrumb.replaceChildren();
    const rows = this.breadcrumbs.length ? this.breadcrumbs : [{ label: 'Mi Drive', route: this.route }];
    rows.forEach((row, index) => {
      if (index > 0) { const separator = this.runtime.document.createElement('span'); separator.textContent = '›'; this.breadcrumb.append(separator); }
      const button = this.runtime.document.createElement('button');
      button.type = 'button'; button.textContent = String(row.label || 'Carpeta'); button.dataset.addressRoute = String(row.route || this.route);
      this.breadcrumb.append(button);
    });
    if (this.address) this.address.value = this.visibleRoute;
  }

  editAddress() { if (this.address) { this.breadcrumb.hidden = true; this.address.hidden = false; this.address.value = this.visibleRoute; this.address.focus(); this.address.select(); } }
  stopEditing() { if (this.address) { this.address.hidden = true; this.breadcrumb.hidden = false; this.hideSuggestions(); } }

  bind() {
    const click = event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const action = target.closest('[data-explorer-action]')?.dataset.explorerAction;
      if (action) {
        event.preventDefault();
        if (action === 'new') this.runtime.openExplorer(this.route, { forceNew: true });
        else if (action === 'back') this.back(); else if (action === 'forward') this.forward();
        else if (action === 'up') this.up(); else this.navigate(this.route, { replace: true, page: this.page });
        return;
      }
      const crumb = target.closest('[data-address-route]');
      if (crumb) { event.preventDefault(); this.navigate(crumb.dataset.addressRoute); return; }
      if (target.closest('[data-explorer-address]')) { if (!target.closest('[data-address-route]')) this.editAddress(); return; }
      const link = target.closest('a[data-explorer-route]');
      if (link) { event.preventDefault(); event.stopPropagation(); this.navigate(link.dataset.explorerRoute, { page: +link.dataset.explorerPage || 1 }); return; }
      const suggestion = target.closest('[data-suggestion-route]');
      if (suggestion) { event.preventDefault(); event.stopPropagation(); this.navigate(suggestion.dataset.suggestionRoute); return; }
      const folder = target.closest('.os-folder-entry');
      if (folder) {
        event.preventDefault(); event.stopPropagation();
        this.navigate(folder.dataset.folderRoute);
      }
    };
    const keydown = event => {
      if (event.target === this.address) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); this.moveSuggestion(event.key === 'ArrowDown' ? 1 : -1); }
        else if (event.key === 'Enter') { event.preventDefault(); const selected = this.suggestions?.querySelector('.is-active'); if (selected?.dataset.suggestionRoute) this.navigate(selected.dataset.suggestionRoute); else if (String(this.address.value || '').trim() === this.visibleRoute) this.stopEditing(); }
        else if (event.key === 'Escape') this.stopEditing();
        return;
      }
      if (event.target.closest('input,textarea,[contenteditable]')) return;
      const selected = [...this.win.querySelectorAll('.os-file-entry.is-selected')];
      if (event.ctrlKey && event.key.toLowerCase() === 'l') { event.preventDefault(); this.editAddress(); }
      else if (event.altKey && event.key === 'ArrowLeft') { event.preventDefault(); this.back(); }
      else if (event.altKey && event.key === 'ArrowRight') { event.preventDefault(); this.forward(); }
      else if (event.ctrlKey && ['c','x'].includes(event.key.toLowerCase()) && selected.length) {
        event.preventDefault(); this.runtime.window.ArcadeCloudOsClipboard?.captureFiles?.(selected[0], event.key.toLowerCase() === 'c' ? 'copy' : 'move', { sourceWindowId: this.id, sourceRoute: this.route, entries: selected });
      } else if (event.ctrlKey && event.key.toLowerCase() === 'v') { event.preventDefault(); this.runtime.window.ArcadeCloudOsClipboard?.paste?.(this.route, { destinationWindowId: this.id }); }
      else if (event.key === 'Delete' && selected.length) { event.preventDefault(); this.runtime.window.ArcadeCloudOsShell?.deleteSelectedFiles?.(); }
      else if (event.key === 'Enter' && selected.length === 1) { event.preventDefault(); this.runtime.window.ArcadeCloudOsShell?.openFileEntry?.(selected[0], false, { sourceWindowId: this.id }); }
    };
    this.win.addEventListener('click', click);
    this.win.addEventListener('keydown', keydown);
    const input = () => this.scheduleSuggestions();
    this.win.addEventListener('input', input);
    // Capture native folder anchors before legacy target listeners can follow so.php?ruta=...
    const guard = event => {
      const folder = event.target instanceof Element ? event.target.closest('a.os-folder-entry') : null;
      if (!folder || !this.win.contains(folder) || event.target.closest('.os-folder-entry-menu')) return;
      event.preventDefault(); event.stopPropagation();
      this.navigate(folder.dataset.folderRoute);
    };
    this.win.addEventListener('click', guard, true);
    this.cleanup.push(() => this.win.removeEventListener('click', click), () => this.win.removeEventListener('keydown', keydown), () => this.win.removeEventListener('input', input), () => this.win.removeEventListener('click', guard, true));
    this.bindDragDrop();
  }

  scheduleSuggestions() {
    clearTimeout(this.suggestionTimer);
    this.suggestionTimer = setTimeout(() => this.loadSuggestions(this.address?.value || ''), 220);
  }

  async loadSuggestions(query) {
    query = String(query).trim();
    if (!query) { this.hideSuggestions(); return; }
    const key = `${this.route}\n${query.toLowerCase()}`; const cached = this.suggestionCache.get(key);
    if (cached && Date.now() - cached.time < 10000) { this.renderSuggestions(cached.items); return; }
    this.suggestionController?.abort(); this.suggestionController = new AbortController(); const sequence = ++this.suggestionSequence;
    try {
      const url = new URL('folder-suggestions.php', this.runtime.window.location.href);
      url.searchParams.set('query', query); url.searchParams.set('currentRoute', this.route); url.searchParams.set('limit', '12');
      const response = await this.runtime.window.fetch(url, { credentials: 'same-origin', signal: this.suggestionController.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) return; const items = await response.json(); if (sequence !== this.suggestionSequence || !Array.isArray(items)) return;
      this.suggestionCache.set(key, { time: Date.now(), items }); this.renderSuggestions(items);
    } catch (error) { if (error?.name !== 'AbortError') this.hideSuggestions(); }
  }

  renderSuggestions(items) {
    if (!this.suggestions) return; this.suggestions.replaceChildren(); this.suggestionIndex = -1;
    items.slice(0, 20).forEach(item => {
      const route = typeof item === 'string' ? item : String(item?.route || '');
      const label = typeof item === 'string' ? 'Carpeta' : String(item?.label || 'Carpeta');
      if (!route) return;
      const button = this.runtime.document.createElement('button'); button.type = 'button'; button.dataset.suggestionRoute = route; button.setAttribute('role', 'option'); button.textContent = label; this.suggestions.append(button);
    });
    this.suggestions.hidden = !this.suggestions.children.length;
  }
  moveSuggestion(delta) { const items = [...(this.suggestions?.querySelectorAll('[data-suggestion-route]') || [])]; if (!items.length) return; this.suggestionIndex = (this.suggestionIndex + delta + items.length) % items.length; items.forEach((item, i) => item.classList.toggle('is-active', i === this.suggestionIndex)); }
  hideSuggestions() { if (this.suggestions) { this.suggestions.hidden = true; this.suggestions.replaceChildren(); } this.suggestionIndex = -1; }

  async navigate(value, options = {}) {
    const route = this.normalize(value);
    const requestedScroll = options.scroll !== undefined
      ? Math.max(0, Number(options.scroll) || 0)
      : (options.preserveScroll || route === this.route ? (this.live?.scrollTop || 0) : 0);
    if (!options.fromHistory && !options.replace && route !== this.route) {
      this.history.push({ route: this.route, page: this.page, scroll: this.live?.scrollTop || 0 });
      this.future = [];
    }
    this.controller?.abort();
    this.controller = new AbortController();
    const request = ++this.sequence;
    this.loading = true; this.error = null; this.win.classList.add('is-loading');
    try {
      const url = new URL('so.php', this.runtime.window.location.href);
      url.searchParams.set('ruta', route); url.searchParams.set('pagina', String(options.page || 1)); url.searchParams.set('_os_fragment', 'explorer');
      const response = await this.runtime.window.fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: this.controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error('No fue posible cargar esta carpeta.');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const replacement = parsed.querySelector('.os-explorer-live');
      if (!replacement) throw new Error('La respuesta del explorador no es válida.');
      if (request !== this.sequence) return;
      replacement.dataset.explorerInstance = this.id;
      replacement.querySelectorAll('.os-file-entry,.os-folder-entry').forEach(entry => { entry.draggable = true; });
      this.scroll = this.live?.scrollTop || 0;
      this.live.replaceWith(replacement); this.live = replacement; this.route = route; this.visibleRoute = String(replacement.dataset.explorerVisibleRoute || 'Mi Drive/').trim() || 'Mi Drive/'; this.breadcrumbs = this.readBreadcrumbs(); this.page = +replacement.dataset.explorerPage || 1; this.selection.clear();
      this.installChrome(); this.updateTitle();
      this.runtime.window.ArcadeCloudOsShell?.bindFiles?.(this.win);
      this.runtime.window.ArcadeCloudOsClipboard?.bindEntries?.(this.win);
      this.runtime.window.ArcadeCloudOsClipboard?.injectPasteToolbar?.(this.win);
      this.runtime.window.ArcadeCloudOsFolders?.rebind?.(this.win);
      this.scroll = requestedScroll;
      const restoreScroll = () => { if (this.live && this.route === route) this.live.scrollTop = requestedScroll; };
      if (typeof this.runtime.window.requestAnimationFrame === 'function') this.runtime.window.requestAnimationFrame(restoreScroll);
      else this.runtime.window.setTimeout(restoreScroll, 0);
      this.runtime.document.dispatchEvent(new CustomEvent('arcadeos:explorer-updated', { detail: { windowId: this.id, route, page: this.page } }));
      this.runtime.bus.emit('route-changed', { windowId: this.id, route, page: this.page });
    } catch (error) {
      if (error?.name !== 'AbortError') { this.error = error?.message || 'No fue posible cargar esta carpeta.'; this.showError(this.error); }
    } finally { if (request === this.sequence) { this.loading = false; this.win.classList.remove('is-loading'); } }
  }

  showError(message) {
    let box = this.win.querySelector('.os-explorer-error');
    if (!box) { box = this.runtime.document.createElement('div'); box.className = 'os-explorer-error'; this.live?.prepend(box); }
    box.textContent = message;
  }
  back() { const item = this.history.pop(); if (item) { this.future.push({ route: this.route, page: this.page, scroll: this.live?.scrollTop || 0 }); this.navigate(item.route, { page: item.page, scroll: item.scroll, fromHistory: true, replace: true }); } }
  forward() { const item = this.future.pop(); if (item) { this.history.push({ route: this.route, page: this.page, scroll: this.live?.scrollTop || 0 }); this.navigate(item.route, { page: item.page, scroll: item.scroll, fromHistory: true, replace: true }); } }
  up() { const root = `${this.runtime.root.replace(/\/+$/, '')}/`; if (this.route !== root) { const bits = this.route.replace(/\/+$/, '').split('/'); bits.pop(); this.navigate(`${bits.join('/')}/`); } }
  updateTitle() {
    const last = this.breadcrumbs[this.breadcrumbs.length - 1];
    this.runtime.manager.setTitle(this.id, `Mis datos — ${String(last?.label || 'Mi Drive')}`);
  }

  bindDragDrop() {
    const start = event => {
      const entry = event.target.closest('.os-file-entry,.os-folder-entry'); if (!entry) return;
      const selected = [...this.win.querySelectorAll('.os-file-entry.is-selected')];
      const entries = entry.classList.contains('is-selected') && selected.length ? selected : [entry];
      const payload = { sourceWindowId: this.id, sourceRoute: this.route, sourceLabel: this.visibleRoute, kind: entry.classList.contains('os-folder-entry') ? 'folder' : 'file', keys: entries.map(item => item.dataset.key || item.dataset.folderRoute).filter(Boolean) };
      event.dataTransfer.effectAllowed = 'copyMove'; event.dataTransfer.setData('application/x-arcadecloud-items', JSON.stringify(payload));
    };
    const over = event => { if ([...event.dataTransfer.types].includes('application/x-arcadecloud-items')) { event.preventDefault(); this.win.classList.add('is-drop-target'); } };
    const leave = event => { if (!this.win.contains(event.relatedTarget)) this.win.classList.remove('is-drop-target'); };
    const drop = async event => {
      event.preventDefault(); this.win.classList.remove('is-drop-target');
      let data; try { data = JSON.parse(event.dataTransfer.getData('application/x-arcadecloud-items')); } catch (_) { return; }
      if (!data?.keys?.length || data.sourceWindowId === this.id && data.sourceRoute === this.route) return;
      const destinationFolder = event.target.closest('.os-folder-entry');
      const destinationRoute = destinationFolder?.dataset.folderRoute || this.route;
      const destinationLabel = destinationFolder
        ? `${this.visibleRoute.replace(/\/+$/, '')}/${String(destinationFolder.dataset.folderName || 'Carpeta')}/`
        : this.visibleRoute;
      const choice = await this.runtime.chooseDropOperation(data.keys.length, data.sourceLabel || 'Carpeta de origen', destinationLabel || 'Carpeta de destino'); if (!choice) return;
      const clipboard = this.runtime.window.ArcadeCloudOsClipboard;
      if (data.kind === 'folder') clipboard?.captureFolder?.({ route: data.keys[0], parent: data.sourceRoute, name: data.keys[0].split('/').filter(Boolean).pop() }, choice, { sourceWindowId: data.sourceWindowId, sourceRoute: data.sourceRoute });
      else {
        const source = this.runtime.explorers.get(data.sourceWindowId);
        const entries = data.keys.map(key => source?.win.querySelector(`.os-file-entry[data-key="${CSS.escape(key)}"]`)).filter(Boolean);
        if (entries.length) clipboard?.captureFiles?.(entries[0], choice, { sourceWindowId: data.sourceWindowId, sourceRoute: data.sourceRoute, entries });
      }
      await clipboard?.paste?.(destinationRoute, { destinationWindowId: this.id });
    };
    this.win.addEventListener('dragstart', start); this.win.addEventListener('dragover', over); this.win.addEventListener('dragleave', leave); this.win.addEventListener('drop', drop);
    this.cleanup.push(() => this.win.removeEventListener('dragstart', start), () => this.win.removeEventListener('dragover', over), () => this.win.removeEventListener('dragleave', leave), () => this.win.removeEventListener('drop', drop));
  }

  destroy() { this.controller?.abort(); this.suggestionController?.abort(); clearTimeout(this.suggestionTimer); this.cleanup.splice(0).forEach(callback => callback()); }
}

class ArcadeCloudDesktopRuntime {
  constructor(win, doc) {
    this.window = win; this.document = doc; this.bus = new ArcadeCloudEventBus();
    this.manager = new ArcadeCloudWindowManager(win, doc, this.bus); this.explorers = new Map();
    this.root = String(win.ARCADECLOUD_OS_ROOT_ROUTE || ''); this.factory = new ExplorerWindowFactory(this);
  }

  init() {
    this.registerApplications();
    this.document.querySelectorAll('.os-window').forEach(element => this.manager.register(element, this.appFor(element)));
    this.document.querySelectorAll('.os-explorer-window.is-open').forEach(element => this.attachExplorer(element));
    this.document.querySelectorAll('.os-window').forEach(element => this.bindWindowChrome(element));
    this.document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null; if (!target) return;
      const launch = target.closest('[data-window-open="explorerWindow"],[data-app-open="explorer"]');
      if (launch) { event.preventDefault(); event.stopImmediatePropagation(); this.openExplorer(this.root, { forceNew: true }); return; }
      const staticLaunch = target.closest('[data-window-open]');
      if (staticLaunch) {
        const element = this.document.getElementById(staticLaunch.dataset.windowOpen);
        const record = element && this.manager.record(element);
        if (record) { event.preventDefault(); event.stopImmediatePropagation(); this.manager.open(record.id); return; }
      }
      const newer = target.closest('[data-folder-open-new]');
      if (newer) { event.preventDefault(); event.stopPropagation(); this.openExplorer(this.window.ArcadeCloudOsFolders?.activeFolder?.route || newer.dataset.folderOpenNew, { forceNew: true }); this.window.ArcadeCloudOsFolders?.hideContext?.(); }
      const tool = target.closest('[data-os-tool]');
      if (tool) { event.preventDefault(); this.openTool(tool.href, tool.dataset.toolTitle || tool.textContent.trim(), tool.dataset.osTool); }
    }, true);
    this.document.addEventListener('pointerdown', event => { const element = event.target.closest('.os-window'); if (element) this.manager.focus(element); }, true);
    this.window.addEventListener('message', event => {
      if (event.origin !== this.window.location.origin || event.data?.type !== 'arcadecloud:notebook-request-close') return;
      const notebook = this.document.getElementById('notebookWindow');
      const frame = notebook?.querySelector('iframe');
      if (!notebook || frame?.contentWindow !== event.source) return;
      this.manager.requestClose(notebook);
    });
    ['file-moved','file-copied','file-deleted','folder-created','upload-completed','task-completed'].forEach(type => this.bus.on(type, event => this.refreshAffected(event.detail)));
    this.bus.on('filesystem:changed', event => this.refreshAffected(event.detail));
    this.document.addEventListener('drive:move-task-completed', event => {
      const active = this.window.ArcadeCloudOsClipboard?.activeTransfer;
      if (active && String(active.jobId || '') === String(event.detail?.job_id || '')) return;
      this.bus.emit('task-completed', event.detail || {});
    });
    this.document.addEventListener('drive:storage-changed', event => {
      const detail = event.detail || {};
      // Route-less notifications (for example quota or media metadata changes)
      // only mark the UI stale; refreshing every open Explorer would defeat
      // route-aware synchronization.
      if (detail.route) this.bus.emit('upload-completed', detail);
    });
    this.document.addEventListener('drive:folder-mutated', event => {
      const detail = event.detail || {};
      const route = String(detail.route || '');
      const kind = String(detail.kind || '');
      if (kind === 'rename' || kind === 'delete') {
        this.explorers.forEach(explorer => {
          const physical = explorer.normalize(route);
          if (!route || !explorer.route.startsWith(physical)) return;
          // Rename changes catalog labels only. Prefixes and descendants stay fixed.
          const destination = kind === 'rename' ? explorer.route : this.parentRoute(route);
          explorer.navigate(destination, { replace: true, page: kind === 'rename' ? explorer.page : 1 });
        });
      }
      const sourceRoute = kind === 'create' ? route : this.parentRoute(route);
      const destinationRoute = kind === 'rename' ? this.parentRoute(route) : String(detail.destination || '');
      this.emitFilesystemChanged({
        operation: `folder-${kind || 'changed'}`,
        sourceRoute,
        destinationRoute,
        route: kind === 'create' ? route : ''
      });
    });
    this.document.addEventListener('drive:folder-document-created', event => {
      this.emitFilesystemChanged({ operation: 'document-created', destinationRoute: event.detail?.route || '' });
    });
    this.observer = new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (!(node instanceof Element) || !node.matches('.os-window')) return;
      const app = this.appFor(node); const registered = this.manager.register(node, app); this.bindWindowChrome(node);
      if (registered.element !== node) node.remove();
    })));
    this.observer.observe(this.document.getElementById('osDesktop') || this.document.body, { childList: true });
    this.enhanceFolderMenu();
    this.window.ArcadeCloudEventBus = this.bus; this.window.ArcadeCloudWindowManager = this.manager; this.window.ArcadeCloudDesktop = this;
    return this;
  }

  registerApplications() {
    [['explorer',true,'dynamic','Mis datos','fa-folder-open'],['image',true,'dynamic','Imagen','fa-file-image'],['pdf',true,'dynamic','PDF','fa-file-pdf'],['text',true,'dynamic','Texto','fa-file-lines'],['audio',true,'dynamic','Audio','fa-file-audio'],['video',true,'dynamic','Video','fa-file-video'],['viewer',true,'dynamic','Archivo','fa-file'],['tool',true,'dynamic','Herramienta','fa-toolbox'],['node',false,'persistent','Mi nodo','fa-server'],['settings',false,'persistent','Configuración','fa-gear'],['links',false,'persistent','Enlaces','fa-link'],['terminal',false,'persistent','Consola servidor','fa-terminal'],['notebook',false,'persistent','Notebook','fa-book-open']]
      .forEach(([app,multiInstance,lifecycle,title,icon]) => this.manager.registerApp(app, { multiInstance, lifecycle, title, icon }));
    this.manager.registerCloseGuard('notebook', record => this.confirmNotebookClose(record));
  }

  async confirmNotebookClose(record) {
    const frame = record?.element?.querySelector('iframe[src*="notebook.php"]');
    if (!frame?.contentWindow) return true;
    try {
      const api = frame.contentWindow.ArcadeCloudNotebookClose;
      if (api && typeof api.requestClose === 'function') return await api.requestClose();
    } catch (_) {}
    return true;
  }

  appFor(element) {
    if (element.classList.contains('os-explorer-window')) return 'explorer';
    if (element.classList.contains('os-document-window')) {
      if (element.classList.contains('os-pdf-window')) return 'pdf';
      if (element.querySelector('.os-viewer-image')) return 'image';
      if (/Editor/.test(element.querySelector('.os-statusbar')?.textContent || '')) return 'text';
      return 'viewer';
    }
    if (element.id === 'nodeWindow') return 'node'; if (element.id === 'settingsWindow') return 'settings'; if (element.id === 'terminalWindow') return 'terminal';
    if (element.id === 'linksWindow') return 'links';
    return element.dataset.appId || element.id?.replace(/Window$/, '') || 'window';
  }

  bindWindowChrome(element) {
    if (element.dataset.managerBound === '1') return; element.dataset.managerBound = '1';
    element.querySelector('[data-window-close]')?.addEventListener('click', async event => { event.stopImmediatePropagation(); const id = element.dataset.windowId; const closed = await this.manager.requestClose(id); if (closed) this.explorers.delete(id); });
    element.querySelector('[data-window-minimize]')?.addEventListener('click', event => { event.stopImmediatePropagation(); this.manager.minimize(element); });
    element.querySelector('[data-window-maximize]')?.addEventListener('click', event => { event.stopImmediatePropagation(); this.manager.toggleMaximize(element); });
    const handle = element.querySelector('[data-window-drag-handle]');
    if (handle) {
      const pointerDown = event => this.beginWindowDrag(event, element, handle);
      handle.addEventListener('pointerdown', pointerDown);
      this.manager.addCleanup(element.dataset.windowId, () => handle.removeEventListener('pointerdown', pointerDown));
    }
  }

  beginWindowDrag(event, element, handle) {
    if (event.button !== undefined && event.button !== 0) return;
    if (event.target.closest('button,input,select,textarea,a,[contenteditable="true"]')) return;
    const record = this.manager.record(element);
    if (!record || record.maximized || element.classList.contains('is-maximized')) return;
    if (this.window.matchMedia('(max-width: 700px)').matches) return;

    event.preventDefault();
    this.manager.focus(record.id);
    const start = element.getBoundingClientRect();
    const offsetX = event.clientX - start.left;
    const offsetY = event.clientY - start.top;
    const titleVisible = Math.min(120, Math.max(56, start.width));
    const titleHeight = Math.max(32, handle.getBoundingClientRect?.().height || 42);
    element.classList.add('is-dragging');
    try { handle.setPointerCapture(event.pointerId); } catch (_) {}

    const move = moveEvent => {
      const minLeft = titleVisible - start.width;
      const maxLeft = this.window.innerWidth - titleVisible;
      const maxTop = Math.max(0, this.window.innerHeight - 52 - titleHeight);
      const left = Math.min(maxLeft, Math.max(minLeft, moveEvent.clientX - offsetX));
      const top = Math.min(maxTop, Math.max(0, moveEvent.clientY - offsetY));
      element.style.left = `${Math.round(left)}px`;
      element.style.top = `${Math.round(top)}px`;
      record.geometry = { ...(record.geometry || {}), left: element.style.left, top: element.style.top };
      record.preferredGeometry = { ...(record.preferredGeometry || {}), left: element.style.left, top: element.style.top };
    };
    const stop = stopEvent => {
      move(stopEvent);
      this.manager.persistCurrentGeometry(record);
      element.classList.remove('is-dragging');
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', stop);
      handle.removeEventListener('pointercancel', stop);
      try { handle.releasePointerCapture(event.pointerId); } catch (_) {}
    };
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
  }

  attachExplorer(element, route) {
    element.classList.add('os-explorer-window');
    const record = this.manager.record(element) || this.manager.register(element, 'explorer');
    if (this.explorers.has(record.id)) return this.explorers.get(record.id);
    const controller = new ArcadeCloudExplorerWindow(this, element, route || element.querySelector('.os-explorer-live')?.dataset.explorerRoute);
    record.state = controller; this.explorers.set(record.id, controller); this.manager.addCleanup(record.id, () => controller.destroy());
    return controller;
  }

  openExplorer(route, options = {}) {
    const existing = this.manager.last('explorer');
    if (options.reuse && existing) { this.manager.focus(existing.id); return this.explorers.get(existing.id); }
    return this.factory.create(route || this.root, options);
  }

  chooseDropOperation(count, source, destination) {
    return new Promise(resolve => {
      const overlay = this.document.createElement('div');
      overlay.className = 'os-decision-overlay';
      overlay.setAttribute('role', 'presentation');
      const dialog = this.document.createElement('section');
      dialog.className = 'os-decision-dialog';
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'osTransferDecisionTitle');
      const noun = Number(count) === 1 ? '1 elemento' : `${Number(count)} elementos`;
      dialog.innerHTML = '<header><h2 id="osTransferDecisionTitle">Mover o copiar</h2></header><div class="os-decision-body"><p data-os-decision-question></p><dl><dt>Origen</dt><dd data-os-decision-source></dd><dt>Destino</dt><dd data-os-decision-destination></dd></dl></div><footer><button type="button" class="os-decision-primary" data-os-decision="move"><i class="fas fa-arrow-right"></i> Mover</button><button type="button" data-os-decision="copy"><i class="fas fa-copy"></i> Copiar</button><button type="button" data-os-decision="cancel">Cancelar</button></footer>';
      dialog.querySelector('[data-os-decision-question]').textContent = `¿Qué deseas hacer con ${noun}?`;
      dialog.querySelector('[data-os-decision-source]').textContent = String(source || '—');
      dialog.querySelector('[data-os-decision-destination]').textContent = String(destination || '—');
      overlay.append(dialog); this.document.body.append(overlay);
      const finish = choice => { this.document.removeEventListener('keydown', onKey); overlay.remove(); resolve(choice); };
      const onKey = event => { if (event.key === 'Escape') { event.preventDefault(); finish(null); } };
      overlay.addEventListener('click', event => {
        const button = event.target.closest?.('[data-os-decision]');
        if (button) finish(button.dataset.osDecision === 'cancel' ? null : button.dataset.osDecision);
        else if (event.target === overlay) finish(null);
      });
      this.document.addEventListener('keydown', onKey);
      dialog.querySelector('[data-os-decision="move"]')?.focus();
    });
  }

  async openTool(url, title, toolId = 'tool') {
    const app = 'tool-' + String(toolId || 'tool').replace(/[^a-z0-9-]/gi, '-').toLowerCase();
    const existing = this.manager.last(app);
    if (existing) { this.manager.focus(existing.id); return existing.element; }
    const element = this.document.createElement('section');
    element.className = 'os-window os-tool-window'; element.dataset.windowTitle = title;
    element.dataset.dynamicWindow = '1';
    element.innerHTML = '<div class="os-window-titlebar" data-window-drag-handle><div class="os-window-title"><i class="fas fa-toolbox"></i><span></span></div><div class="os-window-controls"><button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button><button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button><button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button></div></div><div class="os-window-body os-tool-body"><p class="os-node-placeholder">Cargando…</p></div>';
    element.querySelector('.os-window-title span').textContent = title;
    this.document.getElementById('osDesktop')?.append(element);
    this.manager.register(element, app, { url, toolId }); this.bindWindowChrome(element); this.manager.open(element);
    const body = element.querySelector('.os-tool-body');
    body.addEventListener('click', event => {
      const link = event.target.closest?.('a[href]');
      if (!link || link.target === '_blank' || new URL(link.href, this.window.location.href).origin !== this.window.location.origin) return;
      event.preventDefault(); this.loadToolContent(body, link.href);
    });
    body.addEventListener('submit', event => {
      const form = event.target; if (!(form instanceof HTMLFormElement)) return;
      event.preventDefault();
      const target = new URL(form.action || url, this.window.location.href);
      const data = new FormData(form);
      if (form.matches('[data-os-totp-form]')) {
        this.submitTotpForm(body, target, data, form);
        return;
      }
      if ((form.method || 'get').toLowerCase() === 'get') data.forEach((value, key) => target.searchParams.set(key, String(value)));
      this.loadToolContent(body, target.toString(), (form.method || 'get').toLowerCase() === 'post' ? { method: 'POST', body: data } : {})
        .catch(error => { body.textContent = error?.message || 'No se pudo ejecutar la herramienta.'; });
    });
    try {
      await this.loadToolContent(body, url);
    } catch (error) {
      body.textContent = error?.message || 'No se pudo abrir la herramienta.';
    }
    return element;
  }

  async submitTotpForm(body, target, data, form) {
    const button = form.querySelector('button[type="submit"],button:not([type])');
    const previous = button?.textContent || '';
    if (button) { button.disabled = true; button.textContent = 'Generando…'; }
    const errorBox = body.querySelector('[data-totp-error]');
    try {
      const endpoint = new URL('aws.php', this.window.location.href);
      endpoint.searchParams.set('arcadecloud_os', '1');
      data.set('arcadecloud_os', '1');
      data.set('response_format', 'json');
      const response = await this.window.fetch(endpoint.toString(), {
        method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-ArcadeCloud-Embed': '1', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      });
      const contentType = String(response.headers?.get('Content-Type') || '').toLowerCase();
      if (!contentType.includes('application/json')) {
        let finalPath = 'desconocida';
        try { finalPath = new URL(response.url || endpoint, this.window.location.href).pathname; } catch (_) {}
        throw new Error(`TOTP respondió ${response.status} con ${contentType || 'tipo desconocido'}; ruta final: ${finalPath}; redirección: ${response.redirected ? 'sí' : 'no'}.`);
      }
      const payload = await response.json();
      if (!response.ok || payload?.ok !== true || !payload.result) throw new Error(payload?.error || 'No se pudo generar el código.');
      const result = body.querySelector('[data-totp-result]');
      if (!result) throw new Error('La ventana TOTP no contiene el área de resultado.');
      result.hidden = false;
      result.innerHTML = '<div class="muted" data-totp-label></div><div class="otp" id="otp-code" role="button" tabindex="0" aria-label="Copiar código TOTP"></div><div class="copy-hint" id="copy-status" aria-live="polite">Toca el código para copiar</div><div>Válido aproximadamente <span id="timer"></span> s</div>';
      result.querySelector('[data-totp-label]').textContent = String(payload.result.label || '');
      result.querySelector('#otp-code').textContent = String(payload.result.code || '');
      result.querySelector('#timer').textContent = String(payload.result.remaining || 0);
      if (payload.result.note) { const note = this.document.createElement('div'); note.className = 'note'; note.textContent = String(payload.result.note); result.append(note); }
      if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }
    } catch (error) {
      if (errorBox) { errorBox.hidden = false; errorBox.textContent = error?.message || 'No se pudo generar el código.'; }
    } finally {
      if (button) { button.disabled = false; button.textContent = previous; }
    }
  }

  async loadToolContent(body, url, options = {}) {
    const requestUrl = new URL(url, this.window.location.href); requestUrl.searchParams.set('arcadecloud_os', '1');
    const response = await this.window.fetch(requestUrl.toString(), { ...options, credentials: 'same-origin', headers: { 'X-ArcadeCloud-Embed': '1', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new Error('No se pudo abrir la herramienta.');
    const finalUrl = new URL(response.url || requestUrl, requestUrl);
    if (finalUrl.origin !== requestUrl.origin || /(?:^|\/)index\.php$/i.test(finalUrl.pathname)) {
      throw new Error('La herramienta intentó abandonar ArcadeCloud OS; se bloqueó la navegación.');
    }
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const content = page.querySelector('main') || page.querySelector('[role="main"]');
    if (!content) throw new Error('Esta herramienta no ofrece contenido embebible.');
    const embedded = this.document.importNode(content, true);
    embedded.classList.add('os-app');
    embedded.dataset.osEmbeddedTool = String(body.closest('.os-window')?.dataset.appId || 'tool');
    body.replaceChildren(embedded);
    body.querySelectorAll('a[href]').forEach(link => { link.href = new URL(link.getAttribute('href'), requestUrl).toString(); });
    body.querySelectorAll('form').forEach(form => { form.action = new URL(form.getAttribute('action') || requestUrl, requestUrl).toString(); });
    for (const oldScript of page.querySelectorAll('script')) {
      if (/theme-state-bridge\.js/.test(oldScript.src || '')) { oldScript.remove(); continue; }
      if (oldScript.src && /(?:jquery|bootstrap|fontawesome)/i.test(oldScript.src)) continue;
      const script = this.document.createElement('script');
      Array.from(oldScript.attributes || []).forEach(attribute => script.setAttribute(attribute.name, attribute.value));
      if (oldScript.getAttribute('src')) script.src = new URL(oldScript.getAttribute('src'), requestUrl).toString();
      else script.textContent = oldScript.textContent;
      if (script.src) {
        await new Promise((resolve, reject) => {
          script.onload = resolve; script.onerror = () => reject(new Error('No se pudo cargar la herramienta.'));
          embedded.append(script);
        });
      } else embedded.append(script);
    }
  }

  enhanceFolderMenu() {
    const menu = this.document.getElementById('folderContextMenu'); if (!menu || menu.querySelector('[data-folder-open-new]')) return;
    const button = this.document.createElement('button'); button.type = 'button'; button.dataset.folderOpenNew = '';
    button.innerHTML = '<i class="fas fa-up-right-from-square"></i> Abrir en nueva ventana'; menu.querySelector('[data-folder-action="open"]')?.after(button);
  }

  parentRoute(route) {
    const normalized = String(route || '').replace(/\\/g, '/').replace(/\/+$/, '');
    const index = normalized.lastIndexOf('/');
    return index < 0 ? this.root : `${normalized.slice(0, index)}/`;
  }

  emitFilesystemChanged(detail = {}) {
    this.bus.emit('filesystem:changed', detail);
  }

  refreshAffected(detail = {}) {
    const affected = [detail.sourceRoute, detail.destinationRoute || detail.destination, detail.route].filter(Boolean);
    this.explorers.forEach(explorer => {
      if (!affected.length || affected.some(route => explorer.normalize(route) === explorer.route)) {
        explorer.navigate(explorer.route, { replace: true, page: explorer.page, preserveScroll: true });
      }
    });
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudEventBus, ArcadeCloudWindowLayoutConfig, ArcadeCloudWindowManager, ExplorerWindowFactory, ArcadeCloudExplorerWindow, ArcadeCloudDesktopRuntime };
if (typeof window !== 'undefined') {
  // Loaded at the end of <body>: boot before the legacy shell so it can only
  // coordinate actions and cannot become a competing lifecycle owner.
  window.ArcadeCloudDesktopRuntime = new ArcadeCloudDesktopRuntime(window, document).init();
}
