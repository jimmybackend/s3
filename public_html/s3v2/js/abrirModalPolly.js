
// =======================
// Utilidades y estado
// =======================
const EDITABLES_TEXTO = ['txt','md','csv','log','json','xml','html','js','css','php','py'];
const MIME_BY_FORMAT  = { mp3: 'audio/mpeg', ogg_vorbis: 'audio/ogg', pcm: 'audio/wav' };

function getExtFromKey(key='') {
  const p = String(key).split('?')[0]; // ignora querystring
  const n = p.split('/').pop() || '';
  const i = n.lastIndexOf('.');
  return i >= 0 ? n.slice(i+1).toLowerCase() : '';
}

// =======================
// Abrir modal (global)
// =======================
window.abrirModalPolly = function(key, nombre='') {
  // ⚠️ Asegúrate de tener solo UN #pollyArchivoKey en el HTML
  $('#pollyArchivoKey').val(key || '');
  $('#pollyPlayerBox').addClass('d-none');
  $('#pollyCargando').addClass('d-none');
  $('#pollyEngineHelp').text('');
  $('#pollyS3Note').text('');
  $('#pollyTexto').val('');

  const ext = getExtFromKey(key);
  if (key && EDITABLES_TEXTO.includes(ext)) {
    $('#pollyTexto').val('Cargando contenido…');
    $.post('token_texto.php', { archivo: key }, function(r){
      $('#pollyTexto').val((r && r.estado === 'ok') ? (r.contenido || '') : '');
      $('#modalPollyTTS').modal('show');
    }, 'json').fail(function(){
      $('#pollyTexto').val('');
      $('#modalPollyTTS').modal('show');
    });
  } else {
    $('#modalPollyTTS').modal('show');
  }
};

// =======================
// Voces por idioma
// =======================
const POLLY_VOICES = {
  'es-ES': [
    { id:'Lucia',   name:'Lucia (F)',   engines:'neural,standard' },
    { id:'Sergio',  name:'Sergio (M)',  engines:'neural,standard' },
    { id:'Conchita',name:'Conchita (F)',engines:'standard' },
    { id:'Enrique', name:'Enrique (M)', engines:'standard' }
  ],
  'es-MX': [{ id:'Mia', name:'Mia (F)', engines:'neural,standard' }],
  'es-US': [
    { id:'Penelope',name:'Penelope (F)',engines:'standard' },
    { id:'Miguel',  name:'Miguel (M)',  engines:'standard' }
  ],
  'en-US': [
    { id:'Joanna',  name:'Joanna (F)',  engines:'neural,standard' },
    { id:'Matthew', name:'Matthew (M)', engines:'neural,standard' }
  ],
  'en-GB': [
    { id:'Amy',   name:'Amy (F)',   engines:'neural,standard' },
    { id:'Brian', name:'Brian (M)', engines:'neural,standard' }
  ],
  'pt-BR': [
    { id:'Camila',  name:'Camila (F)',  engines:'neural,standard' },
    { id:'Vitoria', name:'Vitoria (F)', engines:'neural,standard' },
    { id:'Ricardo', name:'Ricardo (M)', engines:'standard' }
  ],
  'fr-FR': [
    { id:'Lea',     name:'Léa (F)',     engines:'neural,standard' },
    { id:'Mathieu', name:'Mathieu (M)', engines:'neural,standard' },
    { id:'Celine',  name:'Céline (F)',  engines:'standard' }
  ],
  'de-DE': [
    { id:'Vicki',   name:'Vicki (F)',   engines:'neural,standard' },
    { id:'Marlene', name:'Marlene (F)', engines:'standard' },
    { id:'Hans',    name:'Hans (M)',    engines:'standard' }
  ],
  'it-IT': [
    { id:'Carla',   name:'Carla (F)',   engines:'neural,standard' },
    { id:'Giorgio', name:'Giorgio (M)', engines:'neural,standard' },
    { id:'Bianca',  name:'Bianca (F)',  engines:'standard' }
  ],
  'ja-JP': [
    { id:'Takumi',  name:'Takumi (M)',  engines:'neural,standard' },
    { id:'Mizuki',  name:'Mizuki (F)',  engines:'standard' }
  ],
  'ko-KR': [{ id:'Seoyeon', name:'Seoyeon (F)', engines:'standard' }]
};

