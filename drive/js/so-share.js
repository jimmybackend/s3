class ArcadeCloudOsShare {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.context = null;
    this.timer = null;
    this.bulkEntries = [];
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
    this.document.addEventListener('click', (event) => {
      const id = event.target?.closest?.('button')?.id;
      if (id === 'osBulkDirectGenerate') { event.preventDefault(); this.generateBulkDirect(); }
      if (id === 'osBulkArcadeLinkChoose') { event.preventDefault(); this.chooseBulkArcadeLink(); }
      if (id === 'osBulkDirectCopy') { event.preventDefault(); this.copyBulkDirect(); }
    });
    return this;
  }

  open(entry) {
    if (!entry || String(entry.dataset.locked || '0') === '1') {
      this.notify('Desbloquea el archivo antes de compartirlo.', 'warning');
      return;
    }

    this.bulkEntries = [];
    this.updateBulkVisibility();
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

  updateBulkVisibility() {
    const multi = this.bulkEntries.length > 1;
    const bulk = this.document.getElementById('osBulkSharePanel');
    const single = this.document.getElementById('osSingleDirectSharePanel');
    if (bulk) bulk.hidden = !multi;
    if (single) single.hidden = multi;
    const count = this.document.getElementById('osBulkShareCount');
    if (count && multi) count.textContent = this.bulkEntries.length + ' archivos seleccionados';
  }

  openMultiple(entries) {
    const files = Array.from(entries || []);
    if (!files.length) return;
    if (files.length === 1) { this.open(files[0]); return; }
    if (files.some(file => file.dataset.locked === '1' || !file.dataset.key)) {
      this.notify('Desbloquea los archivos protegidos antes de compartirlos.', 'warning');
      return;
    }
    this.context = null;
    this.bulkEntries = files.map(file => ({
      key: String(file.dataset.key),
      name: String(file.dataset.name || file.dataset.key),
      ext: String(file.dataset.ext || '').toLowerCase()
    }));
    const module = this.window.ArcadeCloudDrive?.modules?.['arcadelink-share'];
    if (module) {
      module.bulkKeys = this.bulkEntries.map(file => file.key);
      module.context = {key:'',name:''};
      module.ensurePanel?.();
      module.updateContextLabel?.();
      module.updateDownloadButton?.();
    }
    const results = this.document.getElementById('osBulkDirectResults');
    const status = this.document.getElementById('osBulkShareStatus');
    if (results) results.hidden = true;
    if (status) status.textContent = '';
    this.updateBulkVisibility();
    this.showModal();
  }

  chooseBulkArcadeLink() {
    if (!this.bulkEntries.length) return;
    const module = this.window.ArcadeCloudDrive?.modules?.['arcadelink-share'];
    if (!module) { this.notify('ArcadeLink no está disponible.', 'warning'); return; }
    module.bulkKeys = this.bulkEntries.map(file => file.key);
    module.context = {key:'',name:''};
    module.ensurePanel?.();
    module.updateContextLabel?.();
    module.updateDiscoveryHelp?.();
    module.updateDownloadButton?.();
    const target = this.document.getElementById('arcadeLinkSharePanel');
    target?.scrollIntoView?.({block:'nearest'});
    const status = this.document.getElementById('osBulkShareStatus');
    if (status) status.textContent = 'Configura la visibilidad y pulsa Descargar ArcadeLink para generar la colección.';
  }

  async generateBulkDirect() {
    if (this.bulkEntries.length < 2) return;
    const button = this.document.getElementById('osBulkDirectGenerate');
    const output = this.document.getElementById('osBulkDirectText');
    const area = this.document.getElementById('osBulkDirectResults');
    const status = this.document.getElementById('osBulkShareStatus');
    const days = Math.max(1, Math.min(3650, parseInt(this.document.getElementById('osBulkShareDays')?.value || '1', 10) || 1));
    if (button) button.disabled = true;
    const lines = [];
    let failed = 0;
    try {
      for (const file of this.bulkEntries) {
        try {
          const response = await this.window.fetch('generar_token.php', {
            method:'POST', credentials:'same-origin', cache:'no-store',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
              'X-Requested-With':'XMLHttpRequest',
              'X-Drive-CSRF':String(this.window.DRIVE_UPLOAD_CSRF || '')},
            body:new URLSearchParams({archivo:file.key,tipo:this.typeForExtension(file.ext),dias:String(days)}).toString()
          });
          const data = await response.json();
          if (!response.ok || data.estado !== 'ok' || !data.url) throw new Error(data.mensaje || 'No se pudo generar');
          lines.push(file.name + ': ' + data.url);
        } catch (_) {
          failed++;
          lines.push(file.name + ': ERROR al generar enlace');
        }
      }
      if (output) output.value = lines.join('\n');
      if (area) area.hidden = false;
      if (status) status.textContent = 'Generados: ' + (this.bulkEntries.length - failed) + '. Fallidos: ' + failed + '.';
    } finally { if (button) button.disabled = false; }
  }

  async copyBulkDirect() {
    const value = this.document.getElementById('osBulkDirectText')?.value || '';
    if (!value) return;
    try { await this.window.navigator.clipboard.writeText(value); }
    catch (_) {
      const input = this.document.getElementById('osBulkDirectText');
      input?.select();
      this.document.execCommand('copy');
    }
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
          'X-Requested-With': 'XMLHttpRequest',
          'X-Drive-CSRF': String(this.window.DRIVE_UPLOAD_CSRF || '')
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
