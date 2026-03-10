window.abrirModalTranscribir = function (key, nombre) {
  $('#txArchivoKey').val(key);
  $('#txLanguage').val('es-ES'); // default español
  $('#txResultado').val('');
  $('#txResultadoBox').addClass('d-none');
  $('#txCargando').addClass('d-none');
  $('#modalTranscribir').modal('show');
};