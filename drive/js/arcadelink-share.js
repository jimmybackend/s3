class ArcadeLinkShareModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.context = { key: '', name: '' };
    this.bound = false;
  }

  init() {
    if (this.bound) return this;
    this.bound = true;

    this.document.addEventListener('click', (event) => {
      const shareButton = event.target && event.target.closest
        ? event.target.closest('.js-share')
        : null;

      if (shareButton) {
        const row = shareButton.closest('.file-row');
        this.context = {
          key: String(shareButton.dataset.key || '').trim(),
          name: String((row && row.dataset.nombre) || '').trim()
        };
        this.ensurePanel();
        this.updateContextLabel();
        return;
      }

      const downloadButton = event.target && event.target.closest
        ? event.target.closest('#btnArcadeLinkDownload')
        : null;

      if (!downloadButton) return;
      event.preventDefault();
      this.download(downloadButton);
    }, true);

    this.document.addEventListener('shown.bs.modal', (event) => {
      if (!event.target || event.target.id !== 'modalCompartir') return;
      this.ensurePanel();
      this.updateContextLabel();
    }, true);

    if (this.document.readyState === 'loading') {
      this.document.addEventListener('DOMContentLoaded', () => this.ensurePanel(), { once: true });
    } else {
      this.ensurePanel();
    }

    return this;
  }

  ensurePanel() {
    if (this.document.getElementById('arcadeLinkSharePanel')) return;

    const modalBody = this.document.querySelector('#modalCompartir .modal-body');
    if (!modalBody) return;

    const panel = this.document.createElement('div');
    panel.id = 'arcadeLinkSharePanel';
    panel.className = 'mt-4 pt-3 border-top';
    panel.innerHTML = `
      <div class="d-flex align-items-center justify-content-between mb-2">
        <strong><i class="fas fa-network-wired mr-1"></i> ArcadeLink FederationCloud</strong>
        <span class="badge badge-info">portable</span>
      </div>
      <p class="small text-muted mb-3">
        Descarga un pasaporte <code>.arcadelink</code> firmado por este nodo. No contiene credenciales AWS ni una URL permanente de S3.
      </p>
      <div id="arcadeLinkShareFile" class="small text-muted text-break mb-2"></div>
      <div class="form-row">
        <div class="form-group col-md-6 mb-2">
          <label for="arcadeLinkVisibility" class="mb-1">Visibilidad</label>
          <select id="arcadeLinkVisibility" class="form-control form-control-sm">
            <option value="UNLISTED" selected>UNLISTED</option>
            <option value="PRIVATE">PRIVATE</option>
            <option value="PUBLIC">PUBLIC</option>
          </select>
        </div>
        <div class="form-group col-md-6 mb-2">
          <label for="arcadeLinkRights" class="mb-1">Derechos</label>
          <select id="arcadeLinkRights" class="form-control form-control-sm">
            <option value="link_only" selected>link_only</option>
            <option value="unknown_rights">unknown_rights</option>
            <option value="user_owned_authorized">user_owned_authorized</option>
            <option value="copy_allowed">copy_allowed</option>
          </select>
        </div>
      </div>
      <div class="d-flex align-items-center flex-wrap mt-2">
        <button type="button" id="btnArcadeLinkDownload" class="btn btn-info btn-sm mr-2">
          <i class="fas fa-file-download mr-1"></i> Descargar .arcadelink
        </button>
        <span id="arcadeLinkShareStatus" class="small text-muted"></span>
      </div>
      <div class="small text-muted mt-2">
        UNLISTED es el modo recomendado para compartir: no aparece en búsquedas generales, pero puede resolverse con el archivo ArcadeLink.
      </div>`;

    modalBody.appendChild(panel);
  }

  updateContextLabel() {
    const target = this.document.getElementById('arcadeLinkShareFile');
    if (!target) return;
    target.textContent = this.context.name
      ? `Recurso: ${this.context.name}`
      : (this.context.key ? 'Recurso seleccionado listo para ArcadeLink.' : 'Selecciona un archivo desde el botón Compartir.');
  }

  setStatus(message, type = 'muted') {
    const target = this.document.getElementById('arcadeLinkShareStatus');
    if (!target) return;
    target.className = `small text-${type}`;
    target.textContent = message;
  }

  async download(button) {
    const key = String(this.context.key || this.window.__shareContext?.key || '').trim();
    if (!key) {
      this.setStatus('No hay archivo seleccionado.', 'danger');
      return;
    }

    const visibility = String(this.document.getElementById('arcadeLinkVisibility')?.value || 'UNLISTED');
    const rights = String(this.document.getElementById('arcadeLinkRights')?.value || 'link_only');
    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Generando…';
    this.setStatus('Firmando ArcadeLink…', 'muted');

    try {
      const body = new URLSearchParams();
      body.set('storage_ref', key);
      body.set('visibility', visibility);
      body.set('rights', rights);

      const response = await fetch('federationcloud/create.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/vnd.arcadecloud.arcadelink+json, application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });

      if (!response.ok) {
        const text = await response.text();
        let message = `HTTP ${response.status}`;
        try {
          const data = JSON.parse(text);
          if (data && data.error) message = String(data.error);
        } catch (_) {}
        throw new Error(message);
      }

      const blob = await response.blob();
      const contentDisposition = response.headers.get('Content-Disposition') || '';
      const match = contentDisposition.match(/filename="?([^";]+)"?/i);
      const fallbackBase = (this.context.name || 'resource').replace(/[^A-Za-z0-9._-]+/g, '_');
      const filename = match && match[1] ? match[1] : `${fallbackBase}.arcadelink`;
      const url = URL.createObjectURL(blob);
      const anchor = this.document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      anchor.style.display = 'none';
      this.document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      this.window.setTimeout(() => URL.revokeObjectURL(url), 1500);

      this.setStatus(`ArcadeLink generado · ${visibility}`, 'success');
    } catch (error) {
      console.error('[arcadelink-share]', error);
      this.setStatus(error && error.message ? error.message : 'No se pudo generar el ArcadeLink.', 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = oldHtml;
    }
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new ArcadeLinkShareModule(win, doc).init();
    win.ArcadeCloudDrive.modules['arcadelink-share'] = instance;
    return instance;
  }
}

ArcadeLinkShareModule.boot();
