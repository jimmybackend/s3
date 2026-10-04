/* Stage 5 desktop integration. It deliberately composes the existing window
 * manager, application registry and event bus instead of mirroring their state. */
class ArcadeCloudDesktopShell {
  constructor(win, doc, runtime = win.ArcadeCloudDesktop) {
    this.window = win; this.document = doc; this.runtime = runtime;
    this.manager = runtime?.manager || win.ArcadeCloudWindowManager;
    this.bus = runtime?.bus || win.ArcadeCloudEventBus;
    this.selectedSwitcherIndex = 0; this.switcherRecords = [];
    this.notificationTimers = new Map(); this.notifiedOperations = new Set();
  }

  static isEditable(target) {
    return Boolean(target?.closest?.('input,textarea,select,[contenteditable="true"],[role="textbox"],.CodeMirror,.ace_editor,.terminal'));
  }

  init() {
    if (!this.manager || !this.document?.body) return this;
    this.installSurfaces(); this.buildLauncher(); this.bindDesktopMenu(); this.bindKeyboard(); this.bindNotifications();
    ['window-focused', 'window-minimized', 'window-closed', 'window-opened'].forEach(type => this.bus?.on?.(type, () => this.closeTransientMenus()));
    this.window.ArcadeCloudDesktopShell = this;
    return this;
  }

