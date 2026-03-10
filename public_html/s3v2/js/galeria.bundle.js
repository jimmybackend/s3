/*! galeria.bundle.js — v1.1 (actualizado)
   - Une: js-ver-imagen.js, galeriaunica.js, galeriaui.js, galeriagrid.js, galeriaconparametros.js
   - Añade: soporte para 4/6/12 por pase (con localStorage), recarga y delegación segura
   - NUEVO: Interceptor universal de <a href="ver_archivo.php?archivo=..."> para abrir SIEMPRE en el modal
   - Requiere (HTML):
       • Modal #modalImagenUnica con contenedor #gu-carousel-wrap
       • Modal #modalGaleriaCompleta con contenedor #gg-carousel-wrap
       • Botón #btnVerGaleria (opcional) y #btnRecargarGaleria (opcional)
       • Filas de archivos con .list-group .file-row[data-original][data-key][data-nombre] (opcional)
*/

(function(window, document){
  'use strict';

  // -------------------------------
  // Utilidades comunes
  // -------------------------------
  const U = {
    qs:  (sel, ctx=document) => ctx.querySelector(sel),
    qsa: (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel)),
    esc: s => (s||'').replace(/[&<>\"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#039;'}[m])),
    imgExts: ['jpg','jpeg','png','gif','webp','bmp','svg'],
    urlOriginalFromKey: (key) => 'ver_archivo.php?archivo=' + encodeURIComponent(key),
    thumbFromKey: (key, w=384, h=216) => 'thumb.php?key=' + encodeURIComponent(key) + '&w='+w+'&h='+h,
    tryBootstrapModalShow: (el) => {
      if (!el) return;
      try {
        if (window.bootstrap && bootstrap.Modal) { new bootstrap.Modal(el, { backdrop:true, keyboard:true }).show(); return; }
      } catch(e){}
      if (window.jQuery && jQuery.fn.modal) { jQuery(el).modal('show'); }
    },
    on: (type, handler, opts) => document.addEventListener(type, handler, opts||false),
    off:(type, handler, opts) => document.removeEventListener(type, handler, opts||false),
    dispatch: (name, detail) => document.dispatchEvent(new CustomEvent(name, { detail })),
  };

  // -------------------------------
  // Galería Única (1x1, fondo negro)
  // -------------------------------
  window.GaleriaUnica = (function () {
    const ModalId = 'modalImagenUnica';
    const WrapId  = 'gu-carousel-wrap';

    function urlOriginal(key, dataset){
      if (dataset) {
        const o1 = dataset.original || dataset.urlOriginal || dataset.urloriginal;
        if (o1 && o1.trim() !== '') return o1;
      }
      return U.urlOriginalFromKey(key);
    }

    function normalizeItem(it){
      if (!it) return null;
      const key = (it.key || '').toString();
      const nombre = (it.nombre || it.name || key.split('/').pop()).toString();
      const url = it.original || it.urlOriginal || it.url || urlOriginal(key);
      const ext = (key.split('.').pop() || '').toLowerCase();
      if (!key || !url) return null;
      if (!U.imgExts.includes(ext)) return null;
      return { key, nombre, original:url };
    }

    function recolectarLista(){
      const out = [];
    
      // 1) DOM del bloque (estado actual tras navegar)
      U.qsa('.list-group .file-row[data-original]').forEach(li => {
        const key   = li.getAttribute('data-key') || '';
        const nombre= li.getAttribute('data-nombre') || key.split('/').pop();
        const orig  = li.getAttribute('data-original') || '';
        const norm  = normalizeItem({ key, nombre, original: orig });
        if (norm) out.push(norm);
      });
      if (out.length) return out;
    
      // 2) Fallback: buffer previo (por si no hay DOM montado)
      if (Array.isArray(window.imagenesGaleria) && window.imagenesGaleria.length){
        window.imagenesGaleria.forEach(it => {
          const norm = normalizeItem(it);
          if (norm) out.push(norm);
        });
      }
    
      return out;
    }


    function ordenarDesdeClave(lista, clave){
      const idx = lista.findIndex(it => it.key === clave);
      if (idx < 0) return lista.slice();
      return lista.slice(idx).concat(lista.slice(0, idx));
    }

    function renderCarousel(lista){
      const mount = U.qs('#' + WrapId);
      if (!mount) return;

      if (!lista.length){
        mount.innerHTML = '<div class="text-center text-muted p-5">No hay imágenes.</div>';
        return;
      }

      const carouselId = 'gu-carousel-' + Date.now();
      const root = document.createElement('div');
      root.className = 'carousel slide';
      root.id = carouselId;
      root.setAttribute('data-ride', 'carousel');    // BS4
      root.setAttribute('data-bs-ride', 'false');    // BS5 manual

      const inner = document.createElement('div');
      inner.className = 'carousel-inner';

      lista.forEach((it, idx) => {
        const item = document.createElement('div');
        item.className = 'carousel-item' + (idx === 0 ? ' active' : '');

        const frame = document.createElement('div');
        frame.className = 'gu-frame';

        const img = document.createElement('img');
        img.src = it.original;
        img.alt = it.nombre || '';
        img.loading = 'lazy';
        img.decoding = 'async';

        frame.appendChild(img);

        const cap = document.createElement('div');
        cap.className = 'gu-caption';
        cap.textContent = it.nombre || '';

        item.appendChild(frame);
        item.appendChild(cap);
        inner.appendChild(item);
      });

      const prev = document.createElement('a');
      prev.className = 'carousel-control-prev';
      prev.href = '#' + carouselId;
      prev.role = 'button';
      prev.setAttribute('data-slide', 'prev');
      prev.setAttribute('data-bs-slide', 'prev');
      prev.innerHTML = '<span class="carousel-control-prev-icon" aria-hidden="true"></span>';

      const next = document.createElement('a');
      next.className = 'carousel-control-next';
      next.href = '#' + carouselId;
      next.role = 'button';
      next.setAttribute('data-slide', 'next');
      next.setAttribute('data-bs-slide', 'next');
      next.innerHTML = '<span class="carousel-control-next-icon" aria-hidden="true"></span>';

      root.appendChild(inner);
      root.appendChild(prev);
      root.appendChild(next);

      mount.innerHTML = '';
      mount.appendChild(root);

      // Inicializar BS5 si está disponible
      try {
        if (window.bootstrap && bootstrap.Carousel) {
          new bootstrap.Carousel(root, { interval:false, ride:false });
        }
      } catch(e){}
    }

    function abrirModal(){
      U.tryBootstrapModalShow(U.qs('#' + ModalId));
    }

    function ver(clave, opts={}){
      const base = recolectarLista();
      let lista = base.length ? base : [];

      // si no había lista, al menos arma una con la clave/URL que recibimos
      if (!lista.length && clave) {
        const original = (opts && (opts.urlOriginal || opts.original)) || urlOriginal(clave);
        lista = [{ key: clave, nombre: (opts && opts.nombre) || clave.split('/').pop(), original }];
      }

      if (!lista.length){
        // sin nada que mostrar
        renderCarousel([]);
        abrirModal();
        return;
      }

      const ordenadas = clave ? ordenarDesdeClave(lista, clave) : lista;
      renderCarousel(ordenadas);
      abrirModal();
    }

    function verDesdeDataset(el){
      const key   = el.getAttribute('data-key') || '';
      const nombre= el.getAttribute('data-nombre') || key.split('/').pop();
      const orig  = el.getAttribute('data-original') || '';
      ver(key, { nombre, urlOriginal:orig });
    }

    // Delegación: ver imagen desde botones .js-ver-imagen o [data-accion="ver-imagen"]
    U.on('click', function(e){
      const btn = e.target.closest('.js-ver-imagen, [data-accion="ver-imagen"]');
      if (!btn) return;
      e.preventDefault();
      verDesdeDataset(btn);
    });

    return { ver, verDesdeDataset };
  })();

  // -------------------------------
  // NUEVO: Interceptor universal de anchors ver_archivo.php?archivo=...
  // Evita navegación/ventana nueva y abre SIEMPRE el modal
  // -------------------------------
  (function(){
    function getParamFromHref(href, name){
      try {
        const u = new URL(href, window.location.href);
        return u.searchParams.get(name) || '';
      } catch { return ''; }
    }

    document.addEventListener('click', function(e){
      const a = e.target.closest('a[href*="ver_archivo.php?archivo="]');
      if (!a) return;
      e.preventDefault();
      const clave = getParamFromHref(a.getAttribute('href'), 'archivo');
      if (!clave) return;
      const nombre = a.getAttribute('data-nombre') || a.textContent.trim() || clave.split('/').pop();
      const original = a.getAttribute('data-original') || a.getAttribute('href');
      if (window.GaleriaUnica && typeof GaleriaUnica.ver === 'function') {
        GaleriaUnica.ver(clave, { nombre, urlOriginal: original });
      } else {
        // Fallback por si el bundle no cargó algo
        window.location.href = a.getAttribute('href');
      }
    }, true);
  })();

  // -------------------------------
  // Grid (galería completa) con perSlide configurable
  // -------------------------------
  (function(){
    const ModalId   = 'modalGaleriaCompleta';
    const WrapId    = 'gg-carousel-wrap';
    const BtnId     = 'btnVerGaleria';

    const KEY_GRID_SIZE = 'galeria.perSlide';

    function getPerSlide(){
      const v = parseInt(localStorage.getItem(KEY_GRID_SIZE) || '6', 10);
      return [4,6,12].includes(v) ? v : 6;
    }
    function setPerSlide(n){
      if ([4,6,12].includes(n)) {
        localStorage.setItem(KEY_GRID_SIZE, String(n));
      }
      return getPerSlide();
    }

    function normalizeItem(it) {
      if (!it) return null;
      const key    = (it.key || '').toString();
      const nombre = (it.nombre || it.name || key.split('/').pop()).toString();
      let original = (it.original || it.url || '').toString();
      let thumb    = (it.thumb || it.thumbnail || '').toString();

      // construir si faltan
      if (!original && key) original = U.urlOriginalFromKey(key);
      if (!thumb && key)    thumb    = U.thumbFromKey(key, 384, 216);

      if (!key && (!original || !thumb)) return null;
      return { key, nombre, original, thumb };
    }

    async function fetchDesdeServidor(params) {
      try {
        const url = new URL('generar_galeria.php', location.href);
        // conserva filtros del querystring
        const src = new URL(location.href);
        ['buscar','tipo','fecha_inicio','fecha_fin','limite'].forEach(k=>{
          if (src.searchParams.has(k)) url.searchParams.set(k, src.searchParams.get(k));
        });
        // agrega parámetros explícitos (si llegaron)
        if (params) {
          Object.entries(params).forEach(([k,v])=>{
            if (v!==undefined && v!==null && v!=='') url.searchParams.set(k, v);
          });
        }
        const res = await fetch(url.toString(), { credentials:'same-origin' });
        if (!res.ok) return [];
        const data = await res.json();
        if (!Array.isArray(data)) return [];
        return data.map(normalizeItem).filter(Boolean);
      } catch (e) {
        console.warn('GaleriaGrid: error en generar_galeria.php', e);
        return [];
      }
    }

    function recolectarDesdeDOM() {
      const out = [];

      if (Array.isArray(window.imagenesGaleria) && window.imagenesGaleria.length) {
        window.imagenesGaleria.forEach(it => {
          const norm = normalizeItem({ key: it.key, nombre: it.nombre, original: it.original, thumb: it.thumb });
          if (norm) out.push(norm);
        });
        if (out.length) return out;
      }

      U.qsa('.list-group .file-row[data-original]').forEach(li => {
        const key   = li.getAttribute('data-key') || '';
        const name  = li.getAttribute('data-nombre') || key.split('/').pop();
        const orig  = li.getAttribute('data-original') || '';
        const thumb = (U.qs('img.thumb', li) || {}).src || '';
        const norm  = normalizeItem({ key, nombre: name, original: orig, thumb });
        if (norm) out.push(norm);
      });

      return out;
    }

    function chunk(arr, size) {
      const out = [];
      for (let i=0; i<arr.length; i+=size) out.push(arr.slice(i, i+size));
      return out;
    }

    function makeCard(it) {
      const col = document.createElement('div');
      col.className = 'col';

      const card = document.createElement('div');
      card.className = 'gallery-card';

      const frame = document.createElement('div');
      frame.className = 'frame';

      const img = document.createElement('img');
      img.src = it.thumb;
      img.alt = it.nombre || '';
      img.loading = 'lazy';
      img.decoding = 'async';

      img.addEventListener('click', () => {
        if (window.GaleriaUnica && typeof window.GaleriaUnica.ver === 'function') {
          window.GaleriaUnica.ver(it.key, { nombre: it.nombre || '', urlOriginal: it.original });
        } else {
          window.open(it.original, '_blank');
        }
      });

      const cap = document.createElement('div');
      cap.className = 'caption';
      cap.textContent = it.nombre || '';

      frame.appendChild(img);
      card.appendChild(frame);
      card.appendChild(cap);
      col.appendChild(card);
      return col;
    }

    function renderSlides(list, perSlide) {
      const groups = chunk(list, perSlide);

      const carouselId = 'gallery-grid-carousel-' + Date.now();
      const wrap = document.createElement('div');
      wrap.className = 'carousel slide gallery-grid-carousel';
      wrap.id = carouselId;
      wrap.setAttribute('data-ride', 'carousel');   // BS4
      wrap.setAttribute('data-bs-ride', 'false');   // BS5 manual

      const inner = document.createElement('div');
      inner.className = 'carousel-inner';

      groups.forEach((group, idx) => {
        const item = document.createElement('div');
        item.className = 'carousel-item' + (idx === 0 ? ' active' : '');

        const grid = document.createElement('div');
        grid.className = 'gallery-grid';

        group.forEach(it => grid.appendChild(makeCard(it)));
        // relleno para que no salte
        for (let i=group.length; i<perSlide; i++) {
          const empty = document.createElement('div');
          empty.className = 'col';
          grid.appendChild(empty);
        }

        item.appendChild(grid);
        inner.appendChild(item);
      });

      // Controles izquierda/derecha (usa tus estilos de BS)
      const prev = document.createElement('a');
      prev.className = 'carousel-control-prev';
      prev.href = '#' + carouselId;
      prev.role = 'button';
      prev.setAttribute('data-slide', 'prev');
      prev.setAttribute('data-bs-slide', 'prev');
      prev.innerHTML = '<span class="carousel-control-prev-icon" aria-hidden="true"></span>';

      const next = document.createElement('a');
      next.className = 'carousel-control-next';
      next.href = '#' + carouselId;
      next.role = 'button';
      next.setAttribute('data-slide', 'next');
      next.setAttribute('data-bs-slide', 'next');
      next.innerHTML = '<span class="carousel-control-next-icon" aria-hidden="true"></span>';

      wrap.appendChild(inner);
      wrap.appendChild(prev);
      wrap.appendChild(next);
      return wrap;
    }

    function mostrarMensajeVacio(container) {
      container.innerHTML =
        '<div class="gg-empty text-center" style="color:#bbb;padding:3rem 1rem;">' +
        'No se encontraron imágenes para esta carpeta.' +
        '</div>';
    }

    function renderCarrusel(list, perSlide) {
      const mount = U.qs('#' + WrapId);
      if (!mount) return;
      mount.innerHTML = '';

      if (!list.length) {
        mostrarMensajeVacio(mount);
        return;
      }
      mount.appendChild(renderSlides(list, perSlide));

      // Inicialización manual BS5 (opcional)
      try {
        if (window.bootstrap && bootstrap.Carousel) {
          const el = mount.querySelector('.carousel');
          new bootstrap.Carousel(el, { interval: false, ride: false });
        }
      } catch (e) {}
    }

    function abrirModal() {
      U.tryBootstrapModalShow(U.qs('#' + ModalId));
    }

    async function verCarrusel(params) {
      const perSlide = getPerSlide();

      // 1) servidor (con filtros si se pasaron)
      let list = await fetchDesdeServidor(params);
      // 2) fallback DOM
      if (!list.length) list = recolectarDesdeDOM();
      // 3) pintar y mostrar
      renderCarrusel(list, perSlide);
      abrirModal();
      U.dispatch('galeria-grid:rendered', { perSlide, total:list.length });
    }

    // API global
    window.GaleriaGrid = { verCarrusel, setPerSlide, getPerSlide };

    // Botones (si existen)
    document.addEventListener('DOMContentLoaded', function(){
      const btn = U.qs('#' + BtnId);
      if (btn) {
        btn.addEventListener('click', function(e){
          e.preventDefault();
          verCarrusel();
        });
      }
      const btnRe = U.qs('#btnRecargarGaleria');
      if (btnRe) {
        btnRe.addEventListener('click', function(e){
          e.preventDefault();
          verCarrusel();
        });
      }
    });
  })();

  // -------------------------------
  // UI para mostrar/ocultar botón y aplicar filtros actuales
  // -------------------------------
  window.GaleriaUI = (function () {
    function filtrosDeURL() {
      try {
        const u = new URL(window.location.href);
        const q = u.searchParams;
        const lim = parseInt(q.get('limite') || '50', 10);
        return {
          tipo: 'img', // fuerza que sólo se consideren imágenes
          buscar: (q.get('buscar') || '').trim(),
          fecha_inicio: (q.get('fecha_inicio') || '').trim(),
          fecha_fin: (q.get('fecha_fin') || '').trim(),
          limite: Number.isFinite(lim) ? Math.min(Math.max(lim,1),500) : 50,
        };
      } catch {
        return { tipo:'img', buscar:'', fecha_inicio:'', fecha_fin:'', limite:50 };
      }
    }

    function hayImagenesVisibles() {
      const cont = document.getElementById('bloque-archivos') || document;
      const cards = cont.querySelectorAll('[data-tipo="archivo"][data-ext]');
      let tiene = false;
      cards.forEach(n => {
        const ext = (n.getAttribute('data-ext') || '').toLowerCase();
        if (GaleriaUI.IMG_EXTS.includes(ext)) tiene = true;
      });
      return tiene;
    }

    function actualizarBoton() {
      const btn = document.getElementById('btnVerGaleria');
      if (!btn) return;
      btn.style.display = hayImagenesVisibles() ? 'inline-flex' : 'none';
    }

    function onClickBtn(e){
      const b = e.target.closest('#btnVerGaleria');
      if (!b) return;
      e.preventDefault();
      if (window.GaleriaGrid && typeof GaleriaGrid.verCarrusel === 'function') {
        GaleriaGrid.verCarrusel({ tipo: 'img', ...filtrosDeURL() });
      } else {
        console.warn('GaleriaGrid.verCarrusel no disponible');
      }
    }

    function bindEventos() {
      document.removeEventListener('click', onClickBtn, true);
      document.addEventListener('click', onClickBtn, true);

      document.addEventListener('bloque-archivos:updated', actualizarBoton);
      document.addEventListener('galeria-grid:rendered', actualizarBoton);

      const target = document.getElementById('bloque-archivos') || document.body;
      if (target) {
        const mo = new MutationObserver(() => { actualizarBoton(); });
        mo.observe(target, { childList: true, subtree: true });
      }
    }

    function init() {
      bindEventos();
      actualizarBoton();
    }

    const api = { init, actualizarBoton };
    api.IMG_EXTS = U.imgExts;
    return api;
  })();

  // Autoinit UI
  document.addEventListener('DOMContentLoaded', function(){
    if (window.GaleriaUI) GaleriaUI.init();
  });

  // -------------------------------
  // API auxiliar: cargar galería con parámetros (compatibilidad)
  // -------------------------------
  window.cargarGaleriaConParametros = function(tipo='', buscar='', fecha_inicio='', fecha_fin='', limite=50) {
    if (window.GaleriaGrid && typeof GaleriaGrid.verCarrusel === 'function') {
      GaleriaGrid.verCarrusel({ tipo, buscar, fecha_inicio, fecha_fin, limite });
    } else {
      console.warn('GaleriaGrid.verCarrusel no disponible');
    }
  };

  // -------------------------------
  // API pública para escoger tamaño 4/6/12 por pase
  // -------------------------------
  window.setGaleriaGridSize = function(n){
    if (window.GaleriaGrid && typeof GaleriaGrid.setPerSlide === 'function') {
      const final = GaleriaGrid.setPerSlide(parseInt(n,10));
      // si el carrusel ya está abierto, re-renderizar
      try {
        GaleriaGrid.verCarrusel();
      } catch(e){}
      return final;
    }
    return null;
  };
  window.getGaleriaGridSize = function(){
    return (window.GaleriaGrid && typeof GaleriaGrid.getPerSlide === 'function')
      ? GaleriaGrid.getPerSlide() : 6;
  };


  // -------------------------------
  // Cerrar modales con .modal-x (soporte BS4/BS5 y fallback)
  // -------------------------------
  (function(){
    function closeModal(modalEl){
      // Evita: Cannot read properties of null (reading 'style')
      if (!modalEl) return;

      // Evita warning aria-hidden: si un hijo mantiene foco, blurea antes de ocultar
      try { if (document.activeElement) document.activeElement.blur(); } catch(e){}

      try {
        // BS5
        if (window.bootstrap && bootstrap.Modal){
          const inst = (bootstrap.Modal.getOrCreateInstance)
            ? bootstrap.Modal.getOrCreateInstance(modalEl)
            : (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl));
          inst.hide(); return;
        }
      } catch(e){}
      try {
        // BS4 (jQuery)
        if (window.jQuery && jQuery.fn.modal){
          jQuery(modalEl).modal('hide'); return;
        }
      } catch(e){}
      // Fallback sin Bootstrap (oculta a la fuerza)
      try { modalEl.classList.remove('show'); } catch(e){}
      if (modalEl.style) modalEl.style.display = 'none';
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      const backdrops = document.querySelectorAll('.modal-backdrop');
      backdrops.forEach(b=>{ if (b && b.parentNode) b.parentNode.removeChild(b); });
    }

    document.addEventListener('click', function(e){
      const x = e.target.closest('.modal .modal-x');
      if (!x) return;
      e.preventDefault();
      const modal = x.closest('.modal');
      if (modal) closeModal(modal);
    }, true);
  })();

})(window, document);
