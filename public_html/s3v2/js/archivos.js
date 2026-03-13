/* archivos.js
   Unifica TODO lo relacionado a archivos (y añade: modalRenombrar + seguridad_archivos)

   - Refresh AJAX del bloque de archivos (anti-cache + re-ejecuta scripts inline)
   - Renombrar archivo (modal + submit vía fetch)
   - Mover múltiples (modal + selección + fetch)
   - Encriptar archivo (botón .btn-encriptar)
   - Compartir (token + copiar + regenerar por días)
   - Búsqueda global (buscar_archivo.php + navegar sin refrescar + resaltar)
   - Seguridad de archivos (set_file_security.php / relock_file.php) por fila y por lote
   - Modal renombrar carpeta (#modalRenombrar) (lo que hacía modalRenombrar.js)

   Requisitos:
   - Event delegation (robusto con recargas AJAX)
   - No depender de DOMContentLoaded
   - Sin jQuery obligatorio (fallback si existe)
*/
(function (window, document) {
  'use strict';

  if (window.__archivosJSBound) return;
  window.__archivosJSBound = true;

  // =========================
  // Constantes (solo lo que YA existe en tus JS)
  // =========================
  const ID_BLOQUE_ARCHIVOS = 'bloque-archivos';
  const URL_BLOQUE_ARCHIVOS = 'bloque_archivos.php';

  const URL_RENOMBRAR = 'renombrar_archivo.php';
  const URL_MOVER = 'mover_archivo.php';
  const URL_ENCRIPTAR = 'encriptar_archivo.php';

  const URL_BUSCAR = 'buscar_archivo.php';
  const URL_SET_RUTA = 'actualizar_ruta.php';
  const URL_FOOTER = 'bloque_footer.php';

  const URLS_GENERAR_TOKEN = ['generar_token.php', '../generar_token.php'];

  // Seguridad
  const URL_SET_FILE_SECURITY = 'set_file_security.php';
  const URL_RELOCK_FILE = 'relock_file.php';

  // =========================
  // Helpers DOM / Utils
  // =========================
  const qs = (sel, root) => (root || document).querySelector(sel);
  const qsa = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const closest = (el, sel) => (el && el.closest ? el.closest(sel) : null);

  function safeText(s) { return String(s == null ? '' : s); }

  window.escapeHtml = window.escapeHtml || function (s) {
    return safeText(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  function cssEscape(val) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(val);
    return safeText(val).replace(/["\\]/g, '\\$&');
  }

  function dispatch(name) {
    try { document.dispatchEvent(new Event(name)); } catch (_) {}
  }

  function runInlineScripts(container) {
    if (!container) return;
    const scripts = container.querySelectorAll('script');
    scripts.forEach((oldScript) => {
      const t = (oldScript.getAttribute('type') || 'text/javascript').toLowerCase();
      const isDataType =
        t.includes('json') ||
        t.includes('ld+json') ||
        t.includes('template') ||
        t.includes('plain') ||
        t.includes('x-tmpl');
      if (isDataType) return;

      const newScript = document.createElement('script');
      Array.from(oldScript.attributes).forEach((attr) => {
        if (attr && attr.name && attr.value != null) newScript.setAttribute(attr.name, attr.value);
      });
      if (!oldScript.src) newScript.textContent = oldScript.textContent || '';
      oldScript.parentNode.replaceChild(newScript, oldScript);
    });
  }

  function showModal(modalElOrId) {
    const el = (typeof modalElOrId === 'string') ? document.getElementById(modalElOrId) : modalElOrId;
    if (!el) return false;

    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
          const inst = window.bootstrap.Modal.getOrCreateInstance(el);
          inst.show();
          return true;
        }
        const inst = new window.bootstrap.Modal(el);
        inst.show();
        return true;
      } catch (_) {}
    }

    if (window.jQuery && window.$) {
      const $el = window.$(el);
      if ($el && typeof $el.modal === 'function') {
        $el.modal('show');
        return true;
      }
    }

    el.classList.add('show');
    el.style.display = 'block';
    el.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');
    return true;
  }

  function hideModal(modalElOrId) {
    const el = (typeof modalElOrId === 'string') ? document.getElementById(modalElOrId) : modalElOrId;
    if (!el) return false;

    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
          const inst = window.bootstrap.Modal.getOrCreateInstance(el);
          inst.hide();
          return true;
        }
        if (typeof window.bootstrap.Modal.getInstance === 'function') {
          const inst = window.bootstrap.Modal.getInstance(el);
          if (inst) {
            inst.hide();
            return true;
          }
        }
        const inst = new window.bootstrap.Modal(el);
        inst.hide();
        return true;
      } catch (_) {}
    }

    if (window.jQuery && window.$) {
      const $el = window.$(el);
      if ($el && typeof $el.modal === 'function') {
        $el.modal('hide');
        return true;
      }
    }

    el.classList.remove('show');
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');

    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop && backdrop.parentNode) {
      backdrop.parentNode.removeChild(backdrop);
    }

    return true;
  }

  async function fetchText(url, opts) {
    const res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }, opts || {}));
    return { res, text: await res.text() };
  }

  async function fetchJson(url, opts) {
    const res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }, opts || {}));
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    return { res, text, json };
  }

  function urlParams(obj) {
  const usp = new URLSearchParams();

  if (!obj) {
    return usp;
  }

  // Ya viene como URLSearchParams
  if (obj instanceof URLSearchParams) {
    obj.forEach((value, key) => {
      if (value !== undefined && value !== null) {
        usp.set(key, String(value));
      }
    });
    return usp;
  }

  // Ya viene como URL completa
  if (obj instanceof URL) {
    obj.searchParams.forEach((value, key) => {
      if (value !== undefined && value !== null) {
        usp.set(key, String(value));
      }
    });
    return usp;
  }

  // Viene como FormData
  if (obj instanceof FormData) {
    obj.forEach((value, key) => {
      if (value !== undefined && value !== null) {
        usp.set(key, String(value));
      }
    });
    return usp;
  }

  // Viene como string: "?pagina=2&limite=50"
  if (typeof obj === 'string') {
    return new URLSearchParams(obj);
  }

  // Objeto plano
  if (typeof obj === 'object') {
    Object.keys(obj).forEach((k) => {
      const v = obj[k];
      if (v !== undefined && v !== null) {
        usp.set(k, String(v));
      }
    });
  }

  return usp;
}

  function setBtnLoading(btn, on, htmlLoading) {
    if (!btn) return;
    if (on) {
      btn.dataset._oldHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = htmlLoading || '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Procesando...';
    } else {
      btn.disabled = false;
      if (btn.dataset._oldHtml) btn.innerHTML = btn.dataset._oldHtml;
    }
  }

  // =========================
  // 1) Refresh del bloque de archivos (anti-cache) + SOPORTA opts (seguridad)
  // =========================
  async function actualizarBloqueArchivos(params = null, opts = null) {
    const cont = document.getElementById(ID_BLOQUE_ARCHIVOS);
    if (!cont) {
      console.warn(`[actualizarBloqueArchivos] No existe #${ID_BLOQUE_ARCHIVOS}`);
      return;
    }

    try {
      // Compat:
      // - actualizarBloqueArchivos({..})
      // - actualizarBloqueArchivos(null, {..})  <-- esto lo usa seguridad_archivos.js
      let merged = null;

      // params puede venir como URLSearchParams desde la paginación del bloque
      if (params instanceof URLSearchParams) {
        merged = urlParams(params);
      } else if (params && typeof params === 'object') {
        merged = Object.assign({}, params);
      } else if (typeof params === 'string') {
        merged = urlParams(params);
      } else {
        merged = {};
      }

      if (opts instanceof URLSearchParams) {
        const tmp = urlParams(merged);
        for (const [k, v] of opts.entries()) tmp.set(k, v);
        merged = tmp;
      } else if (opts && typeof opts === 'object') {
        if (merged instanceof URLSearchParams) {
          Object.keys(opts).forEach((k) => {
            const v = opts[k];
            if (v !== undefined && v !== null) merged.set(k, String(v));
          });
        } else {
          merged = Object.assign(merged, opts);
        }
      }

      const usp = urlParams(merged);

      if (!usp.get('ruta')) {
        const rutaCtx =
          document.getElementById('archivosContexto')?.dataset?.rutaActual ||
          window.rutaActual ||
          document.getElementById('formFiltros')?.querySelector('[name="ruta"]')?.value ||
          '';
        if (rutaCtx) usp.set('ruta', String(rutaCtx));
      }

      usp.set('_', Date.now().toString()); // anti-cache SIEMPRE

      const url = URL_BLOQUE_ARCHIVOS + '?' + usp.toString();
      const { res, text } = await fetchText(url, { method: 'GET', cache: 'no-store' });

      if (!res.ok) {
        console.error(`[actualizarBloqueArchivos] Error HTTP ${res.status}`);
        return;
      }

      cont.innerHTML = text;
      runInlineScripts(cont);

      if (window.FiltroUI && typeof window.FiltroUI._toggleBtnQuitar === 'function') {
        try { window.FiltroUI._toggleBtnQuitar(); } catch (_) {}
      }

      dispatch('bloque-archivos:actualizado');
      dispatch('bloque-archivos:updated');
    } catch (err) {
      console.error('[actualizarBloqueArchivos] Excepción al refrescar:', err);
    }
  }
  
  window.actualizarBloqueArchivos = window.actualizarBloqueArchivos || actualizarBloqueArchivos;

  // Fallbacks opcionales
  window.actualizarBloqueCarpetas = window.actualizarBloqueCarpetas || (async function () { /* noop */ });
  window.actualizarBloqueFooter = window.actualizarBloqueFooter || (async function (args) {
    const cont = document.getElementById('bloque-footer');
    if (!cont) return;
    try {
      const usp = urlParams(args || {});
      usp.set('_', Date.now().toString());
      const url = URL_FOOTER + '?' + usp.toString();
      const { res, text } = await fetchText(url, { method: 'GET', cache: 'no-store' });
      if (res.ok) cont.innerHTML = text;
    } catch (e) {
      console.error('[actualizarBloqueFooter] error:', e);
    }
  });

  // =========================
  // 2) MODAL RENOMBRAR CARPETA (#modalRenombrar) — integra modalRenombrar.js
  // =========================
  document.addEventListener('show.bs.modal', function (e) {
    const modal = e.target;
    if (!modal || modal.id !== 'modalRenombrar') return;

    const btn = e.relatedTarget;
    const actual = btn?.getAttribute('data-actual') || btn?.dataset?.actual || '';
    const nombre = btn?.getAttribute('data-nombre') || btn?.dataset?.nombre || '';

    const inActual = document.getElementById('nombreActual');
    const inNuevo = document.getElementById('nuevoNombre');

    if (inActual) inActual.value = actual;
    if (inNuevo) inNuevo.value = nombre;
  }, true);

  // =========================
  // 3) Resaltar archivo (compat)
  // =========================
  window.resaltarArchivo = window.resaltarArchivo || function (key) {
    if (!key) return;
    const nombre = safeText(key).split('/').pop();
    const cont = document.getElementById(ID_BLOQUE_ARCHIVOS);
    if (!cont) return;

    let el =
      cont.querySelector(`[data-key="${cssEscape(key)}"]`) ||
      cont.querySelector(`[data-key*="${cssEscape(nombre)}"]`);

    if (!el) {
      const filas = cont.querySelectorAll('tr, .ap-row, .list-group-item, li, .card, .media, .row');
      for (const f of filas) {
        if ((f.textContent || '').includes(nombre)) { el = f; break; }
      }
    }
    if (!el) return;

    const fila = el.closest('tr, .ap-row, .list-group-item, li, .card, .media, .row') || el;
    fila.classList.add('destacado-s3');
    try { fila.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
    setTimeout(() => fila.classList.remove('destacado-s3'), 4000);
  };

  // =========================
  // 4) Renombrar archivo (modalRenombrarArchivo + submit AJAX)
  // =========================
  let __ultimoTriggerRenombrarArchivo = null;

  /**
   * ============================================================
   * FUNCTION: llenarModalRenombrarArchivo
   * ============================================================
   * DESCRIPCIÓN:
   * Carga en el modal de renombrar la key S3 y el nombre visible del
   * archivo recibido desde el botón que abrió el modal.
   * ============================================================
   */
  function llenarModalRenombrarArchivo(triggerButton) {
    const btn = triggerButton || __ultimoTriggerRenombrarArchivo;
    if (!btn) return;

    const key = (btn.getAttribute('data-key') || btn.dataset?.key || '').trim();
    const nombre = (btn.getAttribute('data-nombre') || btn.dataset?.nombre || '').trim();

    const form = document.getElementById('formRenombrarArchivo');
    if (!form) return;

    const inKey = document.getElementById('renameFileKey') || form.querySelector('input[name="key"]');
    const inAct = document.getElementById('nombreActual') || form.querySelector('input[name="nombre_actual"]');
    const inNew = document.getElementById('nuevoNombreArchivo') || form.querySelector('input[name="nombre_nuevo"]');
    const archivoActualLegacy = document.getElementById('archivoActual');

    if (inKey) inKey.value = key;
    if (archivoActualLegacy) archivoActualLegacy.value = key;

    if (inAct) inAct.value = nombre;
    if (inNew) inNew.value = nombre;

    if (inNew) {
      setTimeout(() => {
        try {
          inNew.focus();
          inNew.setSelectionRange(nombre.length, nombre.length);
        } catch (_) {}
      }, 150);
    }
  }

  /**
   * ============================================================
   * FUNCTION: abrirModalRenombrarArchivo
   * ============================================================
   * DESCRIPCIÓN:
   * Abre el modal de renombrar archivo de forma directa y asegura que
   * la key y el nombre actual queden cargados antes de mostrarlo.
   * ============================================================
   */
  window.abrirModalRenombrarArchivo = function (key, nombre) {
    const fakeBtn = document.createElement('button');
    fakeBtn.setAttribute('data-key', (key || '').trim());
    fakeBtn.setAttribute('data-nombre', (nombre || '').trim());
    __ultimoTriggerRenombrarArchivo = fakeBtn;
    llenarModalRenombrarArchivo(fakeBtn);
    showModal('modalRenombrarArchivo');
  };

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '[data-target="#modalRenombrarArchivo"],[data-bs-target="#modalRenombrarArchivo"]');
    if (!btn) return;
    __ultimoTriggerRenombrarArchivo = btn;
    try { llenarModalRenombrarArchivo(btn); } catch (_) {}
  }, true);

  document.addEventListener('show.bs.modal', function (e) {
  const modal = e.target;
  if (!modal || modal.id !== 'modalMover') return;

  const jsonInput = document.getElementById('archivosJson') || qs('#archivosJson', modal);
  const valorActual = (jsonInput?.value || '').trim();

  if (!valorActual) {
    const seleccionados = seleccionActual(document);

    if (!seleccionados.length) {
      alert('Selecciona al menos un archivo para mover.');
      setTimeout(() => hideModal(modal), 0);
      return;
    }

    if (jsonInput) {
      jsonInput.value = JSON.stringify(seleccionados);
    }
  }

  const selDst = document.getElementById('nuevaRutaSelect') || qs('#nuevaRutaSelect', modal);
  if (selDst) toggleCampoNuevaCarpeta(selDst);
}, true);

  document.addEventListener('submit', function (e) {
    const form = document.getElementById('formRenombrarArchivo');
    if (form && e.target === form) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      return false;
    }
  }, true);

  document.addEventListener('keydown', function (e) {
    const form = document.getElementById('formRenombrarArchivo');
    if (!form) return;
    if (e.target && form.contains(e.target) && e.key === 'Enter') e.preventDefault();
  }, true);

  async function renombrarArchivoAjax() {
    const form = document.getElementById('formRenombrarArchivo');
    if (!form) return;

    const key = (
      document.getElementById('renameFileKey')?.value ||
      form.querySelector('input[name="key"]')?.value ||
      document.getElementById('archivoActual')?.value ||
      ''
    ).trim();

    const nombreNuevo = (
      document.getElementById('nuevoNombreArchivo')?.value ||
      form.querySelector('input[name="nombre_nuevo"]')?.value ||
      ''
    ).trim();

    if (!key) { alert('Falta la clave del archivo.'); return; }
    if (!nombreNuevo) { alert('Debes escribir el nuevo nombre.'); return; }

    const btn = document.getElementById('btnRenombrarGuardar') || form.querySelector('[data-action="renombrar-guardar"]');

    try {
      setBtnLoading(btn, true, '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...');
      const fd = new FormData();
      fd.append('key', key);
      fd.append('nombre_nuevo', nombreNuevo);

      const { res, json, text } = await fetchJson(URL_RENOMBRAR, { method: 'POST', body: fd });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      if (!json) throw new Error(text || 'Respuesta inválida');
      if (json.estado !== 'ok') throw new Error(json.mensaje || 'No se pudo renombrar');

      hideModal('modalRenombrarArchivo');
      await window.actualizarBloqueArchivos();
    } catch (err) {
      console.error(err);
      alert('❌ ' + (err.message || err));
    } finally {
      setBtnLoading(btn, false);
    }
  }

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '#btnRenombrarGuardar');
    if (!btn) return;
    e.preventDefault();
    renombrarArchivoAjax();
  });

  // =========================
  // 5) Mover archivos (múltiple)
  // =========================
  function seleccionActual(root) {
  const base = root || document;
  return Array.from(base.querySelectorAll('input[name="archivos[]"]:checked'))
    .map(cb => cb.value)
    .filter(Boolean);
}

  function toggleCampoNuevaCarpeta(selectEl) {
    const wrap = document.getElementById('campoNuevaCarpeta');
    if (!wrap || !selectEl) return;
    wrap.style.display = (selectEl.value === '__crear__') ? '' : 'none';
  }

  async function moverArchivosAjax() {
  const formModal = document.getElementById('formMoverArchivos');
  const btnMover = document.getElementById('btnMoverArchivos');

  if (!formModal) return;

  const rutaActual = (formModal.querySelector('input[name="ruta_actual"]')?.value || '').trim();
  let archivosJSON = (formModal.querySelector('input[name="archivos_json"]')?.value || '').trim();

  const selDst = document.getElementById('nuevaRutaSelect') || formModal.querySelector('#nuevaRutaSelect');
  let nuevaRuta = (selDst?.value || '').trim();
  const nuevaCarp = (formModal.querySelector('input[name="nueva_carpeta"]')?.value || '').trim();

  if (!archivosJSON) {
    const sel = seleccionActual(document);
    if (!sel.length) {
      alert('No hay archivos seleccionados.');
      return;
    }
    archivosJSON = JSON.stringify(sel);
  }

  if (!nuevaRuta) {
    alert('Selecciona la carpeta de destino.');
    return;
  }

  if (nuevaRuta === '__crear__') {
    if (!nuevaCarp) {
      alert('Escribe el nombre de la nueva carpeta.');
      return;
    }
    if (/[\\/]/.test(nuevaCarp)) {
      alert('El nombre no debe contener "/" ni "\\".');
      return;
    }

    nuevaRuta = (rutaActual.replace(/\/?$/, '/')) + nuevaCarp.replace(/^\/+|\/+$/g, '') + '/';
  }

  try {
    setBtnLoading(btnMover, true, '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Moviendo...');

    const body = new URLSearchParams({
      ruta_actual: rutaActual,
      archivos_json: archivosJSON,
      nueva_ruta: nuevaRuta
    });

    const { res, json, text } = await fetchJson(URL_MOVER, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });

    if (!json) throw new Error(text || ('HTTP ' + res.status));
    if (!res.ok) throw new Error(json.error || json.mensaje || ('HTTP ' + res.status));
    if (!json.ok) throw new Error(json.error || json.mensaje || 'No se pudo mover.');

    hideModal('modalMover');

    document.querySelectorAll('input[name="archivos[]"]:checked').forEach(cb => {
      cb.checked = false;
    });

    const selectAllA = document.getElementById('selectAll');
    const selectAllB = document.getElementById('checkAllFiles');

    if (selectAllA) selectAllA.checked = false;
    if (selectAllB) selectAllB.checked = false;

    const inputJson = formModal.querySelector('input[name="archivos_json"]');
    if (inputJson) inputJson.value = '';

    if (typeof window.actualizarBloqueCarpetas === 'function') {
      await window.actualizarBloqueCarpetas();
    }

    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ pagina: 1 });
    }
  } catch (err) {
    console.error(err);
    alert('❌ ' + (err.message || err));
  } finally {
    setBtnLoading(btnMover, false);
  }
}
window.abrirModalMover = window.abrirModalMover || function (key) {
  const archivoKey = String(key || '').trim();
  if (!archivoKey) {
    alert('Falta la clave del archivo.');
    return false;
  }

  const modal = document.getElementById('modalMover');
  if (!modal) {
    alert('No se encontró el modal de mover.');
    return false;
  }

  const jsonInput = document.getElementById('archivosJson') || modal.querySelector('#archivosJson') || modal.querySelector('input[name="archivos_json"]');
  if (jsonInput) {
    jsonInput.value = JSON.stringify([archivoKey]);
  }

  const selDst = document.getElementById('nuevaRutaSelect') || modal.querySelector('#nuevaRutaSelect');
  if (selDst) {
    selDst.value = '';
    toggleCampoNuevaCarpeta(selDst);
  }

  const nuevaCarpeta = modal.querySelector('input[name="nueva_carpeta"]');
  if (nuevaCarpeta) {
    nuevaCarpeta.value = '';
  }

  showModal(modal);
  return false;
};

  document.addEventListener('change', function (e) {
  const selectAll = closest(e.target, '#selectAll, #checkAllFiles');
  if (!selectAll) return;
  toggleAll(selectAll);
});

  document.addEventListener('submit', function (e) {
    const formModal = document.getElementById('formMoverArchivos');
    if (formModal && e.target === formModal) { e.preventDefault(); return false; }
  }, true);

  document.addEventListener('keydown', function (e) {
    const formModal = document.getElementById('formMoverArchivos');
    if (formModal && e.target && formModal.contains(e.target) && e.key === 'Enter') e.preventDefault();
  }, true);

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '#btnMoverArchivos');
    if (!btn) return;
    e.preventDefault();
    moverArchivosAjax();
  });

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '.js-move-one');
    if (!btn) return;

    e.preventDefault();
    const key = (btn.getAttribute('data-key') || btn.dataset?.key || '').trim();
    window.abrirModalMover(key);
  });

  // =========================
  // 6) Encriptar archivo
  // =========================
  async function postEncriptar(key) {
    const { res, json, text } = await fetchJson(URL_ENCRIPTAR, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key })
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    if (!json) throw new Error(text || 'Respuesta inválida');
    if (json.estado !== 'ok') throw new Error(json.mensaje || 'Error en encriptar');
    return json;
  }

  async function refreshBloqueArchivosBasic() {
    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos();
      return;
    }
    const cont = document.getElementById(ID_BLOQUE_ARCHIVOS);
    if (!cont) return;
    const url = URL_BLOQUE_ARCHIVOS + '?_=' + Date.now();
    const { res, text } = await fetchText(url, { method: 'GET' });
    if (res.ok) {
      cont.innerHTML = text;
      runInlineScripts(cont);
    }
  }

  document.addEventListener('click', async function (e) {
    const btn = closest(e.target, '.btn-encriptar');
    if (!btn) return;

    e.preventDefault();
    const key = btn.getAttribute('data-key') || '';
    const nombre = btn.getAttribute('data-nombre') || key;
    if (!key) return;

    if (!confirm(`Esto encriptará (renombrará) el archivo:\n\n${nombre}\n\nLos enlaces previos dejarán de funcionar.\n¿Continuar?`)) return;

    try {
      setBtnLoading(btn, true, '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Encriptando...');
      await postEncriptar(key);
      alert('✔ Archivo encriptado correctamente.');
      await refreshBloqueArchivosBasic();
    } catch (err) {
      console.error(err);
      alert('❌ ' + (err.message || err));
    } finally {
      setBtnLoading(btn, false);
    }
  });

  window.encriptarArchivo = window.encriptarArchivo || async function (key) {
    if (!confirm('Esto encriptará el archivo.\n¿Continuar?')) return;
    const fakeBtn = document.querySelector(`.btn-encriptar[data-key="${cssEscape(key)}"]`) || null;
    try {
      setBtnLoading(fakeBtn, true, '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Encriptando...');
      await postEncriptar(key);
      alert('✔ Archivo encriptado correctamente.');
      await refreshBloqueArchivosBasic();
    } catch (err) {
      console.error(err);
      alert('❌ ' + (err.message || err));
    } finally {
      setBtnLoading(fakeBtn, false);
    }
  };

  // =========================
  // 7) Compartir archivo
  // =========================
  window.__shareContext = window.__shareContext || null;

  function endOfDay(d) { d.setHours(23, 59, 59, 999); return d; }

  function calcularExpiraLabel(dias) {
    dias = Math.max(1, parseInt(dias || '1', 10));
    const fin = endOfDay(new Date());
    fin.setDate(fin.getDate() + (dias - 1));
    try {
      return new Intl.DateTimeFormat(undefined, {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
      }).format(fin);
    } catch (_) {
      return fin.toISOString();
    }
  }

  function tipoPorExt(ext) {
    ext = (ext || '').toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return 'imagen';
    if (['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'ogg', 'opus', 'm4a', 'flac', 'amr', 'webm'].includes(ext)) return 'audio';
    return 'otro';
  }

  function setShareStatus(msg, ok) {
    const s = document.getElementById('copyStatus');
    if (!s) return;
    s.textContent = msg;
    s.classList.toggle('text-success', !!ok);
    s.classList.toggle('text-danger', !ok);
    s.style.opacity = 1;
    setTimeout(() => { s.style.opacity = 0; }, ok ? 1200 : 2000);
  }

  async function postToken(url, data) {
    return fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data)
    });
  }

  async function generarLink(payload) {
    let lastErr = null;
    for (const u of URLS_GENERAR_TOKEN) {
      try {
        const { res, json, text } = await postToken(u, payload);
        if (res.ok && json) return { res, json, text, url: u };
        lastErr = new Error(text || ('Respuesta inválida en ' + u));
      } catch (e) {
        lastErr = e;
      }
    }
    throw lastErr || new Error('No se pudo contactar generar_token.php');
  }

  window.copiarEnlace = window.copiarEnlace || async function () {
    const input = document.getElementById('enlaceCompartido');
    if (!input) return false;
    const texto = input.value || '';

    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(texto);
      } else {
        input.removeAttribute('readonly');
        input.select(); input.setSelectionRange(0, 99999);
        document.execCommand('copy');
        input.setAttribute('readonly', 'readonly');
        if (window.getSelection) window.getSelection().removeAllRanges();
      }
      setShareStatus('Enlace copiado', true);
    } catch (_) {
      setShareStatus('No se pudo copiar', false);
    }
    return false;
  };

  let regenTimer = null;
  async function regenIfContext() {
    const ctx = window.__shareContext;
    const inp = document.getElementById('diasCompartir');
    if (!ctx || !ctx.key || !ctx.tipo || !inp) return;

    clearTimeout(regenTimer);
    regenTimer = setTimeout(async () => {
      const dias = Math.max(1, parseInt(inp.value || '1', 10));
      try {
        const { json } = await generarLink({ archivo: ctx.key, tipo: ctx.tipo, dias });
        if (!json || json.estado !== 'ok') { setShareStatus('No se pudo regenerar el enlace', false); return; }

        const input = document.getElementById('enlaceCompartido');
        if (input) input.value = json.url || '';

        const lbl = document.getElementById('fechaExpiraLabel');
        if (lbl) lbl.textContent = calcularExpiraLabel(dias);

        setShareStatus('Enlace actualizado', true);
      } catch (err) {
        setShareStatus('Error de red al regenerar', false);
        console.error(err);
      }
    }, 450);
  }

  window.share_onDiasInput = window.share_onDiasInput || function () {
    const inp = document.getElementById('diasCompartir');
    const lbl = document.getElementById('fechaExpiraLabel');
    if (inp && lbl) lbl.textContent = calcularExpiraLabel(inp.value);
    regenIfContext();
    return false;
  };

  window.compartirArchivo = window.compartirArchivo || function (key, ext) {
    const inpDias = document.getElementById('diasCompartir');
    const dias = Math.max(1, parseInt((inpDias?.value || '1'), 10));
    const tipo = tipoPorExt(ext);

    window.__shareContext = { key, tipo };

    generarLink({ archivo: key, tipo, dias })
      .then(({ json }) => {
        if (!json || json.estado !== 'ok') throw new Error(json?.mensaje || 'Respuesta inválida');

        const input = document.getElementById('enlaceCompartido');
        if (input) input.value = json.url || '';

        const lbl = document.getElementById('fechaExpiraLabel');
        if (lbl) lbl.textContent = calcularExpiraLabel(dias);

        const modalEl = document.getElementById('modalCompartir');
        if (modalEl) {
          if (!showModal(modalEl)) alert(input?.value || 'Enlace generado');
        } else {
          alert(input?.value || 'Enlace generado');
        }
      })
      .catch((err) => {
        console.error('compartirArchivo:', err);
        alert('No se pudo generar el enlace.\n' + (err.message || err));
      });

    return false;
  };
  
  
  window.cerrarModalCompartir = function () {
  var el = document.getElementById('modalCompartir');
  if (!el) return false;

  try {
    if (window.bootstrap && window.bootstrap.Modal) {
      var inst = window.bootstrap.Modal.getOrCreateInstance
        ? window.bootstrap.Modal.getOrCreateInstance(el)
        : new window.bootstrap.Modal(el);
      inst.hide();
      return false;
    }

    if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery(el).modal('hide');
      return false;
    }
  } catch (e) {
    console.error(e);
  }

  el.style.display = 'none';
  el.classList.remove('show');
  el.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('modal-open');

  document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
    bd.remove();
  });

  return false;
};

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '#btnCopyLink');
    if (!btn) return;
    e.preventDefault();
    window.copiarEnlace();
  });

  document.addEventListener('input', function (e) {
    const inp = closest(e.target, '#diasCompartir');
    if (!inp) return;
    window.share_onDiasInput();
  });

  document.addEventListener('show.bs.modal', function (e) {
    const modal = e.target;
    if (!modal || modal.id !== 'modalCompartir') return;
    const inp = document.getElementById('diasCompartir');
    const lbl = document.getElementById('fechaExpiraLabel');
    if (inp && lbl) lbl.textContent = calcularExpiraLabel(inp.value);
  }, true);
  
  

  // =========================
  // 8) Búsqueda global + abrir carpeta sin refrescar
  // =========================
  async function setRutaSesion(ruta) {
    if (!ruta) return;
    const body = new URLSearchParams({ ruta: ruta, rutaNueva: ruta });
    const { res } = await fetchJson(URL_SET_RUTA, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
  }

  async function abrirCarpetaSinRefresco(opts) {
    const ruta = (opts && opts.ruta) || '';
    const key = (opts && opts.key) || '';
    const args = { pagina: 1, ruta: ruta, rutaNueva: ruta };

    await setRutaSesion(ruta);

    if (typeof window.actualizarBloqueArchivos === 'function') await window.actualizarBloqueArchivos(args);
    if (typeof window.actualizarBloqueCarpetas === 'function') await window.actualizarBloqueCarpetas();
    if (typeof window.actualizarBloqueFooter === 'function') await window.actualizarBloqueFooter(args);

    hideModal('modalBusquedaGlobal');

    if (key) window.resaltarArchivo(key);
  }

  async function buscarArchivos(termino) {
    const body = new URLSearchParams({ termino: termino });
    const { res, json, text } = await fetchJson(URL_BUSCAR, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    if (!json) throw new Error(text || 'Respuesta inválida');
    return json;
  }

  function renderResultadosBusqueda(arr) {
    const $result = document.getElementById('resultadosBusqueda');
    if (!$result) return;

    if (!Array.isArray(arr) || !arr.length) {
      $result.innerHTML = '<div class="alert alert-warning mb-0">No se encontraron archivos con ese criterio.</div>';
      return;
    }

    let html = '<ul class="list-group">';
    arr.forEach(function (it) {
      const nombre = window.escapeHtml(it.nombre || '');
      const ruta = it.ruta || '';
      const key = it.key || '';
      const tamKB = (it.tamano_kb != null) ? ` | ${it.tamano_kb} KB` : '';
      html += `
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <strong>${nombre}</strong><br>
            <small class="text-muted">${window.escapeHtml(ruta)}${tamKB}</small>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary btn-ir" data-ruta="${window.escapeHtml(ruta)}" data-key="${window.escapeHtml(key)}">
            <i class="fas fa-folder-open"></i> Ir
          </button>
        </li>`;
    });
    html += '</ul>';
    $result.innerHTML = html;
  }

  document.addEventListener('submit', function (e) {
    const form = closest(e.target, '#formBusquedaGlobal');
    if (!form) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    const input = document.getElementById('terminoBusqueda');
    const $result = document.getElementById('resultadosBusqueda');
    const termino = (input?.value || '').trim();

    if (!termino) { alert('Ingrese un término de búsqueda.'); return false; }

    if ($result) $result.innerHTML = '<p class="mb-0"><i class="fas fa-spinner fa-spin"></i> Buscando...</p>';

    buscarArchivos(termino)
      .then((resp) => {
        if (resp && resp.estado === 'ok') {
          const arr = Array.isArray(resp.resultados) ? resp.resultados : [];
          renderResultadosBusqueda(arr);
        } else {
          if ($result) {
            $result.innerHTML = '<div class="alert alert-danger mb-0">' +
              window.escapeHtml(resp && resp.mensaje || 'Ocurrió un error al buscar.') +
              '</div>';
          }
        }
      })
      .catch((err) => {
        console.error(err);
        if ($result) $result.innerHTML = '<div class="alert alert-danger mb-0">Ocurrió un error al buscar.</div>';
      });

    return false;
  }, true);

  document.addEventListener('keydown', function (e) {
    const input = closest(e.target, '#terminoBusqueda');
    if (!input) return;
    if (e.key === 'Enter') {
      e.preventDefault();
      const form = document.getElementById('formBusquedaGlobal');
      if (form) {
        try { form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); } catch (_) {}
      }
      return false;
    }
  }, true);

  document.addEventListener('click', function (e) {
    const btn = closest(e.target, '#resultadosBusqueda .btn-ir');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    const ruta = btn.getAttribute('data-ruta') || '';
    const key = btn.getAttribute('data-key') || '';

    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('disabled');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    abrirCarpetaSinRefresco({ ruta, key })
      .catch((err) => { console.error(err); alert('No se pudo abrir la carpeta por AJAX.'); })
      .finally(() => {
        btn.disabled = false;
        btn.classList.remove('disabled');
        btn.innerHTML = oldHtml;
      });
  });

  // =========================
  // 9) Seguridad de archivos
  function normalizeKey(key) {
    key = String(key || '').trim().replace(/\\/g, '/');
    key = key.replace(/\/+/g, '/');
    return key.replace(/^\/+/, '');
  }

  function parseKey(key) {
    key = normalizeKey(key);
    if (!key) return { ruta: '', enc: '' };
    const pos = key.lastIndexOf('/');
    if (pos === -1) return { ruta: '', enc: key };
    return {
      ruta: key.substring(0, pos + 1),
      enc: key
    };
  }

    function ensureSecurityModal() {
    let modal = document.getElementById('securityFileModal');

    if (modal && modal.querySelector('#secModalTitle')) {
      return modal;
    }

    // Compatibilidad: si existe un modal viejo o incompleto, lo quitamos
    const oldModal = document.getElementById('fileSecurityModal');
    if (oldModal) {
      try { oldModal.remove(); } catch (_) {}
    }
    if (modal && !modal.querySelector('#secModalTitle')) {
      try { modal.remove(); } catch (_) {}
    }

    const html = `
      <div class="modal fade" id="securityFileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content security-modal-content">
            <div class="modal-header">
              <h5 id="secModalTitle" class="modal-title">Seguridad del archivo</h5>
              <button type="button" class="btn-close" aria-label="Cerrar" data-sec-close></button>
            </div>
            <div class="modal-body">
              <div class="security-help" id="secModalText"></div>

              <div class="security-help">
                Archivo: <strong class="security-file-name" id="secFileName">—</strong>
              </div>

              <div class="security-hint-box" id="secHintBox" style="display:none;"></div>

              <div class="mb-3" id="secPasswordField">
                <label for="secPasswordInput" class="form-label">Contraseña</label>
                <div class="input-group">
                  <input type="password" id="secPasswordInput" class="form-control" autocomplete="new-password">
                  <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secPasswordInput">Ver</button>
                </div>
              </div>

              <div class="mb-3" id="secConfirmField" style="display:none;">
                <label for="secConfirmInput" class="form-label">Confirmar contraseña</label>
                <div class="input-group">
                  <input type="password" id="secConfirmInput" class="form-control" autocomplete="new-password">
                  <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secConfirmInput">Ver</button>
                </div>
              </div>

              <div class="mb-3" id="secHintField" style="display:none;">
                <label for="secHintInput" class="form-label">Pista para recordar</label>
                <input type="text" id="secHintInput" class="form-control" maxlength="255" placeholder="Opcional">
              </div>

              <label class="sec-check-row" id="secShowAllRow" style="display:none;">
                <input type="checkbox" id="secShowAllCheckbox"> Mostrar contraseñas
              </label>

              <div class="security-error" id="secModalError"></div>
            </div>
            <div class="modal-footer">
              <div class="security-actions w-100">
                <button type="button" class="btn btn-secondary" data-sec-cancel>Cancelar</button>
                <button type="button" class="btn btn-primary" data-sec-accept>Aceptar</button>
              </div>
            </div>
          </div>
        </div>
      </div>`;

    const wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    modal = wrap.firstElementChild;
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeSecurityModal(null);
        return;
      }

      const toggleBtn = e.target.closest('[data-sec-toggle]');
      if (toggleBtn) {
        const id = toggleBtn.getAttribute('data-sec-toggle');
        const input = document.getElementById(id);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        toggleBtn.textContent = input.type === 'password' ? 'Ver' : 'Ocultar';
        return;
      }

      const closeBtn = e.target.closest('[data-sec-close],[data-sec-cancel]');
      if (closeBtn) {
        closeSecurityModal(null);
      }
    });

    const showAll = document.getElementById('secShowAllCheckbox');
    if (showAll) {
      showAll.addEventListener('change', function () {
        ['secPasswordInput', 'secConfirmInput'].forEach(function (id) {
          const input = document.getElementById(id);
          if (!input) return;
          input.type = showAll.checked ? 'text' : 'password';
          const btn = modal.querySelector('[data-sec-toggle="' + id + '"]');
          if (btn) btn.textContent = showAll.checked ? 'Ocultar' : 'Ver';
        });
      });
    }

    return modal;
  }

  let secModalResolver = null;

  function closeSecurityModal(result) {
    const modal = document.getElementById('securityFileModal');
    if (!modal) return;

    let closed = false;

    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        const inst = typeof window.bootstrap.Modal.getOrCreateInstance === 'function'
          ? window.bootstrap.Modal.getOrCreateInstance(modal)
          : new window.bootstrap.Modal(modal);
        inst.hide();
        closed = true;
      } catch (_) {}
    }

    if (!closed && window.jQuery && typeof window.jQuery === 'function') {
      try {
        const $m = window.jQuery(modal);
        if ($m && typeof $m.modal === 'function') {
          $m.modal('hide');
          closed = true;
        }
      } catch (_) {}
    }

    if (!closed) {
      modal.classList.remove('show');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
      document.querySelectorAll('.modal-backdrop').forEach(function (bd) { bd.remove(); });
    }

    if (secModalResolver) {
      const resolve = secModalResolver;
      secModalResolver = null;
      resolve(result);
    }
  }

  function openSecurityModal(opts) {
    const modal = ensureSecurityModal();

    let bsModal = null;
    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        bsModal = typeof window.bootstrap.Modal.getOrCreateInstance === 'function'
          ? window.bootstrap.Modal.getOrCreateInstance(modal)
          : new window.bootstrap.Modal(modal);
      } catch (_) {
        bsModal = null;
      }
    }

    const title = modal.querySelector('#secModalTitle');
    const text = modal.querySelector('#secModalText');
    const error = modal.querySelector('#secModalError');
    const pwdField = modal.querySelector('#secPasswordField');
    const confirmField = modal.querySelector('#secConfirmField');
    const hintField = modal.querySelector('#secHintField');
    const hintBox = modal.querySelector('#secHintBox');
    const showAllRow = modal.querySelector('#secShowAllRow');
    const pwd = modal.querySelector('#secPasswordInput');
    const conf = modal.querySelector('#secConfirmInput');
    const hint = modal.querySelector('#secHintInput');
    const accept = modal.querySelector('[data-sec-accept]');
    const cancel = modal.querySelector('[data-sec-cancel]');
    const closeBtn = modal.querySelector('[data-sec-close]');
    const fileNameBox = modal.querySelector('#secFileName');
    const chk = modal.querySelector('#secShowAllCheckbox');

    if (!title || !text || !error || !pwdField || !confirmField || !hintField || !hintBox ||
        !showAllRow || !pwd || !conf || !hint || !accept || !cancel || !closeBtn || !fileNameBox) {
      throw new Error('El modal de seguridad no se generó correctamente.');
    }

    title.textContent = opts.title || 'Seguridad del archivo';
    text.textContent = opts.text || '';
    error.classList.remove('show');
    error.textContent = '';

    const key = String(opts.key || '');
    fileNameBox.textContent = key ? key.split('/').pop() : '—';

    pwd.value = '';
    conf.value = '';
    hint.value = opts.defaultHint || '';
    pwd.type = 'password';
    conf.type = 'password';

    if (chk) chk.checked = false;

    modal.querySelectorAll('[data-sec-toggle]').forEach(function (btn) {
      btn.textContent = 'Ver';
    });

    pwdField.style.display = opts.askPassword === false ? 'none' : '';
    confirmField.style.display = opts.askConfirm ? '' : 'none';
    hintField.style.display = opts.askHint ? '' : 'none';
    showAllRow.style.display = opts.askPassword === false ? 'none' : '';

    if (opts.hintText) {
      const esc = String(opts.hintText).replace(/[&<>"']/g, function (ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
      });
      hintBox.style.display = '';
      hintBox.innerHTML = '<strong>Pista guardada:</strong> ' + esc;
    } else {
      hintBox.style.display = 'none';
      hintBox.innerHTML = '';
    }

    if (bsModal) {
      bsModal.show();
    } else if (window.jQuery && typeof window.jQuery === 'function') {
      try {
        const $m = window.jQuery(modal);
        if ($m && typeof $m.modal === 'function') {
          $m.modal('show');
        } else {
          throw new Error('sin modal jQuery');
        }
      } catch (_) {
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
        if (!document.querySelector('.modal-backdrop')) {
          const bd = document.createElement('div');
          bd.className = 'modal-backdrop fade show';
          document.body.appendChild(bd);
        }
      }
    } else {
      modal.style.display = 'block';
      modal.classList.add('show');
      modal.removeAttribute('aria-hidden');
      document.body.classList.add('modal-open');
      if (!document.querySelector('.modal-backdrop')) {
        const bd = document.createElement('div');
        bd.className = 'modal-backdrop fade show';
        document.body.appendChild(bd);
      }
    }

    return new Promise(function (resolve) {
      secModalResolver = resolve;

      const cleanup = function () {
        accept.removeEventListener('click', onAccept);
        cancel.removeEventListener('click', onCancel);
        closeBtn.removeEventListener('click', onCancel);
        modal.removeEventListener('keydown', onKey);
      };

      const onAccept = function () {
        const result = {
          password: String(pwd.value || '').trim(),
          confirmPassword: String(conf.value || '').trim(),
          hint: String(hint.value || '').trim()
        };

        if (opts.askPassword !== false && result.password.length < 4) {
          error.textContent = 'La contraseña debe tener al menos 4 caracteres.';
          error.classList.add('show');
          pwd.focus();
          return;
        }

        if (opts.askConfirm && result.password !== result.confirmPassword) {
          error.textContent = 'La confirmación no coincide con la contraseña.';
          error.classList.add('show');
          conf.focus();
          return;
        }

        cleanup();
        closeSecurityModal(result);
      };

      const onCancel = function () {
        cleanup();
        closeSecurityModal(null);
      };

      const onKey = function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          onCancel();
        } else if (e.key === 'Enter') {
          e.preventDefault();
          onAccept();
        }
      };

      accept.addEventListener('click', onAccept);
      cancel.addEventListener('click', onCancel);
      closeBtn.addEventListener('click', onCancel);
      modal.addEventListener('keydown', onKey);

      setTimeout(function () {
        (opts.askPassword === false ? accept : pwd).focus();
      }, 20);
    });
  }

  async function postFormAcceptJSON(url, data) {
    const fd = new FormData();
    Object.entries(data || {}).forEach(([k, v]) => {
      if (Array.isArray(v)) {
        v.forEach(item => fd.append(k + '[]', item));
      } else {
        fd.append(k, v == null ? '' : String(v));
      }
    });

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd
    });

    const ct = res.headers.get('content-type') || '';
    let json = null;

    if (ct.includes('application/json')) {
      json = await res.json().catch(() => null);
    } else {
      const txt = await res.text();
      try {
        json = JSON.parse(txt);
      } catch (_) {
        json = { ok: false, msg: txt || ('HTTP ' + res.status) };
      }
    }

    if (!res.ok) {
      throw new Error(json?.msg || json?.error || ('HTTP ' + res.status));
    }

    return json || { ok: false, msg: 'Respuesta inválida' };
  }

  async function lockFileByKey(key) {
    key = normalizeKey(key);
    if (!key) return false;

    const data = await openSecurityModal({
      title: 'Proteger con contraseña',
      text: 'Escribe una contraseña para proteger este archivo.',
      askPassword: true,
      askConfirm: true,
      askHint: true
    });
    if (!data) return false;

    const j = await postFormAcceptJSON('set_file_security.php', {
      mode: 'secure',
      key: key,
      password: data.password,
      secure_hint: data.hint
    });

    if (!j?.ok || !Number(j?.ok_count || 0)) {
      throw new Error(j?.msg || 'No se pudo proteger el archivo');
    }
    return true;
  }

  async function unlockFileByKey(key, btn) {
    key = normalizeKey(key);
    if (!key) return false;

    const data = await openSecurityModal({
      title: 'Desbloquear archivo',
      text: 'Escribe la contraseña para desbloquear temporalmente este archivo.',
      askPassword: true,
      askConfirm: false,
      askHint: false,
      hintText: btn?.dataset?.hint || ''
    });
    if (!data) return false;

    const parts = parseKey(key);
    const j = await postFormAcceptJSON('unlock_file.php', {
      key: key,
      ruta: parts.ruta,
      encriptado: parts.enc,
      password: data.password
    });

    if (!j?.ok) {
      throw new Error(j?.msg || 'No se pudo desbloquear el archivo');
    }
    return true;
  }

  async function relockFileByKey(key) {
    key = normalizeKey(key);
    if (!key) return false;

    const j = await postFormAcceptJSON('relock_file.php', { key });
    if (!j?.ok) throw new Error(j?.msg || 'No se pudo bloquear de nuevo');
    return true;
  }

  async function unsecureFileByKey(key) {
    key = normalizeKey(key);
    if (!key) return false;
    if (!confirm('¿Quitar la protección con contraseña de este archivo?')) return false;

    const j = await postFormAcceptJSON('set_file_security.php', {
      mode: 'normal',
      key: key
    });

    if (!j?.ok || !Number(j?.ok_count || 0)) {
      throw new Error(j?.msg || 'No se pudo quitar la seguridad');
    }
    return true;
  }

    window.setFileSecurity = async function (action, key) {
    try {
      if (action === 'lock') {
        const ok = await lockFileByKey(key);
        if (ok) {
          if (typeof refreshBloqueArchivosDesdeFiltros === 'function') {
            refreshBloqueArchivosDesdeFiltros();
          } else {
            window.location.reload();
          }
        }
        return false;
      }

      if (action === 'unlock') {
        const btn = document.querySelector(`.js-unlock-file[data-key="${cssEscape(key)}"]`);
        const unlocked = btn && btn.dataset.unlocked === '1';

        const ok = unlocked
          ? await relockFileByKey(key)
          : await unlockFileByKey(key);

        if (ok) {
          if (typeof refreshBloqueArchivosDesdeFiltros === 'function') {
            refreshBloqueArchivosDesdeFiltros();
          } else {
            window.location.reload();
          }
        }
        return false;
      }

      if (action === 'unsecure') {
        const ok = await unsecureFileByKey(key);
        if (ok) {
          if (typeof refreshBloqueArchivosDesdeFiltros === 'function') {
            refreshBloqueArchivosDesdeFiltros();
          } else {
            window.location.reload();
          }
        }
        return false;
      }

      return false;
    } catch (err) {
      console.error(err);
      alert(err.message || 'Error de seguridad');
      return false;
    }
  };

  document.addEventListener('click', function (e) {
    const btnLock = closest(e.target, '.js-lock-file');
    if (btnLock) {
      e.preventDefault();
      window.setFileSecurity('lock', btnLock.dataset.key || '');
      return;
    }

    const btnUnlock = closest(e.target, '.js-unlock-file');
    if (btnUnlock) {
      e.preventDefault();
      window.setFileSecurity('unlock', btnUnlock.dataset.key || '');
      return;
    }

    const btnUnsecure = closest(e.target, '.js-unsecure-one');
    if (btnUnsecure) {
      e.preventDefault();
      window.setFileSecurity('unsecure', btnUnsecure.dataset.key || '');
      return;
    }
  });

