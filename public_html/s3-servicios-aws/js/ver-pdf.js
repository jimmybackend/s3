(function (window, document) {
  'use strict';

  function verPDF(key) {
    key = String(key || '').trim();
    if (!key) {
      console.error('verPDF: key vac¨ªo');
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
      console.error('No se encontr¨® #modalEditorArchivo');
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

  // delegaci¨®n para botones generados din¨¢micamente
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-ver-pdf');
    if (!btn) return;

    e.preventDefault();

    const key = (btn.dataset.key || '').trim();
    if (!key) {
      console.error('Bot¨®n .js-ver-pdf sin data-key');
      return;
    }

    verPDF(key);
  });
})(window, document);