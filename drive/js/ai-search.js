class DriveAiSearchModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.bound = false;
  }

  init() {
    if (this.bound) return this;
    this.bound = true;
    this.installUi();
    this.document.addEventListener('click', (event) => this.onClick(event), true);
    return this;
  }

  installUi() {
    const form = this.document.getElementById('formBusquedaGlobal');
    const input = this.document.getElementById('terminoBusqueda');
    if (!form || !input || this.document.getElementById('btnBusquedaIA')) return;

    const label = form.querySelector('label[for="terminoBusqueda"]');
    if (label) label.textContent = 'Buscar archivo, carpeta o describir lo que recuerdas';

    input.placeholder = 'Ej. el PDF del amparo de Juan, fotos de la iglesia, factura de agosto...';

    const helper = form.querySelector('.form-text');
    if (helper) {
      helper.innerHTML =
        'La búsqueda normal acepta <code>fact*</code>, <code>*.pdf</code> y <code>?</code>. ' +
        'La búsqueda IA entiende una descripción libre y usa nombres, carpetas y metadatos de MySQL.';
    }

    const box = this.document.createElement('div');
    box.className = 'mt-2 d-flex flex-wrap align-items-center';
    box.style.gap = '8px';
    box.innerHTML = `
      <button type="button" class="btn btn-sm btn-outline-info" id="btnBusquedaIA">
        <i class="fas fa-wand-magic-sparkles mr-1"></i> Buscar con IA
      </button>
      <small class="text-muted">
        Nova Micro propone pistas y busca sólo en tu catálogo MySQL. No lista S3 ni envía el archivo físico a la IA.
      </small>`;

    form.appendChild(box);
  }

  async onClick(event) {
    const aiButton = event.target.closest('#btnBusquedaIA');
    if (aiButton) {
      event.preventDefault();
      event.stopPropagation();
      await this.search(aiButton);
      return;
    }

    const openButton = event.target.closest('#resultadosBusqueda [data-ai-open]');
    if (!openButton) return;

    event.preventDefault();
    const route = String(openButton.dataset.ruta || '').trim();
    const name = String(openButton.dataset.nombre || '').trim();
    if (!route) return;

    const url = new URL('s3.php', this.window.location.href);
    url.searchParams.set('ruta', route);
    if (name) url.searchParams.set('buscar', name);
    this.window.location.href = url.toString();
  }

  async search(button) {
    const input = this.document.getElementById('terminoBusqueda');
    const result = this.document.getElementById('resultadosBusqueda');
    const query = String(input?.value || '').trim();

    if (!query) {
      this.window.alert('Describe lo que buscas o escribe un nombre.');
      return;
    }

    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Analizando...';

    if (result) {
      result.innerHTML = '<p class="mb-0"><i class="fas fa-spinner fa-spin"></i> Nova Micro está buscando pistas en tu catálogo...</p>';
    }

    try {
      const body = new URLSearchParams({
        modo: 'ai',
        termino: query
      });

      const response = await this.window.fetch('buscar_archivo.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body
      });

      const json = await response.json().catch(() => null);
      if (!response.ok || !json || json.estado !== 'ok') {
        throw new Error(json?.mensaje || `HTTP ${response.status}`);
      }

      this.render(json);
    } catch (error) {
      if (result) {
        result.innerHTML = `<div class="alert alert-danger mb-0">${this.escape(error.message || String(error))}</div>`;
      }
    } finally {
      button.disabled = false;
      button.innerHTML = oldHtml;
    }
  }

  render(payload) {
    const result = this.document.getElementById('resultadosBusqueda');
    if (!result) return;

    const rows = Array.isArray(payload.resultados) ? payload.resultados : [];
    const clues = Array.isArray(payload.pistas) ? payload.pistas : [];

    if (!rows.length) {
      result.innerHTML = `
        <div class="alert alert-warning mb-0">
          No encontré candidatos razonables para esa descripción.
          ${clues.length ? '<br><small>Pistas probadas: ' + clues.map((x) => this.escape(x)).join(', ') + '</small>' : ''}
        </div>`;
      return;
    }

    let html = '<div class="small text-muted mb-2">';
    html += '<i class="fas fa-robot mr-1"></i> Nova Micro sugirió ' + rows.length + ' posible(s) ubicación(es).';
    if (clues.length) html += ' Pistas: ' + clues.map((x) => this.escape(x)).join(', ') + '.';
    html += '</div><div class="list-group">';

    rows.forEach((row) => {
      const type = row.tipo === 'carpeta' ? 'carpeta' : 'archivo';
      const icon = type === 'carpeta' ? 'fa-folder' : 'fa-file';
      const name = this.escape(row.nombre || 'Sin nombre');
      const visibleRoute = this.escape(row.ruta_visible || 'Inicio/');
      const reason = this.escape(row.motivo || 'Coincide con las pistas de búsqueda.');
      const confidence = Math.max(0, Math.min(100, Number(row.confianza || 0)));
      const route = this.escapeAttribute(row.ruta || '');
      const searchName = type === 'archivo' ? this.escapeAttribute(row.nombre || '') : '';

      html += `
        <div class="list-group-item">
          <div class="d-flex justify-content-between align-items-start" style="gap:12px;">
            <div class="min-width-0">
              <div class="font-weight-bold"><i class="fas ${icon} mr-1"></i>${name}</div>
              <div class="small text-muted">${visibleRoute}</div>
              <div class="small mt-1">${reason}</div>
              <div class="small text-info mt-1">Confianza aproximada: ${confidence}%</div>
            </div>
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-ai-open="1"
                    data-ruta="${route}"
                    data-nombre="${searchName}">
              <i class="fas fa-folder-open mr-1"></i> Abrir ubicación
            </button>
          </div>
        </div>`;
    });

    html += '</div>';
    result.innerHTML = html;
  }

  escape(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  escapeAttribute(value) {
    return this.escape(value).replace(/`/g, '&#096;');
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const previous = win.ArcadeCloudDrive.modules.aiSearch;
    if (previous instanceof DriveAiSearchModule) return previous;
    const instance = new DriveAiSearchModule(win, doc).init();
    win.ArcadeCloudDrive.modules.aiSearch = instance;
    return instance;
  }
}

DriveAiSearchModule.boot();
