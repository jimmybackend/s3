class DriveMoveTasks {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.active = new Map();
    this.storageKey = 'arcadecloud.moveJobs';
    this.pollMs = 1500;
  }

  init() {
    this.restore().forEach((jobId) => this.watch(jobId));
    return this;
  }

  async start(payload) {
    const body = new URLSearchParams();
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null) body.set(key, String(value));
    });

    const response = await this.window.fetch('move_task.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });

    const text = await response.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!response.ok || !json || json.ok !== true || !json.job_id) {
      throw new Error(
        (json && (json.error || json.mensaje)) ||
        text ||
        `HTTP ${response.status}`
      );
    }

    const jobId = String(json.job_id);
    this.remember(jobId);
    this.notify(json.mensaje || 'Movimiento enviado a segundo plano.', 'info', 5500);
    this.watch(jobId);
    return json;
  }

  watch(jobId) {
    jobId = String(jobId || '').trim();
    if (!jobId || this.active.has(jobId)) return;

    const state = { stopped: false, networkErrors: 0 };
    this.active.set(jobId, state);

    const tick = async () => {
      if (state.stopped) return;

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

        const text = await response.text();
        let json = null;
        try { json = JSON.parse(text); } catch (_) {}

        if (!response.ok || !json || json.ok !== true) {
          throw new Error((json && (json.error || json.mensaje)) || text || `HTTP ${response.status}`);
        }

        state.networkErrors = 0;
        const status = String(json.estado || '');

        if (status === 'completed') {
          state.stopped = true;
          this.active.delete(jobId);
          this.forget(jobId);
          this.notify(json.mensaje || 'Movimiento completado.', 'success', 6000);
          await this.refreshUi(json);
          this.dispatch('drive:move-task-completed', json);
          return;
        }

        if (status === 'failed') {
          state.stopped = true;
          this.active.delete(jobId);
          this.forget(jobId);
          this.notify(json.error || json.mensaje || 'No se pudo completar el movimiento.', 'danger', 9000);
          this.dispatch('drive:move-task-failed', json);
          return;
        }
      } catch (error) {
        state.networkErrors++;
        console.warn('[move-task] estado no disponible todavía:', error);
        if (state.networkErrors === 5) {
          this.notify('La tarea sigue en segundo plano. Reintentando estado…', 'warning', 5000);
        }
      }

      if (!state.stopped) {
        this.window.setTimeout(tick, this.pollMs);
      }
    };

    this.window.setTimeout(tick, 500);
  }

  async refreshUi(status) {
    const route = String(status?.ruta_actual || '').trim();
    if (route) this.window.rutaActual = route;

    try {
      if (typeof this.window.actualizarBloqueCarpetas === 'function') {
        await this.window.actualizarBloqueCarpetas(route ? { ruta_actual: route } : {});
      }
    } catch (error) {
      console.warn('[move-task] no se pudo refrescar carpetas:', error);
    }

    try {
      if (typeof this.window.actualizarBloqueArchivos === 'function') {
        await this.window.actualizarBloqueArchivos(route ? { pagina: 1, ruta: route } : { pagina: 1 });
      }
    } catch (error) {
      console.warn('[move-task] no se pudo refrescar archivos:', error);
    }

    try {
      if (typeof this.window.actualizarBloqueFooter === 'function') {
        await this.window.actualizarBloqueFooter(route ? { ruta: route, rutaNueva: route, pagina: 1 } : { pagina: 1 });
      }
    } catch (_) {}

    try {
      this.document.dispatchEvent(new Event('drive:storage-changed'));
    } catch (_) {}
  }

  notify(message, type = 'info', timeout = 5000) {
    let box = this.document.getElementById('driveMoveTaskNotice');
    if (!box) {
      box = this.document.createElement('div');
      box.id = 'driveMoveTaskNotice';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      Object.assign(box.style, {
        position: 'fixed',
        left: '1rem',
        right: '1rem',
        bottom: '1rem',
        zIndex: '2050',
        maxWidth: '520px',
        marginLeft: 'auto'
      });
      this.document.body.appendChild(box);
    }

    box.className = `alert alert-${type} shadow`;
    box.textContent = String(message || '');
    box.style.display = 'block';

    if (timeout > 0) {
      this.window.setTimeout(() => {
        if (box.textContent === String(message || '')) box.style.display = 'none';
      }, timeout);
    }
  }

  remember(jobId) {
    const ids = new Set(this.restore());
    ids.add(jobId);
    try { this.window.sessionStorage.setItem(this.storageKey, JSON.stringify(Array.from(ids))); } catch (_) {}
  }

  forget(jobId) {
    const ids = this.restore().filter((id) => id !== jobId);
    try { this.window.sessionStorage.setItem(this.storageKey, JSON.stringify(ids)); } catch (_) {}
  }

  restore() {
    try {
      const raw = this.window.sessionStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.map(String).filter(Boolean) : [];
    } catch (_) {
      return [];
    }
  }

  dispatch(name, detail) {
    try {
      this.document.dispatchEvent(new CustomEvent(name, { detail }));
    } catch (_) {}
  }

  static boot(win = window, doc = document) {
    if (win.DriveMoveTasks instanceof DriveMoveTasks) return win.DriveMoveTasks;
    const instance = new DriveMoveTasks(win, doc).init();
    win.DriveMoveTasks = instance;
    return instance;
  }
}

DriveMoveTasks.boot();