// 10) Toggle "Seleccionar todos" (integra toggleAll.js)
// =========================
function toggleAll(source) {
  const checkboxes = document.querySelectorAll('input[name="archivos[]"]');
  checkboxes.forEach(cb => cb.checked = source.checked);
  
  // Actualizar estado visual del checkbox "select all" si existe
  const selectAll = document.getElementById('selectAll');
  if (selectAll && source !== selectAll) {
    selectAll.checked = Array.from(checkboxes).every(cb => cb.checked);
  }
}

// Delegación de evento para el checkbox "Seleccionar todos"
document.addEventListener('change', function (e) {
  const selectAll = closest(e.target, '#selectAll');
  if (!selectAll) return;
  toggleAll(selectAll);
});

// Sincronizar estado del "select all" al hacer click en checkboxes individuales
document.addEventListener('change', function (e) {
  const cb = closest(e.target, 'input[name="archivos[]"]');
  if (!cb) return;
  const selectAll = document.getElementById('selectAll');
  if (!selectAll) return;
  
  const checkboxes = document.querySelectorAll('input[name="archivos[]"]');
  selectAll.checked = Array.from(checkboxes).every(c => c.checked);
});

// =========================
// 11) Dropdown de acciones: asegurar que el menú quede por encima de otras filas
// =========================
function setDropdownRowZIndexFromEvent(ev, isOpen) {
  // Bootstrap dispara el evento en el .dropdown
  const dd = ev && ev.target ? ev.target : null;
  if (!dd) return;
  const row = dd.closest ? dd.closest('.file-row') : null;
  if (!row) return;
  if (isOpen) row.classList.add('dropdown-open');
  else row.classList.remove('dropdown-open');
}

