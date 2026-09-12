class FederationShareDriveModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.csrf = '';
    this.rows = [];
    this.observer = null;
    this.pollTimer = null;
  }

  init() {
    this.bind();
    this.load();
    this.selectSharesView();
    return this;
  }

  bind() {
    this.document.addEventListener('click', (event) => {
      const button = event.target?.closest?.('[data-share-drive-import]');
      if (!button) return;
      event.preventDefault();
      this.importShare(String(button.dataset.shareDriveImport || ''), button);
    });

    const target = this.document.getElementById('federationReceivedShares');
    if (target && 'MutationObserver' in this.window) {
      this.observer = new MutationObserver(() => this.enhanceCards());
      // El portal reemplaza las tarjetas como hijos directos. No observamos el
      // subárbol porque enhanceCards() añade botones dentro de esas tarjetas.
      this.observer.observe(target, { childList: true });
    }
  }

  async load() {
    try {
      const data = await this.fetchJson('share-drive.php', {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      this.csrf = String(data.csrf || '');
      this.rows = Array.isArray(data.received) ? data.received : [];
      this.enhanceCards();
      this.schedulePoll();
    } catch (error) {
      this.alert(error.message || 'No se pudo cargar el estado de Compartidos.', 'warning');
    }
  }

  schedulePoll() {
    if (this.pollTimer) {
      this.window.clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
    const waiting = this.rows.some((row) => ['queued', 'processing', 'retry'].includes(String(row.import_status || '')));
    if (waiting) {
      this.pollTimer = this.window.setTimeout(() => this.load(), 8000);
    }
  }

  enhanceCards() {
    const portal = this.window.ArcadeCloudDrive?.modules?.['federation-portal'];
    const portalShares = Array.isArray(portal?.state?.shares)
      ? portal.state.shares.filter((row) => String(row.direction) === 'received')
      : [];
    const target = this.document.getElementById('federationReceivedShares');
    if (!target || !portalShares.length || !this.rows.length) return;

    const cards = Array.from(target.querySelectorAll(':scope > .federation-mini-card'));
    portalShares.forEach((share, index) => {
      const card = cards[index];
      if (!card) return;
      const shareId = String(share.share_id || '');
      const state = this.rows.find((row) => String(row.share_id || '') === shareId);
      if (!state) return;

      let actions = card.querySelector('.federation-share-drive-actions');
      if (!actions) {
        actions = this.document.createElement('div');
        actions.className = 'federation-share-drive-actions d-flex flex-wrap align-items-center mt-2';
        card.appendChild(actions);
      }
      actions.innerHTML = '';

      if (state.imported) {
        const badge = this.document.createElement('span');
        badge.className = 'badge badge-success mr-2 mb-1';
        badge.innerHTML = '<i class="fas fa-cloud-arrow-down mr-1"></i>En Mi Drive';
        actions.appendChild(badge);

        const openDrive = this.document.createElement('a');
        openDrive.className = 'btn btn-outline-info btn-sm mr-2 mb-1';
        openDrive.href = '../s3.php';
        openDrive.innerHTML = '<i class="fas fa-folder-open mr-1"></i>Ver en Archivos';
        actions.appendChild(openDrive);
      }

      const importStatus = String(state.import_status || '');
      if (['queued', 'processing', 'retry'].includes(importStatus)) {
        const pending = this.document.createElement('span');
        pending.className = importStatus === 'retry' ? 'badge badge-warning mb-1' : 'badge badge-info mb-1';
        pending.innerHTML = importStatus === 'processing'
          ? '<i class="fas fa-spinner fa-spin mr-1"></i>Copiando a Mi Drive'
          : importStatus === 'retry'
            ? '<i class="fas fa-clock mr-1"></i>Reintentando copia'
            : '<i class="fas fa-hourglass-half mr-1"></i>Copia en cola';
        actions.appendChild(pending);
        return;
      }

      if (String(state.status || '') !== 'active') return;

      if (!state.imported || state.update_available || importStatus === 'failed') {
        const button = this.document.createElement('button');
        button.type = 'button';
        button.className = state.update_available
          ? 'btn btn-warning btn-sm mb-1'
          : 'btn btn-success btn-sm mb-1';
        button.dataset.shareDriveImport = shareId;
        button.innerHTML = state.update_available
          ? '<i class="fas fa-rotate mr-1"></i>Agregar versión nueva'
          : importStatus === 'failed'
            ? '<i class="fas fa-rotate mr-1"></i>Reintentar Agregar a Mi Drive'
            : '<i class="fas fa-plus mr-1"></i>Agregar a Mi Drive';
        actions.appendChild(button);

        if (importStatus === 'failed' && state.import_error) {
          const error = this.document.createElement('span');
          error.className = 'small text-warning ml-2 mb-1';
          error.textContent = String(state.import_error);
          actions.appendChild(error);
        }
      } else {
        const current = this.document.createElement('span');
        current.className = 'small text-muted mb-1';
        current.textContent = 'La copia local corresponde a la versión global conocida.';
        actions.appendChild(current);
      }
    });
  }

  async importShare(shareId, button) {
    if (!shareId || !this.csrf) return;
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span>Encolando…';
    try {
      const body = new URLSearchParams();
      body.set('action', 'import');
      body.set('share_id', shareId);
      const data = await this.fetchJson('share-drive.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Federation-Share-Drive-CSRF': this.csrf
        },
        body: body.toString()
      });
      this.alert(String(data.message || 'La copia quedó en cola.'), data.status === 'failed' ? 'warning' : 'success');
      await this.load();
    } catch (error) {
      this.alert(error.message || 'No se pudo encolar el archivo para Mi Drive.', 'danger');
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  selectSharesView() {
    const view = new URLSearchParams(this.window.location.search).get('view');
    if (view !== 'shares') return;
    const tab = this.document.querySelector('a[href="#federationSharesPane"]');
    if (tab && this.window.jQuery) this.window.jQuery(tab).tab('show');
  }

  async fetchJson(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    const response = await fetch(url, { ...options, headers });
    const text = await response.text();
    let data = null;
    try { data = text ? JSON.parse(text) : {}; } catch (_) { data = null; }
    if (!response.ok || !data || data.ok === false) {
      throw new Error(String((data && data.error) || `HTTP ${response.status}`));
    }
    return data;
  }

  alert(message, type = 'info') {
    const portal = this.window.ArcadeCloudDrive?.modules?.['federation-portal'];
    if (portal && typeof portal.alert === 'function') {
      portal.alert(message, type);
      return;
    }
    const target = this.document.getElementById('federationPortalAlert');
    if (!target) return;
    target.className = `alert alert-${type}`;
    target.textContent = message;
    target.classList.remove('d-none');
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new FederationShareDriveModule(win, doc).init();
    win.ArcadeCloudDrive.modules['federation-share-drive'] = instance;
    return instance;
  }
}

FederationShareDriveModule.boot();