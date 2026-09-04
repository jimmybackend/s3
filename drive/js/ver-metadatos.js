class VerMetadatosModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (() => {
      'use strict';

      function escapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      window.verMetadatos = function verMetadatos(nombre, datos) {
        const contenedor = document.getElementById('cuerpoMetadatos');
        const titulo = document.getElementById('metaTitulo');
        if (!contenedor || !titulo) return;

        const entries = datos && typeof datos === 'object' ? Object.entries(datos) : [];
        titulo.textContent = 'Metadatos de: ' + String(nombre || 'archivo');

        if (!entries.length) {
          contenedor.innerHTML = '<div class="text-muted">Sin metadatos.</div>';
        } else {
          let html = '<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Clave</th><th>Valor</th></tr></thead><tbody>';
          for (const [key, value] of entries) {
            const shown = value && typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
            html += '<tr><td>' + escapeHtml(key) + '</td><td><pre class="mb-0 text-wrap">' + escapeHtml(shown) + '</pre></td></tr>';
          }
          html += '</tbody></table></div>';
          contenedor.innerHTML = html;
        }

        if (window.jQuery && jQuery.fn.modal) {
          jQuery('#modalMetadatos').modal('show');
        }
      };

      document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-file-metadata');
        if (!button) return;
        event.preventDefault();

        let data = {};
        try {
          data = JSON.parse(button.getAttribute('data-meta') || '{}');
        } catch (_) {
          data = { valor: button.getAttribute('data-meta') || '' };
        }
        window.verMetadatos(button.getAttribute('data-nombre') || 'archivo', data);
      });
    })();

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new VerMetadatosModule(win, doc).init();
    win.ArcadeCloudDrive.modules['ver-metadatos'] = instance;
    return instance;
  }
}

VerMetadatosModule.boot();
