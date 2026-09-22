class ArcadeCloudSetup {
  constructor(doc = document) {
    this.doc = doc;
    this.csrf = '';
    this.settings = [];
    this.groups = {
      database: ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'],
      aws: ['AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY']
    };
    this.defaults = {
      DB_PORT: '3306', AWS_REGION: 'us-east-1'
    };
  }

  async init() {
    this.doc.getElementById('btnSetupLogin').addEventListener('click', () => this.login());
    this.doc.getElementById('btnCreateSuperadmin').addEventListener('click', () => this.createSuperadmin());
    this.doc.getElementById('btnSetupLogout').addEventListener('click', () => this.logout());
    this.doc.querySelectorAll('[data-save-group]').forEach((button) => {
      button.addEventListener('click', () => this.saveGroup(String(button.dataset.saveGroup || '')));
    });
    await this.load();
  }

  async load() {
    try {
      const response = await fetch('api.php', {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      this.csrf = String(data.csrf || '');
      const setup = data.setup || {};
      this.toggle('loginCard', false);
      this.toggle('setupPanel', false);
      this.toggle('completedCard', false);

      if (setup.locked) {
        this.message('Setup completado. El supervisor temporal ya fue retirado.', 'ok');
        this.toggle('completedCard', true);
        return;
      }
      if (!setup.available) {
        this.message('Setup no inicializado. Ejecuta el instalador del helper con --bootstrap-setup desde el servidor.', 'warn');
        return;
      }
      if (!setup.token_validated) {
        const invalid = new URLSearchParams(window.location.search).get('activation') === 'invalid';
        this.message(invalid ? 'Token de activación inválido. Usa el token generado por el instalador.' : 'Activa esta sesión abriendo /setup/?token=TOKEN_GENERADO_POR_EL_SERVIDOR.', invalid ? 'error' : 'warn');
        return;
      }
      if (!setup.authenticated) {
        this.message('Token de instalación validado. Entra con el supervisor temporal.', 'ok');
        this.toggle('loginCard', true);
        return;
      }

      this.settings = Array.isArray(data.settings) ? data.settings : [];
      this.renderAllGroups();
      this.toggle('setupPanel', true);
      const ready = Boolean(data.basic_ready);
      this.message(
        `Instalación básica: MySQL ${data.database_ready ? '✓' : 'pendiente'} · AWS/S3 ${data.aws_ready ? '✓' : 'pendiente'}.`,
        ready ? 'ok' : 'warn'
      );
    } catch (error) {
      this.message(error.message || 'No se pudo consultar el setup.', 'error');
    }
  }

  renderAllGroups() {
    Object.keys(this.groups).forEach((group) => this.renderGroup(group));
  }

  renderGroup(group) {
    const container = this.doc.getElementById(`${group}Fields`);
    if (!container) return;
    container.replaceChildren();
    this.groups[group].forEach((name) => {
      const row = this.settings.find((item) => String(item.name) === name) || {name, configured: false, secret: false, value: '', source: 'unset', required: false};
      const wrapper = this.doc.createElement('div');
      wrapper.className = 'field';
      const label = this.doc.createElement('label');
      label.textContent = `${name}${row.required ? ' *' : ''}`;
      const input = this.doc.createElement('input');
      input.dataset.settingName = name;
      input.type = row.secret ? 'password' : 'text';
      input.autocomplete = 'off';
      input.value = row.secret ? '' : (String(row.value || '') || String(this.defaults[name] || ''));
      if (row.secret && row.configured) input.placeholder = 'Configurada; deja vacío para conservarla';
      else if (row.secret) input.placeholder = row.required ? 'Obligatoria' : 'Opcional';
      else if (!input.value && !row.required) input.placeholder = 'Opcional';
      const help = this.doc.createElement('span');
      help.className = 'secret-note';
      const source = row.source === 'managed' ? 'runtime-env.json' : (row.source === 'process' ? 'entorno PHP' : 'sin configurar');
      help.textContent = `${row.configured ? 'Configurada' : 'Sin configurar'} · ${source}${row.secret ? ' · valor oculto' : ''}`;
      wrapper.append(label, input, help);
      container.appendChild(wrapper);
    });
  }

  async login() {
    const username = String(this.doc.getElementById('setupUsername').value || '');
    const password = String(this.doc.getElementById('setupPassword').value || '');
    try {
      const body = new URLSearchParams({action: 'login', username, password, csrf: this.csrf});
      const data = await this.post(body, false);
      this.csrf = String(data.csrf || '');
      this.doc.getElementById('setupPassword').value = '';
      await this.load();
    } catch (error) {
      this.message(error.message || 'No se pudo iniciar setup.', 'error');
    }
  }

  async saveGroup(group) {
    const container = this.doc.getElementById(`${group}Fields`);
    if (!container) return;
    const values = {};
    container.querySelectorAll('[data-setting-name]').forEach((input) => {
      values[String(input.dataset.settingName || '')] = String(input.value || '');
    });
    this.message(`Validando ${group}…`, 'warn');
    try {
      const body = new URLSearchParams({action: 'save_group', group, values_json: JSON.stringify(values)});
      const data = await this.post(body, true);
      this.message(data.message || 'Configuración guardada.', 'ok');
      await this.load();
    } catch (error) {
      this.message(error.message || 'No se pudo guardar la configuración.', 'error');
    }
  }

  async createSuperadmin() {
    const values = {
      firstname: String(this.doc.getElementById('adminFirstname').value || ''),
      lastname: String(this.doc.getElementById('adminLastname').value || ''),
      curp: String(this.doc.getElementById('adminCurp').value || ''),
      gender: String(this.doc.getElementById('adminGender').value || ''),
      birthdate: String(this.doc.getElementById('adminBirthdate').value || ''),
      email: String(this.doc.getElementById('adminEmail').value || ''),
      password: String(this.doc.getElementById('adminPassword').value || ''),
      address: String(this.doc.getElementById('adminAddress').value || ''),
      neighborhood: String(this.doc.getElementById('adminNeighborhood').value || ''),
      postalcode: String(this.doc.getElementById('adminPostalcode').value || ''),
      state: String(this.doc.getElementById('adminState').value || ''),
      country: String(this.doc.getElementById('adminCountry').value || ''),
      homephone: String(this.doc.getElementById('adminHomephone').value || ''),
      mobilephone: String(this.doc.getElementById('adminMobilephone').value || '')
    };
    this.message('Validando los tres pasos y creando el superadmin…', 'warn');
    try {
      const body = new URLSearchParams({action: 'create_superadmin', ...values});
      const data = await this.post(body, true);
      this.message(data.message || 'Instalación completada.', 'ok');
      this.toggle('setupPanel', false);
      this.toggle('completedCard', true);
    } catch (error) {
      this.message(error.message || 'No se pudo crear el superadmin.', 'error');
    }
  }

  async logout() {
    try {
      await this.post(new URLSearchParams({action: 'logout'}), true);
    } finally {
      window.location.reload();
    }
  }

  async post(body, authenticated) {
    const headers = {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'};
    if (authenticated) headers['X-ArcadeCloud-Setup-CSRF'] = this.csrf;
    const response = await fetch('api.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers, body: body.toString()});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  }

  toggle(id, visible) {
    const node = this.doc.getElementById(id);
    if (node) node.classList.toggle('hidden', !visible);
  }

  message(text, type) {
    const node = this.doc.getElementById('globalStatus');
    if (!node) return;
    node.textContent = text;
    node.className = `status ${type || ''}`;
  }
}

window.addEventListener('DOMContentLoaded', () => new ArcadeCloudSetup().init());
