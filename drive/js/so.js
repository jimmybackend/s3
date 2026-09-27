(() => {
  'use strict';

  const windows = Array.from(document.querySelectorAll('.os-window'));
  const taskButtons = document.getElementById('osTaskButtons');
  const launcher = document.getElementById('osLauncher');
  const startButton = document.getElementById('osStart');
  const context = document.getElementById('fileContextMenu');
  const contextName = document.getElementById('fileContextName');
  let zCounter = 200;
  let activeFile = null;

  const activateWindow = (win) => {
    if (!win) return;
    windows.forEach((item) => item.classList.remove('is-active'));
    win.classList.add('is-active', 'is-open');
    win.style.zIndex = String(++zCounter);
    syncTaskbar();
  };

  const openWindow = (id) => {
    const win = document.getElementById(id);
    if (!win) return;
    win.classList.add('is-open');
    win.hidden = false;
    activateWindow(win);
    closeLauncher();
  };

  const closeWindow = (win) => {
    if (!win) return;
    win.classList.remove('is-open', 'is-active');
    syncTaskbar();
  };

  const minimizeWindow = (win) => {
    if (!win) return;
    win.classList.remove('is-open', 'is-active');
    win.dataset.minimized = '1';
    syncTaskbar();
  };

  const toggleMaximize = (win) => {
    if (!win) return;
    win.classList.toggle('is-maximized');
    activateWindow(win);
  };

  const syncTaskbar = () => {
    if (!taskButtons) return;
    taskButtons.innerHTML = '';

    windows.forEach((win) => {
      const isVisible = win.classList.contains('is-open');
      const hasHistory = isVisible || win.dataset.minimized === '1';
      if (!hasHistory) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'os-task-button' + (win.classList.contains('is-active') ? ' is-active' : '');
      button.innerHTML = '<i class="far fa-window-maximize"></i><span></span>';
      button.querySelector('span').textContent = win.dataset.windowTitle || 'Ventana';
      button.addEventListener('click', () => {
        if (win.classList.contains('is-open') && win.classList.contains('is-active')) {
          minimizeWindow(win);
        } else {
          win.dataset.minimized = '0';
          openWindow(win.id);
        }
      });
      taskButtons.appendChild(button);
    });
  };

  const closeLauncher = () => {
    if (!launcher || !startButton) return;
    launcher.hidden = true;
    startButton.classList.remove('is-open');
    startButton.setAttribute('aria-expanded', 'false');
  };

  document.querySelectorAll('[data-window-open]').forEach((control) => {
    control.addEventListener('click', (event) => {
      if (control.tagName === 'A') return;
      event.preventDefault();
      openWindow(control.dataset.windowOpen || '');
    });
  });

  windows.forEach((win) => {
    win.addEventListener('pointerdown', () => activateWindow(win));

    win.querySelector('[data-window-close]')?.addEventListener('click', () => closeWindow(win));
    win.querySelector('[data-window-minimize]')?.addEventListener('click', () => minimizeWindow(win));
    win.querySelector('[data-window-maximize]')?.addEventListener('click', () => toggleMaximize(win));

    const handle = win.querySelector('[data-window-drag-handle]');
    if (!handle) return;

    handle.addEventListener('pointerdown', (event) => {
      if (event.target.closest('.os-window-controls')) return;
      if (window.matchMedia('(max-width: 800px), (pointer: coarse)').matches) return;
      if (win.classList.contains('is-maximized')) return;

      activateWindow(win);
      const startX = event.clientX;
      const startY = event.clientY;
      const rect = win.getBoundingClientRect();
      handle.setPointerCapture(event.pointerId);

      const move = (moveEvent) => {
        const maxLeft = Math.max(4, window.innerWidth - 120);
        const maxTop = Math.max(4, window.innerHeight - 110);
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
    });
  });

  if (startButton && launcher) {
    startButton.addEventListener('click', () => {
      const next = launcher.hidden;
      launcher.hidden = !next;
      startButton.classList.toggle('is-open', next);
      startButton.setAttribute('aria-expanded', next ? 'true' : 'false');
    });
  }

  const hideContext = () => {
    if (!context) return;
    context.hidden = true;
    activeFile = null;
  };

  const showContext = (entry, x, y) => {
    if (!context || !entry) return;
    activeFile = entry;
    contextName.textContent = entry.dataset.name || 'Archivo';

    const edit = context.querySelector('[data-file-action="edit"]');
    const open = context.querySelector('[data-file-action="open"]');
    const download = context.querySelector('[data-file-action="download"]');

    const locked = entry.dataset.locked === '1';
    if (edit) edit.hidden = locked || !entry.dataset.editUrl;
    if (open) open.hidden = locked || !entry.dataset.openUrl;
    if (download) download.hidden = locked || !entry.dataset.downloadUrl;

    context.hidden = false;
    const rect = context.getBoundingClientRect();
    context.style.left = Math.max(4, Math.min(x, window.innerWidth - rect.width - 4)) + 'px';
    context.style.top = Math.max(4, Math.min(y, window.innerHeight - rect.height - 52)) + 'px';
  };

  document.querySelectorAll('.os-file-entry').forEach((entry) => {
    entry.addEventListener('dblclick', () => {
      if (entry.dataset.locked === '1' || !entry.dataset.openUrl) {
        window.location.href = entry.dataset.classicUrl || 's3.php';
        return;
      }
      window.open(entry.dataset.openUrl, '_blank', 'noopener');
    });

    entry.addEventListener('contextmenu', (event) => {
      event.preventDefault();
      showContext(entry, event.clientX, event.clientY);
    });

    entry.addEventListener('click', (event) => {
      if (!event.target.closest('.os-entry-menu')) return;
      const rect = entry.getBoundingClientRect();
      showContext(entry, rect.right - 20, rect.top + 30);
    });
  });

  context?.querySelectorAll('[data-file-action]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!activeFile) return;
      const action = button.dataset.fileAction;
      if (action === 'open' && activeFile.dataset.openUrl) {
        window.open(activeFile.dataset.openUrl, '_blank', 'noopener');
      } else if (action === 'edit' && activeFile.dataset.editUrl) {
        window.open(activeFile.dataset.editUrl, '_blank', 'noopener');
      } else if (action === 'download' && activeFile.dataset.downloadUrl) {
        window.location.href = activeFile.dataset.downloadUrl;
      } else if (action === 'classic') {
        window.location.href = activeFile.dataset.classicUrl || 's3.php';
      }
      hideContext();
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#fileContextMenu') && !event.target.closest('.os-file-entry')) {
      hideContext();
    }
    if (!event.target.closest('#osLauncher') && !event.target.closest('#osStart')) {
      closeLauncher();
    }
  });

  const updateClock = () => {
    const clock = document.getElementById('osClock');
    if (!clock) return;
    clock.textContent = new Intl.DateTimeFormat(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).format(new Date());
  };

  updateClock();
  window.setInterval(updateClock, 30000);
  syncTaskbar();
})();
