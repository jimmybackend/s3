class RepositoryUpdateModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.config = null;
    this.lastCheck = null;
  }

  init() {
    const boot = () => {
      this.config = this.document.getElementById('btnServerAdmin');
      if (!this.config) return;
      this.ensurePanel();
      this.bind();
    };

    if (this.document.readyState === 'loading') {
      this.document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
      boot();
    }
    return this;
  }

  ensurePanel() {
    if (this.document.getElementById('arcadeCloudUpdatePanel')) return;
    const modalBody = this.document.querySelector('#modalAcercaArcadeCloud .modal-body');
    if (!modalBody) return;

    const panel = this.document.createElement('div');
    panel.id = 'arcadeCloudUpdatePanel';
    panel.className = 'card mt-3';
    panel.innerHTML = `
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
          <h6 class="mb-1"><i class="fas fa-rotate mr-1"></i> Actualizaciones de ArcadeCloud</h6>
          <span class="badge badge-secondary">Superusuario</span>
        </div>
        <p class="small mb-2">
          ArcadeCloud no consulta GitHub automáticamente. Sólo se comprueba el repositorio cuando pulses el botón.
        </p>
        <div id="arcadeCloudUpdateAlert" class="alert alert-secondary py-2 mb-2" role="status">
          No se han buscado actualizaciones en esta sesión.
        </div>
        <div id="arcadeCloudUpdateDetails" class="d-none small mb-3">
          <div><strong>Instalado:</strong> <span id="arcadeCloudUpdateLocal">—</span></div>
          <div><strong>Disponible:</strong> <span id="arcadeCloudUpdateRemote">—</span></div>
          <div><strong>Consulta:</strong> <span id="arcadeCloudUpdateSource">—</span></div>
          <div id="arcadeCloudUpdateBlockers" class="text-danger mt-2 d-none"></div>
          <div id="arcadeCloudUpdateChangesWrap" class="mt-2 d-none">
            <strong>Cambios encontrados:</strong>
            <ul id="arcadeCloudUpdateChanges" class="mb-0 pl-4"></ul>
          </div>
        </div>
        <div id="arcadeCloudUpdatePasswordWrap" class="form-group d-none">
          <label for="arcadeCloudUpdatePassword">Contraseña actual de superusuario</label>
          <input id="arcadeCloudUpdatePassword" type="password" class="form-control" autocomplete="current-password">
          <small class="form-text text-muted">Se solicita únicamente para aplicar la actualización.</small>
        </div>
        <div class="d-flex flex-wrap" style="gap:.5rem;">
          <button type="button" id="btnArcadeCloudCheckUpdate" class="btn btn-outline-info">
            <i class="fas fa-magnifying-glass mr-1"></i>Buscar actualizaciones
          </button>
          <button type="button" id="btnArcadeCloudApplyUpdate" class="btn btn-success d-none">
            <i class="fas fa-download mr-1"></i>Actualizar ahora
          </button>
        </div>
      </div>`;
    modalBody.appendChild(panel);
  }

  bind() {
    this.document.addEventListener('click', (event) => {
      const check = event.target.closest?.('#btnArcadeCloudCheckUpdate');
      if (check) {
        event.preventDefault();
        this.checkForUpdates();
        return;
      }
      const apply = event.target.closest?.('#btnArcadeCloudApplyUpdate');
      if (apply) {
        event.preventDefault();
        this.applyUpdate();
      }
    });
  }

  csrf() {
    return String(this.config?.dataset?.csrf || '');
  }

  async request(data) {
    const response = await fetch('repository-update.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Server-Admin-CSRF': this.csrf()
      },
      body: new URLSearchParams(data).toString()
    });
    let payload = null;
    try {
      payload = await response.json();
    } catch (_) {
      throw new Error('El servidor no devolvió una respuesta válida.');
    }
    if (!response.ok || !payload || payload.ok === false) {
      throw new Error(String(payload?.error || 'No se pudo completar la operación.'));
    }
    return payload;
  }

  setBusy(busy) {
    const check = this.document.getElementById('btnArcadeCloudCheckUpdate');
    const apply = this.document.getElementById('btnArcadeCloudApplyUpdate');
    if (check) check.disabled = busy;
    if (apply) apply.disabled = busy;
  }

  showAlert(message, kind = 'secondary') {
    const alert = this.document.getElementById('arcadeCloudUpdateAlert');
    if (!alert) return;
    alert.className = `alert alert-${kind} py-2 mb-2`;
    alert.textContent = message;
  }

  renderCheck(state) {
    this.lastCheck = state;
    const details = this.document.getElementById('arcadeCloudUpdateDetails');
    const local = this.document.getElementById('arcadeCloudUpdateLocal');
    const remote = this.document.getElementById('arcadeCloudUpdateRemote');
    const source = this.document.getElementById('arcadeCloudUpdateSource');
    const blockers = this.document.getElementById('arcadeCloudUpdateBlockers');
    const changesWrap = this.document.getElementById('arcadeCloudUpdateChangesWrap');
    const changes = this.document.getElementById('arcadeCloudUpdateChanges');
    const passwordWrap = this.document.getElementById('arcadeCloudUpdatePasswordWrap');
    const apply = this.document.getElementById('btnArcadeCloudApplyUpdate');

    details?.classList.remove('d-none');
    if (local) local.textContent = `${state.local_short || '—'} · ${state.local_subject || ''}`;
    if (remote) remote.textContent = `${state.remote_short || '—'} · ${state.remote_subject || ''}`;
    if (source) source.textContent = state.remote_source === 'public' ? 'GitHub público' : 'origin configurado';

    const blockerList = Array.isArray(state.blockers) ? state.blockers : [];
    if (blockers) {
      blockers.textContent = blockerList.join(' ');
      blockers.classList.toggle('d-none', blockerList.length === 0);
    }

    if (changes) {
      changes.replaceChildren();
      const commits = Array.isArray(state.commits) ? state.commits : [];
      commits.forEach((commit) => {
        const li = this.document.createElement('li');
        li.textContent = `${String(commit.sha || '')} · ${String(commit.subject || '')}`;
        changes.appendChild(li);
      });
      changesWrap?.classList.toggle('d-none', commits.length === 0);
    }

    if (!state.update_available) {
      this.showAlert('ArcadeCloud está actualizado.', 'success');
      apply?.classList.add('d-none');
      passwordWrap?.classList.add('d-none');
      return;
    }

    if (!state.can_update) {
      this.showAlert('Hay cambios remotos, pero este checkout no puede actualizarse automáticamente de forma segura.', 'warning');
      apply?.classList.add('d-none');
      passwordWrap?.classList.add('d-none');
      return;
    }

    this.showAlert(`Hay ${Number(state.behind || 0)} actualización(es) por aplicar.`, 'warning');
    apply?.classList.remove('d-none');
    passwordWrap?.classList.remove('d-none');
  }

  async checkForUpdates() {
    this.setBusy(true);
    this.showAlert('Consultando el repositorio…', 'info');
    try {
      const state = await this.request({ action: 'check' });
      this.renderCheck(state);
    } catch (error) {
      this.lastCheck = null;
      this.showAlert(error instanceof Error ? error.message : 'No se pudo comprobar la actualización.', 'danger');
    } finally {
      this.setBusy(false);
    }
  }

  async applyUpdate() {
    if (!this.lastCheck?.can_update || !this.lastCheck?.remote_sha) {
      this.showAlert('Vuelve a buscar actualizaciones antes de actualizar.', 'warning');
      return;
    }

    const password = String(this.document.getElementById('arcadeCloudUpdatePassword')?.value || '');
    if (!password) {
      this.showAlert('Confirma tu contraseña actual de superusuario.', 'warning');
      return;
    }
    if (!this.window.confirm('¿Actualizar ArcadeCloud ahora con un fast-forward seguro de main?')) return;

    this.setBusy(true);
    this.showAlert('Aplicando actualización… no cierres esta ventana.', 'info');
    try {
      const result = await this.request({
        action: 'update',
        expected_remote_sha: String(this.lastCheck.remote_sha),
        current_password: password
      });
      const restart = result.php_restart_scheduled
        ? ' PHP-FPM se reiniciará automáticamente en unos segundos.'
        : ' El repositorio quedó actualizado; si tu servidor usa caché de PHP, reinicia PHP-FPM manualmente.';
      this.showAlert(`ArcadeCloud actualizado a ${String(result.new_short || result.new_sha || 'la nueva versión')}.${restart}`, 'success');
      this.document.getElementById('btnArcadeCloudApplyUpdate')?.classList.add('d-none');
      this.document.getElementById('arcadeCloudUpdatePasswordWrap')?.classList.add('d-none');
      this.lastCheck = null;
    } catch (error) {
      this.showAlert(error instanceof Error ? error.message : 'No se pudo aplicar la actualización.', 'danger');
    } finally {
      this.setBusy(false);
    }
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new RepositoryUpdateModule(win, doc).init();
    win.ArcadeCloudDrive.modules['repository-update'] = instance;
    return instance;
  }
}

RepositoryUpdateModule.boot();
