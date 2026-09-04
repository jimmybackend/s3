(() => {
  'use strict';

  function currentRoute() {
    const contextRoute = document.getElementById('archivosContexto')?.dataset?.rutaActual;
    const footerRoute = document.getElementById('footerRutaActual')?.textContent;
    return String(contextRoute || window.rutaActual || window.DRIVE_INITIAL_ROUTE || footerRoute || '').trim();
  }

  function capture() {
    const route = currentRoute();
    if (!route) {
      throw new Error('No se pudo determinar la carpeta destino de la subida.');
    }
    return route.endsWith('/') ? route : route + '/';
  }

  function sameRoute(a, b) {
    const norm = (v) => String(v || '').trim().replace(/\\/g, '/').replace(/\/+$/, '') + '/';
    return norm(a) === norm(b);
  }

  async function afterSuccess(route) {
    try { document.dispatchEvent(new Event('drive:storage-changed')); } catch (_) {}

    // Solo consultamos MySQL para refrescar la lista si el usuario sigue en
    // la misma carpeta donde terminó la subida.
    if (sameRoute(route, currentRoute()) && typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ ruta: route, pagina: 1 });
    }
  }

  window.DriveUploadDestination = Object.freeze({
    currentRoute,
    capture,
    sameRoute,
    afterSuccess
  });
})();
