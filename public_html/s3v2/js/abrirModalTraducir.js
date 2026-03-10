
function abrirModalTraducir(key, nombre) {
  $('#traducirArchivoKey').val(key);
  $('#traducirTarget').val('es'); // predeterminado
  $('#traducirResultado').val('');
  $('#traducirResultadoBox').addClass('d-none');
  $('#traducirCargando').addClass('d-none');
  $('#modalTraducir').modal('show');
}

function copiarTraducido() {
  const ta = document.getElementById('traducirResultado');
  ta.select(); ta.setSelectionRange(0, 999999);
  document.execCommand('copy');
}

$('#btnTraducirAhora').on('click', function() {
  const archivoKey = $('#traducirArchivoKey').val();
  const target     = $('#traducirTarget').val();

  if (!archivoKey) { alert('Archivo no válido'); return; }

  $('#traducirCargando').removeClass('d-none');
  $('#traducirResultadoBox').addClass('d-none');

  $.ajax({
    url: 'traducir_archivo.php',
    type: 'POST',
    dataType: 'json',
    data: { archivo: archivoKey, target: target, source: 'auto' },
    cache: false
  })
  .done(function(r) {
    if (r && r.error) {
      alert('⚠️ Error: ' + r.error);
      return;
    }
    const texto = r.traduccion || r.translated || r.text || '';
    $('#traducirResultado').val(texto || '(Vacío)');
    $('#traducirResultadoBox').removeClass('d-none');
  })
  .fail(function(xhr, s, e) {
    alert('❌ Error AJAX: ' + e + '\n' + (xhr.responseText || ''));
  })
  .always(function() {
    $('#traducirCargando').addClass('d-none');
  });
});