  installSurfaces() {
    const create = (id, className, html, attributes = {}) => {
      let node = this.document.getElementById(id);
      if (!node) { node = this.document.createElement('div'); node.id = id; node.className = className; node.innerHTML = html; Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value)); this.document.body.append(node); }
      return node;
    };
    this.desktopMenu = create('osDesktopContext', 'os-shell-menu', '<button type="button" data-shell-action="explorer"><i class="fas fa-folder-open"></i>Nueva ventana Mis datos</button><button type="button" data-shell-action="page-monitor"><i class="fas fa-chart-line"></i>Administrador de la página</button><button type="button" data-shell-action="settings"><i class="fas fa-gear"></i>Configuración</button><button type="button" data-shell-action="refresh"><i class="fas fa-rotate"></i>Actualizar escritorio</button>', { role: 'menu', hidden: '' });
    this.switcher = create('osWindowSwitcher', 'os-window-switcher', '<div class="os-switcher-list" role="listbox" aria-label="Ventanas abiertas"></div>', { hidden: '', 'aria-hidden': 'true' });
    this.notifications = create('osNotifications', 'os-notifications', '', { 'aria-label': 'Notificaciones', 'aria-live': 'polite' });
  }

  launchableApplications() {
    const targets = {
      explorer: { title: 'Mis datos', icon: 'fa-folder-open', launch: () => this.runtime.openExplorer(this.runtime.root, { forceNew: true }) },
      node: { target: 'nodeWindow' }, pageMonitor: { target: 'pageMonitorWindow', title: 'Administrador de la página', icon: 'fa-chart-line' }, settings: { target: 'settingsWindow' }, links: { target: 'linksWindow' },
      terminal: { target: 'terminalWindow' }, notebook: { target: 'notebookWindow', title: 'Notebook', icon: 'fa-book-open' }, search: { target: 'searchWindow', title: 'Buscar', icon: 'fa-magnifying-glass' },
      federation: { target: 'federationWindow', title: 'FederationCloud', icon: 'fa-globe' }, apps: { target: 'appsWindow' }
    };
    const applications = Object.entries(targets).filter(([id, spec]) => id !== 'apps' && (id === 'explorer' || this.document.getElementById(spec.target))).map(([id, spec]) => {
      const registered = this.manager.apps.get(id) || {};
      return { id, title: spec.title || registered.title || id, icon: spec.icon || registered.icon || 'fa-window-maximize', launch: spec.launch || (() => this.launchTarget(spec.target)) };
    });
    this.manager.apps.forEach((definition, id) => {
      if (!definition.launchable || typeof definition.launch !== 'function' || applications.some(app => app.id === id)) return;
      applications.push({ id, title: definition.title || id, icon: definition.icon || 'fa-window-maximize', launch: definition.launch });
    });
    return applications;
  }

  registerDeclarativeApplications(appsWindow) {
    appsWindow.querySelectorAll('[data-launcher-app]').forEach(element => {
      const id = String(element.dataset.launcherApp || '');
      const href = element.getAttribute('href');
      if (!id || !href) return;
      const title = element.querySelector('strong')?.textContent?.trim() || id;
      const icon = [...(element.querySelector('i')?.classList || [])].find(name => name.startsWith('fa-')) || 'fa-arrow-up-right-from-square';
      const target = element.getAttribute('target') || '_self';
      this.manager.registerApp(id, {
        multiInstance: true, lifecycle: 'external', launchable: true, title, icon,
        launch: () => this.window.open(href, target, target === '_blank' ? 'noopener' : undefined)
      });
    });
  }

  buildLauncher() {
    const appsWindow = this.document.getElementById('appsWindow'); const body = appsWindow?.querySelector('.os-window-body');
    if (!body) return;
    this.registerDeclarativeApplications(appsWindow);
    body.innerHTML = '<label class="os-app-search"><i class="fas fa-magnifying-glass"></i><input type="search" autocomplete="off" placeholder="Buscar aplicaciones" aria-label="Buscar aplicaciones"></label><div class="os-app-grid" role="listbox" aria-label="Aplicaciones"></div><p class="os-app-empty" hidden>No hay aplicaciones coincidentes.</p>';
    this.launcherInput = body.querySelector('input'); this.launcherGrid = body.querySelector('.os-app-grid'); this.launcherEmpty = body.querySelector('.os-app-empty');
    this.apps = this.launchableApplications(); this.renderApplications('');
    this.launcherInput.addEventListener('input', () => this.renderApplications(this.launcherInput.value));
    this.launcherInput.addEventListener('keydown', event => this.routeLauncherKey(event));
    this.bus?.on?.('window-opened', event => { if (event.detail?.app === 'apps') { this.launcherInput.value = ''; this.renderApplications(''); this.window.setTimeout(() => this.launcherInput.focus(), 0); } });
  }

  renderApplications(query) {
    const normalized = String(query || '').trim().toLocaleLowerCase();
    const matches = this.apps.filter(app => app.title.toLocaleLowerCase().includes(normalized));
    this.launcherGrid.replaceChildren();
    matches.forEach((app, index) => {
      const button = this.document.createElement('button'); button.type = 'button'; button.className = `os-app-card is-ready${index === 0 ? ' is-selected' : ''}`;
      button.dataset.appId = app.id; button.setAttribute('role', 'option'); button.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
      button.innerHTML = `<i class="fas ${app.icon}"></i><strong></strong><span>Disponible</span>`; button.querySelector('strong').textContent = app.title;
      button.addEventListener('click', () => { app.launch(); this.manager.close('appsWindow'); }); this.launcherGrid.append(button);
    });
    this.launcherEmpty.hidden = matches.length !== 0;
  }

  routeLauncherKey(event) {
    const buttons = [...this.launcherGrid.querySelectorAll('button')]; if (!buttons.length) return;
    let index = Math.max(0, buttons.findIndex(button => button.classList.contains('is-selected')));
    if (event.key === 'Escape') { event.preventDefault(); this.manager.close('appsWindow'); return; }
    if (event.key === 'Enter') { event.preventDefault(); buttons[index].click(); return; }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault(); index = (index + (event.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length;
    buttons.forEach((button, current) => { button.classList.toggle('is-selected', current === index); button.setAttribute('aria-selected', current === index ? 'true' : 'false'); });
  }

  launchTarget(id) { const element = this.document.getElementById(id); if (!element) return this.notify({ type: 'warning', message: 'La aplicación no está disponible en este nodo.' }); this.manager.open(element); }

  bindDesktopMenu() {
    const desktop = this.document.getElementById('osDesktop');
    desktop?.addEventListener('contextmenu', event => { if (event.target !== desktop) return; event.preventDefault(); this.showDesktopMenu(event.clientX, event.clientY); });
    this.desktopMenu.addEventListener('click', event => {
      const action = event.target.closest('[data-shell-action]')?.dataset.shellAction; if (!action) return;
      if (action === 'explorer') this.runtime.openExplorer(this.runtime.root, { forceNew: true });
      if (action === 'page-monitor') this.launchTarget('pageMonitorWindow');
      if (action === 'settings') this.launchTarget('settingsWindow');
      if (action === 'refresh') this.manager.openRecords().filter(record => record.app === 'explorer').forEach(record => this.runtime.explorers.get(record.id)?.reload?.());
      this.desktopMenu.hidden = true;
    });
    this.document.addEventListener('pointerdown', event => { if (!event.target.closest('#osDesktopContext')) this.desktopMenu.hidden = true; }, true);
  }

  showDesktopMenu(x, y) { this.closeTransientMenus(); this.desktopMenu.hidden = false; const rect = this.desktopMenu.getBoundingClientRect(); this.desktopMenu.style.left = `${Math.max(4, Math.min(x, this.window.innerWidth - rect.width - 4))}px`; this.desktopMenu.style.top = `${Math.max(4, Math.min(y, this.window.innerHeight - rect.height - 52))}px`; }
  closeTransientMenus() { if (this.desktopMenu) this.desktopMenu.hidden = true; this.window.ArcadeCloudOsShell?.hideContext?.(); this.window.ArcadeCloudOsFolders?.hideContext?.(); }

  bindKeyboard() {
    this.document.addEventListener('keydown', event => this.routeShortcut(event), true);
    this.document.addEventListener('keyup', event => { if (event.key === 'Alt' && !this.switcher.hidden) this.commitSwitcher(); }, true);
  }

  routeShortcut(event) {
    if (event.altKey && event.key === 'Tab') { event.preventDefault(); this.cycleSwitcher(event.shiftKey ? -1 : 1); return; }
    if (event.key === 'Escape') { if (!this.switcher.hidden) { event.preventDefault(); this.hideSwitcher(); return; } if (!this.desktopMenu.hidden) { event.preventDefault(); this.desktopMenu.hidden = true; return; } }
    if (ArcadeCloudDesktopShell.isEditable(event.target)) return;
    const explorer = this.manager.activeId ? this.runtime.explorers.get(this.manager.activeId) : null; if (!explorer) return;
    const modifier = event.ctrlKey || event.metaKey; const key = event.key.toLowerCase();
    if (modifier && key === 'l') { event.preventDefault(); explorer.editAddress(); return; }
    const shell = this.window.ArcadeCloudOsShell; const selected = shell?.selectedFileEntries?.() || [];
    if (modifier && (key === 'c' || key === 'x') && selected.length) { event.preventDefault(); this.window.ArcadeCloudOsClipboard?.captureFiles?.(selected[0], key === 'c' ? 'copy' : 'move', { entries: selected, sourceWindowId: this.manager.activeId, sourceRoute: explorer.route }); }
    if (modifier && key === 'v' && this.window.ArcadeCloudOsClipboard?.clipboard) { event.preventDefault(); this.window.ArcadeCloudOsClipboard.paste(explorer.route, { destinationWindowId: this.manager.activeId }); }
    if (event.key === 'Delete' && selected.length) { event.preventDefault(); shell?.document?.querySelector?.('[data-selection-action="delete"]')?.click(); }
  }

  mruRecords() { return [...this.manager.openRecords()].sort((a, b) => b.lastFocused - a.lastFocused); }
  cycleSwitcher(direction) {
    if (this.switcher.hidden) { this.switcherRecords = this.mruRecords(); this.selectedSwitcherIndex = this.switcherRecords.length > 1 ? 1 : 0; }
    else this.selectedSwitcherIndex = (this.selectedSwitcherIndex + direction + this.switcherRecords.length) % this.switcherRecords.length;
    if (!this.switcherRecords.length) return;
    this.switcher.hidden = false; this.switcher.setAttribute('aria-hidden', 'false'); const list = this.switcher.querySelector('.os-switcher-list'); list.replaceChildren();
    this.switcherRecords.forEach((record, index) => { const item = this.document.createElement('div'); item.className = `os-switcher-item${index === this.selectedSwitcherIndex ? ' is-selected' : ''}`; item.setAttribute('role', 'option'); item.setAttribute('aria-selected', index === this.selectedSwitcherIndex ? 'true' : 'false'); item.innerHTML = `<i class="fas ${this.manager.apps.get(record.app)?.icon || 'fa-window-maximize'}"></i><span></span>`; item.querySelector('span').textContent = record.element.dataset.windowTitle || this.manager.apps.get(record.app)?.title || 'Ventana'; list.append(item); });
  }
  commitSwitcher() { const record = this.switcherRecords[this.selectedSwitcherIndex]; this.hideSwitcher(); if (record && this.manager.record(record.id)) this.manager.open(record.id); }
  hideSwitcher() { this.switcher.hidden = true; this.switcher.setAttribute('aria-hidden', 'true'); this.switcherRecords = []; }

  bindNotifications() {
    this.bus?.on?.('notification:show', event => this.notify(event.detail));
    this.document.addEventListener('filesystem:operation', event => {
      const operation = event.detail || {}; if (!['completed', 'failed'].includes(operation.status) || this.notifiedOperations.has(operation.operationId)) return;
      this.notifiedOperations.add(operation.operationId);
      this.notify({ type: operation.status === 'completed' ? 'success' : 'error', message: operation.status === 'completed' ? `${operation.items?.length || 1} elemento(s): operación completada` : operation.error || 'La operación de archivos falló.' });
    });
  }
  notify(detail = {}) {
    const item = this.document.createElement('section'); const type = ['info', 'success', 'warning', 'error'].includes(detail.type) ? detail.type : 'info';
    item.className = `os-notification is-${type}`; item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    item.innerHTML = '<i class="fas fa-circle-info"></i><p></p><button type="button" aria-label="Cerrar notificación"><i class="fas fa-xmark"></i></button>'; item.querySelector('p').textContent = String(detail.message || '');
    const remove = () => { this.window.clearTimeout(this.notificationTimers.get(item)); this.notificationTimers.delete(item); item.remove(); };
    item.querySelector('button').addEventListener('click', remove); this.notifications.prepend(item);
    while (this.notifications.children.length > 5) this.notifications.lastElementChild.remove();
    this.notificationTimers.set(item, this.window.setTimeout(remove, Number(detail.duration) || (type === 'error' ? 12000 : 6000)));
    return item;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudDesktopShell };
if (typeof window !== 'undefined' && typeof document !== 'undefined') window.addEventListener('DOMContentLoaded', () => new ArcadeCloudDesktopShell(window, document).init());
