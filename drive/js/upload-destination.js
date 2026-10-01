class UploadDestinationModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (() => {
      'use strict';

      function currentRoute() {
        const contextRoute = document.getElementById('archivosContexto')?.dataset?.rutaActual;
        const footerRoute = document.getElementById('footerRutaActual')?.textContent;
        return String(contextRoute || window.rutaActual || window.DRIVE_INITIAL_ROUTE || footerRoute || '').trim();
      }

      function normalize(route) {
        route = String(route || '').trim();
        return route && !route.endsWith('/') ? route + '/' : route;
      }

      function contextFromElement(element) {
        const live = element?.closest?.('.os-explorer-live')
          || element?.closest?.('.os-explorer-window')?.querySelector?.('.os-explorer-live');
        const owner = element?.closest?.('.os-explorer-window, .os-window');
        const route = normalize(live?.dataset?.explorerRoute || '');
        return Object.freeze({
          route,
          sourceWindowId: String(owner?.dataset?.windowId || '')
        });
      }

      function capture(context = null) {
        const explicit = context && typeof context === 'object'
          ? String(context.route || context.destinationRoute || '')
          : String(context || '');
        const route = normalize(explicit || currentRoute());
        if (!route) {
          throw new Error('No se pudo determinar la carpeta destino de la subida.');
        }
        return route;
      }

      function sameRoute(a, b) {
        const norm = (v) => String(v || '').trim().replace(/\\/g, '/').replace(/\/+$/, '') + '/';
        return norm(a) === norm(b);
      }

      async function afterSuccess(route) {
        try {
          document.dispatchEvent(new CustomEvent('drive:storage-changed', {
            detail: { source: 'upload', route: route }
          }));
        } catch (_) {}

        // La subida conserva el destino capturado al iniciar. Sólo refrescamos
        // visualmente si el usuario sigue mirando esa misma carpeta.
        if (!sameRoute(route, currentRoute())) return;

        if (window.ArcadeCloudOsShell && typeof window.ArcadeCloudOsShell.refreshExplorer === 'function') {
          await window.ArcadeCloudOsShell.refreshExplorer(route, { page: 1, replaceHistory: true });
          return;
        }

        if (typeof window.actualizarBloqueArchivos === 'function') {
          await window.actualizarBloqueArchivos({ ruta: route, pagina: 1 });
        }
      }

      window.DriveUploadDestination = Object.freeze({
        currentRoute,
        contextFromElement,
        capture,
        sameRoute,
        afterSuccess
      });
    })();

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new UploadDestinationModule(win, doc).init();
    win.ArcadeCloudDrive.modules['upload-destination'] = instance;
    return instance;
  }
}

UploadDestinationModule.boot();
