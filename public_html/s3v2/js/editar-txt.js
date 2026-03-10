
function editarTxt(key) {
  const extensionesEditables = ['txt', 'jas', 'inf', 'bat', 'html', 'php', 'js', 'css', 'py'];
  const ext = key.split('.').pop().toLowerCase();

  if (ext === 'pdf') {
    verPDF(key);
    return;
  }

  if (!extensionesEditables.includes(ext)) {
    alert('Este tipo de archivo no es editable desde el navegador.');
    return;
  }

  $.post('token_texto.php', { archivo: key }, function(data) {
    if (data.estado !== 'ok') {
      alert('Error al cargar archivo: ' + data.mensaje);
      return;
    }

    $('#tituloEditor').text(key.split('/').pop());
    $('#editorTxt').val(data.contenido).show();
    $('#visorPdf').hide();
    $('#btnGuardarTxt').data('archivo', key).show();
    $('#modalEditorArchivo').modal('show');
  }, 'json');
}

$('#btnGuardarTxt').on('click', function() {
  const key = $(this).data('archivo');
  const contenido = $('#editorTxt').val();

  $.post('guardar_texto.php', { archivo: key, contenido: contenido }, function(respuesta) {
    if (respuesta.estado === 'ok') {
      alert('Cambios guardados correctamente.');
      $('#modalEditorArchivo').modal('hide');
    } else {
      alert('Error: ' + respuesta.mensaje);
    }
  }, 'json');
});

