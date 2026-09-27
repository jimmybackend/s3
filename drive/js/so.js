class ArcadeCloudOsShell {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.windows = Array.from(doc.querySelectorAll('.os-window'));
    this.taskButtons = doc.getElementById('osTaskButtons');
    this.launcher = doc.getElementById('osLauncher');
    this.startButton = doc.getElementById('osStart');
    this.context = doc.getElementById('fileContextMenu');
    this.contextName = doc.getElementById('fileContextName');
    this.zCounter = 200;
    this.activeFile = null;
  }

  init() {
    this.bindLaunchers();
    this.bindWindows();
    this.bindStartMenu();
    this.bindFiles();
    this.bindContextActions();
    this.bindDocumentDismiss();
    this.updateClock();
    this.window.setInterval(() => this.updateClock(), 30000);
    this.syncTaskbar();
    return this;
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
    this.closeLauncher();
  }

  closeWindow(win) {
    if (!win) return;
    win.dataset.minimized = '0';
    win.classList.remove('is-open', 'is-active');
    this.syncTaskbar();
  }

  minimizeWindow(win) {
    if (!win) return;
    win.classList.remove('is-open', 'is-active');
    win.dataset.minimized = '1';
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

    this.windows.forEach((win) => {
      const isVisible = win.classList.contains('is-open');
      const hasHistory = isVisible || win.dataset.minimized === '1';
      if (!hasHistory) return;

      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'os-task-button' + (win.classList.contains('is-active') ? ' is-active' : '');
      button.innerHTML = '<i class="far fa-window-maximize"></i><span></span>';

      const label = button.querySelector('span');
      if (label) {
        label.textContent = win.dataset.windowTitle || 'Ventana';
      }

      button.addEventListener('click', () => {
        if (win.classList.contains('is-open') && win.classList.contains('is-active')) {
          this.minimizeWindow(win);
          return;
        }

        win.dataset.minimized = '0';
        this.openWindow(win.id);
      });

      this.taskButtons.appendChild(button);
    });
  }

  closeLauncher() {
    if (!this.launcher || !this.startButton) return;
    this.launcher.hidden = true;
    this.startButton.classList.remove('is-open');
    this.startButton.setAttribute('aria-expanded', 'false');
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

  bindWindows() {
    this.windows.forEach((win) => {
      win.addEventListener('pointerdown', () => this.activateWindow(win));

      win.querySelector('[data-window-close]')?.addEventListener('click', () => this.closeWindow(win));
      win.querySelector('[data-window-minimize]')?.addEventListener('click', () => this.minimizeWindow(win));
      win.querySelector('[data-window-maximize]')?.addEventListener('click', () => this.toggleMaximize(win));

      const handle = win.querySelector('[data-window-drag-handle]');
      if (!handle) return;

      handle.addEventListener('pointerdown', (event) => this.beginDrag(event, win, handle));
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

    handle.setPointerCapture(event.pointerId);

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
  }

  hideContext() {
    if (!this.context) return;
    this.context.hidden = true;
    this.activeFile = null;
  }

  showContext(entry, x, y) {
    if (!this.context || !entry || !this.contextName) return;

    this.activeFile = entry;
    this.contextName.textContent = entry.dataset.name || 'Archivo';

    const edit = this.context.querySelector('[data-file-action="edit"]');
    const open = this.context.querySelector('[data-file-action="open"]');
    const download = this.context.querySelector('[data-file-action="download"]');
    const locked = entry.dataset.locked === '1';

    if (edit) edit.hidden = locked || !entry.dataset.editUrl;
    if (open) open.hidden = locked || !entry.dataset.openUrl;
    if (download) download.hidden = locked || !entry.dataset.downloadUrl;

    this.context.hidden = false;
    const rect = this.context.getBoundingClientRect();
    this.context.style.left = Math.max(4, Math.min(x, this.window.innerWidth - rect.width - 4)) + 'px';
    this.context.style.top = Math.max(4, Math.min(y, this.window.innerHeight - rect.height - 52)) + 'px';
  }

  bindFiles() {
    this.document.querySelectorAll('.os-file-entry').forEach((entry) => {
      entry.addEventListener('dblclick', () => this.openFileEntry(entry));

      entry.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        this.showContext(entry, event.clientX, event.clientY);
      });

      entry.addEventListener('click', (event) => {
        if (!event.target.closest('.os-entry-menu')) return;
        const rect = entry.getBoundingClientRect();
        this.showContext(entry, rect.right - 20, rect.top + 30);
      });
    });
  }

  openFileEntry(entry) {
    if (entry.dataset.locked === '1' || !entry.dataset.openUrl) {
      this.window.location.href = entry.dataset.classicUrl || 's3.php';
      return;
    }

    this.window.open(entry.dataset.openUrl, '_blank', 'noopener');
  }

  bindContextActions() {
    this.context?.querySelectorAll('[data-file-action]').forEach((button) => {
      button.addEventListener('click', () => this.runFileAction(button.dataset.fileAction || ''));
    });
  }

  runFileAction(action) {
    if (!this.activeFile) return;

    if (action === 'open' && this.activeFile.dataset.openUrl) {
      this.window.open(this.activeFile.dataset.openUrl, '_blank', 'noopener');
    } else if (action === 'edit' && this.activeFile.dataset.editUrl) {
      this.window.open(this.activeFile.dataset.editUrl, '_blank', 'noopener');
    } else if (action === 'download' && this.activeFile.dataset.downloadUrl) {
      this.window.location.href = this.activeFile.dataset.downloadUrl;
    } else if (action === 'classic') {
      this.window.location.href = this.activeFile.dataset.classicUrl || 's3.php';
    }

    this.hideContext();
  }

  bindDocumentDismiss() {
    this.document.addEventListener('click', (event) => {
      if (!event.target.closest('#fileContextMenu') && !event.target.closest('.os-file-entry')) {
        this.hideContext();
      }

      if (!event.target.closest('#osLauncher') && !event.target.closest('#osStart')) {
        this.closeLauncher();
      }
    });
  }

  updateClock() {
    const clock = this.document.getElementById('osClock');
    if (!clock) return;

    clock.textContent = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).format(new Date());
  }
}

new ArcadeCloudOsShell(window, document).init();
