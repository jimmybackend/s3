
// fix-modal-backdrop.js — limpia backdrops colgados y normaliza cierres (BS4/BS5)
(function(){
  function cleanupBackdrops(){
    try {
      // Quita backdrop(s) colgados
      document.querySelectorAll('.modal-backdrop').forEach(b => b.parentNode && b.parentNode.removeChild(b));
      // Quita estado de "modal abierto" del body
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('padding-right');
      document.body.style.removeProperty('overflow');
    } catch(e){}
  }

  // Cuando un modal se haya OCULTADO correctamente (BS4/BS5), limpia por si quedó algo
  document.addEventListener('hidden.bs.modal', function(){ cleanupBackdrops(); }, true);
  // Algunos temas/plug-ins disparan hide.bs.modal sin llegar a hidden: cubrimos ambos
  document.addEventListener('hide.bs.modal', function(){ setTimeout(cleanupBackdrops, 50); }, true);

  // Captura clics en cualquier control de cierre: <a class="modal-x">, [data-bs-dismiss], [data-dismiss], .btn-close, .close
  document.addEventListener('click', function(e){
    const closeBtn = e.target.closest('.modal .modal-x, [data-bs-dismiss="modal"], [data-dismiss="modal"], .modal .btn-close, .modal .close');
    if (!closeBtn) return;
    // damos un pequeño respiro para que Bootstrap quite el modal y luego limpiamos lo que quede
    setTimeout(cleanupBackdrops, 80);
  }, true);

  // Antes de MOSTRAR nuestros visores, asegúrate de no tener backdrops previos:
  ['modalImagenUnica','modalGaleriaCompleta'].forEach(function(id){
    document.addEventListener('show.bs.modal', function(ev){
      if (ev && ev.target && ev.target.id === id) cleanupBackdrops();
    }, true);
  });

  // Watchdog: si por alguna razón, 400ms después de cerrar sigue oscuro, forzamos limpieza
  document.addEventListener('click', function(e){
    const mayClose = e.target.closest('.modal [data-bs-dismiss], .modal [data-dismiss], .modal .close, .modal .btn-close, .modal .modal-x');
    if (!mayClose) return;
    setTimeout(function(){
      if (document.querySelector('.modal-backdrop')) cleanupBackdrops();
    }, 400);
  }, true);
})();

