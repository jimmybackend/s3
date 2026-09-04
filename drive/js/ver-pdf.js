class VerPdfModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (function (window, document) {
      'use strict';

      function verPDF(key) {
        key = String(key || '').trim();
        if (!key) {
          console.error('verPDF: key vaco');
          return;
        }

        const url = 'ver_pdf.php?archivo=' + encodeURIComponent(key);
        const nombre = key.split('/').pop();

        const titulo = document.getElementById('tituloEditor');
        const visorPdf = document.getElementById('visorPdf');
        const editorTxt = document.getElementById('editorTxt');
        const btnGuardarTxt = document.getElementById('btnGuardarTxt');
        const modal = document.getElementById('modalEditorArchivo');

        if (titulo) {
          titulo.textContent = nombre || 'PDF';
        }

        if (visorPdf) {
          visorPdf.src = url;
          visorPdf.style.display = 'block';
        }

        if (editorTxt) {
          editorTxt.style.display = 'none';
          editorTxt.value = '';
        }

        if (btnGuardarTxt) {
          btnGuardarTxt.style.display = 'none';
        }

        if (!modal) {
          console.error('No se encontr #modalEditorArchivo');
          return;
        }

        if (window.jQuery && typeof window.jQuery === 'function' && typeof window.jQuery.fn.modal === 'function') {
          window.jQuery(modal).modal('show');
          return;
        }

        if (window.bootstrap && window.bootstrap.Modal) {
          const instancia = window.bootstrap.Modal.getOrCreateInstance
            ? window.bootstrap.Modal.getOrCreateInstance(modal)
            : new window.bootstrap.Modal(modal);

          instancia.show();
          return;
        }

        modal.classList.add('show');
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        if (!document.querySelector('.modal-backdrop')) {
          const backdrop = document.createElement('div');
          backdrop.className = 'modal-backdrop fade show';
          document.body.appendChild(backdrop);
        }
      }

      // dejar global por si la llamas desde onclick en otro lado
      window.verPDF = verPDF;

      // delegacin para botones generados dinmicamente
      document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-ver-pdf');
        if (!btn) return;

        e.preventDefault();

        const key = (btn.dataset.key || '').trim();
        if (!key) {
          console.error('Botn .js-ver-pdf sin data-key');
          return;
        }

        verPDF(key);
      });
    })(window, document);

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new VerPdfModule(win, doc).init();
    win.ArcadeCloudDrive.modules['ver-pdf'] = instance;
    return instance;
  }
}

VerPdfModule.boot();
