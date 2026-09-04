class EliminaUnoModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (function(){
      // 🌀 Muestra o quita spinner temporal en el botón del form
      function setBtnLoading(btn, on){
        if (!btn) return;
        if (on) {
          btn.dataset.oldHtml = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Eliminando…';
        } else {
          if (btn.dataset.oldHtml) btn.innerHTML = btn.dataset.oldHtml;
          btn.disabled = false;
        }
      }

      // 🔄 Refresca el bloque de archivos vía AJAX
      async function refreshBloqueArchivos(){
        if (typeof window.actualizarBloqueArchivos === 'function') {
          await window.actualizarBloqueArchivos({ pagina: 1 });
        } else {
          const res  = await fetch('bloque_archivos.php', { credentials: 'same-origin' });
          const html = await res.text();
          const cont = document.getElementById('bloque-archivos');
          if (cont) cont.innerHTML = html;
        }
      }

      // 🔔 Muestra mensajes visuales
      function showToast(msg, type = 'success') {
        try {
          let container = document.getElementById('toast-container');
          if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.position = 'fixed';
            container.style.bottom = '20px';
            container.style.right = '20px';
            container.style.zIndex = '2000';
            document.body.appendChild(container);
          }
          const toast = document.createElement('div');
          toast.className = `toast align-items-center text-bg-${type} border-0 show`;
          toast.role = 'alert';
          toast.innerHTML = `
            <div class="d-flex">
              <div class="toast-body">${msg}</div>
              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;
          container.appendChild(toast);
          setTimeout(() => toast.remove(), 4000);
        } catch {
          alert(msg);
        }
      }

      // 🎯 Captura submit de eliminación individual dentro de #bloque-archivos
      document.addEventListener('submit', async function(e){
        const form = e.target;
        if (!form.closest('#bloque-archivos')) return;
        if (!form.action || !(form.action.includes('delete.php') || form.action.includes('eliminar_archivo.php'))) return;

        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        if (!confirm('¿Eliminar este archivo?')) return;

        try {
          setBtnLoading(submitBtn, true);

          const fd = new FormData(form);
          const res = await fetch(form.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });

          if (!res.ok) throw new Error('HTTP ' + res.status);
          const j = await res.json().catch(() => ({}));
          if (!j.ok) throw new Error(j.error || 'No se pudo eliminar.');

          await refreshBloqueArchivos();
          showToast('✔ Archivo eliminado correctamente.', 'success');

        } catch (err) {
          console.error(err);
          showToast('❌ ' + (err.message || err), 'danger');
        } finally {
          setBtnLoading(submitBtn, false);
        }
      }, true);

      // 🚫 Evita submit accidental con Enter dentro del bloque
      document.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && e.target.closest('#bloque-archivos form')) {
          e.preventDefault();
        }
      });
    })();

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new EliminaUnoModule(win, doc).init();
    win.ArcadeCloudDrive.modules['elimina-uno'] = instance;
    return instance;
  }
}

EliminaUnoModule.boot();
