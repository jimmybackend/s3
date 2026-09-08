class FileBlockApp {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.paginationHandler = this.onPaginationClick.bind(this);
    this.changeHandler = this.onChange.bind(this);
    this.bulkHandler = this.onBulkClick.bind(this);
    this.refreshHandler = this.refresh.bind(this);
    this.bound = false;
  }

  init() {
    if (!this.bound) {
      this.document.addEventListener('click', this.paginationHandler);
      this.document.addEventListener('click', this.bulkHandler);
      this.document.addEventListener('change', this.changeHandler);
      this.document.addEventListener('bloque-archivos:actualizado', this.refreshHandler);
      this.document.addEventListener('DOMContentLoaded', this.refreshHandler, { once: true });
      this.bound = true;
    }
    this.refresh();
    return this;
  }

  refresh() {
    this.removeMetadataHover();
    this.initTooltips();
    this.syncContext();
    this.finishLoader();
    this.updateSelectionCount();
  }

  removeMetadataHover() {
    const lines = Array.from(this.document.querySelectorAll('.file-meta-line'));
    if (!lines.length) return;

    try {
      if (this.window.jQuery && typeof this.window.jQuery.fn.tooltip === 'function') {
        this.window.jQuery(lines).tooltip('dispose');
      }
    } catch (_) {}

    lines.forEach((element) => {
      element.removeAttribute('data-toggle');
      element.removeAttribute('data-placement');
      element.removeAttribute('data-original-title');
      element.removeAttribute('aria-describedby');
      element.removeAttribute('title');
    });
  }

  initTooltips() {
    try {
      if (this.window.jQuery && typeof this.window.jQuery.fn.tooltip === 'function') {
        this.window.jQuery('[data-toggle="tooltip"]').tooltip({ html: false, container: 'body' });
      }
    } catch (_) {}
  }

  syncContext() {
    const context = this.document.getElementById('archivosContexto');
    if (context?.dataset?.rutaActual) {
      this.window.rutaActual = context.dataset.rutaActual;
    }

    const data = this.document.getElementById('imagenesGaleriaData');
    if (!data) {
      this.window.imagenesGaleria = [];
      return;
    }

    try {
      const parsed = JSON.parse(data.textContent || '[]');
      this.window.imagenesGaleria = Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      this.window.imagenesGaleria = [];
    }
  }

  getCurrentLimit() {
    const fromSelect = this.document.querySelector('#formLimite select[name="limite"]');
    if (fromSelect?.value?.trim()) return String(fromSelect.value);

    const fromContext = this.document.getElementById('archivosContexto')?.dataset?.limite;
    if (fromContext?.trim()) return String(fromContext);

    const fromUrl = new URLSearchParams(this.window.location.search).get('limite');
    if (fromUrl?.trim()) return String(fromUrl);

    const candidates = [
      this.document.querySelector('select[name="limite"]'),
      this.document.getElementById('limite'),
      this.document.querySelector('#formFiltros [name="limite"]')
    ];
    for (const element of candidates) {
      if (element?.value?.trim()) return String(element.value);
    }
    return null;
  }

  onPaginationClick(event) {
    const link = event.target.closest('.pagination .page-link');
    if (!link) return;

    const page = parseInt(link.getAttribute('data-pagina') || '0', 10);
    if (!page) return;

    event.preventDefault();
    event.__archivosPaginationHandled = true;

    const params = new URLSearchParams(this.window.location.search);
    params.set('pagina', String(page));

    const filters = typeof this.window.obtenerFiltros === 'function'
      ? this.window.obtenerFiltros()
      : {
          buscar: this.document.querySelector('#formFiltros [name="buscar"]')?.value ?? '',
          tipo: this.document.querySelector('#formFiltros [name="tipo"]')?.value ?? '',
          fecha_inicio: this.document.querySelector('#formFiltros [name="fecha_inicio"]')?.value ?? '',
          fecha_fin: this.document.querySelector('#formFiltros [name="fecha_fin"]')?.value ?? ''
        };

    ['buscar', 'tipo', 'fecha_inicio', 'fecha_fin'].forEach((key) => {
      const value = filters?.[key];
      if (value && String(value).trim() !== '') params.set(key, String(value));
      else params.delete(key);
    });

    const route = this.document.getElementById('archivosContexto')?.dataset?.rutaActual;
    if (route?.trim()) params.set('ruta', String(route));

    const limit = this.getCurrentLimit();
    if (limit) params.set('limite', limit);

    if (typeof this.window.actualizarBloqueArchivos === 'function') {
      this.window.actualizarBloqueArchivos(params);
      return;
    }

    const url = new URL(this.window.location.href);
    url.search = params.toString();
    this.window.location.href = url.toString();
  }

  finishLoader() {
    const wrap = this.document.getElementById('archivosWrap');
    const overlay = this.document.getElementById('archivosLoaderOverlay');
    const backdrop = this.document.getElementById('archivosLoaderBackdrop');
    if (wrap) {
      wrap.querySelectorAll('li.file-row').forEach((row) => {
        row.classList.remove('is-pending');
        row.classList.add('is-ready');
      });
      wrap.classList.remove('is-loading');
    }
    if (overlay) overlay.style.display = 'none';
    if (backdrop) backdrop.style.display = 'none';
  }

  selectedKeys() {
    const wrap = this.document.getElementById('archivosWrap') || this.document;
    return Array.from(wrap.querySelectorAll('input[name="archivos[]"]:checked'))
      .map((checkbox) => checkbox.value)
      .filter(Boolean);
  }

  onChange(event) {
    if (event.target?.id === 'checkAllFiles') {
      const checked = Boolean(event.target.checked);
      const wrap = this.document.getElementById('archivosWrap') || this.document;
      wrap.querySelectorAll('input[name="archivos[]"]').forEach((checkbox) => {
        checkbox.checked = checked;
      });
      this.updateSelectionCount();
      return;
    }

    if (event.target?.matches?.('input[name="archivos[]"]')) {
      this.updateSelectionCount();
    }
  }

  updateSelectionCount() {
    const element = this.document.getElementById('filesSelectedCount');
    if (!element) return;
    const count = this.selectedKeys().length;
    element.textContent = count ? `${count} seleccionado(s)` : '';
  }

  onBulkClick(event) {
    const button = event.target.closest('[data-file-bulk-action]');
    if (!button) return;
    event.preventDefault();
    const action = button.dataset.fileBulkAction;
    if (action === 'download') this.downloadSelected();
    else if (action === 'move') this.moveSelected();
    else if (action === 'delete') this.deleteSelected();
  }

  downloadSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo.');
      return;
    }

    const form = this.document.createElement('form');
    form.method = 'POST';
    form.action = 'descargar_zip.php';
    form.style.display = 'none';

    const input = this.document.createElement('input');
    input.type = 'hidden';
    input.name = 'archivos_json';
    input.value = JSON.stringify(selected);
    form.appendChild(input);
    this.document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  moveSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo para mover.');
      return;
    }

    const jsonInput = this.document.getElementById('archivosJson');
    if (jsonInput) jsonInput.value = JSON.stringify(selected);
    const modal = this.document.getElementById('modalMover');
    if (!modal) return;

    try {
      if (this.window.jQuery && typeof this.window.jQuery(modal).modal === 'function') {
        this.window.jQuery(modal).modal('show');
        return;
      }
    } catch (_) {}

    try {
      if (this.window.bootstrap?.Modal) {
        const modalApi = this.window.bootstrap.Modal;
        if (typeof modalApi.getOrCreateInstance === 'function') modalApi.getOrCreateInstance(modal).show();
        else new modalApi(modal).show();
        return;
      }
    } catch (_) {}

    modal.classList.add('show');
    modal.style.display = 'block';
  }

  async deleteSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo.');
      return;
    }
    if (!this.window.confirm('¿Eliminar los archivos seleccionados?')) return;

    try {
      const body = new URLSearchParams({ archivos_json: JSON.stringify(selected) });
      const response = await this.window.fetch('delete_multiple.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok || json.estado !== 'ok') {
        throw new Error(json.mensaje || json.error || `HTTP ${response.status}`);
      }

      if (typeof this.window.actualizarBloqueArchivos === 'function') {
        await this.window.actualizarBloqueArchivos({ pagina: 1 });
      } else {
        this.window.location.reload();
      }
      this.document.dispatchEvent(new Event('drive:storage-changed'));
    } catch (error) {
      console.error(error);
      this.window.alert('❌ ' + (error.message || error));
    }
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const previous = win.ArcadeCloudDrive.modules.fileBlock;
    if (previous instanceof FileBlockApp) {
      previous.refresh();
      return previous;
    }
    const instance = new FileBlockApp(win, doc).init();
    win.ArcadeCloudDrive.modules.fileBlock = instance;
    return instance;
  }
}

FileBlockApp.boot();
