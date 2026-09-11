class ServerAdminModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.button = null;
    this.modal = null;
    this.saveButton = null;
    this.groupSaveButton = null;
    this.select = null;
    this.tableBody = null;
    this.valueInput = null;
    this.passwordInput = null;
    this.singleEditor = null;
    this.groupEditor = null;
    this.groupFields = null;
    this.groupTitle = null;
    this.activeGroup = '';
    this.csrf = '';
    this.settings = [];
  }

  init() {
    this.button = this.document.getElementById('btnServerAdmin');
    this.modal = this.document.getElementById('modalServerAdmin');
    this.saveButton = this.document.getElementById('btnSaveServerAdmin');
    this.groupSaveButton = this.document.getElementById('btnSaveServerAdminGroup');
    this.select = this.document.getElementById('serverAdminVariable');
    this.tableBody = this.document.getElementById('serverAdminSettingsTableBody');
    this.valueInput = this.document.getElementById('serverAdminValue');
    this.passwordInput = this.document.getElementById('serverAdminPassword');
    this.singleEditor = this.document.getElementById('serverAdminSingleEditor');
    this.groupEditor = this.document.getElementById('serverAdminGroupEditor');
    this.groupFields = this.document.getElementById('serverAdminGroupFields');
    this.groupTitle = this.document.getElementById('serverAdminGroupTitle');
    if (!this.button || !this.modal || !this.saveButton || !this.groupSaveButton || !this.select || !this.tableBody || !this.valueInput || !this.passwordInput || !this.singleEditor || !this.groupEditor || !this.groupFields || !this.groupTitle) return this;

    this.csrf = String(this.button.dataset.csrf || '');
    if (this.modal.parentElement !== this.document.body) this.document.body.appendChild(this.modal);
    if (this.window.jQuery) this.window.jQuery(this.modal).on('shown.bs.modal', () => this.load());
    else this.button.addEventListener('click', () => this.load());
    this.select.addEventListener('change', () => this.syncSelectedSetting());
    this.saveButton.addEventListener('click', () => this.save());
    this.groupSaveButton.addEventListener('click', () => this.saveGroup());
    return this;
  }

  async load(preferredName = '', preferredGroup = '') {
    this.showMessage('Cargando configuración administrada…', 'info');
    try {
      const response = await fetch('server-settings.php', {
        credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !Array.isArray(data.settings)) throw new Error(data.error || `HTTP ${response.status}`);
      this.settings = data.settings;
      this.renderSettings(preferredName, preferredGroup);

      const helper = this.document.getElementById('serverAdminHelperStatus');
      const helperAvailable = Boolean(data.helper_available);
      const groupReady = Boolean(data.helper_group_settings);
      if (helper) {
        if (!helperAvailable) {
          helper.textContent = `Helper no instalado. Preparación única desde el servidor: ${data.install_command}`;
        } else if (!groupReady) {
          helper.textContent = `Helper activo para variables simples, pero necesita actualizarse para Base de datos/AWS: ${data.install_command}`;
        } else {
          helper.textContent = `Helper activo: ${data.helper_path}. Archivo administrado: ${data.managed_env_path}.`;
        }
        helper.className = helperAvailable && groupReady ? 'small text-success mb-3' : 'small text-warning mb-3';
      }

      if (!helperAvailable) {
        this.showMessage('Antes del primer cambio debes instalar una sola vez el helper mostrado arriba.', 'warning');
      } else if (!groupReady) {
        this.showMessage('Actualiza una vez el helper para habilitar la configuración agrupada de Base de datos y AWS.', 'warning');
      } else {
        this.showMessage('', '');
      }
    } catch (error) {
      this.showMessage(error.message || 'No se pudo cargar la configuración del servidor.', 'danger');
    }
  }

  renderSettings(preferredName = '', preferredGroup = '') {
    this.select.replaceChildren();
    this.tableBody.replaceChildren();

    const groups = new Map();
    this.settings.forEach((row) => {
      const group = String(row.group || 'General');
      let optgroup = groups.get(group);
      if (!optgroup) {
        optgroup = this.document.createElement('optgroup');
        optgroup.label = group;
        groups.set(group, optgroup);
        this.select.appendChild(optgroup);
      }

      const option = this.document.createElement('option');
      option.value = String(row.name || '');
      option.textContent = `${row.name}${row.configured ? ' · configurada' : ' · sin configurar'}`;
      optgroup.appendChild(option);

      const tr = this.document.createElement('tr');
      const nameCell = this.document.createElement('td');
      const code = this.document.createElement('code');
      code.textContent = String(row.name || '');
      nameCell.appendChild(code);
      const groupLine = this.document.createElement('div');
      groupLine.className = 'small text-muted mt-1';
      groupLine.textContent = group;
      nameCell.appendChild(groupLine);

      const valueCell = this.document.createElement('td');
      valueCell.className = 'text-break';
      if (row.secret) valueCell.textContent = row.configured ? '•••••••• · configurada' : '—';
      else valueCell.textContent = String(row.value || '') || '—';

      const sourceCell = this.document.createElement('td');
      sourceCell.textContent = this.sourceLabel(row.source);

      const actionCell = this.document.createElement('td');
      actionCell.className = 'text-right text-nowrap';
      const editButton = this.document.createElement('button');
      editButton.type = 'button';
      editButton.className = 'btn btn-sm btn-outline-info';
      editButton.textContent = row.atomic_group ? 'Configurar' : 'Modificar';
      editButton.addEventListener('click', () => {
        this.select.value = String(row.name || '');
        this.syncSelectedSetting();
      });
      actionCell.appendChild(editButton);

      tr.appendChild(nameCell);
      tr.appendChild(valueCell);
      tr.appendChild(sourceCell);
      tr.appendChild(actionCell);
      this.tableBody.appendChild(tr);
    });

    const count = this.document.getElementById('serverAdminVariableCount');
    if (count) count.textContent = `${this.settings.length} variables disponibles`;

    if (preferredGroup) {
      const row = this.settings.find((item) => String(item.atomic_group || '') === String(preferredGroup));
      if (row) {
        this.select.value = String(row.name || '');
        this.openGroup(preferredGroup);
        return;
      }
    }
    if (preferredName && this.settings.some((row) => String(row.name) === String(preferredName))) this.select.value = preferredName;
    this.syncSelectedSetting();
  }

  syncSelectedSetting() {
    const row = this.settings.find((item) => String(item.name) === String(this.select.value));
    if (!row) return;
    const atomicGroup = String(row.atomic_group || '');
    if (atomicGroup) {
      this.openGroup(atomicGroup);
      return;
    }
    this.openSingle(row);
  }

  openSingle(row) {
    this.activeGroup = '';
    this.singleEditor.classList.remove('d-none');
    this.groupEditor.classList.add('d-none');
    this.saveButton.classList.remove('d-none');
    this.groupSaveButton.classList.add('d-none');
    this.valueInput.type = row.secret ? 'password' : 'text';
    this.valueInput.value = row.secret ? '' : String(row.value || '');
    this.valueInput.placeholder = row.secret && row.configured ? 'Escribe un nuevo valor para reemplazar el actual' : '';
    const help = this.document.getElementById('serverAdminValueHelp');
    if (help) {
      help.textContent = row.secret
        ? `Variable configurada: ${row.configured ? 'sí' : 'no'}.`
        : `Valor actual cargado desde: ${this.sourceLabel(row.source)}.`;
    }
  }

  openGroup(groupKey) {
    const rows = this.settings.filter((item) => String(item.atomic_group || '') === String(groupKey));
    if (!rows.length) return;
    this.activeGroup = String(groupKey);
    this.singleEditor.classList.add('d-none');
    this.groupEditor.classList.remove('d-none');
    this.saveButton.classList.add('d-none');
    this.groupSaveButton.classList.remove('d-none');
    this.groupFields.replaceChildren();
    this.groupTitle.textContent = `Configurar ${String(rows[0].group || groupKey)}`;

    rows.forEach((row) => {
      const wrapper = this.document.createElement('div');
      wrapper.className = 'form-group';
      const label = this.document.createElement('label');
      const code = this.document.createElement('code');
      code.textContent = String(row.name || '');
      label.appendChild(code);
      if (row.required) {
        const required = this.document.createElement('span');
        required.className = 'text-warning ml-1';
        required.textContent = '*';
        label.appendChild(required);
      }

      const input = this.document.createElement('input');
      input.className = 'form-control';
      input.dataset.settingName = String(row.name || '');
      input.type = row.secret ? 'password' : 'text';
      input.autocomplete = 'off';
      input.value = row.secret ? '' : String(row.value || '');
      if (row.secret && row.configured) input.placeholder = 'Configurada; deja vacío para conservarla';
      else if (row.secret && row.required) input.placeholder = 'Obligatoria';
      else if (String(row.name) === 'DB_PORT' && !row.configured) input.placeholder = '3306 (predeterminado)';
      else if (!row.required) input.placeholder = 'Opcional';

      const help = this.document.createElement('small');
      help.className = 'form-text text-muted';
      help.textContent = `${row.configured ? 'Configurada' : 'Sin configurar'} · ${this.sourceLabel(row.source)}${row.secret ? ' · el valor actual no se muestra' : ''}`;
      wrapper.appendChild(label);
      wrapper.appendChild(input);
      wrapper.appendChild(help);
      this.groupFields.appendChild(wrapper);
    });

    const note = this.document.createElement('div');
    note.className = 'alert alert-secondary small';
    note.textContent = groupKey === 'database'
      ? 'La conexión completa se prueba primero. Si no conecta, no se escribe ningún cambio.'
      : 'Las credenciales ya configuradas se conservan si dejas su campo secreto vacío.';
    this.groupFields.appendChild(note);
  }

  sourceLabel(source) {
    const value = String(source || 'unset');
    if (value === 'managed') return 'runtime-env.json';
    if (value === 'process') return 'entorno PHP';
    return 'sin configurar';
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
      const data = await this.post(body);
      this.passwordInput.value = '';
      this.valueInput.value = '';
      await this.load(name);
      this.showMessage(data.message || 'Variable actualizada.', 'success');
    } catch (error) {
      this.showMessage(error.message || 'No se pudo actualizar la variable.', 'danger');
    } finally {
      this.saveButton.disabled = false;
      this.saveButton.innerHTML = '<i class="fas fa-save mr-1"></i>Guardar variable';
    }
  }

  async saveGroup() {
    const group = String(this.activeGroup || '');
    const currentPassword = String(this.passwordInput.value || '');
    if (!group || !this.csrf) return;
    if (!currentPassword) {
      this.showMessage('Confirma tu contraseña actual de superusuario.', 'warning');
      return;
    }

    const values = {};
    this.groupFields.querySelectorAll('[data-setting-name]').forEach((input) => {
      values[String(input.dataset.settingName || '')] = String(input.value || '');
    });

    this.groupSaveButton.disabled = true;
    this.groupSaveButton.textContent = 'Guardando grupo…';
    this.showMessage(`Validando ${group === 'database' ? 'base de datos' : 'AWS'}…`, 'info');
    try {
      const body = new URLSearchParams();
      body.set('action', 'set_group');
      body.set('group', group);
      body.set('values_json', JSON.stringify(values));
      body.set('current_password', currentPassword);
      const data = await this.post(body);
      this.passwordInput.value = '';
      await this.load('', group);
      this.showMessage(data.message || 'Grupo actualizado.', 'success');
    } catch (error) {
      this.showMessage(error.message || 'No se pudo actualizar el grupo.', 'danger');
    } finally {
      this.groupSaveButton.disabled = false;
      this.groupSaveButton.innerHTML = '<i class="fas fa-save mr-1"></i>Guardar grupo';
    }
  }

  async post(body) {
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
    return data;
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
