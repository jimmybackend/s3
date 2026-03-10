(function (window, document) {
  'use strict';

  /** Lee los filtros actuales desde los formularios principales */
  function obtenerFiltros() {
    const f = document.getElementById('formFiltros');
    const l = document.getElementById('formLimite');
    const get = (frm, sel, def = '') => frm?.querySelector(sel)?.value ?? def;

    return {
      tipo: get(f, '[name="tipo"]', ''),
      buscar: get(f, '[name="buscar"]', ''),
      fecha_inicio: get(f, '[name="fecha_inicio"]', ''),
      fecha_fin: get(f, '[name="fecha_fin"]', ''),
      limite: get(l, 'select[name="limite"]', '5')
    };
  }

  async function actualizarBloqueCarpetasLocal() {
    const res = await fetch('bloque_carpetas.php', { credentials: 'same-origin' });
    const html = await res.text();
    const cont = document.getElementById('bloque-carpetas');
    if (cont) cont.innerHTML = html;
  }

  async function refrescarArchivosCompat(opts = {}) {
    const filtros = obtenerFiltros();
    const pagina = opts.pagina ? Number(opts.pagina) : 1;
    const rutaCtx =
      document.getElementById('archivosContexto')?.dataset?.rutaActual ||
      document.getElementById('formFiltros')?.querySelector('[name="ruta"]')?.value ||
      window.rutaActual ||
      '';

    if (opts.rutaNueva) {
      await fetch('actualizar_ruta.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ruta: opts.rutaNueva })
      });
    }

    const params = new URLSearchParams({
      tipo: filtros.tipo,
      buscar: filtros.buscar,
      fecha_inicio: filtros.fecha_inicio,
      fecha_fin: filtros.fecha_fin,
      limite: filtros.limite,
      pagina: String(pagina)
    });

    if (rutaCtx) {
      params.set('ruta', String(rutaCtx));
    }

    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos(params);
      return;
    }

    const res = await fetch('bloque_archivos.php?' + params.toString(), { credentials: 'same-origin' });
    const html = await res.text();
    const cont = document.getElementById('bloque-archivos');
    if (cont) cont.innerHTML = html;
  }

  // Exponer helpers sin pisar implementaciones existentes
  window.obtenerFiltros = window.obtenerFiltros || obtenerFiltros;
  window.actualizarBloqueArchivos = window.actualizarBloqueArchivos || refrescarArchivosCompat;
  window.actualizarBloqueCarpetas = window.actualizarBloqueCarpetas || actualizarBloqueCarpetasLocal;

  /** Delegación de eventos: paginación dentro de #bloque-archivos */
  document.addEventListener('click', (ev) => {
    if (ev.__archivosPaginationHandled) return;

    const a = ev.target.closest('a.page-link[data-pagina]');
    if (a && a.closest('#bloque-archivos')) {
      ev.preventDefault();
      const pagina = parseInt(a.dataset.pagina, 10) || 1;
      refrescarArchivosCompat({ pagina });
    }
  });

  /** Intercepta cambios del selector de "limite" para recargar a página 1 */
  document.addEventListener('change', (ev) => {
    const sel = ev.target.closest('#formLimite select[name="limite"]');
    if (sel) {
      refrescarArchivosCompat({ pagina: 1 });
    }
  });

  /** Intercepta envío del form de filtros para recargar a página 1 */
  document.addEventListener('submit', (ev) => {
    const f = ev.target.closest('#formFiltros');
    if (f) {
      ev.preventDefault();
      refrescarArchivosCompat({ pagina: 1 });
    }
  });
})(window, document);