function renderVoices(lang) {
  const $v = $('#pollyVoice');
  const prev = $v.val();
  $v.empty();
  const list = POLLY_VOICES[lang] || [];
  if (!list.length) {
    $v.append('<option value="">— No hay voces locales —</option>');
    return;
  }
  list.forEach(v => {
    $v.append(`<option value="${v.id}" data-engines="${v.engines}">${v.name} · ${v.id}</option>`);
  });
  // intenta conservar la selección anterior si coincide
  if (prev && $v.find(`option[value="${prev}"]`).length) $v.val(prev);
}

$('#pollyLanguage').on('change', function(){
  renderVoices(this.value);
});

$('#modalPollyTTS').on('show.bs.modal', function(){
  renderVoices($('#pollyLanguage').val());
});

// =======================
// Generar audio (handler)
// =======================
$('#btnPollyGenerar').on('click', function(){
  const texto    = $('#pollyTexto').val().trim();
  const $sel     = $('#pollyVoice').find(':selected');
  const voiceId  = $sel.val();
  const engines  = String($sel.data('engines') || '').split(',').map(s => s.trim()).filter(Boolean);

  let engine     = $('#pollyEngine').val();              // 'neural' | 'standard'
  const format   = $('#pollyFormat').val();              // 'mp3' | 'ogg_vorbis' | 'pcm'
  const sample   = $('#pollySample').val();
  const toS3     = $('#pollyToS3').prop('checked') ? 1 : 0;
  const fromKey  = $('#pollyArchivoKey').val() || '';

  if (!texto)   return alert('Escribe el texto a leer.');
  if (!voiceId) return alert('Selecciona una voz.');

  // Ajuste automático del motor si la voz no lo soporta
  if (engines.length && !engines.includes(engine)) {
    engine = engines.includes('neural') ? 'neural' : 'standard';
    $('#pollyEngine').val(engine);
    $('#pollyEngineHelp').text('Se ajustó automáticamente el motor a "' + engine + '".');
  } else {
    $('#pollyEngineHelp').text('');
  }

  // UI
  $('#pollyCargando').removeClass('d-none');
  $('#pollyPlayerBox').addClass('d-none');
  $('#pollyS3Note').text('');

  $.ajax({
    url: 'polly_tts.php',
    type: 'POST',
    dataType: 'json',
    data: { texto, voiceId, engine, format, sampleRate: sample, to_s3: toS3, from_key: fromKey }
  })
  .done(function(r){
    if (!r || !r.ok) {
      return alert('No se pudo generar audio.' + (r && r.error ? '\n' + r.error : ''));
    }

    // Previsualizar SIEMPRE si viene audioBase64
    const ct = r.contentType || MIME_BY_FORMAT[format] || 'audio/mpeg';
    if (r.audioBase64) {
      try {
        const bin  = atob(r.audioBase64);
        const len  = bin.length;
        const buf  = new Uint8Array(len);
        for (let i = 0; i < len; i++) buf[i] = bin.charCodeAt(i);
        const blob = new Blob([buf], { type: ct });
        const url  = URL.createObjectURL(blob);

        $('#pollyAudio').attr('src', url);
        const dlName = r.filename || ('polly.' + (format === 'mp3' ? 'mp3' : (format === 'ogg_vorbis' ? 'ogg' : 'wav')));
        $('#pollyDownload').attr('href', url).attr('download', dlName);
        $('#pollyPlayerBox').removeClass('d-none');
      } catch (e) {
        console.error('Error preparando audio inline:', e);
      }
    }

    // Mensaje si se guardó en S3 + refresco de lista (si tienes función)
    if (toS3 && (r.s3_key || r.output)) {
      $('#pollyS3Note').text('Guardado en S3: ' + (r.s3_key || r.output));
      // Refresca el listado de la carpeta actual si tu app lo soporta
      if (typeof actualizarBloqueArchivos === 'function') {
        setTimeout(() => actualizarBloqueArchivos(), 400);
      }
    }
  })
  .fail(function(xhr, st, e){
    alert('❌ Error AJAX: ' + (xhr.responseText || e));
  })
  .always(function(){
    $('#pollyCargando').addClass('d-none');
  });
});
