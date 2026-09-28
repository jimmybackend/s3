class ArcadeCloudOsNodeMonitor {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.config = win.ARCADECLOUD_OS_NODE || {};
    this.endpoint = String(this.config.endpoint || 'node-status.php');
    this.busy = false;
  }

  init() {
    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      if (target.closest('[data-window-open="nodeWindow"]')) {
        this.window.setTimeout(() => this.refresh(), 30);
      }

      const broom = target.closest('[data-node-memory-clear]');
      if (broom) {
        event.preventDefault();
        this.openMemoryModal();
      }

      const disk = target.closest('[data-node-disk-clean]');
      if (disk) {
        event.preventDefault();
        this.openDiskModal();
      }

      const submit = target.closest('[data-node-memory-submit]');
      if (submit) {
        event.preventDefault();
        this.queueMaintenance('memory-clear');
      }

      const diskSubmit = target.closest('[data-node-disk-submit]');
      if (diskSubmit) {
        event.preventDefault();
        this.queueMaintenance('disk-clean');
      }

      const refresh = target.closest('[data-node-refresh]');
      if (refresh) {
        event.preventDefault();
        this.refresh();
      }
    });

    this.document.addEventListener('background-tasks:refresh', () => {
      const win = this.document.getElementById('nodeWindow');
      if (win?.classList.contains('is-open')) this.refresh();
    });

    return this;
  }

  async refresh() {
    if (this.busy) return;
    this.busy = true;
    const windowEl = this.document.getElementById('nodeWindow');
    windowEl?.classList.add('is-node-loading');

    try {
      const url = new URL(this.endpoint, this.window.location.href);
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.error || 'No se pudo actualizar Mi nodo.');
      }
      this.render(data.node || {});
    } catch (error) {
      this.notify(error?.message || 'No se pudo actualizar Mi nodo.', 'warning');
    } finally {
      this.busy = false;
      windowEl?.classList.remove('is-node-loading');
    }
  }

  render(node) {
    const values = {
      instance_type: node.instance_type || 'no identificada',
      vcpu: String(node.vcpu ?? 0),
      memory_total: node.memory_total || '0 B',
      memory_available: node.memory_available || '0 B',
      disk_total: node.disk_total || '0 B',
      disk_used: (node.disk_used || '0 B') + ' · ' + String(node.disk_used_percent ?? 0) + '%',
      disk_free: node.disk_free || '0 B',
      swap: node.swap_total || '0 B',
      load: Array.isArray(node.load_average) ? node.load_average.join(' · ') : '0 · 0 · 0',
      role: node.role || 'web',
      instance_id: node.instance_id || '—'
    };

    Object.entries(values).forEach(([key, value]) => {
      this.document.querySelectorAll('[data-node-field="' + key + '"]').forEach((el) => {
        el.textContent = String(value);
      });
    });

    this.renderCapability('ffmpeg', node.ffmpeg_available, 'Disponible', 'No disponible');
    this.renderCapability('ffprobe', node.ffprobe_available, 'Disponible', 'No disponible');
    this.renderCapability('docker', node.docker_installed, 'Instalado', 'No instalado');
    this.renderCapability('gpu', node.gpu_present, 'Detectada', 'No detectada');

    const updated = this.document.querySelector('[data-node-updated]');
    if (updated) {
      const date = node.generated_at ? new Date(node.generated_at) : new Date();
      updated.textContent = 'Actualizado ' + date.toLocaleTimeString('es-MX');
    }
  }

  renderCapability(name, ready, yes, no) {
    const el = this.document.querySelector('[data-node-capability="' + name + '"]');
    if (!el) return;
    el.textContent = ready ? yes : no;
    el.classList.toggle('is-ready', Boolean(ready));
    el.classList.toggle('is-missing', !ready);
  }

  openMemoryModal() {
    const modal = this.document.getElementById('nodeMemoryClearModal');
    if (!modal) return;
    const input = modal.querySelector('[data-node-memory-password]');
    const status = modal.querySelector('[data-node-memory-status]');
    if (input) input.value = '';
    if (status) {
      status.textContent = 'Si hay procesos activos, la limpieza quedará en cola hasta que terminen.';
      status.className = 'small text-muted';
    }
    const jq = this.window.jQuery || this.window.$;
    if (jq && typeof jq(modal).modal === 'function') jq(modal).modal('show');
  }

  openDiskModal() {
    const modal = this.document.getElementById('nodeDiskCleanModal');
    if (!modal) return;
    const input = modal.querySelector('[data-node-disk-password]');
    const status = modal.querySelector('[data-node-disk-status]');
    if (input) input.value = '';
    if (status) {
      status.textContent = 'La limpieza se ejecutará sólo cuando no haya tareas activas.';
      status.className = 'small text-muted';
    }
    const jq = this.window.jQuery || this.window.$;
    if (jq && typeof jq(modal).modal === 'function') jq(modal).modal('show');
  }

  async queueMaintenance(action) {
    if (this.busy) return;

    const isDisk = action === 'disk-clean';
    const modal = this.document.getElementById(isDisk ? 'nodeDiskCleanModal' : 'nodeMemoryClearModal');
    const input = modal?.querySelector(isDisk ? '[data-node-disk-password]' : '[data-node-memory-password]');
    const status = modal?.querySelector(isDisk ? '[data-node-disk-status]' : '[data-node-memory-status]');
    const button = modal?.querySelector(isDisk ? '[data-node-disk-submit]' : '[data-node-memory-submit]');
    const password = String(input?.value || '');

    if (!password) {
      if (status) {
        status.textContent = 'Escribe la contraseña privada.';
        status.className = 'small text-danger';
      }
      return;
    }

    this.busy = true;
    if (button) button.disabled = true;
    if (status) {
      status.textContent = 'Programando mantenimiento…';
      status.className = 'small text-info';
    }

    try {
      const response = await this.window.fetch(this.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Server-Admin-CSRF': String(this.config.csrf || ''),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action,
          access_password: password
        }).toString()
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.error || 'No se pudo programar el mantenimiento.');
      }

      if (status) {
        status.textContent = String(data.message || 'Mantenimiento programado.');
        status.className = 'small text-success';
      }
      this.render(data.node || {});
      this.document.dispatchEvent(new Event('background-tasks:refresh'));
      this.notify(String(data.message || 'Mantenimiento programado.'), 'success');

      const jq = this.window.jQuery || this.window.$;
      this.window.setTimeout(() => {
        if (jq && modal && typeof jq(modal).modal === 'function') jq(modal).modal('hide');
      }, 1000);
    } catch (error) {
      if (status) {
        status.textContent = error?.message || 'No se pudo programar el mantenimiento.';
        status.className = 'small text-danger';
      }
    } finally {
      this.busy = false;
      if (button) button.disabled = false;
    }
  }

  notify(message, type) {
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
    } else {
      console.log('[Mi nodo]', message);
    }
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudOsNodeMonitor instanceof ArcadeCloudOsNodeMonitor) return win.ArcadeCloudOsNodeMonitor;
    const instance = new ArcadeCloudOsNodeMonitor(win, doc).init();
    win.ArcadeCloudOsNodeMonitor = instance;
    return instance;
  }
}

ArcadeCloudOsNodeMonitor.boot();
