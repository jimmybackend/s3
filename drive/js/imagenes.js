/*! imagenes.js
   Unifica:
   - build-imagenes-from-list
   - ver imagen
   - galería única
   - galería grid
   - fix backdrop modal
   Requiere:
   - ver_archivo.php
   - thumb.php
   - Bootstrap 4/5 recomendado
*/

(function(window, document){
  'use strict';

  const U = {
    qs:  (sel, ctx=document) => ctx.querySelector(sel),
    qsa: (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel)),
    imgExts: ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'],
    urlOriginalFromKey: (key) => 'ver_archivo.php?archivo=' + encodeURIComponent(key),
    thumbFromKey: (key, w=384, h=216) => 'thumb.php?key=' + encodeURIComponent(key) + '&w=' + w + '&h=' + h + '&fit=cover&fmt=jpg',
    dispatch: (name, detail) => document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })),
    on: (type, handler, opts) => document.addEventListener(type, handler, opts || false)
  };

  function cleanupBackdrops(){
    try {
      document.querySelectorAll('.modal-backdrop').forEach(function(b){
        if (b && b.parentNode) b.parentNode.removeChild(b);
      });
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      document.body.style.removeProperty('overflow');
    } catch(e){}
  }

  function blurActiveElement(){
    try {
      if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
    } catch(e){}
  }

  function showModal(el){
    if (!el) return;

    try {
      if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el, { backdrop:true, keyboard:true }).show();
        return;
      }
    } catch(e){}

    try {
      if (window.jQuery && jQuery.fn.modal) {
        jQuery(el).modal('show');
        return;
      }
    } catch(e){}

    el.classList.add('show');
    el.style.display = 'block';
    el.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');
  }

  function hideModal(el){
    if (!el) return;

    blurActiveElement();

    try {
      if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).hide();
        setTimeout(cleanupBackdrops, 80);
        return;
      }
    } catch(e){}

    try {
      if (window.jQuery && jQuery.fn.modal) {
        jQuery(el).modal('hide');
        setTimeout(cleanupBackdrops, 80);
        return;
      }
    } catch(e){}

    el.classList.remove('show');
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
    cleanupBackdrops();
  }

  function normalizeItem(it){
    if (!it) return null;

    var key = String(it.key || '');
    var nombre = String(it.nombre || it.name || (key ? key.split('/').pop() : ''));
    var original = String(it.original || it.urlOriginal || it.url || '');
    var thumb = String(it.thumb || it.thumbnail || '');

    if (!original && key) original = U.urlOriginalFromKey(key);
    if (!thumb && key) thumb = U.thumbFromKey(key, 384, 216);

    if (!key && !original && !thumb) return null;

    return {
      key: key,
      nombre: nombre,
      original: original,
      thumb: thumb
    };
  }

  function recolectarImagenesDesdeDOM(){
    var out = [];

    document.querySelectorAll('.list-group .file-row').forEach(function(li){
      var key = li.getAttribute('data-key') || '';
      var nombre = li.getAttribute('data-nombre') || (key ? key.split('/').pop() : '');
      var original = li.getAttribute('data-original') || '';
      var thumbEl = li.querySelector('img.file-thumb-image, img.thumb-img, img.thumb');
      var thumb = thumbEl ? thumbEl.src : '';

      var ext = '';
      if (key.indexOf('.') !== -1) {
        ext = key.split('.').pop().toLowerCase();
      } else {
        ext = (li.getAttribute('data-ext') || '').toLowerCase();
      }

      if (!U.imgExts.includes(ext)) return;

      var item = normalizeItem({
        key: key,
        nombre: nombre,
        original: original,
        thumb: thumb
      });

      if (item) out.push(item);
    });

    return out;
  }

  function actualizarBufferGaleria(){
    var lista = recolectarImagenesDesdeDOM();
    window.imagenesGaleria = lista;

    var btn = document.getElementById('btnVerGaleria');
    if (btn) {
      btn.style.display = lista.length ? 'inline-flex' : 'none';
    }
  }

  window.GaleriaUnica = (function(){
    var ModalId = 'modalImagenUnica';
    var WrapId = 'gu-carousel-wrap';

    function recolectarLista(){
      var out = [];

      if (Array.isArray(window.imagenesGaleria) && window.imagenesGaleria.length) {
        window.imagenesGaleria.forEach(function(it){
          var n = normalizeItem(it);
          if (n) out.push(n);
        });
      }

      if (out.length) return out;

      return recolectarImagenesDesdeDOM();
    }

    function ordenarDesdeClave(lista, clave){
      var idx = lista.findIndex(function(it){ return it.key === clave; });
      if (idx < 0) return lista.slice();
      return lista.slice(idx).concat(lista.slice(0, idx));
    }

    function render(lista){
      var mount = document.getElementById(WrapId);
      if (!mount) return;

      if (!lista.length) {
        mount.innerHTML = '<div class="text-center text-muted p-4">No hay imágenes.</div>';
        return;
      }

      var carouselId = 'gu-carousel-' + Date.now();

      var html = '';
      html += '<div id="' + carouselId + '" class="carousel slide" data-ride="carousel" data-bs-ride="false">';
      html += '  <div class="carousel-inner">';

      lista.forEach(function(it, idx){
        html += '    <div class="carousel-item' + (idx === 0 ? ' active' : '') + '">';
        html += '      <div class="gu-frame text-center" style="background:#000;">';
        html += '        <img class="gu-original-image" src="' + it.original + '" alt="' + (it.nombre || '') + '" loading="lazy" decoding="async" draggable="false">';
        html += '      </div>';
        html += '      <div class="gu-caption text-center p-2" style="color:#fff;background:#111;">' + (it.nombre || '') + '</div>';
        html += '    </div>';
      });

      html += '  </div>';
      html += '  <a class="carousel-control-prev" href="#' + carouselId + '" role="button" data-slide="prev" data-bs-slide="prev">';
      html += '    <span class="carousel-control-prev-icon" aria-hidden="true"></span>';
      html += '  </a>';
      html += '  <a class="carousel-control-next" href="#' + carouselId + '" role="button" data-slide="next" data-bs-slide="next">';
      html += '    <span class="carousel-control-next-icon" aria-hidden="true"></span>';
      html += '  </a>';
      html += '</div>';

      mount.innerHTML = html;

      try {
        var el = document.getElementById(carouselId);
        if (window.bootstrap && bootstrap.Carousel && el) {
          new bootstrap.Carousel(el, { interval:false, ride:false });
        }
      } catch(e){}
    }

    function ver(clave, opts){
      opts = opts || {};
      var lista = recolectarLista();

      if (!lista.length && clave) {
        lista = [{
          key: clave,
          nombre: opts.nombre || (clave ? clave.split('/').pop() : ''),
          original: opts.urlOriginal || opts.original || U.urlOriginalFromKey(clave),
          thumb: ''
        }];
      }

      if (clave) {
        lista = ordenarDesdeClave(lista, clave);
      }

      render(lista);
      showModal(document.getElementById(ModalId));
    }

    return { ver: ver };
  })();

  window.GaleriaGrid = (function(){
    var ModalId = 'modalGaleriaCompleta';
    var WrapId = 'gg-carousel-wrap';
    var KEY_GRID_SIZE = 'galeria.perSlide';

    function getPerSlide(){
      var v = parseInt(localStorage.getItem(KEY_GRID_SIZE) || '6', 10);
      return [4,6,12].includes(v) ? v : 6;
    }

    function setPerSlide(n){
      n = parseInt(n, 10);
      if ([4,6,12].includes(n)) {
        localStorage.setItem(KEY_GRID_SIZE, String(n));
      }
      return getPerSlide();
    }

    function chunk(arr, size){
      var out = [];
      for (var i = 0; i < arr.length; i += size) {
        out.push(arr.slice(i, i + size));
      }
      return out;
    }

    function recolectarDesdeBufferODOM(){
      var out = [];

      if (Array.isArray(window.imagenesGaleria) && window.imagenesGaleria.length) {
        window.imagenesGaleria.forEach(function(it){
          var n = normalizeItem(it);
          if (n) out.push(n);
        });
      }

      if (out.length) return out;

      return recolectarImagenesDesdeDOM();
    }

    function render(list, perSlide){
      var mount = document.getElementById(WrapId);
      if (!mount) return;

      if (!list.length) {
        mount.innerHTML = '<div class="text-center text-muted p-4">No se encontraron imágenes.</div>';
        return;
      }

      var groups = chunk(list, perSlide);
      var carouselId = 'gg-carousel-' + Date.now();

      var html = '';
      html += '<div id="' + carouselId + '" class="carousel slide" data-ride="carousel" data-bs-ride="false">';
      html += '  <div class="carousel-inner">';

      groups.forEach(function(group, index){
        html += '<div class="carousel-item' + (index === 0 ? ' active' : '') + '">';
        html += '<div class="container-fluid"><div class="row">';

        group.forEach(function(it){
          html += '<div class="col-6 col-md-4 col-lg-' + (perSlide === 4 ? '3' : perSlide === 6 ? '2' : '2') + ' mb-3">';
          html += '  <div class="card h-100">';
          html += '    <div class="text-center" style="background:#111;min-height:160px;display:flex;align-items:center;justify-content:center;">';
          html += '      <img class="img-fluid js-grid-open"';
          html += '           src="' + it.thumb + '"';
          html += '           data-key="' + it.key + '"';
          html += '           data-nombre="' + (it.nombre || '') + '"';
          html += '           data-original="' + it.original + '"';
          html += '           style="max-height:160px;object-fit:cover;cursor:pointer;"';
          html += '           loading="lazy" decoding="async">';
          html += '    </div>';
          html += '    <div class="card-body p-2">';
          html += '      <div class="small text-truncate" title="' + (it.nombre || '') + '">' + (it.nombre || '') + '</div>';
          html += '    </div>';
          html += '  </div>';
          html += '</div>';
        });

        html += '</div></div>';
        html += '</div>';
      });

      html += '  </div>';
      html += '  <a class="carousel-control-prev" href="#' + carouselId + '" role="button" data-slide="prev" data-bs-slide="prev">';
      html += '    <span class="carousel-control-prev-icon" aria-hidden="true"></span>';
      html += '  </a>';
      html += '  <a class="carousel-control-next" href="#' + carouselId + '" role="button" data-slide="next" data-bs-slide="next">';
      html += '    <span class="carousel-control-next-icon" aria-hidden="true"></span>';
      html += '  </a>';
      html += '</div>';

      mount.innerHTML = html;

      try {
        var el = document.getElementById(carouselId);
        if (window.bootstrap && bootstrap.Carousel && el) {
          new bootstrap.Carousel(el, { interval:false, ride:false });
        }
      } catch(e){}
    }

    async function verCarrusel(){
      var perSlide = getPerSlide();
      // Fuente única: filas de bloque_archivos.php de la página actual.
      // No consulta toda la carpeta ni S3 ni generar_galeria.php.
      actualizarBufferGaleria();
      var list = recolectarDesdeBufferODOM();

      render(list, perSlide);
      showModal(document.getElementById(ModalId));
      U.dispatch('galeria-grid:rendered', { total: list.length, perSlide: perSlide });
    }

    return {
      verCarrusel: verCarrusel,
      getPerSlide: getPerSlide,
      setPerSlide: setPerSlide
    };
  })();

  function abrirImagenDesdeElemento(el){
    var key = el.getAttribute('data-key') || '';
    var nombre = el.getAttribute('data-nombre') || (key ? key.split('/').pop() : '');
    var original = el.getAttribute('data-original') || '';

    if (!key && original) {
      try {
        var u = new URL(original, window.location.href);
        key = u.searchParams.get('archivo') || '';
      } catch(e){}
    }

    if (window.GaleriaUnica && typeof window.GaleriaUnica.ver === 'function') {
      window.GaleriaUnica.ver(key, {
        nombre: nombre,
        urlOriginal: original
      });
    } else if (original) {
      window.open(original, '_blank');
    }
  }

  U.on('click', function(e){
    var btn = e.target.closest('.js-ver-imagen, [data-accion="ver-imagen"]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

    actualizarBufferGaleria();
    abrirImagenDesdeElemento(btn);
  }, true);

  U.on('click', function(e){
    var img = e.target.closest('.js-grid-open');
    if (!img) return;

    e.preventDefault();
    abrirImagenDesdeElemento(img);
  }, true);

  U.on('click', function(e){
    var a = e.target.closest('a[href*="ver_archivo.php?archivo="]');
    if (!a) return;

    e.preventDefault();

    var href = a.getAttribute('href') || '';
    var key = '';
    try {
      var u = new URL(href, window.location.href);
      key = u.searchParams.get('archivo') || '';
    } catch(e){}

    if (!key) return;

    var nombre = a.getAttribute('data-nombre') || a.textContent.trim() || key.split('/').pop();
    var original = a.getAttribute('data-original') || href;

    if (window.GaleriaUnica && typeof window.GaleriaUnica.ver === 'function') {
      window.GaleriaUnica.ver(key, {
        nombre: nombre,
        urlOriginal: original
      });
    } else {
      window.location.href = href;
    }
  }, true);

  U.on('click', function(e){
    var btn = e.target.closest('#btnVerGaleria');
    if (!btn) return;

    e.preventDefault();
    actualizarBufferGaleria();

    if (window.GaleriaGrid && typeof window.GaleriaGrid.verCarrusel === 'function') {
      window.GaleriaGrid.verCarrusel({ tipo: 'img' });
    }
  }, true);

  U.on('click', function(e){
    var btn = e.target.closest('#btnRecargarGaleria');
    if (!btn) return;

    e.preventDefault();
    actualizarBufferGaleria();

    if (window.GaleriaGrid && typeof window.GaleriaGrid.verCarrusel === 'function') {
      window.GaleriaGrid.verCarrusel({ tipo: 'img' });
    }
  }, true);

  U.on('click', function(e){
    var closeBtn = e.target.closest('.modal .modal-x, .modal [data-bs-dismiss="modal"], .modal [data-dismiss="modal"], .modal .btn-close, .modal .close');
    if (!closeBtn) return;

    var modal = closeBtn.closest('.modal');
    if (modal) {
      e.preventDefault();
      hideModal(modal);
    }
  }, true);

  document.addEventListener('hidden.bs.modal', function(){
    cleanupBackdrops();
  }, true);

  document.addEventListener('hide.bs.modal', function(){
    blurActiveElement();
    setTimeout(cleanupBackdrops, 50);
  }, true);

  document.addEventListener('DOMContentLoaded', function(){
    actualizarBufferGaleria();
  });

  // Cada cambio AJAX de página sustituye #bloque-archivos. Reconstruimos
  // inmediatamente el buffer para que nunca queden imágenes de la página anterior.
  document.addEventListener('bloque-archivos:actualizado', function(){
    setTimeout(actualizarBufferGaleria, 0);
  });

  document.addEventListener('bloque-archivos:actualizado', function(){
    actualizarBufferGaleria();
  });

  document.addEventListener('bloque-archivos:updated', function(){
    actualizarBufferGaleria();
  });

  var target = document.getElementById('bloque-archivos') || document.body;
  if (target && window.MutationObserver) {
    var mo = new MutationObserver(function(){
      actualizarBufferGaleria();
    });
    mo.observe(target, { childList: true, subtree: true });
  }

  window.setGaleriaGridSize = function(n){
    if (!window.GaleriaGrid) return null;
    var final = window.GaleriaGrid.setPerSlide(n);
    try {
      window.GaleriaGrid.verCarrusel({ tipo:'img' });
    } catch(e){}
    return final;
  };

  window.getGaleriaGridSize = function(){
    if (!window.GaleriaGrid) return 6;
    return window.GaleriaGrid.getPerSlide();
  };

})(window, document);