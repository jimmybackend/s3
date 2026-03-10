
function extraerTexto(archivoKey) {
  $.ajax({
    url: 'procesar_textract.php',
    type: 'POST',
    data: { archivo: archivoKey },
    dataType: 'json',
    cache: false,
    success: function(response) {
      if (response && response.error) {
        alert("⚠️ Error Textract: " + response.error);
        return;
      }
      var textoPlano = response.textoJ || (Array.isArray(response.texto) ? response.texto.join("\n") : "");
      if (!textoPlano) textoPlano = "(No se detectó texto)";
      mostrarResultadoTextract(textoPlano, archivoKey);
    },
    error: function(xhr, status, error) {
      alert("❌ Error AJAX: " + error + "\n" + (xhr.responseText || ''));
    }
  });
}

function mostrarResultadoTextract(texto, nombre) {
  var modalId = "resultadoTextractModal";
  var html = `
    <div class="modal fade" id="${modalId}" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title">Texto extraído</h5>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p class="text-muted mb-2"><strong>Archivo:</strong> ${nombre}</p>
            <pre class="p-2 bg-light border rounded" style="white-space: pre-wrap; max-height: 60vh; overflow:auto;">${$('<div>').text(texto).html()}</pre>
          </div>
        </div>
      </div>
    </div>`;
  $('#'+modalId).remove();
  $('body').append(html);
  $('#'+modalId).modal('show').on('hidden.bs.modal', function(){ $(this).remove(); });
}
