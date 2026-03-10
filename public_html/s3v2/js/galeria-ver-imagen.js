/* parche-ver-imagen.js — Prioriza data-original y evita doble apertura del modal */
(function(){
  function abrirEnModalDesde(el){
    var key    = el.getAttribute('data-key') || '';
    var nombre = el.getAttribute('data-nombre') || (key ? key.split('/').pop() : '');
    var orig   = el.getAttribute('data-original') || ''; // URL final

    if (!key && orig) {
      try {
        var u = new URL(orig, window.location.href);
        key = u.searchParams.get('archivo') || u.pathname || orig;
      } catch(e){ key = orig; }
    }

    if (window.GaleriaUnica && typeof window.GaleriaUnica.ver === 'function') {
      window.GaleriaUnica.ver(key, { nombre: nombre, urlOriginal: orig });
    } else {
      if (orig) window.open(orig, '_blank');
    }
  }

  document.addEventListener('click', function(e){
  var btn = e.target.closest('.js-ver-imagen, [data-accion="ver-imagen"]');
  if (!btn) return;
  e.preventDefault();
  e.stopPropagation();
  if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

  // 🔔 avisa al build para que reconstruya el buffer (por si lo usas de respaldo)
  try { document.dispatchEvent(new Event('bloque-archivos:actualizado')); } catch(_){}

  abrirEnModalDesde(btn);
}, true);

  
  
})();
