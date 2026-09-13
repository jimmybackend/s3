class ArcadeLinkShareModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.context = { key: '', name: '' };
    this.bulkKeys = [];
    this.bound = false;
  }

  init() {
    if (this.bound) return this;
    this.bound = true;

    this.document.addEventListener('click', (event) => {
      const bulkButton = event.target && event.target.closest
        ? event.target.closest('[data-file-bulk-action="share-arcadelink"]')
        : null;

      if (bulkButton) {
        event.preventDefault();
        event.stopImmediatePropagation();
        this.prepareBulkShare();
        return;
      }

      const shareButton = event.target && event.target.closest
        ? event.target.closest('.js-share')
        : null;

      if (shareButton) {
        const row = shareButton.closest('.file-row');
        this.bulkKeys = [];
        this.context = {
          key: String(shareButton.dataset.key || '').trim(),
          name: String((row && row.dataset.nombre) || '').trim()
        };
        this.ensurePanel();
        this.updateContextLabel();
        this.updateDownloadButton();
        return;
      }

      const downloadButton = event.target && event.target.closest
        ? event.target.closest('#btnArcadeLinkDownload')
        : null;

      if (!downloadButton) return;
      event.preventDefault();
      this.download(downloadButton);
    }, true);

    this.document.addEventListener('change', (event) => {
      const target = event.target;
      if (!target || target.id !== 'arcadeLinkDiscoveryPolicy') return;
      this.updateDiscoveryHelp();
    }, true);

    this.document.addEventListener('shown.bs.modal', (event) => {
      if (!event.target || event.target.id !== 'modalCompartir') return;
      this.ensurePanel();
      this.updateContextLabel();
      this.updateDiscoveryHelp();
      this.updateDownloadButton();
    }, true);

    this.document.addEventListener('bloque-archivos:actualizado', () => {
      this.ensureBulkButton();
    });

    if (this.document.readyState === 'loading') {
      this.document.addEventListener('DOMContentLoaded', () => {
        this.ensurePanel();
        this.ensureBulkButton();
      }, { once: true });
    } else {
      this.ensurePanel();
      this.ensureBulkButton();
    }

    return this;
  }

  ensureBulkButton() {
    const actions = this.document.querySelector('#archivosWrap .bulk-actions-inner');
    if (!actions || actions.querySelector('[data-file-bulk-action="share-arcadelink"]')) return;

    const button = this.document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-info';
    button.dataset.fileBulkAction = 'share-arcadelink';
    button.title = 'Compartir seleccionados con ArcadeLink FederationCloud';
    button.innerHTML = '<i class="fas fa-share-alt mr-1"></i> Compartir ArcadeLink';

    const gallery = actions.querySelector('#btnVerGaleria');
    if (gallery) actions.insertBefore(button, gallery);
    else actions.appendChild(button);
  }

  selectedKeys() {
    const wrap = this.document.getElementById('archivosWrap') || this.document;
    return Array.from(wrap.querySelectorAll('input[name="archivos[]"]:checked'))
      .map((checkbox) => String(checkbox.value || '').trim())
      .filter(Boolean);
  }

  prepareBulkShare() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo para compartir con ArcadeLink.');
      return;
    }

    this.bulkKeys = selected;
    this.context = { key: '', name: '' };
    this.ensurePanel();
    this.updateContextLabel();
    this.updateDiscoveryHelp();
    this.updateDownloadButton();

    const modal = this.document.getElementById('modalCompartir');
    const jq = this.window.jQuery || this.window.$;
    if (modal && jq && typeof jq(modal).modal === 'function') {
      jq(modal).modal('show');
      return;
    }

    this.window.alert('No se pudo abrir el panel de políticas ArcadeLink.');
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
        <a class="badge badge-info" href="federationcloud/portal.php">portal global</a>
      </div>
      <p class="small text-muted mb-2">
        Descarga un ZIP portable. Incluye el pasaporte firmado <code>.arcadelink</code> y el archivo <code>abrir-federtioncloud.html</code> compatible con Windows, Linux y macOS.
      </p>
      <p class="small text-muted mb-3">
        El paquete no contiene credenciales AWS ni una URL permanente de S3. El receptor abre FederationCloud y deposita ahí el archivo <code>.arcadelink</code>.
      </p>
      <div id="arcadeLinkShareFile" class="small text-muted text-break mb-2"></div>
      <div class="form-row">
        <div class="form-group col-md-4 mb-2">
          <label for="arcadeLinkVisibility" class="mb-1">Visibilidad</label>
          <select id="arcadeLinkVisibility" class="form-control form-control-sm">
            <option value="UNLISTED" selected>UNLISTED</option>
            <option value="PRIVATE">PRIVATE</option>
            <option value="PUBLIC">PUBLIC</option>
          </select>
        </div>
        <div class="form-group col-md-4 mb-2">
          <label for="arcadeLinkDiscoveryPolicy" class="mb-1">Descubrimiento</label>
          <select id="arcadeLinkDiscoveryPolicy" class="form-control form-control-sm">
            <option value="" selected>Automático</option>
            <option value="local_only">local_only</option>
            <option value="requestable_metadata">requestable_metadata</option>
            <option value="public_metadata">public_metadata</option>
          </select>
        </div>
        <div class="form-group col-md-4 mb-2">
          <label for="arcadeLinkRights" class="mb-1">Derechos</label>
          <select id="arcadeLinkRights" class="form-control form-control-sm">
            <option value="link_only" selected>link_only</option>
            <option value="unknown_rights">unknown_rights</option>
            <option value="user_owned_authorized">user_owned_authorized</option>
            <option value="copy_allowed">copy_allowed</option>
          </select>
        </div>
      </div>
      <div id="arcadeLinkDiscoveryHelp" class="small text-muted mb-2"></div>
      <div class="d-flex align-items-center flex-wrap mt-2">
        <button type="button" id="btnArcadeLinkDownload" class="btn btn-info btn-sm mr-2">
          <i class="fas fa-file-archive mr-1"></i> Descargar ArcadeLink ZIP
        </button>
        <span id="arcadeLinkShareStatus" class="small text-muted"></span>
      </div>
      <div class="small text-muted mt-2">
        <strong>local_only</strong> no publica metadata; <strong>requestable_metadata</strong> permite encontrar el recurso y solicitar acceso; <strong>public_metadata</strong> publica metadata de un recurso PUBLIC.
      </div>`;

    modalBody.appendChild(panel);
    this.updateDiscoveryHelp();
  }

  updateContextLabel() {
    const target = this.document.getElementById('arcadeLinkShareFile');
    if (!target) return;

    if (this.bulkKeys.length) {
      target.textContent = `${this.bulkKeys.length} archivo${this.bulkKeys.length === 1 ? '' : 's'} seleccionado${this.bulkKeys.length === 1 ? '' : 's'} para un solo ZIP portable.`;
      return;
    }

    target.textContent = this.context.name
      ? `Recurso: ${this.context.name}`
      : (this.context.key ? 'Recurso seleccionado listo para ArcadeLink.' : 'Selecciona un archivo desde el botón Compartir.');
  }

  updateDownloadButton() {
    const button = this.document.getElementById('btnArcadeLinkDownload');
    if (!button) return;

    if (this.bulkKeys.length) {
      const count = this.bulkKeys.length;
      button.innerHTML = `<i class="fas fa-file-archive mr-1"></i> Descargar ${count} ArcadeLink${count === 1 ? '' : 's'} en ZIP`;
      return;
    }

    button.innerHTML = '<i class="fas fa-file-archive mr-1"></i> Descargar ArcadeLink ZIP';
  }

  updateDiscoveryHelp() {
    const target = this.document.getElementById('arcadeLinkDiscoveryHelp');
    const select = this.document.getElementById('arcadeLinkDiscoveryPolicy');
    if (!target || !select) return;
    const policy = String(select.value || '');
    const labels = {
      '': 'Automático: PUBLIC → public_metadata; PRIVATE/UNLISTED → local_only.',
      local_only: 'Sólo existe localmente en este nodo y no entra al índice global.',
      requestable_metadata: 'La metadata entra al catálogo global; el archivo sigue protegido y el acceso requiere aprobación del propietario.',
      public_metadata: 'La metadata es global. Sólo es válido cuando Visibilidad = PUBLIC.'
    };
    target.textContent = labels[policy] || '';
  }

  setStatus(message, type = 'muted') {
    const target = this.document.getElementById('arcadeLinkShareStatus');
    if (!target) return;
    target.className = `small text-${type}`;
    target.textContent = message;
  }

  download(button) {
    const visibility = String(this.document.getElementById('arcadeLinkVisibility')?.value || 'UNLISTED');
    const rights = String(this.document.getElementById('arcadeLinkRights')?.value || 'link_only');
    const discoveryPolicy = String(this.document.getElementById('arcadeLinkDiscoveryPolicy')?.value || '');

    if (discoveryPolicy === 'public_metadata' && visibility !== 'PUBLIC') {
      this.setStatus('public_metadata requiere Visibilidad = PUBLIC.', 'danger');
      return;
    }

    if (this.bulkKeys.length) {
      this.downloadBulk(button, visibility, rights, discoveryPolicy);
      return;
    }

    this.downloadSingle(button, visibility, rights, discoveryPolicy);
  }

  downloadBulk(button, visibility, rights, discoveryPolicy) {
    const selected = this.bulkKeys.slice();
    if (!selected.length) {
      this.setStatus('No hay archivos seleccionados.', 'danger');
      return;
    }

    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Generando ZIP…';
    this.setStatus(`Firmando ${selected.length} ArcadeLink${selected.length === 1 ? '' : 's'} y construyendo un solo ZIP…`, 'muted');

    const form = this.document.createElement('form');
    form.method = 'POST';
    form.action = 'federationcloud/bundle.php';
    form.style.display = 'none';

    const values = {
      archivos_json: JSON.stringify(selected),
      visibility,
      rights,
      discovery_policy: discoveryPolicy
    };

    Object.entries(values).forEach(([name, value]) => {
      const input = this.document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });

    this.document.body.appendChild(form);
    form.submit();
    form.remove();

    const policyText = discoveryPolicy || (visibility === 'PUBLIC' ? 'public_metadata' : 'local_only');
    this.setStatus(`ZIP solicitado · ${selected.length} ArcadeLink${selected.length === 1 ? '' : 's'} · ${visibility} · ${policyText}`, 'success');

    this.window.setTimeout(() => {
      button.disabled = false;
      button.innerHTML = oldHtml;
      this.updateDownloadButton();
    }, 1800);
  }

  async downloadSingle(button, visibility, rights, discoveryPolicy) {
    const key = String(this.context.key || this.window.__shareContext?.key || '').trim();
    if (!key) {
      this.setStatus('No hay archivo seleccionado.', 'danger');
      return;
    }

    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Generando…';
    this.setStatus('Firmando ArcadeLink y construyendo paquete portable…', 'muted');

    try {
      const body = new URLSearchParams();
      body.set('storage_ref', key);
      body.set('visibility', visibility);
      body.set('rights', rights);
      body.set('discovery_policy', discoveryPolicy);

      const response = await fetch('federationcloud/create.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/zip, application/json',
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
      const filename = match && match[1] ? match[1] : `${fallbackBase}-ArcadeLink.zip`;
      const url = URL.createObjectURL(blob);
      const anchor = this.document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      anchor.style.display = 'none';
      this.document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      this.window.setTimeout(() => URL.revokeObjectURL(url), 1500);

      const policyText = discoveryPolicy || (visibility === 'PUBLIC' ? 'public_metadata' : 'local_only');
      this.setStatus(`ZIP ArcadeLink generado · Windows/Linux/macOS · ${visibility} · ${policyText}`, 'success');
    } catch (error) {
      console.error('[arcadelink-share]', error);
      this.setStatus(error && error.message ? error.message : 'No se pudo generar el paquete ArcadeLink.', 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = oldHtml;
      this.updateDownloadButton();
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
