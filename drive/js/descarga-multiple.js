class DescargaMultipleModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    (function(){
      const multiForm = document.getElementById('multiDeleteForm');
      if (!multiForm) return;

      // Devuelve la lista de keys S3 seleccionadas dentro del form
      function seleccionActual(){
        return Array.from(multiForm.querySelectorAll('input[name="archivos[]"]:checked'))
                    .map(cb => cb.value);
      }

      // Toma el nombre sugerido desde Content-Disposition o X-Filename
      function getFileNameFromHeaders(res, fallback){
        const xf = res.headers.get('X-Filename');
        if (xf) {
          try { return decodeURIComponent(xf); } catch(_) { return xf; }
        }
        const cd = res.headers.get('Content-Disposition') || '';
        const m = cd.match(/filename\*?=(?:UTF-8''|")?([^\";]+)/i);
        if (m && m[1]) {
          try { return decodeURIComponent(m[1]); } catch(_) { return m[1]; }
        }
        return fallback || ('archivos_' + new Date().toISOString().replace(/[-:T]/g,'').slice(0,15) + '.zip');
      }

      // Intercepta el submit del form SOLO si el "submitter" es el botón de descargar_zip.php
      multiForm.addEventListener('submit', async function(e){
        const btn = e.submitter;
        if (!btn) return; // navegadores viejos
        const fa = btn.getAttribute('formaction') || '';
        if (!/descargar_zip\.php/i.test(fa)) return; // no es el botón de ZIP, deja fluir

        e.preventDefault();

        const seleccionados = seleccionActual();
        if (!seleccionados.length) {
          alert('Selecciona al menos un archivo para descargar.');
          return;
        }

        // Visual (spinner en el botón)
        btn.dataset.oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Preparando ZIP…';

        try {
          const body = new URLSearchParams({
            archivos_json: JSON.stringify(seleccionados)
          });

          const res = await fetch('descargar_zip.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest' },
            body
          });

          if (!res.ok) throw new Error('HTTP ' + res.status);

          const blob = await res.blob();
          const name = getFileNameFromHeaders(res, 'archivos.zip');

          // Descarga sin navegar
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = name;
          document.body.appendChild(a);
          a.click();
          setTimeout(() => {
            URL.revokeObjectURL(url);
            a.remove();
          }, 100);

          // (Opcional) limpiar selección
          // multiForm.querySelectorAll('input[name="archivos[]"]:checked').forEach(cb => cb.checked = false);
          // const selectAll = multiForm.querySelector('#selectAll');
          // if (selectAll) selectAll.checked = false;

          // No refrescamos nada; la UI permanece igual

        } catch (err) {
          console.error(err);
          alert('❌ ' + (err.message || 'No se pudo generar el ZIP.'));
        } finally {
          btn.disabled = false;
          if (btn.dataset.oldHtml) btn.innerHTML = btn.dataset.oldHtml;
        }
      });
    })();

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new DescargaMultipleModule(win, doc).init();
    win.ArcadeCloudDrive.modules['descarga-multiple'] = instance;
    return instance;
  }
}

DescargaMultipleModule.boot();
