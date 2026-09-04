(() => {
  'use strict';

  const btn = document.getElementById('btnSyncS3');
  const host = document.getElementById('syncStatus');
  if (!btn) return;

  function setStatus(message, kind = 'muted') {
    if (!host) return;
    host.className = 'mb-2 small ' + (
      kind === 'success' ? 'text-success' :
      kind === 'danger' ? 'text-danger' :
      kind === 'primary' ? 'text-primary' : 'text-muted'
    );
    host.textContent = message;
  }

  async function triggerSyncS3(event) {
    if (event) event.preventDefault();
    if (btn.disabled) return;

    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando…';
    setStatus('Comparando S3 con la base de datos…', 'primary');

    try {
      const response = await fetch('sync_s3_to_db.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const raw = await response.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) {}

      if (!response.ok || !data || data.ok !== true) {
        throw new Error((data && (data.error || data.message)) || `HTTP ${response.status}`);
      }

      setStatus('Sincronización completada.', 'success');

      // Solo después de una sincronización manual actualizamos las vistas DB.
      if (typeof window.actualizarBloqueArchivos === 'function') {
        await window.actualizarBloqueArchivos({ pagina: 1 });
      }
      if (typeof window.actualizarBloqueCarpetas === 'function') {
        await window.actualizarBloqueCarpetas();
      }
      try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}
    } catch (error) {
      console.error(error);
      setStatus('No se pudo sincronizar: ' + (error.message || error), 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = oldHtml;
    }
  }

  btn.addEventListener('click', triggerSyncS3);
  window.triggerSyncS3 = triggerSyncS3;
})();
