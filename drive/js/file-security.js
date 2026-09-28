class ArcadeCloudFileSecurity {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.resolver = null;
  }

  normalizeKey(key) {
    return String(key || '')
      .trim()
      .replace(/\\/g, '/')
      .replace(/\/+/g, '/')
      .replace(/^\/+/, '');
  }

  parseKey(key) {
    key = this.normalizeKey(key);
    const pos = key.lastIndexOf('/');
    return pos === -1
      ? { ruta: '', enc: key }
      : { ruta: key.substring(0, pos + 1), enc: key.substring(pos + 1) };
  }

  modal() {
    const modal = this.document.getElementById('securityFileModal');
    if (!modal) throw new Error('No existe el panel compartido de seguridad.');
    if (modal.dataset.boundSharedSecurity !== '1') {
      modal.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const toggle = target?.closest('[data-sec-toggle]');
        if (toggle) {
          event.preventDefault();
          const input = this.document.getElementById(String(toggle.dataset.secToggle || ''));
          if (!input) return;
          input.type = input.type === 'password' ? 'text' : 'password';
          toggle.textContent = input.type === 'password' ? 'Ver' : 'Ocultar';
          return;
        }
        if (event.target === modal) this.close(null);
      });

      modal.querySelector('#secShowAllCheckbox')?.addEventListener('change', (event) => {
        const checked = Boolean(event.target?.checked);
        ['secPasswordInput', 'secConfirmInput'].forEach((id) => {
          const input = this.document.getElementById(id);
          if (!input) return;
          input.type = checked ? 'text' : 'password';
          const button = modal.querySelector('[data-sec-toggle="' + id + '"]');
          if (button) button.textContent = checked ? 'Ocultar' : 'Ver';
        });
      });
      modal.dataset.boundSharedSecurity = '1';
    }
    return modal;
  }

  async post(url, data) {
    const body = new FormData();
    Object.entries(data || {}).forEach(([key, value]) => {
      body.append(key, value == null ? '' : String(value));
    });
    const response = await this.window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body
    });
    const text = await response.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    if (!response.ok || !json) {
      throw new Error(json?.msg || json?.error || text || ('HTTP ' + response.status));
    }
    return json;
  }

  open(options) {
    const modal = this.modal();
    const title = modal.querySelector('#secModalTitle');
    const text = modal.querySelector('#secModalText');
    const error = modal.querySelector('#secModalError');
    const passwordField = modal.querySelector('#secPasswordField');
    const confirmField = modal.querySelector('#secConfirmField');
    const hintField = modal.querySelector('#secHintField');
    const hintBox = modal.querySelector('#secHintBox');
    const showAllRow = modal.querySelector('#secShowAllRow');
    const password = modal.querySelector('#secPasswordInput');
    const confirm = modal.querySelector('#secConfirmInput');
    const hint = modal.querySelector('#secHintInput');
    const accept = modal.querySelector('[data-sec-accept]');
    const cancel = modal.querySelector('[data-sec-cancel]');
    const close = modal.querySelector('[data-sec-close]');
    const fileName = modal.querySelector('#secFileName');

    if (!title || !text || !error || !passwordField || !confirmField || !hintField ||
        !hintBox || !showAllRow || !password || !confirm || !hint || !accept ||
        !cancel || !close || !fileName) {
      throw new Error('El panel compartido de seguridad está incompleto.');
    }

    const key = this.normalizeKey(options.key || '');
    title.textContent = options.title || 'Seguridad del archivo';
    text.textContent = options.text || '';
    fileName.textContent = key ? key.split('/').pop() : '—';
    error.textContent = '';
    error.classList.remove('show');
    password.value = '';
    confirm.value = '';
    hint.value = String(options.defaultHint || '');
    password.type = 'password';
    confirm.type = 'password';

    const showAll = modal.querySelector('#secShowAllCheckbox');
    if (showAll) showAll.checked = false;
    modal.querySelectorAll('[data-sec-toggle]').forEach((button) => { button.textContent = 'Ver'; });

    passwordField.style.display = options.askPassword === false ? 'none' : '';
    confirmField.style.display = options.askConfirm ? '' : 'none';
    hintField.style.display = options.askHint ? '' : 'none';
    showAllRow.style.display = options.askPassword === false ? 'none' : '';

    if (options.hintText) {
      hintBox.style.display = '';
      hintBox.textContent = 'Pista guardada: ' + String(options.hintText);
    } else {
      hintBox.style.display = 'none';
      hintBox.textContent = '';
    }

    if (this.resolver) {
      const old = this.resolver;
      this.resolver = null;
      old(null);
    }

    return new Promise((resolve) => {
      this.resolver = resolve;
      let closed = false;

      const cleanup = () => {
        accept.removeEventListener('click', onAccept);
        cancel.removeEventListener('click', onCancel);
        close.removeEventListener('click', onCancel);
        modal.removeEventListener('keydown', onKey);
        if (this.window.jQuery) this.window.jQuery(modal).off('hidden.bs.modal.sharedSecurity');
      };
      const finish = (result) => {
        if (closed) return;
        closed = true;
        cleanup();
        this.close(result);
      };
      const onAccept = () => {
        const result = {
          password: String(password.value || '').trim(),
          confirmPassword: String(confirm.value || '').trim(),
          hint: String(hint.value || '').trim()
        };
        if (options.askPassword !== false && result.password.length < 4) {
          error.textContent = 'La contraseña debe tener al menos 4 caracteres.';
          error.classList.add('show');
          password.focus();
          return;
        }
        if (options.askConfirm && result.password !== result.confirmPassword) {
          error.textContent = 'La confirmación no coincide con la contraseña.';
          error.classList.add('show');
          confirm.focus();
          return;
        }
        finish(result);
      };
      const onCancel = (event) => {
        event?.preventDefault?.();
        finish(null);
      };
      const onKey = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish(null);
        } else if (event.key === 'Enter') {
          event.preventDefault();
          onAccept();
        }
      };

      accept.addEventListener('click', onAccept);
      cancel.addEventListener('click', onCancel);
      close.addEventListener('click', onCancel);
      modal.addEventListener('keydown', onKey);

      if (this.window.jQuery && typeof this.window.jQuery.fn?.modal === 'function') {
        this.window.jQuery(modal)
          .off('hidden.bs.modal.sharedSecurity')
          .one('hidden.bs.modal.sharedSecurity', () => {
            if (!closed) {
              closed = true;
              cleanup();
              const resolver = this.resolver;
              this.resolver = null;
              resolver?.(null);
            }
          })
          .modal({ backdrop: true, keyboard: true, show: true });
      } else {
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
      }

      this.window.setTimeout(() => {
        try { (options.askPassword === false ? accept : password).focus(); } catch (_) {}
      }, 40);
    });
  }

  close(result) {
    const modal = this.document.getElementById('securityFileModal');
    const finish = () => {
      const resolver = this.resolver;
      this.resolver = null;
      resolver?.(result);
    };
    if (!modal) return finish();

    ['secPasswordInput','secConfirmInput','secHintInput'].forEach((id) => {
      const input = this.document.getElementById(id);
      if (input) input.value = '';
    });
    const error = modal.querySelector('#secModalError');
    if (error) {
      error.textContent = '';
      error.classList.remove('show');
    }

    if (this.window.jQuery && typeof this.window.jQuery.fn?.modal === 'function') {
      this.window.jQuery(modal)
        .off('hidden.bs.modal.sharedSecurityClose')
        .one('hidden.bs.modal.sharedSecurityClose', finish)
        .modal('hide');
      return;
    }

    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    finish();
  }

  async protect(key) {
    key = this.normalizeKey(key);
    const data = await this.open({
      title: 'Proteger con contraseña',
      text: 'Escribe una contraseña para proteger este archivo.',
      key,
      askPassword: true,
      askConfirm: true,
      askHint: true
    });
    if (!data) return false;
    const response = await this.post('set_file_security.php', {
      mode: 'secure',
      key,
      password: data.password,
      secure_hint: data.hint
    });
    if (!response.ok || !Number(response.ok_count || 0)) {
      throw new Error(response.msg || 'No se pudo proteger el archivo.');
    }
    return true;
  }

  async unlock(key, hintText = '') {
    key = this.normalizeKey(key);
    const data = await this.open({
      title: 'Desbloquear archivo',
      text: 'Escribe la contraseña para desbloquear temporalmente este archivo.',
      key,
      askPassword: true,
      askConfirm: false,
      askHint: false,
      hintText
    });
    if (!data) return false;
    const parts = this.parseKey(key);
    const response = await this.post('unlock_file.php', {
      key,
      ruta: parts.ruta,
      encriptado: parts.enc,
      password: data.password
    });
    if (!response.ok) throw new Error(response.msg || 'No se pudo desbloquear el archivo.');
    return true;
  }

  async relock(key) {
    key = this.normalizeKey(key);
    const response = await this.post('relock_file.php', { key });
    if (!response.ok) throw new Error(response.msg || 'No se pudo bloquear de nuevo.');
    return true;
  }

  async unsecure(key) {
    key = this.normalizeKey(key);
    if (!this.window.confirm('¿Quitar la protección con contraseña de este archivo?')) return false;
    const response = await this.post('set_file_security.php', { mode: 'normal', key });
    if (!response.ok || !Number(response.ok_count || 0)) {
      throw new Error(response.msg || 'No se pudo quitar la seguridad.');
    }
    return true;
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudFileSecurity instanceof ArcadeCloudFileSecurity) return win.ArcadeCloudFileSecurity;
    const instance = new ArcadeCloudFileSecurity(win, doc);
    win.ArcadeCloudFileSecurity = instance;
    return instance;
  }
}

ArcadeCloudFileSecurity.boot();
