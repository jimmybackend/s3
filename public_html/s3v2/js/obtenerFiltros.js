
/** Lee los filtros actuales desde los formularios que están dentro de #bloque-archivos */
function obtenerFiltros() {
  const f = document.getElementById('formFiltros');
  const l = document.getElementById('formLimite');
  const get = (frm, sel, def='') => frm?.querySelector(sel)?.value ?? def;

  return {
    tipo:         get(f,  '[name="tipo"]', ''),
    buscar:       get(f,  '[name="buscar"]', ''),
    fecha_inicio: get(f,  '[name="fecha_inicio"]', ''),
    fecha_fin:    get(f,  '[name="fecha_fin"]', ''),
    limite:       get(l,  'select[name="limite"]', '50'),
  };
}

/** Refresca SOLO el bloque de archivos (respetando filtros).
 *  opts puede traer { pagina, rutaNueva } */
async function actualizarBloqueArchivos(opts = {}) {
  const filtros = obtenerFiltros();
  const pagina  = opts.pagina ? Number(opts.pagina) : 1;

  // Si nos pasan una rutaNueva, primero actualizamos la sesión
  if (opts.rutaNueva) {
    await fetch('actualizar_ruta.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ ruta: opts.rutaNueva })
    });
  }

  const params = new URLSearchParams({
    tipo: filtros.tipo,
    buscar: filtros.buscar,
    fecha_inicio: filtros.fecha_inicio,
    fecha_fin: filtros.fecha_fin,
    limite: filtros.limite,
    pagina
  });

  const res  = await fetch('bloque_archivos.php?' + params.toString(), { credentials: 'same-origin' });
  const html = await res.text();
  const cont = document.getElementById('bloque-archivos');
  if (cont) cont.innerHTML = html;

  // Reinit del bloque si hace falta (players, tooltips, etc.)
}

/** (Opcional) Refresca el árbol/lista de carpetas si lo tienes separado */
async function actualizarBloqueCarpetas() {
  const res  = await fetch('bloque_carpetas.php', { credentials: 'same-origin' });
  const html = await res.text();
  const cont = document.getElementById('bloque-carpetas');
  if (cont) cont.innerHTML = html;
}

/** Delegación de eventos: paginación dentro de #bloque-archivos */
document.addEventListener('click', (ev) => {
  const a = ev.target.closest('a.page-link[data-pagina]');
  if (a && a.closest('#bloque-archivos')) {
    ev.preventDefault();
    const pagina = parseInt(a.dataset.pagina, 10) || 1;
    actualizarBloqueArchivos({ pagina });
  }
});

/** Intercepta cambios del selector de "limite" para recargar a página 1 */
document.addEventListener('change', (ev) => {
  const sel = ev.target.closest('#formLimite select[name="limite"]');
  if (sel) {
    actualizarBloqueArchivos({ pagina: 1 });
  }
});

/** Intercepta envío del form de filtros para recargar a página 1 */
document.addEventListener('submit', (ev) => {
  const f = ev.target.closest('#formFiltros');
  if (f) {
    ev.preventDefault();
    actualizarBloqueArchivos({ pagina: 1 });
  }
});
