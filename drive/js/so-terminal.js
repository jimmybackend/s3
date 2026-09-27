class ArcadeCloudOsTerminal {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.config = win.ARCADECLOUD_OS_SERVER_CONSOLE || null;
    this.windowEl = doc.getElementById('terminalWindow');
    this.commandsEl = doc.getElementById('osTerminalCommands');
    this.outputEl = doc.getElementById('osTerminalOutput');
    this.inputEl = doc.getElementById('osTerminalInput');
    this.runEl = doc.getElementById('osTerminalRun');
    this.statusEl = doc.getElementById('osTerminalStatus');
    this.privateBox = doc.getElementById('osTerminalPrivateConfirm');
    this.privatePassword = doc.getElementById('osTerminalPrivatePassword');
    this.privateAccept = doc.getElementById('osTerminalPrivateAccept');
    this.privateCancel = doc.getElementById('osTerminalPrivateCancel');
    this.pendingPrivateCommand = '';
    this.loaded = false;
    this.loading = false;
  }

  init() {
    if (!this.config || !this.windowEl) return this;

    this.document.querySelectorAll('[data-open-terminal]').forEach((button) => {
      button.addEventListener('click', () => this.ensureState());
    });

    this.commandsEl?.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-terminal-command]');
      if (!button) return;
      event.preventDefault();
      this.runCommand(String(button.dataset.terminalCommand || ''));
    });

    this.runEl?.addEventListener('click', () => {
      this.runCommand(this.inputEl ? this.inputEl.value : '');
    });

    this.inputEl?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      this.runCommand(this.inputEl.value);
    });

    this.privateCancel?.addEventListener('click', () => this.hidePrivateConfirm());
    this.privateAccept?.addEventListener('click', () => {
      const password = this.privatePassword ? this.privatePassword.value : '';
      const command = this.pendingPrivateCommand;
      if (!command) return;
      this.hidePrivateConfirm(false);
      this.execute(command, password);
    });

    this.privatePassword?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      this.privateAccept?.click();
    });

    return this;
  }

  async ensureState() {
    if (this.loaded || this.loading) {
      this.inputEl?.focus();
      return;
    }

    this.loading = true;
    this.setStatus('Consultando terminal restringida de este nodo…');

    try {
      const response = await fetch(this.config.endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'No se pudo consultar la terminal.');
      }

      this.renderCommands(Array.isArray(data.commands) ? data.commands : []);

      if (data.helper_console_ready) {
        this.setStatus('Helper privilegiado listo · comandos restringidos activos.', 'ready');
      } else {
        const install = String(data.install_command || '').trim();
        this.setStatus(
          install
            ? 'El helper necesita actualizarse una vez desde SSH: ' + install
            : 'El helper privilegiado no está listo en este nodo.',
          'warning'
        );
      }

      this.loaded = true;
      this.inputEl?.focus();
    } catch (error) {
      this.setStatus(
        error && error.message ? error.message : 'No se pudo consultar la terminal.',
        'error'
      );
    } finally {
      this.loading = false;
    }
  }

  renderCommands(commands) {
    if (!this.commandsEl) return;

    this.commandsEl.innerHTML = '';

    const groups = new Map();
    commands.forEach((row) => {
      const command = String(row.command || '').trim();
      if (!command) return;

      const group = this.groupFor(command);
      if (!groups.has(group)) groups.set(group, []);
      groups.get(group).push({
        command,
        label: String(row.label || command),
        requiresPassword: row.requires_password === true
      });
    });

    groups.forEach((rows, groupName) => {
      const group = this.document.createElement('section');
      group.className = 'os-terminal-command-group';

      const title = this.document.createElement('div');
      title.className = 'os-terminal-command-group-title';
      title.textContent = groupName;
      group.appendChild(title);

      const buttons = this.document.createElement('div');
      buttons.className = 'os-terminal-command-buttons';

      rows.forEach((row) => {
        const button = this.document.createElement('button');
        button.type = 'button';
        button.dataset.terminalCommand = row.command;
        button.title = row.label + (row.requiresPassword ? ' · requiere contraseña privada' : '');

        const command = this.document.createElement('code');
        command.textContent = row.command;
        button.appendChild(command);

        if (row.requiresPassword) {
          const lock = this.document.createElement('i');
          lock.className = 'fas fa-lock';
          lock.setAttribute('aria-hidden', 'true');
          button.appendChild(lock);
        }

        buttons.appendChild(button);
      });

      group.appendChild(buttons);
      this.commandsEl.appendChild(group);
    });

    if (!this.commandsEl.children.length) {
      this.commandsEl.textContent = 'Este nodo no devolvió comandos disponibles.';
    }
  }

  groupFor(command) {
    if (['help', 'clear', 'pwd', 'ls -lah'].includes(command)) return 'Terminal';
    if (command.startsWith('git ') || command === 'du -sh .') return 'Repositorio local';
    if (command.startsWith('logs ')) return 'Logs locales';
    if (
      command.startsWith('arcadecloud ') ||
      command === 'systemctl status arcadecloud-media-worker' ||
      command === 'media tools'
    ) return 'ArcadeCloud y tareas';
    if (command === 'memory-clear') return 'Mantenimiento';
    return 'Servidor';
  }

  async runCommand(command) {
    command = String(command || '').trim();
    if (!command) return;

    await this.ensureState();

    if (command === 'clear') {
      if (this.outputEl) this.outputEl.textContent = '';
      if (this.inputEl) {
        this.inputEl.value = '';
        this.inputEl.focus();
      }
      return;
    }

    if (command === 'memory-clear') {
      this.showPrivateConfirm(command);
      return;
    }

    await this.execute(command, '');
  }

  async execute(command, password) {
    command = String(command || '').trim();
    if (!command) return;

    this.append('$ ' + command);
    this.setBusy(true);

    try {
      const body = new FormData();
      body.append('csrf', String(this.config.csrf || ''));
      body.append('command', command);
      if (password) body.append('access_password', password);

      const response = await fetch(this.config.endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await response.json();

      if (!response.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + response.status));
      }

      if (data.client_action === 'clear') {
        if (this.outputEl) this.outputEl.textContent = '';
      } else {
        this.append(String(data.output || '(sin salida)'));
      }
    } catch (error) {
      this.append('ERROR: ' + (error && error.message ? error.message : String(error)));
    } finally {
      this.setBusy(false);
      if (this.inputEl) {
        this.inputEl.value = '';
        this.inputEl.focus();
      }
    }
  }

  append(text) {
    if (!this.outputEl) return;
    const current = this.outputEl.textContent || '';
    this.outputEl.textContent = current + (current ? '\n' : '') + String(text || '');
    this.outputEl.scrollTop = this.outputEl.scrollHeight;
  }

  setBusy(busy) {
    if (this.runEl) this.runEl.disabled = busy;
    if (this.inputEl) this.inputEl.disabled = busy;
    this.commandsEl?.querySelectorAll('button[data-terminal-command]').forEach((button) => {
      button.disabled = busy;
    });
  }

  setStatus(message, state = '') {
    if (!this.statusEl) return;
    this.statusEl.className = 'os-terminal-status' + (state ? ' is-' + state : '');
    this.statusEl.textContent = String(message || '');
  }

  showPrivateConfirm(command) {
    if (!this.privateBox) return;

    this.pendingPrivateCommand = command;
    this.privateBox.hidden = false;
    if (this.privatePassword) {
      this.privatePassword.value = '';
      this.privatePassword.focus();
    }
  }

  hidePrivateConfirm(clearPending = true) {
    if (this.privateBox) this.privateBox.hidden = true;
    if (this.privatePassword) this.privatePassword.value = '';
    if (clearPending) this.pendingPrivateCommand = '';
  }
}

window.ArcadeCloudOsTerminal = new ArcadeCloudOsTerminal(window, document).init();
