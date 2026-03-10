
let txPoll = null;

function abrirModalTranscribir(key, nombre) {
  $('#txArchivoKey').val(key);
  $('#txLanguage').val('es-ES'); // default español
  $('#txResultado').val('');
  $('#txResultadoBox').addClass('d-none');
  $('#txCargando').addClass('d-none');
  $('#modalTranscribir').modal('show');
}

function copiarTx() {
  const ta = document.getElementById('txResultado');
  ta.select(); ta.setSelectionRange(0, 999999);
  document.execCommand('copy');
}

$('#btnTxIniciar').on('click', function() {
  const archivoKey = $('#txArchivoKey').val();
  const language   = $('#txLanguage').val();
  if (!archivoKey) { alert('Archivo no válido'); return; }

  $('#txCargando').removeClass('d-none');
  $('#txResultadoBox').addClass('d-none');

  $.ajax({
    url: 'transcribir_iniciar.php',
    type: 'POST',
    dataType: 'json',
    data: { archivo: archivoKey, language: language },
  }).done(function(r) {
    if (r && r.error) { alert('⚠️ ' + r.error); return; }
    if (!r || !r.jobName) { alert('No se obtuvo jobName'); return; }
    // Polling
    if (txPoll) clearInterval(txPoll);
    txPoll = setInterval(function() {
      $.ajax({
        url: 'transcribir_estado.php',
        type: 'POST',
        dataType: 'json',
        data: { jobName: r.jobName }
      }).done(function(s) {
        if (!s || !s.status) return;
        if (s.status === 'FAILED') {
          clearInterval(txPoll);
          $('#txCargando').addClass('d-none');
          alert('❌ Falló la transcripción: ' + (s.message || ''));
        }
        if (s.status === 'COMPLETED') {
          clearInterval(txPoll);
          $('#txCargando').addClass('d-none');
          $('#txResultado').val(s.texto || '(Vacío)');
          $('#txResultadoBox').removeClass('d-none');
        }
      }).fail(function(xhr, st, e){
        console.error('poll error', e, xhr.responseText);
      });
    }, 3000);
  }).fail(function(xhr, st, e) {
    $('#txCargando').addClass('d-none');
    alert('❌ Error AJAX: ' + e + '\n' + (xhr.responseText || ''));
  });
});
