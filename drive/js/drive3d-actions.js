/* Reuse authenticated Web OS menus and their real action handlers in the 3D HUD. */
(() => {
  'use strict';
  class Drive3DActions {
    constructor(win, doc) {
      this.window = win;
      this.document = doc;
      this.toggle = doc.querySelector('[data-hud-more-actions]');
      this.list = doc.querySelector('[data-hud-extra-actions]');
      this.item = null;
      this.generation = 0;
      this.expanded = false;
      this.loadedTarget = '';
      this.pending = null;
    }
    init() {
      if (!this.toggle || !this.list) return this;
      this.document.addEventListener('drive3d:selection', event => this.select(event.detail));
      this.toggle.addEventListener('click', () => {
        this.expanded = !this.expanded;
        this.toggle.setAttribute('aria-expanded', String(this.expanded));
        this.list.hidden = !this.expanded;
        if (this.expanded) this.render();
      });
      this.select({type:'folder', name:'Carpeta actual', openHref:this.window.location.href});
      return this;
    }
    select(item) {
      this.item = item;
      this.generation++;
      if (this.expanded) this.render();
    }
    target(item) {
      const url = new URL('so.php', this.window.location.href);
      const route = item.type === 'folder'
        ? new URL(item.openHref || this.window.location.href, this.window.location.href).searchParams.get('ruta')
        : (item.route || this.window.DRIVE_INITIAL_ROUTE);
      url.searchParams.set('ruta', route || this.window.DRIVE_INITIAL_ROUTE || '');
      if (item.type !== 'folder') url.searchParams.set('buscar', item.name || '');
      return url;
    }
    ensureFrame() {
      if (this.frame) return;
      this.dialog = this.document.createElement('dialog');
      this.dialog.className = 'dw-action-dialog';
      this.dialog.setAttribute('aria-label', 'Acciones de Drive');
      const header = this.document.createElement('header');
      this.title = this.document.createElement('strong');
      const close = this.document.createElement('button');
      close.type = 'button'; close.textContent = 'Volver al 3D';
      close.addEventListener('click', () => this.dialog.close());
      header.append(this.title, close);
      this.frame = this.document.createElement('iframe');
      this.frame.title = 'Acciones de archivos y carpetas';
      this.frame.setAttribute('allow', 'clipboard-read; clipboard-write; fullscreen');
      this.dialog.append(header, this.frame);
      this.document.body.append(this.dialog);
      this.dialog.addEventListener('close', () => {
        this.toggle.focus();
        if (this.dirty) {
          // Folder shelves, security URLs and selection metadata need a fresh
          // authenticated server render; environment preferences are persisted.
          this.window.location.reload();
        } else if (this.expanded) this.render();
      });
      this.frame.addEventListener('load', () => {
        this.loadedTarget = '';
        const pending = this.pending;
        if (!pending) return;
        try {
          const win = this.frame.contentWindow;
          // A superseded navigation may finish while the next one is pending.
          if (win.location.href !== pending.url) return;
          this.pending = null;
          this.window.clearTimeout(pending.timeout);
          if (win.location.origin !== this.window.location.origin || !win.ArcadeCloudOsShell) {
            throw new Error('Abre tu sesión de Drive para acceder a las acciones.');
          }
          this.loadedTarget = pending.url;
          ['drive:storage-changed', 'drive:folder-mutated', 'bloque-archivos:updated', 'bloque-carpetas:updated', 'filesystem:changed', 'arcadeos:explorer-updated'].forEach(name => {
            win.document.addEventListener(name, () => { this.dirty = true; });
          });
          pending.resolve(win);
        } catch (error) {
          this.pending = null;
          this.window.clearTimeout(pending.timeout);
          pending.reject(error);
        }
      });
    }
    load(item) {
      this.ensureFrame();
      const url = this.target(item).href;
      if (this.loadedTarget === url) return Promise.resolve(this.frame.contentWindow);
      if (this.pending?.url === url) return this.pending.promise;
      if (this.pending) {
        this.window.clearTimeout(this.pending.timeout);
        this.pending.reject(new Error('La selección cambió.'));
      }
      let resolve, reject;
      const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
      const pending = {url, promise, resolve, reject};
      pending.timeout = this.window.setTimeout(() => {
        if (this.pending !== pending) return;
        this.pending = null;
        reject(new Error('No se pudieron cargar las acciones. Pulsa de nuevo para reintentar.'));
      }, 30000);
      this.pending = pending;
      this.loadedTarget = '';
      this.frame.src = url;
      return promise;
    }
    context(win, item) {
      let menu;
      if (item.type === 'folder') {
        const service = win.ArcadeCloudOsFolders;
        if (!service) throw new Error('No se pudieron cargar las acciones de la carpeta.');
        const folder = service.currentFolderFromDom();
        // The authenticated explorer must actually be showing the target route.
        const normalize = route => String(route || '').replace(/^\/+|\/+$/g, '');
        if (normalize(folder.route) !== normalize(this.target(item).searchParams.get('ruta'))) {
          throw new Error('La carpeta cambió. Vuelve a seleccionarla.');
        }
        service.showContext(folder, 8, 8, true);
        menu = win.document.getElementById('folderContextMenu');
      } else {
        const entry = Array.from(win.document.querySelectorAll('.os-file-entry')).find(node => node.dataset.key === item.key);
        if (!entry || !item.key) throw new Error('El archivo ya no está disponible. Actualiza la carpeta.');
        win.ArcadeCloudOsShell.showContext(entry, 8, 8);
        menu = win.document.getElementById('fileContextMenu');
      }
      if (!menu) throw new Error('No se encontraron acciones para este objeto.');
      menu.hidden = true;
      return Array.from(menu.querySelectorAll('button[data-file-action], button[data-folder-action], button[data-os-clipboard-action]'))
        .filter(button => !button.hidden && !button.disabled);
    }
    async render() {
      const item = this.item;
      const generation = this.generation;
      this.list.textContent = 'Cargando acciones…';
      try {
        const win = await this.load(item);
        if (generation !== this.generation || !this.expanded) return;
        const controls = this.context(win, item);
        this.list.replaceChildren();
        for (const control of controls) {
          const button = this.document.createElement('button');
          button.type = 'button';
          button.textContent = control.textContent.trim();
          button.addEventListener('click', () => {
            if (generation !== this.generation) return;
            try {
              // Recheck current capabilities and selection before dispatch. Never
              // infer permissions from 3D metadata or create a second action API.
              const current = this.context(win, item);
              if (!current.includes(control)) throw new Error('Esta acción ya no está disponible.');
              this.title.textContent = item.name || 'Carpeta actual';
              this.dialog.showModal();
              control.click();
            } catch (error) { this.list.textContent = error.message; }
          });
          this.list.append(button);
        }
        if (!controls.length) this.list.textContent = 'No hay acciones disponibles.';
      } catch (error) {
        if (generation === this.generation && this.expanded) this.list.textContent = error.message;
      }
    }
  }
  window.addEventListener('DOMContentLoaded', () => {
    window.ArcadeCloudDrive3DActions = new Drive3DActions(window, document).init();
  });
})();
