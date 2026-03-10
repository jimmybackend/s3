
function verPDF(key) {
  const url = 'ver_pdf.php?archivo=' + encodeURIComponent(key);

  $('#tituloEditor').text(key.split('/').pop());
  $('#visorPdf')
    .attr('src', url)
    .show();
  $('#editorTxt, #btnGuardarTxt').hide();
  $('#modalEditorArchivo').modal('show');
}
