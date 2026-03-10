
// build-imagenes-galeria-from-list.js
(function(){
  function recolectar(){
    var out = [];
    document.querySelectorAll('.list-group .file-row[data-original]').forEach(function(li){
      var key   = li.getAttribute('data-key') || '';
      var nombre= li.getAttribute('data-nombre') || (key ? key.split('/').pop() : '');
      var orig  = li.getAttribute('data-original') || '';
      var imgEl = li.querySelector('img.thumb');
      var thumb = imgEl ? imgEl.src : '';

      if (!orig && key)  orig  = 'ver_archivo.php?archivo=' + encodeURIComponent(key);
      if (!thumb && key) thumb = 'thumb.php?key=' + encodeURIComponent(key) + '&w=128&h=128';

      if (orig || thumb || key){
        out.push({ key:key, nombre:nombre, original:orig, thumb:thumb });
      }
    });
    return out;
  }

  function actualizarBuffer(){
    var lista = recolectar();
    // Publica el buffer global que GaleriaGrid consulta primero
    window.imagenesGaleria = lista;
    // Si el botón está visible, le devolvemos el click para abrir con el nuevo buffer
    var btn = document.getElementById('btnVerGaleria');
    if (btn) btn.classList.toggle('d-none', !lista.length);
  }

  // Inicial
  document.addEventListener('DOMContentLoaded', actualizarBuffer);
  // Si recargas el bloque por AJAX, dispara este evento para refrescar el buffer:
  // document.dispatchEvent(new Event('bloque-archivos:actualizado'));
  document.addEventListener('bloque-archivos:actualizado', actualizarBuffer);

  // Asegura que al hacer click se use el buffer actual
  document.addEventListener('click', function(e){
    var b = e.target.closest('#btnVerGaleria');
    if (!b) return;
    // reconstruye por si cambió la carpeta sin recargar
    actualizarBuffer();
  }, true);
})();

