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
    this.applyAction = 'apply';
  }

  init() {
    const install = () => {
      const modal = this.document.getElementById('modalAcercaArcadeCloud');
      if (!modal || this.document.getElementById('arcadeCloudUpdateSection')) return;

      const serverButton = this.document.getElementById('btnServerAdmin');
      const configuredCsrf = String(this.window.ARCADECLOUD_UPDATER?.csrf || '');
      if (!serverButton && !configuredCsrf) return; // sólo superadmin
      this.csrf = configuredCsrf || String(serverButton?.dataset?.csrf || '');
      if (!this.csrf) return;

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
            <small id="arcadeCloudUpdateApplyHelp" class="form-text text-muted">La actualización usa fast-forward. Si existen cambios locales, podrás guardarlos primero en git stash.</small>
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
      const response = await fetch('update.php?_=' + encodeURIComponent(String(Date.now())), {
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
    const canStashAndApply = Boolean(data.can_apply_with_stash);
    const helperInSync = data.helper_in_sync !== false;

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
      let schemaLine = '';
      if (Object.prototype.hasOwnProperty.call(data, 'federation_schema_ready')) {
        const schemaClass = data.federation_schema_ready ? 'text-success' : 'text-warning';
        const schemaMessage = data.federation_schema_message
          || (data.federation_schema_ready ? 'Esquema preparado.' : 'Esquema pendiente.');
        schemaLine = `<div class="${schemaClass}"><strong>FederationCloud DB:</strong> ${this.escape(schemaMessage)}</div>`;
      }
      if (Object.prototype.hasOwnProperty.call(data, 'moderation_schema_ready')) {
        const moderationClass = data.moderation_schema_ready ? 'text-success' : 'text-danger';
        const moderationMessage = data.moderation_schema_message
          || (data.moderation_schema_ready ? 'Moderación preparada.' : 'Moderación pendiente.');
        schemaLine += `<div class="${moderationClass}"><strong>Moderación DB:</strong> ${this.escape(moderationMessage)}</div>`;
      }
      if (Object.prototype.hasOwnProperty.call(data, 'office_schema_ready')) {
        const officeClass = data.office_schema_ready ? 'text-success' : 'text-danger';
        const officeMessage = data.office_schema_message
          || (data.office_schema_ready ? 'Esquema Office preparado.' : 'Esquema Office pendiente.');
        schemaLine += `<div class="${officeClass}"><strong>ArcadeCloud Office DB:</strong> ${this.escape(officeMessage)}</div>`;
      }
      const dirtyFiles = Array.isArray(data.dirty_files) ? data.dirty_files : [];
      const dirtyList = dirty && dirtyFiles.length
        ? `<div class="mt-1"><strong>Cambios locales:</strong><ul class="mb-1 pl-4">${dirtyFiles.map((line) => `<li><code>${this.escape(line)}</code></li>`).join('')}</ul></div>`
        : '';
      const helperLine = helperInSync
        ? ''
        : '<div class="text-danger"><strong>Helper:</strong> la copia privilegiada instalada no coincide con el checkout. Ejecuta la reconciliación segura del instalador.</div>';
      this.details.innerHTML = `
        <div><strong>Rama:</strong> ${this.escape(branch)}</div>
        <div><strong>Instalado:</strong> ${this.escape(local)}</div>
        <div><strong>Disponible:</strong> ${this.escape(remote)}</div>
        ${helperLine}
        ${schemaLine}
        ${dirty ? '<div class="text-danger"><strong>Atención:</strong> hay cambios locales sin guardar.</div>' : ''}
        ${dirtyList}
        ${list}`;
      this.details.classList.remove('d-none');
    }

    if (data.update_available && data.can_apply) {
      this.applyAction = 'apply';
      this.document.getElementById('arcadeCloudUpdateApplyBox')?.classList.remove('d-none');
      if (this.applyButton) this.applyButton.innerHTML = '<i class="fas fa-download mr-1"></i>Actualizar ahora';
    } else if (data.update_available && canStashAndApply) {
      this.applyAction = 'apply_stash';
      this.document.getElementById('arcadeCloudUpdateApplyBox')?.classList.remove('d-none');
      if (this.applyButton) this.applyButton.innerHTML = '<i class="fas fa-box-archive mr-1"></i>Guardar cambios y actualizar';
      this.status.textContent += ' Puedes guardar los cambios locales en git stash y actualizar sin perderlos.';
    } else if (data.update_available) {
      this.applyAction = '';
      const box = this.document.getElementById('arcadeCloudUpdateApplyBox');
      if (box) box.classList.add('d-none');
      this.status.textContent += ' Revisión manual requerida; el updater no forzará el repositorio.';
    } else {
      this.applyAction = '';
      this.hideApply();
    }
  }

  async apply() {
    if (!this.applyButton || !this.password || !this.csrf || !this.applyAction) return;
    const currentPassword = String(this.password.value || '');
    if (!currentPassword) {
      this.status.textContent = 'Escribe tu contraseña actual de superusuario para confirmar.';
      this.status.className = 'small text-warning';
      return;
    }
    const confirmation = this.applyAction === 'apply_stash'
      ? 'ArcadeCloud guardará los cambios locales en git stash, avanzará main por fast-forward y NO restaurará esos cambios automáticamente. ¿Continuar?'
      : 'ArcadeCloud avanzará main por fast-forward a la versión disponible. ¿Continuar?';
    if (!this.window.confirm(confirmation)) return;

    this.applyButton.disabled = true;
    this.applyButton.textContent = 'Actualizando…';
    this.status.textContent = 'Aplicando actualización segura…';
    this.status.className = 'small text-info';
    try {
      const body = new URLSearchParams();
      body.set('action', this.applyAction);
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
