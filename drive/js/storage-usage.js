(() => {
  'use strict';

  async function actualizarEspacioUsado(force = true) {
    const target = document.getElementById('footerEspacioUsado');
    if (!target) return;

    try {
      const url = 'storage_usage.php' + (force ? '?refresh=1' : '');
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
      }
      target.textContent = data.formatted || '0 B';
    } catch (error) {
      console.error('[storage-usage] No se pudo actualizar el espacio usado:', error);
    }
  }

  window.actualizarEspacioUsado = actualizarEspacioUsado;
  document.addEventListener('drive:storage-changed', () => actualizarEspacioUsado(true));
})();
