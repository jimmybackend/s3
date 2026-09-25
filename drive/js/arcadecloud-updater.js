class ArcadeCloudUpdaterModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.section = null;
    this.checkButton = null;
    this.applyButton = null;
    this.password = null;
    this.status = null;
    this.details = null;
    this.csrf = '';
  }

  init() {
    const install = () => {
      const modal = this.document.getElementById('modalAcercaArcadeCloud');
      if (!modal || this.document.getElementById('arcadeCloudUpdateSection')) return;

      const serverButton = this.document.getElementById('btnServerAdmin');
      if (!serverButton) return; // sólo superadmin
      this.csrf = String(serverButton.dataset.csrf || '');

      const body = modal.querySelector('.modal-body');
      if (!body) return;

      const section = this.document.createElement('div');
      section.id = 'arcadeCloudUpdateSection';
      section.className = 'alert alert-secondary mt-3 mb-0';
      section.innerHTML = `
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap:.5rem">
          <div>
            <strong><i class="fas fa-rotate mr-1"></i>Actualizaciones de ArcadeCloud</strong>
            <div class="small text-muted">La consulta sólo se realiza cuando pulsas el botón.</div>
          </div>
          <button type="button" id="btnArcadeCloudCheckUpdate" class="btn btn-outline-info btn-sm">
            <i class="fas fa-magnifying-glass mr-1"></i>Buscar actualizaciones
          </button>
        </div>
        <div id="arcadeCloudUpdateStatus" class="small">No se ha comprobado todavía.</div>
        <div id="arcadeCloudUpdateDetails" class="small mt-2 d-none"></div>
        <div id="arcadeCloudUpdateApplyBox" class="mt-3 d-none">
          <div class="form-group mb-2">
            <label for="arcadeCloudUpdatePassword" class="mb-1">Contraseña actual de superusuario</label>
            <input id="arcadeCloudUpdatePassword" type="password" class="form-control form-control-sm" autocomplete="current-password">
            <small class="form-text text-muted">La actualización sólo se permite si main está limpio y puede avanzar por fast-forward.</small>
          </div>
          <button type="button" id="btnArcadeCloudApplyUpdate" class="btn btn-warning btn-sm">
            <i class="fas fa-download mr-1"></i>Actualizar ahora
          </button>
        </div>`;

      const legal = body.querySelector('small.d-block.text-muted.mt-3');
      if (legal) body.insertBefore(section, legal);
      else body.appendChild(section);

      this.section = section;
      this.checkButton = this.document.getElementById('btnArcadeCloudCheckUpdate');
      this.applyButton = this.document.getElementById('btnArcadeCloudApplyUpdate');
      this.password = this.document.getElementById('arcadeCloudUpdatePassword');
      this.status = this.document.getElementById('arcadeCloudUpdateStatus');
      this.details = this.document.getElementById('arcadeCloudUpdateDetails');
      this.checkButton?.addEventListener('click', () => this.check());
      this.applyButton?.addEventListener('click', () => this.apply());
    };

    if (this.document.readyState === 'loading') this.document.addEventListener('DOMContentLoaded', () => setTimeout(install, 0));
    else setTimeout(install, 0);
    return this;
  }

  async check() {
    if (!this.checkButton || !this.status) return;
    this.checkButton.disabled = true;
    this.status.textContent = 'Consultando origin/main…';
    this.status.className = 'small text-info';
    this.hideApply();
    try {
      const response = await fetch('update.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await this.readJsonResponse(response);
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      this.render(data);
    } catch (error) {
      this.status.textContent = error.message || 'No se pudo comprobar la actualización.';
      this.status.className = 'small text-danger';
      if (this.details) this.details.classList.add('d-none');
    } finally {
      this.checkButton.disabled = false;
    }
  }

  render(data) {
    const local = String(data.local_commit || '').slice(0, 12);
    const remote = String(data.remote_commit || '').slice(0, 12);
    const branch = String(data.branch || '');
    const behind = Number(data.behind || 0);
    const dirty = Boolean(data.dirty);

    if (!data.update_available) {
      this.status.textContent = `✓ ArcadeCloud está actualizado · ${local || 'commit desconocido'}`;
      this.status.className = 'small text-success';
    } else {
      this.status.textContent = `Actualización disponible · ${behind} commit${behind === 1 ? '' : 's'} nuevo${behind === 1 ? '' : 's'}.`;
      this.status.className = 'small text-warning';
    }

    if (this.details) {
      const lines = Array.isArray(data.summary) ? data.summary : [];
      const list = lines.length ? `<ul class="mb-0 pl-4">${lines.map((line) => `<li>${this.escape(line)}</li>`).join('')}</ul>` : '';
      this.details.innerHTML = `
        <div><strong>Rama:</strong> ${this.escape(branch)}</div>
        <div><strong>Instalado:</strong> ${this.escape(local)}</div>
        <div><strong>Disponible:</strong> ${this.escape(remote)}</div>
        ${dirty ? '<div class="text-danger"><strong>Atención:</strong> hay cambios locales sin guardar.</div>' : ''}
        ${list}`;
      this.details.classList.remove('d-none');
    }

    if (data.update_available && data.can_apply) {
      this.document.getElementById('arcadeCloudUpdateApplyBox')?.classList.remove('d-none');
    } else if (data.update_available && !data.can_apply) {
      const box = this.document.getElementById('arcadeCloudUpdateApplyBox');
      if (box) box.classList.add('d-none');
      this.status.textContent += ' Revisión manual requerida; el updater no forzará el repositorio.';
    } else {
      this.hideApply();
    }
  }

  async apply() {
    if (!this.applyButton || !this.password || !this.csrf) return;
    const currentPassword = String(this.password.value || '');
    if (!currentPassword) {
      this.status.textContent = 'Escribe tu contraseña actual de superusuario para confirmar.';
      this.status.className = 'small text-warning';
      return;
    }
    if (!this.window.confirm('ArcadeCloud avanzará main por fast-forward a la versión disponible. ¿Continuar?')) return;

    this.applyButton.disabled = true;
    this.applyButton.textContent = 'Actualizando…';
    this.status.textContent = 'Aplicando actualización segura…';
    this.status.className = 'small text-info';
    try {
      const body = new URLSearchParams();
      body.set('action', 'apply');
      body.set('current_password', currentPassword);
      const response = await fetch('update.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {
          'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          'X-Server-Admin-CSRF': this.csrf,
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
      });
      const data = await this.readJsonResponse(response);
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      if (Object.prototype.hasOwnProperty.call(data, 'update_available')) {
        this.render(data);
      } else {
        this.status.textContent = data.message || 'ArcadeCloud actualizado. Recarga la página.';
        this.status.className = data.needs_attention ? 'small text-warning' : 'small text-success';
      }
      if (data.needs_attention) {
        this.status.textContent = data.message || 'El código quedó actualizado, pero un servicio necesita revisión.';
        this.status.className = 'small text-warning';
      }
      this.hideApply();
      this.password.value = '';
    } catch (error) {
      this.status.textContent = error.message || 'No se pudo aplicar la actualización.';
      this.status.className = 'small text-danger';
    } finally {
      this.applyButton.disabled = false;
      this.applyButton.innerHTML = '<i class="fas fa-download mr-1"></i>Actualizar ahora';
    }
  }

  async readJsonResponse(response) {
    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch {
      throw new Error(
        `El servidor devolvió una respuesta no JSON (HTTP ${response.status}). `
        + 'La actualización puede haber terminado; pulsa Buscar actualizaciones para confirmar.'
      );
    }
  }

  hideApply() {
    this.document.getElementById('arcadeCloudUpdateApplyBox')?.classList.add('d-none');
  }

  escape(value) {
    const div = this.document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || {modules: {}};
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new ArcadeCloudUpdaterModule(win, doc).init();
    win.ArcadeCloudDrive.modules['arcadecloud-updater'] = instance;
    return instance;
  }
}

ArcadeCloudUpdaterModule.boot();
