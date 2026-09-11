class ServerAdminModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.button = null;
    this.modal = null;
    this.saveButton = null;
    this.select = null;
    this.valueInput = null;
    this.passwordInput = null;
    this.csrf = '';
    this.settings = [];
  }

  init() {
    this.button = this.document.getElementById('btnServerAdmin');
    this.modal = this.document.getElementById('modalServerAdmin');
    this.saveButton = this.document.getElementById('btnSaveServerAdmin');
    this.select = this.document.getElementById('serverAdminVariable');
    this.valueInput = this.document.getElementById('serverAdminValue');
    this.passwordInput = this.document.getElementById('serverAdminPassword');
    if (!this.button || !this.modal || !this.saveButton || !this.select || !this.valueInput || !this.passwordInput) return this;

    this.csrf = String(this.button.dataset.csrf || '');
    if (this.modal.parentElement !== this.document.body) this.document.body.appendChild(this.modal);
    if (this.window.jQuery) this.window.jQuery(this.modal).on('shown.bs.modal', () => this.load());
    else this.button.addEventListener('click', () => this.load());
    this.select.addEventListener('change', () => this.syncSelectedSetting());
    this.saveButton.addEventListener('click', () => this.save());
    return this;
  }

  async load() {
    this.showMessage('Cargando configuración administrada…', 'info');
    try {
      const response = await fetch('server-settings.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !Array.isArray(data.settings)) throw new Error(data.error || `HTTP ${response.status}`);
      this.settings = data.settings;
      this.renderSettings();
      const helper = this.document.getElementById('serverAdminHelperStatus');
      if (helper) {
        helper.textContent = data.helper_available
          ? `Helper privilegiado activo: ${data.helper_path}. Variables guardadas en ${data.managed_env_path}.`
          : `Helper no instalado. Preparación única desde el servidor: ${data.install_command}`;
        helper.className = data.helper_available ? 'small text-success mb-3' : 'small text-warning mb-3';
      }
      this.showMessage(data.helper_available ? '' : 'Antes del primer cambio debes instalar una sola vez el helper mostrado abajo. Después podrás administrar estas variables desde la aplicación.', data.helper_available ? '' : 'warning');
    } catch (error) {
      this.showMessage(error.message || 'No se pudo cargar la configuración del servidor.', 'danger');
    }
  }

  renderSettings() {
    this.select.replaceChildren();
    let lastGroup = '';
    this.settings.forEach((row) => {
      const group = String(row.group || 'General');
      if (group !== lastGroup) {
        const optgroup = this.document.createElement('optgroup');
        optgroup.label = group;
        optgroup.dataset.group = group;
        this.select.appendChild(optgroup);
        lastGroup = group;
      }
      const target = this.select.lastElementChild;
      const option = this.document.createElement('option');
      option.value = String(row.name || '');
      option.textContent = `${row.name}${row.configured ? ' · configurada' : ' · sin configurar'}`;
      if (target && target.tagName === 'OPTGROUP') target.appendChild(option);
      else this.select.appendChild(option);
    });
    this.syncSelectedSetting();
  }

  syncSelectedSetting() {
    const row = this.settings.find((item) => String(item.name) === String(this.select.value));
    if (!row) return;
    this.valueInput.type = row.secret ? 'password' : 'text';
    this.valueInput.value = row.secret ? '' : String(row.value || '');
    this.valueInput.placeholder = row.secret && row.configured ? 'Secreto configurado; escribe uno nuevo para reemplazarlo' : '';
    const help = this.document.getElementById('serverAdminValueHelp');
    if (help) {
      help.textContent = row.secret
        ? 'El valor actual no se devuelve al navegador.'
        : `Origen actual: ${row.source || 'unset'}.`;
    }
  }

  async save() {
    const name = String(this.select.value || '');
    const value = String(this.valueInput.value || '');
    const currentPassword = String(this.passwordInput.value || '');
    if (!name || !this.csrf) return;
    if (!currentPassword) {
      this.showMessage('Confirma tu contraseña actual de superusuario.', 'warning');
      return;
    }

    this.saveButton.disabled = true;
    this.saveButton.textContent = 'Guardando…';
    this.showMessage(`Actualizando ${name}…`, 'info');
    try {
      const body = new URLSearchParams();
      body.set('action', 'set');
      body.set('name', name);
      body.set('value', value);
      body.set('current_password', currentPassword);
      const response = await fetch('server-settings.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {
          'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          'X-Server-Admin-CSRF': this.csrf,
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      this.passwordInput.value = '';
      this.valueInput.value = '';
      this.showMessage(data.message || 'Variable actualizada.', 'success');
      await this.load();
    } catch (error) {
      this.showMessage(error.message || 'No se pudo actualizar la variable.', 'danger');
    } finally {
      this.saveButton.disabled = false;
      this.saveButton.innerHTML = '<i class="fas fa-save mr-1"></i>Guardar variable';
    }
  }

  showMessage(message, type) {
    const alert = this.document.getElementById('serverAdminAlert');
    if (!alert) return;
    if (!message) { alert.className = 'alert d-none'; alert.textContent = ''; return; }
    alert.className = `alert alert-${type || 'info'}`;
    alert.textContent = message;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || {modules: {}};
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new ServerAdminModule(win, doc).init();
    win.ArcadeCloudDrive.modules['server-admin'] = instance;
    return instance;
  }
}

ServerAdminModule.boot();
