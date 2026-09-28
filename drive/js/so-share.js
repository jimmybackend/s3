class ArcadeCloudOsShare {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.context = null;
    this.timer = null;
  }

  init() {
    this.document.addEventListener('input', (event) => {
      if (!(event.target instanceof Element) || event.target.id !== 'diasCompartir') return;
      this.updateExpiry();
      this.scheduleRegenerate();
    });

    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      if (target.closest('#btnCopyLink')) {
        event.preventDefault();
        this.copyLink();
      }
    });
    return this;
  }

  open(entry) {
    if (!entry || String(entry.dataset.locked || '0') === '1') {
      this.notify('Desbloquea el archivo antes de compartirlo.', 'warning');
      return;
    }

    const key = String(entry.dataset.key || '').trim();
    if (!key) return;

    this.context = {
      key,
      ext: String(entry.dataset.ext || '').toLowerCase(),
      name: String(entry.dataset.name || key).trim()
    };
    this.window.__shareContext = {
      key,
      tipo: this.typeForExtension(this.context.ext)
    };

    const module = this.window.ArcadeCloudDrive?.modules?.['arcadelink-share'];
    if (module?.prepareSingle) module.prepareSingle(key, this.context.name);

    const days = this.document.getElementById('diasCompartir');
    const link = this.document.getElementById('enlaceCompartido');
    const status = this.document.getElementById('sharePanelStatus');
    if (days && (!days.value || Number(days.value) < 1)) days.value = '1';
    if (link) link.value = '';
    if (status) {
      status.textContent = 'Generando enlace…';
      status.className = 'small text-info';
    }
    this.updateExpiry();
    this.showModal();
    this.generate();
  }

  showModal() {
    const modal = this.document.getElementById('modalCompartir');
    const jq = this.window.jQuery || this.window.$;
    if (modal && jq && typeof jq(modal).modal === 'function') {
      jq(modal).modal('show');
    }
  }

  scheduleRegenerate() {
    if (!this.context) return;
    if (this.timer) this.window.clearTimeout(this.timer);
    this.timer = this.window.setTimeout(() => this.generate(), 450);
  }

  async generate() {
    if (!this.context) return;
    const daysInput = this.document.getElementById('diasCompartir');
    const days = Math.max(1, Math.min(3650, parseInt(String(daysInput?.value || '1'), 10) || 1));
    if (daysInput) daysInput.value = String(days);

    const status = this.document.getElementById('sharePanelStatus');
    try {
      const response = await this.window.fetch('generar_token.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          archivo: this.context.key,
          tipo: this.typeForExtension(this.context.ext),
          dias: String(days)
        }).toString()
      });
      const text = await response.text();
      let data = null;
      try { data = JSON.parse(text); } catch (_) {}
      if (!response.ok || !data || data.estado !== 'ok' || !data.url) {
        throw new Error(data?.mensaje || text || 'No se pudo generar el enlace.');
      }

      const input = this.document.getElementById('enlaceCompartido');
      if (input) input.value = String(data.url);
      if (status) {
        status.textContent = 'Enlace listo.';
        status.className = 'small text-success';
      }
    } catch (error) {
      if (status) {
        status.textContent = error?.message || 'No se pudo generar el enlace.';
        status.className = 'small text-danger';
      }
    }
  }

  async copyLink() {
    const input = this.document.getElementById('enlaceCompartido');
    const value = String(input?.value || '');
    if (!value) return;
    try {
      await this.window.navigator.clipboard.writeText(value);
      this.setCopyStatus('Copiado');
    } catch (_) {
      if (input) {
        input.focus();
        input.select();
        this.document.execCommand('copy');
        this.setCopyStatus('Copiado');
      }
    }
  }

  setCopyStatus(message) {
    const node = this.document.getElementById('copyStatus');
    if (!node) return;
    node.textContent = message;
    node.style.opacity = '1';
    this.window.setTimeout(() => { node.style.opacity = '0'; }, 1800);
  }

  updateExpiry() {
    const input = this.document.getElementById('diasCompartir');
    const label = this.document.getElementById('fechaExpiraLabel');
    if (!input || !label) return;
    const days = Math.max(1, parseInt(String(input.value || '1'), 10) || 1);
    const date = new Date();
    date.setDate(date.getDate() + days);
    label.textContent = date.toLocaleDateString('es-MX', {
      year: 'numeric', month: 'short', day: '2-digit'
    });
  }

  typeForExtension(ext) {
    if (['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'].includes(ext)) return 'imagen';
    if (['mp4','webm','mov','avi','mkv'].includes(ext)) return 'video';
    if (['mp3','wav','ogg','opus','m4a','flac','amr'].includes(ext)) return 'audio';
    return 'archivo';
  }

  notify(message, type) {
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
    }
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudOsShare instanceof ArcadeCloudOsShare) return win.ArcadeCloudOsShare;
    const instance = new ArcadeCloudOsShare(win, doc).init();
    win.ArcadeCloudOsShare = instance;
    return instance;
  }
}

ArcadeCloudOsShare.boot();
