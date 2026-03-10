// Delegación de eventos: sirve aunque #bloque-archivos se reemplace por AJAX
$(document).on('click', '.btn-transcribir', function (e) {
  e.preventDefault();
  const key    = $(this).data('key');
  const nombre = $(this).data('nombre') || '';
  if (!key) { alert('No se detectó la clave del archivo.'); return; }
  // Llama a la función que abre el modal
  if (typeof window.abrirModalTranscribir === 'function') {
    window.abrirModalTranscribir(key, nombre);
  } else {
    alert('No se encontró la función abrirModalTranscribir.');
  }
});