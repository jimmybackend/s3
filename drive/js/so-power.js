class ArcadeCloudFastDrivePower {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.config = win.ARCADECLOUD_FASTDRIVE_POWER || {};
    this.button = doc.querySelector('[data-fastdrive-force-stop]');
    this.modal = doc.getElementById('fastDrivePowerModal');
    this.password = doc.getElementById('fastDrivePowerPassword');
    this.accept = doc.querySelector('[data-fastdrive-power-accept]');
    this.message = doc.getElementById('fastDrivePowerMessage');
    this.busy = false;
  }

  init() {
    if (!this.button || !this.modal || !this.accept) return this;
    this.button.addEventListener('click', () => this.open());
    this.accept.addEventListener('click', () => this.submit());
    this.password?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        this.submit();
      }
    });
    return this;
  }

  open() {
    if (this.message) {
      this.message.textContent = '';
      this.message.className = 'os-power-message';
    }
    if (this.password) this.password.value = '';
    if (this.window.jQuery?.fn?.modal) {
      this.window.jQuery(this.modal).modal('show');
      this.window.setTimeout(() => this.password?.focus(), 180);
    } else {
      this.modal.hidden = false;
      this.password?.focus();
    }
  }

  async submit() {
    if (this.busy) return;
    const password = String(this.password?.value || '');
    if (!password) {
      this.show('Escribe tu contraseña actual.', 'warning');
      this.password?.focus();
      return;
    }

    this.busy = true;
    this.accept.disabled = true;
    const original = this.accept.innerHTML;
    this.accept.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>Apagando…';

    try {
      const body = new URLSearchParams({
        action: 'force-stop',
        csrf: String(this.config.csrf || ''),
        current_password: password
      });
      const response = await this.window.fetch(
        String(this.config.endpoint || 'fastdrive-power.php'),
        {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        }
      );
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.error || 'No se pudo apagar FastDrive.');
      }

      this.show(data?.message || 'Apagado solicitado.', 'success');
      if (this.password) this.password.value = '';
      this.window.setTimeout(() => {
        if (this.window.jQuery?.fn?.modal) {
          this.window.jQuery(this.modal).modal('hide');
        }
      }, 1100);
    } catch (error) {
      this.show(error?.message || 'No se pudo apagar FastDrive.', 'danger');
    } finally {
      this.busy = false;
      this.accept.disabled = false;
      this.accept.innerHTML = original;
    }
  }

  show(message, type) {
    if (this.message) {
      this.message.textContent = String(message || '');
      this.message.className = 'os-power-message is-' + String(type || 'info');
    }
    if (this.window.ArcadeCloudOsShell?.notify && type === 'success') {
      this.window.ArcadeCloudOsShell.notify(message, 'success');
    }
  }
}

window.ArcadeCloudFastDrivePower = new ArcadeCloudFastDrivePower(window, document).init();
