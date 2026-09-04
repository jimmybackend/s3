class CarpetasModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    /* carpetas.js (unificado)
       Basado ESTRICTAMENTE en tu código compartido:
       - Contenedor árbol:  #bloque-carpetas  (incluye #arbolCarpetas y .folder-row/.folder-item/.toggle/.children)
       - Contenedor archivos: #bloque-archivos
       - Endpoints usados en tus JS: actualizar_ruta.php, bloque_archivos.php, bloque_carpetas.php,
                                    listar_carpetas.php, mover_carpeta.php, renombrar_carpeta.php, eliminar_carpeta.php
       - Modales/IDs: #modalMoverCarpeta, #formMoverCarpeta, #moverOrigen, #moverNombre, #moverOrigenLabel,
                     #moverDestino, #moverPreview, #btnMoverCarpeta
                     #modalRenombrar, #renombrarRuta, #nombreActual, #nuevoNombre
                     #modalEliminarCarpeta, #formEliminarCarpeta, #eliminarRuta, #eliminarNombre, #eliminarConfirm, #btnEliminarAceptar
                     #formCrearCarpeta (POST a s3.php en tu HTML)
    */

    (function (window, document) {
      'use strict';

      /* ---------------- Config (solo cosas que existen en tu código) ---------------- */
      const CFG = {
        ids: {
          bloqueCarpetas: 'bloque-carpetas',
          arbolCarpetas: 'arbolCarpetas',
          bloqueArchivos: 'bloque-archivos',

          
          
            formCrearCarpeta: 'formCrearCarpeta',
            modalCrearCarpeta: 'modalCrearCarpeta',
            crearCarpetaRuta: 'crearCarpetaRuta',
            crearCarpetaRutaTexto: 'crearCarpetaRutaTexto',
            crearCarpetaNombre: 'crearCarpetaNombre',
            btnCrearCarpeta: 'btnCrearCarpeta',

          modalMoverCarpeta: 'modalMoverCarpeta',
          formMoverCarpeta: 'formMoverCarpeta',
          moverOrigen: 'moverOrigen',
          moverNombre: 'moverNombre',
          moverOrigenLabel: 'moverOrigenLabel',
          moverDestino: 'moverDestino',
          moverPreview: 'moverPreview',
          btnMoverCarpeta: 'btnMoverCarpeta',

          modalRenombrar: 'modalRenombrar',
          renombrarRuta: 'renombrarRuta',
          nombreActual: 'nombreActual',
          nuevoNombre: 'nuevoNombre',

          modalEliminarCarpeta: 'modalEliminarCarpeta',
          formEliminarCarpeta: 'formEliminarCarpeta',
          eliminarRuta: 'eliminarRuta',
          eliminarNombre: 'eliminarNombre',
          eliminarConfirm: 'eliminarConfirm',
          btnEliminarAceptar: 'btnEliminarAceptar',
          nuevaRutaSelect: 'nuevaRutaSelect',
          campoNuevaCarpeta: 'campoNuevaCarpeta',
          
        },
        urls: {
          bloqueArchivos: 'bloque_archivos.php',
          bloqueCarpetas: 'bloque_carpetas.php',

          listarCarpetas: 'listar_carpetas.php',
          moverCarpeta: 'mover_carpeta.php',
          renombrarCarpeta: 'renombrar_carpeta.php',
          eliminarCarpeta: 'eliminar_carpeta.php',
          crearCarpeta: 'crear_carpeta.php',
        }
      };

      /* ---------------- Estado ---------------- */
      let ultimaRutaAbierta = '';
      let creandoCarpeta = false;

      /* ---------------- Helpers seguros ---------------- */
      const $id = (id) => document.getElementById(id);

      function nowBuster() {
        return String(Date.now()) + String(Math.floor(Math.random() * 1000));
      }

      function fetchNoCache(url, options) {
        const opt = options || {};
        opt.credentials = opt.credentials || 'same-origin';
        opt.cache = 'no-store';

        // cache-buster por querystring (evita “requiere F5” cuando el server/proxy cachea)
        const u = new URL(url, window.location.href);
        u.searchParams.set('_', nowBuster());
        return fetch(u.toString(), opt);
      }

      function toFormUrlEncoded(obj) {
        const body = new URLSearchParams();
        Object.keys(obj || {}).forEach(k => body.append(k, obj[k]));
        return body;
      }

      function closestOrNull(el, selector) {
        try { return el && el.closest ? el.closest(selector) : null; } catch (_) { return null; }
      }

      function getRutaFromElement(el) {
        if (!el) return '';
        const ruta =
          el.getAttribute('data-route') ||
          el.getAttribute('data-ruta')  ||
          el.getAttribute('data-prefix') ||
          el.getAttribute('data-ruta-actual') ||
          el.getAttribute('data-key') ||
          el.getAttribute('data-path') ||
          '';
        return String(ruta || '').trim();
      }

      function baseNameOf(prefix) {
        const p = String(prefix || '').replace(/\/+$/, '');
        const i = p.lastIndexOf('/');
        return (i >= 0 ? p.slice(i + 1) : p);
      }

      function parentPrefixOf(prefix) {
        let p = String(prefix || '').replace(/\/+$/, '');
        const i = p.lastIndexOf('/');
        return (i >= 0) ? (p.slice(0, i + 1)) : '';
      }

      function safeHideModal(modalEl) {
        if (!modalEl) return;

        // Bootstrap 5 (vanilla)
        if (window.bootstrap && window.bootstrap.Modal) {
          try {
            const inst = window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
            inst.hide();
            return;
          } catch (_) {}
        }

        // Bootstrap 4 (jQuery)
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          try { window.jQuery(modalEl).modal('hide'); } catch (_) {}
        }
      }

      function safeSetText(el, txt) {
        if (!el) return;
        el.textContent = (txt == null ? '' : String(txt));
      }

      // Ejecuta scripts <script> inline dentro de un contenedor recargado por AJAX.
      // Necesario porque innerHTML NO ejecuta scripts; en F5 sí se ejecutan.
      function runInlineScripts(container) {
        if (!container) return;
        const scripts = container.querySelectorAll('script');
        scripts.forEach((oldScript) => {
          const t = (oldScript.getAttribute('type') || 'text/javascript').toLowerCase();

          // Evita ejecutar scripts de tipo data/template/json
          if (t.includes('json') || t.includes('ld+json') || t.includes('template') || t.includes('plain') || t.includes('x-tmpl')) return;

          const newScript = document.createElement('script');
          Array.from(oldScript.attributes).forEach((attr) => {
            if (attr && attr.name && attr.value != null) newScript.setAttribute(attr.name, attr.value);
          });
          if (!oldScript.src) newScript.textContent = oldScript.textContent || '';
          oldScript.parentNode.replaceChild(newScript, oldScript);
        });
      }


      function marcarSeleccionEnArbol(prefix) {
        const bloque = $id(CFG.ids.bloqueCarpetas) || document;
        try {
          // Tu HTML marca activo con "a.folder.active"
          bloque.querySelectorAll('a.folder.active').forEach(a => a.classList.remove('active'));
          if (prefix) {
            const a = bloque.querySelector(`a.folder[data-route="${CSS.escape(prefix)}"], a.folder[data-ruta="${CSS.escape(prefix)}"]`);
            if (a) a.classList.add('active');
          }
        } catch (_) {}
      }

      async function refrescarBloqueArchivosCompat(params) {
        // Siempre refrescamos por fetch aquí para evitar depender de otras funciones/globales
        // (esto evita el caso donde el bloque se actualiza pero NO se ejecutan scripts/miniaturas hasta F5).

        // 1) traer HTML del bloque directo y reemplazar

        const cont = $id(CFG.ids.bloqueArchivos);
        if (!cont) return;

        const qs = new URLSearchParams(params || {});
        if (!qs.has('pagina')) qs.set('pagina', '1');

        const html = await fetchNoCache(CFG.urls.bloqueArchivos + '?' + qs.toString(), {
          method: 'GET'
        }).then(r => r.text());

        cont.innerHTML = html;


        // ✅ CLAVE: ejecutar scripts inline que vengan en bloque_archivos.php
        runInlineScripts(cont);

        // Disparar eventos (compat con archivos.js)
        try { document.dispatchEvent(new Event('bloque-archivos:actualizado')); } catch (_) {}
        try { document.dispatchEvent(new Event('bloque-archivos:updated')); } catch (_) {}

        // Si tienes binder adicional para seguridad, respétalo (tu s3.php lo hace en DOMContentLoaded, pero acá es recarga AJAX)
        if (typeof window.bindBloqueArchivosSeguridad === 'function') {
          try { window.bindBloqueArchivosSeguridad(cont); } catch (_) {}
        }
      }

      async function refrescarBloqueCarpetasCompat(opts) {
      opts = opts || {};

      const actual = $id(CFG.ids.bloqueCarpetas);
      if (!actual) return false;

      const url = new URL(CFG.urls.bloqueCarpetas, window.location.href);

      if (opts && opts.ruta_actual) {
        url.searchParams.set('ruta_actual', String(opts.ruta_actual).trim());
      }

      url.searchParams.set('_', nowBuster());

      const res = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (!res.ok) {
        throw new Error('No se pudo refrescar bloque_carpetas.php (HTTP ' + res.status + ')');
      }

      const html = await res.text();

      const temp = document.createElement('div');
      temp.innerHTML = html;

      const nuevo = temp.querySelector('#' + CFG.ids.bloqueCarpetas);
      if (!nuevo) {
        throw new Error('La respuesta de bloque_carpetas.php no contiene #' + CFG.ids.bloqueCarpetas);
      }

      actual.replaceWith(nuevo);

      runInlineScripts(nuevo);

      try { document.dispatchEvent(new Event('bloque-carpetas:actualizado')); } catch (_) {}
      try { document.dispatchEvent(new Event('bloque-carpetas:updated')); } catch (_) {}

      return true;
    }

    window.actualizarBloqueCarpetas = async function actualizarBloqueCarpetas(opts) {
      try {
        return await refrescarBloqueCarpetasCompat(opts || {});
      } catch (err) {
        console.error('[actualizarBloqueCarpetas] error:', err);
        return false;
      }
    };

      function leerFiltrosActualesDelDOM() {
        const f = $id('formFiltros');
        const L = $id('formLimite');

        return {
          buscar:       f && f.buscar ? (f.buscar.value || '') : '',
          fecha_inicio: f && f.fecha_inicio ? (f.fecha_inicio.value || '') : '',
          fecha_fin:    f && f.fecha_fin ? (f.fecha_fin.value || '') : '',
          tipo:         f && f.tipo ? (f.tipo.value || '') : '',
          limite:       (L && L.querySelector) ? (L.querySelector('select[name="limite"]')?.value ?? 5) : 5,
          pagina:       1
        };
      }

      /* ---------------- API principal: abrir carpeta (1 click) ---------------- */
      async function abrirRuta(ruta, force = false) {
      ruta = String(ruta || '').trim();
      if (!ruta) return;

      if (!force && ruta === ultimaRutaAbierta) return;
      ultimaRutaAbierta = ruta;

      try { if (window.imagenesGaleria) window.imagenesGaleria = []; } catch (_) {}

      window.rutaActual = ruta;

      marcarSeleccionEnArbol(ruta);

      const filtros = leerFiltrosActualesDelDOM();
      filtros.ruta = ruta;

      await refrescarBloqueArchivosCompat(filtros);

      if (typeof window.actualizarBloqueFooter === 'function') {
        try { window.actualizarBloqueFooter({ ruta: ruta, rutaNueva: ruta, pagina: 1 }); } catch (_) {}
      }
    }

      /* ---------------- Click handler (delegation) para árbol ---------------- */
      async function onClickArbol(e) {
        const bloque = $id(CFG.ids.bloqueCarpetas) || document;

        // No interferir con botones de acciones (Mover/Renombrar/Eliminar) ni con data-toggle=modal
        if (closestOrNull(e.target, '.btn-group') || closestOrNull(e.target, '[data-toggle="modal"]') || closestOrNull(e.target, '[data-bs-toggle="modal"]')) {
          return;
        }

        // Toggle expand/collapse (tu bloque_carpetas.php ya trae uno inline, pero aquí lo hacemos robusto
        // y NO truena si faltan nodos)
        const toggle = closestOrNull(e.target, '.toggle');
        if (toggle && !toggle.classList.contains('empty')) {
          const li = closestOrNull(toggle, '.folder-item');
          const cont = li ? li.querySelector(':scope > .children') : null;
          const prefix = li ? (li.getAttribute('data-prefix') || '') : '';

          if (cont) {
            const visible = (getComputedStyle(cont).display !== 'none');

            // Toggle visual inmediato
            cont.style.display = visible ? 'none' : 'block';
            toggle.textContent = visible ? '+' : '−';

            // ✅ Si estamos EXPANDIENDO y el contenedor está vacío (o sin <li>),
            // refrescamos el bloque de carpetas para que cargue los hijos sin necesidad de F5.
            if (!visible) {
              const hasAnyLi = cont.querySelector('li.folder-item');
              const hasAnyUl = cont.querySelector('ul');
              const hasContent = !!(hasAnyLi || hasAnyUl || (cont.textContent || '').trim());

              if (!hasContent) {
                try {
                  await refrescarBloqueCarpetasCompat();

                  // Reabrir el mismo nodo tras refrescar
                  const bloque = $id(CFG.ids.bloqueCarpetas) || document;
                  const li2 = prefix
                    ? bloque.querySelector('.folder-item[data-prefix="' + CSS.escape(prefix) + '"]')
                    : null;

                  if (li2) {
                    const cont2 = li2.querySelector(':scope > .children');
                    const tog2  = li2.querySelector(':scope > .folder-row .toggle');
                    if (cont2) cont2.style.display = 'block';
                    if (tog2 && !tog2.classList.contains('empty')) tog2.textContent = '−';
                  }
                } catch (err) {
                  console.warn('[carpetas.js] No se pudo refrescar el árbol al expandir:', err);
                }
              }
            }
          }
          return;
        }

        // Click en carpeta (1 click abre)
        const aFolder = closestOrNull(e.target, 'a.folder');
        if (!aFolder) return;

        // Si el click fue en iconos internos ok, pero prevenimos navegación
        e.preventDefault();

        // ✅ Si viene de una secuencia de doble click, el dblclick handler se encargará
        if (e.detail && e.detail >= 2) return;

        const ruta = getRutaFromElement(aFolder);
        abrirRuta(ruta).catch(err => console.error('[carpetas.js] abrirRuta error:', err));
      }

      /* ---------------- Modal: mover carpeta ---------------- */
      async function cargarDestinosMover(origen) {
        const res = await fetchNoCache(CFG.urls.listarCarpetas, { method: 'GET' });
        const j = await res.json().catch(() => ({}));
        if (!j.ok || !Array.isArray(j.carpetas)) throw new Error(j.error || 'No se pudieron cargar las carpetas.');

        // tu mover-carpeta.js re-ordenaba con localeCompare
        j.carpetas.sort((a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' }));
        return j.carpetas;
      }

      function renderOptionsMover(selectEl, lista, origen) {
        if (!selectEl) return;

        selectEl.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '— Selecciona —';
        selectEl.appendChild(opt0);

        const ban = String(origen || '');

        (lista || []).forEach((ruta) => {
          ruta = String(ruta || '');
          // No permitir mover dentro de sí misma o hijos
          if (!ruta) return;
          if (ruta === ban || ruta.startsWith(ban)) return;

          const opt = document.createElement('option');
          opt.value = ruta;

          // Indent como tu script (aprox por profundidad de "/")
          const depth = Math.max(0, ((ruta.match(/\//g) || []).length - 1));
          opt.innerHTML = '&nbsp;'.repeat(depth * 3) + ruta;

          selectEl.appendChild(opt);
        });
      }

      function updatePreviewMover(origen, destino, previewEl) {
        if (!previewEl) return;
        const org = String(origen || '').trim();
        const dst = String(destino || '').trim();
        const bn = baseNameOf(org);
        safeSetText(previewEl, dst ? (dst + bn + '/') : '');
      }

      async function onShowMoverModal(evt) {
        const modal = $id(CFG.ids.modalMoverCarpeta);
        if (!modal) return;

        // relatedTarget (Bootstrap) o fallback
        const btn = evt && evt.relatedTarget ? evt.relatedTarget : null;

        const origen = getRutaFromElement(btn) || (btn ? (btn.getAttribute('data-route') || btn.getAttribute('data-ruta') || '') : '');
        const nombre = (btn && (btn.getAttribute('data-name') || btn.getAttribute('data-nombre'))) || baseNameOf(origen);

        const $origen  = $id(CFG.ids.moverOrigen);
        const $nombre  = $id(CFG.ids.moverNombre);
        const $label   = $id(CFG.ids.moverOrigenLabel);
        const $destino = $id(CFG.ids.moverDestino);
        const $preview = $id(CFG.ids.moverPreview);

        if ($origen) $origen.value = origen || '';
        if ($nombre) $nombre.value = nombre || '';
        safeSetText($label, origen || '');
        if ($destino) $destino.innerHTML = '<option value="">Cargando…</option>';
        safeSetText($preview, '');

        try {
          const lista = await cargarDestinosMover(origen || '');
          renderOptionsMover($destino, lista, origen || '');

          // Selecciona por defecto el padre (igual que tu mover-carpeta.js)
          const parent = parentPrefixOf(origen || '');
          if (parent && $destino) {
            const opt = $destino.querySelector(`option[value="${CSS.escape(parent)}"]`);
            if (opt) $destino.value = parent;
          }
        } catch (e) {
          console.error(e);
          if ($destino) $destino.innerHTML = '<option value="">(Error cargando carpetas)</option>';
        } finally {
          updatePreviewMover(origen, $destino ? $destino.value : '', $preview);
        }
      }

    async function onClickMover() {
      const modal = $id(CFG.ids.modalMoverCarpeta);
      const $origen  = $id(CFG.ids.moverOrigen);
      const $destino = $id(CFG.ids.moverDestino);
      const $btn     = $id(CFG.ids.btnMoverCarpeta);

      const origen  = ($origen && $origen.value) ? String($origen.value).trim() : '';
      const destino = ($destino && $destino.value) ? String($destino.value).trim() : '';

      if (!origen) {
        alert('Falta la carpeta de origen.');
        return;
      }

      if (!destino) {
        alert('Selecciona una carpeta de destino.');
        return;
      }

      // Previene mover dentro de sí misma o dentro de una subcarpeta
      const base = baseNameOf(origen);
      const toPrefix = destino.replace(/\/?$/, '/') + base + '/';

      if (toPrefix === origen || toPrefix.startsWith(origen)) {
        alert('No puedes mover la carpeta dentro de sí misma o sus subcarpetas.');
        return;
      }

      let oldHtml = null;

      try {
        if ($btn) {
          oldHtml = $btn.innerHTML;
          $btn.disabled = true;
          $btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Moviendo…';
        }

        const res = await fetchNoCache(CFG.urls.moverCarpeta, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: toFormUrlEncoded({ origen, destino })
        });

        const j = await res.json().catch(() => ({}));

        if (!res.ok || !j || !j.ok) {
          throw new Error((j && j.error) ? j.error : 'No se pudo mover la carpeta.');
        }

        const rutaActualizada = String(j.ruta_actual || window.rutaActual || '').trim();

        if (rutaActualizada) {
          window.rutaActual = rutaActualizada;
        }

        safeHideModal(modal);

        await refrescarBloqueCarpetasCompat({
          ruta_actual: rutaActualizada
        });

        await refrescarBloqueArchivosCompat({
          pagina: 1,
          ruta: rutaActualizada || undefined
        });

        if (typeof window.actualizarBloqueFooter === 'function') {
          try {
            await window.actualizarBloqueFooter({
              pagina: 1,
              ruta: rutaActualizada,
              rutaNueva: rutaActualizada
            });
          } catch (_) {}
        }

      } catch (err) {
        console.error(err);
        alert('❌ ' + (err && err.message ? err.message : err));
      } finally {
        if ($btn) {
          $btn.disabled = false;
          if (oldHtml != null) $btn.innerHTML = oldHtml;
        }
      }
    }

      /* ---------------- Modal: renombrar carpeta ---------------- */
      function onShowRenombrarModal(evt) {
        const modal = $id(CFG.ids.modalRenombrar);
        if (!modal) return;

        const btn = evt && evt.relatedTarget ? evt.relatedTarget : null;

        // tu renombrar-carpeta.js usa data-actual + data-nombre
        const ruta = btn ? (btn.getAttribute('data-actual') || '') : '';
        const nombre = btn ? (btn.getAttribute('data-nombre') || '') : '';

        const $ruta  = $id(CFG.ids.renombrarRuta);
        const $act   = $id(CFG.ids.nombreActual);
        const $nuevo = $id(CFG.ids.nuevoNombre);

        if ($ruta)  $ruta.value = ruta || '';
        if ($act)   $act.value = nombre || '';
        if ($nuevo) $nuevo.value = nombre || '';

        // focus seguro
        setTimeout(() => {
          const input = $id(CFG.ids.nuevoNombre);
          if (input) {
            try {
              input.focus();
              const v = input.value || '';
              input.setSelectionRange(v.length, v.length);
            } catch (_) {}
          }
        }, 120);
      }

    async function onSubmitRenombrar(e) {
      const modal = $id(CFG.ids.modalRenombrar);
      if (!modal) return;

      // Solo si el submit viene del form dentro de #modalRenombrar
      const form = closestOrNull(e.target, '#modalRenombrar form');
      if (!form) return;

      e.preventDefault();

      const ruta = $id(CFG.ids.renombrarRuta)?.value || '';
      const nuevo = ($id(CFG.ids.nuevoNombre)?.value || '').trim();
      if (!ruta || !nuevo) return;

      const btn = form.querySelector('[type="submit"]');
      const old = btn ? btn.textContent : null;

      try {
        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Renombrando…';
        }

        let rutaActualizada = '';

        // Preferimos el endpoint AJAX usado por tu JS actual.
        // Si no existe o falla, hacemos fallback al action del form (tu s3.php lo soporta).
        let res = await fetchNoCache(CFG.urls.renombrarCarpeta, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: toFormUrlEncoded({ ruta, nuevo })
        });

        let j = await res.json().catch(() => null);

        if (!res.ok || !j) {
          // fallback: POST normal al action del form (s3.php)
          const action = form.getAttribute('action') || window.location.href;
          const fd = new FormData(form);

          res = await fetchNoCache(action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });

          if (!res.ok) {
            throw new Error('No se pudo renombrar la carpeta (HTTP ' + res.status + ').');
          }

          rutaActualizada = String(window.rutaActual || ruta).trim();
        } else if (j.ok !== true) {
          throw new Error(j.error || 'No se pudo renombrar la carpeta.');
        } else {
          rutaActualizada = String(j.ruta_actual || window.rutaActual || ruta).trim();
        }

        if (rutaActualizada) {
          window.rutaActual = rutaActualizada;
        }

        safeHideModal(modal);

        await refrescarBloqueCarpetasCompat({
          ruta_actual: rutaActualizada
        });

        await refrescarBloqueArchivosCompat({
          pagina: 1,
          ruta: rutaActualizada || undefined
        });

        if (typeof window.actualizarBloqueFooter === 'function') {
          try {
            await window.actualizarBloqueFooter({
              pagina: 1,
              ruta: rutaActualizada,
              rutaNueva: rutaActualizada
            });
          } catch (_) {}
        }

      } catch (err) {
        console.error(err);
        alert(err && err.message ? err.message : 'Error al renombrar carpeta.');
      } finally {
        if (btn) {
          btn.disabled = false;
          if (old != null) btn.textContent = old;
        }
      }
    }


    function toggleCampoNuevaCarpeta(selectEl) {
      if (!selectEl) return;
      const campo = $id(CFG.ids.campoNuevaCarpeta);
      if (!campo) return;

      // Evita el error "Cannot read properties of null (reading 'style')"
      campo.style.display = (selectEl.value === '__crear__') ? 'block' : 'none';
    }

    function initNuevaRutaSelect(scope) {
      // scope opcional por si algún día quieres buscar dentro de un modal específico
      const select = $id(CFG.ids.nuevaRutaSelect);
      if (!select) return;

      toggleCampoNuevaCarpeta(select);
    }

      /* ---------------- Modal: eliminar carpeta ---------------- */
      function onShowEliminarModal(evt) {
        const modal = $id(CFG.ids.modalEliminarCarpeta);
        if (!modal) return;

        const btn = evt && evt.relatedTarget ? evt.relatedTarget : null;

        const ruta = btn ? (btn.getAttribute('data-route') || '') : '';
        const nombre = btn ? (btn.getAttribute('data-name') || '') : '';

        const $ruta = $id(CFG.ids.eliminarRuta);
        const $nom  = $id(CFG.ids.eliminarNombre);
        const $conf = $id(CFG.ids.eliminarConfirm);
        const $ok   = $id(CFG.ids.btnEliminarAceptar);

        if ($ruta) $ruta.value = ruta || '';
        safeSetText($nom, nombre || '');
        if ($conf) $conf.value = '';
        if ($ok) $ok.disabled = true;

        setTimeout(() => { try { $conf && $conf.focus(); } catch (_) {} }, 150);
      }

      function onInputEliminarConfirm(e) {
        const inp = e.target;
        if (!inp || inp.id !== CFG.ids.eliminarConfirm) return;
        const ok = (String(inp.value || '').trim().toLowerCase() === 'eliminar');
        const btn = $id(CFG.ids.btnEliminarAceptar);
        if (btn) btn.disabled = !ok;
      }

    async function onSubmitEliminar(e) {
      const form = e.target;
      if (!form || form.id !== CFG.ids.formEliminarCarpeta) return;

      e.preventDefault();

      const ruta = $id(CFG.ids.eliminarRuta)?.value || '';
      if (!ruta) return;

      const btn = $id(CFG.ids.btnEliminarAceptar);
      const old = btn ? btn.textContent : null;

      try {
        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Eliminando…';
        }

        const res = await fetchNoCache(CFG.urls.eliminarCarpeta, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: toFormUrlEncoded({ ruta })
        });

        const j = await res.json().catch(() => ({}));

        if (!res.ok || !j || !j.ok) {
          throw new Error((j && j.error) ? j.error : 'No se pudo eliminar la carpeta.');
        }

        const rutaActualizada = String(j.ruta_actual || window.rutaActual || '').trim();

        if (rutaActualizada) {
          window.rutaActual = rutaActualizada;
        }

        safeHideModal($id(CFG.ids.modalEliminarCarpeta));

        await refrescarBloqueCarpetasCompat({
          ruta_actual: rutaActualizada
        });

        await refrescarBloqueArchivosCompat({
          pagina: 1,
          ruta: rutaActualizada || undefined
        });

        if (typeof window.actualizarBloqueFooter === 'function') {
          try {
            await window.actualizarBloqueFooter({
              pagina: 1,
              ruta: rutaActualizada,
              rutaNueva: rutaActualizada
            });
          } catch (_) {}
        }

      } catch (err) {
        console.error(err);
        alert(err && err.message ? err.message : 'Error al eliminar carpeta.');
      } finally {
        if (btn) {
          btn.disabled = false;
          if (old != null) btn.textContent = old;
        }
      }
    }

      /* ---------------- Crear carpeta (formCrearCarpeta) ---------------- */
      function onShowCrearModal(evt) {
      const modal = $id(CFG.ids.modalCrearCarpeta);
      if (!modal) return;

      const btn = evt && evt.relatedTarget ? evt.relatedTarget : null;
      const ruta = getRutaFromElement(btn) || '';

      const inputRuta = $id(CFG.ids.crearCarpetaRuta);
      const textoRuta = $id(CFG.ids.crearCarpetaRutaTexto);
      const inputNombre = $id(CFG.ids.crearCarpetaNombre);

      if (inputRuta) {
        inputRuta.value = ruta || inputRuta.value || '';
      }

      safeSetText(textoRuta, ruta || (inputRuta ? inputRuta.value : '') || '');

      if (inputNombre) {
        inputNombre.value = '';
        setTimeout(function () {
          try { inputNombre.focus(); } catch (_) {}
        }, 150);
      }
    }
     // ====== Crear carpeta (formCrearCarpeta) ======

    async function onSubmitCrearCarpeta(e) {
      const form = e.target;
      if (!form || form.id !== CFG.ids.formCrearCarpeta) return;

      e.preventDefault();
      if (creandoCarpeta) return;
      creandoCarpeta = true;

      const fd = new FormData(form);
      const nueva = String(fd.get('nueva') || '').trim();
      const ruta = String(fd.get('ruta') || '').trim();

      if (!nueva) {
        creandoCarpeta = false;
        alert('Debes escribir el nombre de la carpeta.');
        return;
      }

      if (!ruta) {
        creandoCarpeta = false;
        alert('No se encontró la ruta destino.');
        return;
      }

      const btn = $id(CFG.ids.btnCrearCarpeta) || form.querySelector('button[type="submit"], [type="submit"]');
      const oldHtml = btn ? btn.innerHTML : '';

      try {
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creando...';
        }

        const res = await fetchNoCache(CFG.urls.crearCarpeta, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        const j = await res.json().catch(() => null);

        if (!res.ok || !j) {
          throw new Error('Respuesta inválida creando carpeta.');
        }

        if (!j.ok) {
          throw new Error(j.error || 'No se pudo crear la carpeta.');
        }

        const rutaActualizada = String(j.ruta_actual || ruta).trim();

        form.reset();

        const inputRuta = $id(CFG.ids.crearCarpetaRuta);
        if (inputRuta) {
          inputRuta.value = rutaActualizada;
        }

        const rutaTexto = $id(CFG.ids.crearCarpetaRutaTexto);
        if (rutaTexto) {
          rutaTexto.textContent = rutaActualizada;
        }

        if (rutaActualizada) {
          window.rutaActual = rutaActualizada;
        }

        safeHideModal($id(CFG.ids.modalCrearCarpeta));

        await refrescarBloqueCarpetasCompat({
          ruta_actual: rutaActualizada
        });

        await refrescarBloqueArchivosCompat({
          pagina: 1,
          ruta: rutaActualizada
        });

        if (typeof window.actualizarBloqueFooter === 'function') {
          try {
            await window.actualizarBloqueFooter({
              pagina: 1,
              ruta: rutaActualizada,
              rutaNueva: rutaActualizada
            });
          } catch (_) {}
        }

      } catch (err) {
        console.error(err);
        alert(err && err.message ? err.message : 'Error al crear carpeta.');
      } finally {
        creandoCarpeta = false;

        if (btn) {
          btn.disabled = false;
          btn.innerHTML = oldHtml;
        }
      }
    }


    /* ---------------- Eventos Bootstrap show.bs.modal (sin depender de jQuery) ---------------- */
    function bindModalEvents() {
      document.addEventListener('show.bs.modal', function (evt) {
        const modal = evt.target;
        if (!modal || !modal.id) return;

        if (modal.id === CFG.ids.modalCrearCarpeta) onShowCrearModal(evt);
        if (modal.id === CFG.ids.modalMoverCarpeta) onShowMoverModal(evt);
        if (modal.id === CFG.ids.modalRenombrar) onShowRenombrarModal(evt);
        if (modal.id === CFG.ids.modalEliminarCarpeta) onShowEliminarModal(evt);
      }, true);

      document.addEventListener('shown.bs.modal', function () {
        initNuevaRutaSelect();
      }, true);

      if (window.jQuery) {
        try {
          window.jQuery('#' + CFG.ids.modalCrearCarpeta)
            .off('show.bs.modal.carpetasjs')
            .on('show.bs.modal.carpetasjs', onShowCrearModal);

          window.jQuery('#' + CFG.ids.modalMoverCarpeta)
            .off('show.bs.modal.carpetasjs')
            .on('show.bs.modal.carpetasjs', onShowMoverModal);

          window.jQuery('#' + CFG.ids.modalRenombrar)
            .off('show.bs.modal.carpetasjs')
            .on('show.bs.modal.carpetasjs', onShowRenombrarModal);

          window.jQuery('#' + CFG.ids.modalEliminarCarpeta)
            .off('show.bs.modal.carpetasjs')
            .on('show.bs.modal.carpetasjs', onShowEliminarModal);

          window.jQuery(document)
            .off('shown.bs.modal.carpetasjs')
            .on('shown.bs.modal.carpetasjs', function () {
              initNuevaRutaSelect();
            });

        } catch (_) {}
      }
    }
       
      /* ---------------- Bind global (delegation) ---------------- */
    function bindDelegationGlobal() {
      // Árbol carpetas
      document.addEventListener('click', function (e) {
        const inBloque = closestOrNull(e.target, '#' + CFG.ids.bloqueCarpetas);
        if (!inBloque) return;
        onClickArbol(e).catch(err => console.error('[carpetas.js] onClickArbol error:', err));
      }, true);


      // ✅ Doble click en carpeta: FORZAR recarga de bloque archivos (sin F5)
      document.addEventListener('dblclick', function (e) {
        const inBloque = closestOrNull(e.target, '#' + CFG.ids.bloqueCarpetas);
        if (!inBloque) return;

        const aFolder = closestOrNull(e.target, 'a.folder');
        if (!aFolder) return;

        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();

        const ruta = getRutaFromElement(aFolder);
        abrirRuta(ruta, true).catch(err => console.error('[carpetas.js] abrirRuta(dblclick) error:', err));
      }, true);

      // Mover carpeta (button)
      document.addEventListener('click', function (e) {
        const btn = e.target && (e.target.id === CFG.ids.btnMoverCarpeta
          ? e.target
          : closestOrNull(e.target, '#' + CFG.ids.btnMoverCarpeta));
        if (!btn) return;
        e.preventDefault();
        onClickMover().catch(err => console.error('[carpetas.js] mover error:', err));
      }, true);

      // Change destino -> preview (mover carpeta)
      document.addEventListener('change', function (e) {
        if (!e.target || e.target.id !== CFG.ids.moverDestino) return;
        const origen = $id(CFG.ids.moverOrigen)?.value || '';
        const destino = e.target.value || '';
        updatePreviewMover(origen, destino, $id(CFG.ids.moverPreview));
      }, true);

      // ✅ Change nuevaRutaSelect -> mostrar/ocultar campo
      document.addEventListener('change', function (e) {
        if (!e.target || e.target.id !== CFG.ids.nuevaRutaSelect) return;
        toggleCampoNuevaCarpeta(e.target);
      }, true);

      // ✅ UN SOLO submit handler
      document.addEventListener('submit', function (e) {
        // crear carpeta
        if (e.target && e.target.id === CFG.ids.formCrearCarpeta) {
          onSubmitCrearCarpeta(e);
          return;
        }

        // renombrar
        if (closestOrNull(e.target, '#modalRenombrar form')) {
          onSubmitRenombrar(e).catch(err => console.error('[carpetas.js] renombrar error:', err));
          return;
        }

        // eliminar
        if (e.target && e.target.id === CFG.ids.formEliminarCarpeta) {
          onSubmitEliminar(e).catch(err => console.error('[carpetas.js] eliminar error:', err));
          return;
        }
      }, true);

      // Eliminar confirm input
      document.addEventListener('input', onInputEliminarConfirm, true);

      // Evita submit por Enter en mover
      document.addEventListener('keydown', function (e) {
        const form = closestOrNull(e.target, '#' + CFG.ids.formMoverCarpeta);
        if (!form) return;
        if (e.key === 'Enter') e.preventDefault();
      }, true);
    }

      /* ---------------- Compatibilidad: exponer funciones públicas ---------------- */
      // Para tu HTML: ondblclick="cargarCarpetas('...')"
      async function cargarCarpetasCompat(ruta, force = false) {
        // Firma: cargarCarpetas(ruta) o cargarCarpetas(ruta,true)
        return abrirRuta(ruta, force);
      }

      // Para compat con bindArbolCarpetas.js si alguien lo usaba
      window.__bindArbolCarpetas = window.__bindArbolCarpetas || {};
      window.__bindArbolCarpetas.abrirRuta = abrirRuta;

      // API pública principal
      window.cargarCarpetas = cargarCarpetasCompat;

      // Inicialización inmediata (no depende de DOMContentLoaded)
      bindDelegationGlobal();
      bindModalEvents();

    })(window, document);
    if (typeof toggleCampoNuevaCarpeta === 'function' && typeof window.toggleCampoNuevaCarpeta !== 'function') window.toggleCampoNuevaCarpeta = toggleCampoNuevaCarpeta;
    if (typeof initNuevaRutaSelect === 'function' && typeof window.initNuevaRutaSelect !== 'function') window.initNuevaRutaSelect = initNuevaRutaSelect;
    if (typeof bindModalEvents === 'function' && typeof window.bindModalEvents !== 'function') window.bindModalEvents = bindModalEvents;
    if (typeof bindDelegationGlobal === 'function' && typeof window.bindDelegationGlobal !== 'function') window.bindDelegationGlobal = bindDelegationGlobal;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new CarpetasModule(win, doc).init();
    win.ArcadeCloudDrive.modules['carpetas'] = instance;
    return instance;
  }
}

CarpetasModule.boot();