// Bootstrap 5/4
document.addEventListener('show.bs.dropdown',   ev => setDropdownRowZIndexFromEvent(ev, true));
document.addEventListener('shown.bs.dropdown',  ev => setDropdownRowZIndexFromEvent(ev, true));
document.addEventListener('hide.bs.dropdown',   ev => setDropdownRowZIndexFromEvent(ev, false));
document.addEventListener('hidden.bs.dropdown', ev => setDropdownRowZIndexFromEvent(ev, false));

// Fallback (si por alguna razón Bootstrap no emite eventos): al hacer click en el botón de 3 puntos
document.addEventListener('click', function (e) {
  const btn = closest(e.target, '.file-actions [data-bs-toggle="dropdown"], .file-actions [data-toggle="dropdown"]');
  if (!btn) return;
  const row = btn.closest ? btn.closest('.file-row') : null;
  if (!row) return;
  // Toggle optimista: si ya está abierto lo quitamos, si no lo ponemos
  row.classList.toggle('dropdown-open');
});

})(window, document);

// =========================
// 12) Contador de archivos seleccionados
// =========================
(function(){
  function updateCounter(){
    const c = document.querySelectorAll('input[name="archivos[]"]:checked').length;
    const el = document.getElementById('filesSelectedCount');
    if (el) el.textContent = c ? (c + ' archivo(s) seleccionado(s)') : '';
  }
  document.addEventListener('change', function(e){
    if (e.target && (e.target.matches('#selectAll') || e.target.matches('input[name="archivos[]"]'))) {
      setTimeout(updateCounter, 0);
    }
  });
  document.addEventListener('bloque-archivos:actualizado', updateCounter);
  document.addEventListener('bloque-archivos:updated', updateCounter);
})();