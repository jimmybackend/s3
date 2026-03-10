
let _rekogLastKey = null;
function abrirModalRekognition(key) {
  _rekogLastKey = key;
  $('#rekogLabelsBody').empty();
  $('#rekogModerationList').empty();
  $('#rekogModerationBox').addClass('d-none');
  $('#rekogMeta').text('');
  $('#rekogLoading').removeClass('d-none');
  $('#modalRekognition').modal('show');

  $.post('rekognition_labels.php', { archivo: key, min_conf: 70, max_labels: 50, save: 0 }, function(r){
    $('#rekogLoading').addClass('d-none');
    if (!r || !r.ok) {
      return alert('Error Rekognition:\n' + (r && r.error ? r.error : 'Desconocido'));
    }

    $('#rekogMeta').text('Key: ' + r.s3_key + ' | MinConf: ' + r.min_conf + ' | MaxLabels: ' + r.max_labels);

    (r.labels || []).forEach(l => {
      const tr = `<tr>
        <td>${escapeHtml(l.Name || '')}</td>
        <td>${(l.Confidence ?? 0).toFixed(1)}%</td>
        <td>${(l.Parents || []).map(p => escapeHtml(p)).join(', ')}</td>
        <td>${l.Instances ?? 0}</td>
      </tr>`;
      $('#rekogLabelsBody').append(tr);
    });

    if (Array.isArray(r.moderation) && r.moderation.length) {
      r.moderation.forEach(m => {
        $('#rekogModerationList').append(
          `<li>${escapeHtml(m.ParentName ? m.ParentName + ' › ' + m.Name : m.Name)} (${(m.Confidence ?? 0).toFixed(1)}%)</li>`
        );
      });
      $('#rekogModerationBox').removeClass('d-none');
    }
  }, 'json').fail(function(xhr){
    $('#rekogLoading').addClass('d-none');
    alert('❌ Error AJAX Rekognition:\n' + (xhr.responseText || ''));
  });
}

$('#rekogSaveBtn').on('click', function(){
  if (!_rekogLastKey) return;
  $('#rekogLoading').removeClass('d-none');
  $.post('rekognition_labels.php', { archivo: _rekogLastKey, save: 1 }, function(r){
    $('#rekogLoading').addClass('d-none');
    if (!r || !r.ok) return alert('No se pudo guardar en metadatos.');
    alert('✅ Resultado guardado en Metadatos de FileS3.');
    if (typeof actualizarBloqueArchivos === 'function') {
      setTimeout(() => actualizarBloqueArchivos(), 300);
    }
  }, 'json').fail(function(xhr){
    $('#rekogLoading').addClass('d-none');
    alert('❌ Error al guardar:\n' + (xhr.responseText || ''));
  });
});

function escapeHtml(s){ return String(s||'')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
  .replace(/'/g,'&#039;'); }
