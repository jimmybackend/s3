class ArcadeCloudFilesystemOperations {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.operations = new Map();
    this.inFlight = new Map();
    this.sequence = 0;
    this.bindTaskLifecycle();
  }

  bindTaskLifecycle() {
    if (!this.document?.addEventListener) return;
    this.document.addEventListener('drive:move-task-progress', event => this.updateTask(event.detail, 'running'));
    this.document.addEventListener('drive:move-task-completed', event => this.updateTask(event.detail, 'completed'));
    this.document.addEventListener('drive:move-task-failed', event => this.updateTask(event.detail, 'failed'));
    this.document.addEventListener('drive:move-task-cancelled', event => this.updateTask(event.detail, 'cancelled'));
  }

  updateTask(detail = {}, status) {
    const operation = this.operations.get(String(detail.job_id || ''));
    if (!operation) return;
    operation.status = status;
    const progress = detail.progress;
    operation.progress = progress === null || progress === undefined || progress === '' ? null : Number(progress);
    operation.error = status === 'failed' ? String(detail.error || detail.mensaje || 'La operación falló.') : null;
    if (['completed', 'failed', 'cancelled'].includes(status)) operation.completedAt = new Date().toISOString();
    this.dispatch('filesystem:operation', operation);
  }

  operation(spec = {}) {
    const now = new Date().toISOString();
    const operation = {
      operationId: String(spec.operationId || `fs-${Date.now()}-${++this.sequence}`),
      type: String(spec.type || 'unknown'),
      items: Array.isArray(spec.items) ? [...spec.items] : [],
      sourceRoute: String(spec.sourceRoute || ''),
      destinationRoute: String(spec.destinationRoute || ''),
      sourceWindowId: String(spec.sourceWindowId || ''),
      destinationWindowId: String(spec.destinationWindowId || ''),
      status: 'pending',
      progress: spec.progress ?? null,
      error: null,
      startedAt: null,
      completedAt: null
    };
    this.operations.set(operation.operationId, operation);
    return operation;
  }

  fingerprint(operation) {
    return JSON.stringify([
      operation.type,
      operation.items,
      operation.sourceRoute,
      operation.destinationRoute
    ]);
  }

  async run(spec, executor, options = {}) {
    const operation = this.operation(spec);
    const fingerprint = String(options.fingerprint || this.fingerprint(operation));
    if (this.inFlight.has(fingerprint)) return this.inFlight.get(fingerprint);

    const pending = (async () => {
      operation.status = 'running';
      operation.startedAt = new Date().toISOString();
      this.dispatch('filesystem:operation', operation);
      try {
        const result = await executor(operation);
        operation.status = options.background === true ? 'running' : 'completed';
        operation.completedAt = options.background === true ? null : new Date().toISOString();
        operation.result = result;
        if (operation.status === 'completed' && options.announce !== false) {
          this.announce(operation);
        }
        this.dispatch('filesystem:operation', operation);
        return { operation, result };
      } catch (error) {
        operation.status = 'failed';
        operation.error = String(error?.message || error || 'La operación falló.');
        operation.completedAt = new Date().toISOString();
        this.dispatch('filesystem:operation', operation);
        throw error;
      } finally {
        this.inFlight.delete(fingerprint);
      }
    })();
    this.inFlight.set(fingerprint, pending);
    return pending;
  }

  async startTransfer(spec, payload) {
    if (!this.window.DriveMoveTasks?.start) throw new Error('El servicio de transferencias no está disponible.');
    return this.run(spec, async (operation) => {
      const result = await this.window.DriveMoveTasks.start(payload);
      this.operations.delete(operation.operationId);
      operation.operationId = String(result.job_id || operation.operationId);
      operation.jobId = String(result.job_id || '');
      this.operations.set(operation.operationId, operation);
      return result;
    }, { background: true, announce: false });
  }

  async request(endpoint, body) {
    const response = await this.window.fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body instanceof URLSearchParams ? body.toString() : new URLSearchParams(body || {}).toString()
    });
    const text = await response.text();
    let data = null;
    try { data = JSON.parse(text); } catch (_) {}
    if (!response.ok || data?.ok !== true) {
      throw new Error(data?.error || data?.mensaje || data?.message || text || `HTTP ${response.status}`);
    }
    return data;
  }

  announce(operation) {
    const detail = {
      operation: operation.type,
      operationId: operation.operationId,
      sourceRoute: operation.sourceRoute,
      destinationRoute: operation.destinationRoute,
      sourceWindowId: operation.sourceWindowId,
      destinationWindowId: operation.destinationWindowId,
      items: [...operation.items]
    };
    this.window.ArcadeCloudDesktop?.emitFilesystemChanged?.(detail);
    this.dispatch('filesystem:changed', detail);
  }

  dispatch(name, detail) {
    try { this.document.dispatchEvent(new CustomEvent(name, { detail: { ...detail } })); } catch (_) {}
  }

  confirm(options = {}) {
    if (!this.document?.body || !this.document.createElement) return Promise.resolve(false);
    const overlay = this.document.createElement('div');
    overlay.className = 'os-operation-dialog-backdrop';
    overlay.innerHTML = `<section class="os-operation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="os-operation-dialog-title" aria-describedby="os-operation-dialog-message"><h2 id="os-operation-dialog-title"></h2><p id="os-operation-dialog-message"></p><div class="os-operation-dialog-actions"><button type="button" data-dialog-cancel>Cancelar</button><button type="button" class="is-primary" data-dialog-confirm></button></div></section>`;
    const title = overlay.querySelector('#os-operation-dialog-title');
    const message = overlay.querySelector('#os-operation-dialog-message');
    const cancel = overlay.querySelector('[data-dialog-cancel]');
    const accept = overlay.querySelector('[data-dialog-confirm]');
    title.textContent = String(options.title || 'Confirmar operación');
    message.textContent = String(options.message || '¿Quieres continuar?');
    accept.textContent = String(options.confirmLabel || 'Continuar');
    if (options.danger) accept.classList.add('is-danger');
    this.document.body.appendChild(overlay);

    return new Promise((resolve) => {
      let settled = false;
      const finish = (value) => {
        if (settled) return;
        settled = true;
        this.document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        resolve(value);
      };
      const onKey = (event) => {
        if (event.key === 'Escape') { event.preventDefault(); finish(false); }
        if (event.key === 'Enter' && event.target === accept) { event.preventDefault(); finish(true); }
        if (event.key === 'Tab') {
          const controls = [cancel, accept];
          const index = controls.indexOf(this.document.activeElement);
          event.preventDefault();
          controls[(index + (event.shiftKey ? 1 : 3)) % 2].focus();
        }
      };
      cancel.addEventListener('click', () => finish(false));
      accept.addEventListener('click', () => finish(true), { once: true });
      overlay.addEventListener('click', event => { if (event.target === overlay) finish(false); });
      this.document.addEventListener('keydown', onKey, true);
      accept.focus();
    });
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudFilesystemOperations instanceof ArcadeCloudFilesystemOperations) return win.ArcadeCloudFilesystemOperations;
    win.ArcadeCloudFilesystemOperations = new ArcadeCloudFilesystemOperations(win, doc);
    return win.ArcadeCloudFilesystemOperations;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudFilesystemOperations };
if (typeof window !== 'undefined' && typeof document !== 'undefined') ArcadeCloudFilesystemOperations.boot(window, document);
