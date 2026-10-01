class ArcadeCloudOsSearch {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.form = null;
    this.input = null;
    this.results = null;
    this.mode = 'normal';
    this.busy = false;
  }

  init() {
    this.form = this.document.getElementById('osSearchForm');
    this.input = this.document.getElementById('osSearchInput');
    this.results = this.document.getElementById('osSearchResults');
    if (!this.form || !this.input || !this.results) return this;

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.search();
    });

    this.document.querySelectorAll('[data-os-search-mode]').forEach((button) => {
      button.addEventListener('click', () => {
        const mode = String(button.dataset.osSearchMode || 'normal');
        this.setMode(mode === 'ai' ? 'ai' : 'normal');
      });
    });

    this.results.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const button = target?.closest('[data-os-search-open]');
      if (!button) return;
      event.preventDefault();
      this.openResult(button);
    });

    this.setMode('normal');
    return this;
  }

  setMode(mode) {
    this.mode = mode === 'ai' ? 'ai' : 'normal';
    this.document.querySelectorAll('[data-os-search-mode]').forEach((button) => {
      const active = String(button.dataset.osSearchMode || '') === this.mode;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    const help = this.document.getElementById('osSearchHelp');
    if (help) {
      help.textContent = this.mode === 'ai'
        ? 'Describe lo que recuerdas. La IA busca por nombres, rutas y metadatos del catálogo privado.'
        : 'Busca por nombre: factura, fact*, *.pdf, *2026* o usa ? para un carácter.';
    }
    if (this.input) {
      this.input.placeholder = this.mode === 'ai'
        ? 'Ej. foto del billete antiguo que guardé en trabajos'
        : 'factura, fact*, *.pdf, *2026*';
    }
  }

  async search() {
    const term = String(this.input?.value || '').trim();
    if (!term || this.busy) return;

    this.busy = true;
    this.renderLoading();

    try {
      const response = await this.window.fetch('buscar_archivo.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          termino: term,
          modo: this.mode
        }).toString()
      });

      const text = await response.text();
      let data = null;
      try { data = JSON.parse(text); } catch (_) {}

      if (!response.ok || !data || data.estado !== 'ok') {
        throw new Error(data?.mensaje || text || 'No se pudo realizar la búsqueda.');
      }

      this.renderResults(data);
    } catch (error) {
      this.results.innerHTML = '';
      const box = this.document.createElement('div');
      box.className = 'os-search-empty is-error';
      box.textContent = error?.message || 'No se pudo realizar la búsqueda.';
      this.results.appendChild(box);
    } finally {
      this.busy = false;
    }
  }

  renderLoading() {
    this.results.innerHTML = '';
    const box = this.document.createElement('div');
    box.className = 'os-search-loading';
    box.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i><span>Buscando…</span>';
    this.results.appendChild(box);
  }

  renderResults(data) {
    const rows = Array.isArray(data.resultados) ? data.resultados : [];
    this.results.innerHTML = '';

    const summary = this.document.createElement('div');
    summary.className = 'os-search-summary';
    const total = Number(data.total || rows.length || 0);
    summary.textContent = total + (total === 1 ? ' resultado' : ' resultados');
    if (data.modo === 'ai') {
      summary.textContent += data.ia_usada ? ' · IA aplicada' : ' · coincidencia local';
    }
    this.results.appendChild(summary);

    if (!rows.length) {
      const empty = this.document.createElement('div');
      empty.className = 'os-search-empty';
      empty.innerHTML = '<i class="fas fa-search"></i><strong>No encontré coincidencias.</strong><span>Prueba otro nombre o descripción.</span>';
      this.results.appendChild(empty);
      return;
    }

    rows.forEach((row) => {
      const type = String(row.tipo || 'archivo');
      const name = String(row.nombre_real || row.nombre || (type === 'carpeta' ? 'Carpeta' : 'Archivo'));
      const route = String(row.ruta || '');
      const visibleRoute = String(row.ruta_visible || route || 'Inicio/');
      const key = String(row.key || '');
      const confidence = Number(row.confianza ?? -1);
      const reason = String(row.motivo || '');
      const locked = row.bloqueado === true || row.bloqueado === 1 || row.bloqueado === '1';

      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'os-search-result';
      button.dataset.osSearchOpen = '1';
      button.dataset.type = type;
      button.dataset.id = String(row.id || '');
      button.dataset.route = route;
      button.dataset.key = key;
      button.dataset.name = name;

      const icon = this.document.createElement('span');
      icon.className = 'os-search-result-icon';
      icon.innerHTML = '<i class="fas ' + (type === 'carpeta' ? 'fa-folder' : (locked ? 'fa-lock' : 'fa-file')) + '"></i>';

      const content = this.document.createElement('span');
      content.className = 'os-search-result-content';

      const title = this.document.createElement('strong');
      title.textContent = name;

      const path = this.document.createElement('span');
      path.className = 'os-search-result-path';
      path.textContent = visibleRoute;

      content.appendChild(title);
      content.appendChild(path);

      if (reason) {
        const why = this.document.createElement('small');
        why.className = 'os-search-result-reason';
        why.textContent = reason;
        content.appendChild(why);
      }

      const meta = this.document.createElement('span');
      meta.className = 'os-search-result-meta';
      if (confidence >= 0) {
        const badge = this.document.createElement('b');
        badge.textContent = Math.max(0, Math.min(100, confidence)) + '%';
        meta.appendChild(badge);
      }
      const open = this.document.createElement('span');
      open.innerHTML = '<i class="fas fa-folder-open"></i>';
      meta.appendChild(open);

      button.appendChild(icon);
      button.appendChild(content);
      button.appendChild(meta);
      this.results.appendChild(button);
    });
  }

  async openResult(button) {
    const shell = this.window.ArcadeCloudOsShell;
    if (!shell?.refreshExplorer) return;

    const type = String(button.dataset.type || 'archivo');
    let route = String(button.dataset.route || '').trim();
    const key = String(button.dataset.key || '').trim();
    const name = String(button.dataset.name || '').trim();
    const id = Math.max(0, parseInt(String(button.dataset.id || '0'), 10) || 0);
    let page = 1;

    button.classList.add('is-opening');

    try {
      if (type === 'archivo' && id > 0) {
        const response = await this.window.fetch('buscar_archivo.php', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({ localizar_id: String(id) }).toString()
        });
        const data = await response.json();
        if (!response.ok || data?.estado !== 'ok' || !data?.archivo) {
          throw new Error(data?.mensaje || 'No se pudo localizar el archivo.');
        }
        route = String(data.archivo.ruta || route).trim();
        page = Math.max(1, parseInt(String(data.archivo.pagina || '1'), 10) || 1);
      }

      if (!route) throw new Error('El resultado no tiene una carpeta válida.');

      const explorer = this.document.querySelector('.os-explorer-window');
      if (explorer && typeof shell.openWindow === 'function') {
        shell.openWindow(explorer);
      }

      const ok = await shell.refreshExplorer(route, {
        page,
        replaceHistory: false
      });
      if (!ok) return;

      let entry = null;
      if (type === 'archivo') {
        if (key) {
          entry = Array.from(this.document.querySelectorAll('.os-file-entry'))
            .find((item) => String(item.dataset.key || '') === key) || null;
        }
        if (!entry && name) {
          entry = Array.from(this.document.querySelectorAll('.os-file-entry'))
            .find((item) => String(item.dataset.name || '') === name) || null;
        }
      }

      if (entry) {
        entry.classList.add('is-search-target');
        try { entry.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
        this.window.setTimeout(() => entry.classList.remove('is-search-target'), 5000);
      }

      if (explorer) shell.activateWindow?.(explorer);
    } catch (error) {
      this.window.ArcadeCloudOsShell?.notify?.(
        error?.message || 'No se pudo abrir la carpeta del resultado.',
        'warning'
      );
    } finally {
      button.classList.remove('is-opening');
    }
  }

  cssEscape(value) {
    if (this.window.CSS?.escape) return this.window.CSS.escape(String(value || ''));
    return String(value || '').replace(/["\\]/g, '\\$&');
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudOsSearch instanceof ArcadeCloudOsSearch) return win.ArcadeCloudOsSearch;
    const instance = new ArcadeCloudOsSearch(win, doc).init();
    win.ArcadeCloudOsSearch = instance;
    return instance;
  }
}

ArcadeCloudOsSearch.boot();
