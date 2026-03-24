(function ($) {
  'use strict';

  // =========================
  // CONFIG
  // =========================
const URLS = {
  pollyVoices: 'polly_list_voices.php',
  pollyTts: 'polly_tts.php',
  pollyTexto: 'polly_cargar_texto.php',
  textract: 'procesar_textract.php',
  rekognition: 'rekognition_labels.php',
  traducir: 'traducir_archivo.php',
  txIniciar: 'transcribir_iniciar.php',
  txEstado: 'transcribir_estado.php'
};
  window.AWS_BUCKET_NAME = window.AWS_BUCKET_NAME || 'm41717c71ck';
  const MODAL_IDS = [
    '#modalTraducir',
    '#modalTranscribir',
    '#modalPollyTTS',
    '#modalRekognition',
    '#modalGrabarAudio',
    '#modalTextract'
  ];

  let rekognitionLastKey = '';
  let txPollingTimer = null;
  let txCurrentJobName = '';
  let txCurrentArchivoKey = '';
  let lastFocusedElement = null;

  // =========================
  // HELPERS
  // =========================
  function safeVal(v, fallback = '') {
    return (v === null || v === undefined) ? fallback : v;
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showAlert(msg) {
    alert(msg);
  }

  function b64toBlob(base64, contentType = 'application/octet-stream') {
    const byteChars = atob(base64);
    const byteArrays = [];
    const sliceSize = 1024;

    for (let offset = 0; offset < byteChars.length; offset += sliceSize) {
      const slice = byteChars.slice(offset, offset + sliceSize);
      const byteNumbers = new Array(slice.length);

      for (let i = 0; i < slice.length; i++) {
        byteNumbers[i] = slice.charCodeAt(i);
      }

      byteArrays.push(new Uint8Array(byteNumbers));
    }

    return new Blob(byteArrays, { type: contentType });
  }

  function setBtnLoading($btn, isLoading, loadingText, normalHtml) {
    if (!$btn || !$btn.length) return;

    if (isLoading) {
      if (!$btn.data('normal-html')) {
        $btn.data('normal-html', $btn.html());
      }
      $btn.prop('disabled', true).html(loadingText || 'Procesando...');
    } else {
      const original = normalHtml || $btn.data('normal-html') || $btn.html();
      $btn.prop('disabled', false).html(original);
    }
  }

  function ajaxPost(url, data) {
    return $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: data
    });
  }

  function ajaxGet(url, data) {
    return $.ajax({
      url: url,
      type: 'GET',
      dataType: 'json',
      data: data
    });
  }

  function focusSafeElement() {
    try {
      const active = document.activeElement;
      if (active && typeof active.blur === 'function') {
        active.blur();
      }
    } catch (e) {}

    try {
      if (!document.body.hasAttribute('tabindex')) {
        document.body.setAttribute('tabindex', '-1');
      }
      document.body.focus({ preventScroll: true });
    } catch (e) {}
  }

  function rememberFocusedElement() {
    const active = document.activeElement;
    if (
      active &&
      active !== document.body &&
      active !== document.documentElement
    ) {
      lastFocusedElement = active;
    } else {
      lastFocusedElement = null;
    }
  }

  function restoreFocusAfterModal() {
    const el = lastFocusedElement;
    lastFocusedElement = null;

    if (
      el &&
      document.contains(el) &&
      $(el).is(':visible') &&
      !$(el).closest('.modal.show').length
    ) {
      try {
        el.focus({ preventScroll: true });
        return;
      } catch (e) {}
    }

    focusSafeElement();
  }

  function hideAnyOpenDropdowns() {
    try {
      $('.dropdown-menu.show').removeClass('show');
      $('.dropdown-toggle[aria-expanded="true"]').attr('aria-expanded', 'false');
    } catch (e) {}
  }


  function bindModalFocusFlow() {
    const selector = MODAL_IDS.join(',');

    $(document)
      .off('show.bs.modal.pollyModals', selector)
      .on('show.bs.modal.pollyModals', selector, function () {
        rememberFocusedElement();
        hideAnyOpenDropdowns();
      });

    $(document)
      .off('shown.bs.modal.pollyModals', selector)
      .on('shown.bs.modal.pollyModals', selector, function () {
        const $modal = $(this);
        const $target = $modal.find(
          'textarea:not([readonly]), input:not([type="hidden"]):not([readonly]), select, button:not(.close)'
        ).filter(':visible').first();

        if ($target.length) {
          try {
            $target.trigger('focus');
          } catch (e) {}
        }
      });

    $(document)
      .off('hide.bs.modal.pollyModals', selector)
      .on('hide.bs.modal.pollyModals', selector, function () {
        try {
          const active = document.activeElement;
          if (active && $.contains(this, active) && typeof active.blur === 'function') {
            active.blur();
          }
        } catch (e) {}

        focusSafeElement();
      });

    $(document)
      .off('hidden.bs.modal.pollyModals', selector)
      .on('hidden.bs.modal.pollyModals', selector, function () {
        const $modal = $(this);

        try {
          $modal.find(':focus').trigger('blur');
        } catch (e) {}

        $modal.attr('aria-hidden', 'true');

        if (this.id === 'modalTranscribir') {
          $('#txCargando').addClass('d-none');
        }

        restoreFocusAfterModal();
      });
  }

  // =========================
  // COPY
  // =========================
  function copiarDesdeTextarea(id) {
    const el = document.getElementById(id);
    if (!el) return;

    el.removeAttribute('readonly');
    el.select();
    el.setSelectionRange(0, 999999);
    document.execCommand('copy');
    el.setAttribute('readonly', 'readonly');
  }

  window.copiarTraducido = function () {
    copiarDesdeTextarea('traducirResultado');
  };

  window.copiarTx = function () {
    copiarDesdeTextarea('txResultado');
  };

  window.copiarTextract = function () {
    copiarDesdeTextarea('textractResultado');
  };

  // =========================
  // MODAL TRADUCIR
  // =========================
  window.abrirModalTraducir = function (key, nombre) {
    $('#traducirArchivoKey').val(safeVal(key));
    $('#traducirTarget').val('es');
    $('#traducirResultado').val('');
    $('#traducirResultadoBox').addClass('d-none');
    $('#traducirCargando').addClass('d-none');
    $('#modalTraducir').modal('show');
  };

  function traducirArchivo() {
    const archivo = $.trim($('#traducirArchivoKey').val());
    const target = $.trim($('#traducirTarget').val()) || 'es';
    const $btn = $('#btnTraducirAhora');

    if (!archivo) {
      showAlert('No se encontró el archivo a traducir.');
      return;
    }

    $('#traducirCargando').removeClass('d-none');
    $('#traducirResultadoBox').addClass('d-none');
    setBtnLoading($btn, true, 'Traduciendo...');

    ajaxPost(URLS.traducir, {
      archivo: archivo,
      target: target,
      source: 'auto'
    })
      .done(function (resp) {
        if (resp && resp.ok) {
          $('#traducirResultado').val(resp.traduccion || '');
          $('#traducirResultadoBox').removeClass('d-none');
        } else {
          showAlert((resp && resp.error) ? resp.error : 'No se pudo traducir el archivo.');
        }
      })
      .fail(function (xhr) {
        showAlert('Error al traducir el archivo.');
        console.error(xhr.responseText || xhr);
      })
      .always(function () {
        $('#traducirCargando').addClass('d-none');
        setBtnLoading($btn, false);
      });
  }

  // =========================
  // MODAL TRANSCRIBIR
  // =========================
  function txBasename(path) {
    const clean = String(path || '').replace(/\\/g, '/');
    const parts = clean.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : '';
  }

  function txFileNameWithoutExt(path) {
    const file = txBasename(path);
    return file.replace(/\.[^.]+$/, '');
  }

  function txFolderFromKey(key) {
    const clean = String(key || '').replace(/^\/+/, '').replace(/\\/g, '/');
    const pos = clean.lastIndexOf('/');
    return pos >= 0 ? clean.substring(0, pos + 1) : '';
  }

  function txMarkCheckboxGroup(name, values) {
    const list = Array.isArray(values) ? values : [];
    $('input[name="' + name + '"]').prop('checked', false);
    list.forEach(function (value) {
      $('input[name="' + name + '"][value="' + value + '"]').prop('checked', true);
    });
  }

  function txGetCheckedValues(name) {
    return $('input[name="' + name + '"]:checked').map(function () {
      return $.trim($(this).val());
    }).get();
  }

  function actualizarUITranscripcion() {
    const mode = $.trim($('#txLanguageMode').val()) || 'specific';
    const modelType = $.trim($('#txModelType').val()) || 'general';
    const audioIdType = $.trim($('input[name="txAudioIdType"]:checked').val()) || 'none';
    const enableAlternatives = $('#txShowAlternatives').is(':checked');
    const enableRedaction = $('#txEnableContentRedaction').is(':checked');
    const enableToxicity = $('#txToxicityDetection').is(':checked');

    $('#txLanguageSpecificBox').toggleClass('d-none', mode !== 'specific');
    $('#txLanguageOptionsBox').toggleClass('d-none', mode === 'specific');
    $('#txCustomModelBox').toggleClass('d-none', modelType !== 'custom');
    $('#txSpeakerBox').toggleClass('d-none', audioIdType !== 'speaker');
    $('#txAlternativesBox').toggleClass('d-none', !enableAlternatives);
    $('#txPiiBox').toggleClass('d-none', !enableRedaction);
    $('#txToxicityBox').toggleClass('d-none', !enableToxicity);
  }

  function resetModalTranscripcion(key, nombre) {
    const archivo = $.trim(safeVal(key));
    const nombreBase = txFileNameWithoutExt(archivo) || $.trim(safeVal(nombre)) || 'transcripcion';
    const folder = txFolderFromKey(archivo);

    $('#txArchivoKey').val(archivo);
    $('#txFolderKey').val(folder);
    $('#txFolderView').val(folder);
    $('#txJobName').val(nombreBase);
    $('#txLanguageMode').val('specific');
    $('#txLanguage').val('es-ES');
    $('#txModelType').val('general');
    $('#txCustomLanguageModelName').val('');
    txMarkCheckboxGroup('txSubtitleFormats[]', []);
    $('input[name="txAudioIdType"][value="none"]').prop('checked', true);
    $('#txMaxSpeakerLabels').val('10');
    $('#txShowAlternatives').prop('checked', false);
    $('#txMaxAlternatives').val('2');
    $('#txEnableContentRedaction').prop('checked', false);
    $('#txPiiEntityTypes').val('');
    $('#txVocabularyName').val('');
    $('#txVocabularyFilterName').val('');
    $('#txVocabularyFilterMethod').val('remove');
    $('#txToxicityDetection').prop('checked', false);
    txMarkCheckboxGroup('txToxicityCategories[]', []);
    $('#txMedicalPhi').prop('checked', false);
    $('#txResultado').val('');
    $('#txResultadoBox').addClass('d-none');
    $('#txCargando').addClass('d-none');
    $('#txEstadoInfo').addClass('d-none').text('');
    actualizarUITranscripcion();
  }

  window.abrirModalTranscribir = function (key, nombre) {
    resetModalTranscripcion(key, nombre);
    $('#modalTranscribir').modal('show');
  };

  window.transcribirAudio = function (key, nombre) {
    window.abrirModalTranscribir(key, nombre);
  };

  function detenerPollingTranscripcion() {
    if (txPollingTimer) {
      clearTimeout(txPollingTimer);
      txPollingTimer = null;
    }
  }

  function consultarEstadoTranscripcion(jobName, intento) {
    intento = intento || 1;

    ajaxPost(URLS.txEstado, {
      jobName: jobName,
      archivo: txCurrentArchivoKey || $.trim($('#txArchivoKey').val())
    })
      .done(function (resp) {
        if (!resp) {
          detenerPollingTranscripcion();
          showAlert('Respuesta inválida del estado de transcripción.');
          return;
        }

        if (resp.error) {
          detenerPollingTranscripcion();
          showAlert(resp.error);
          return;
        }

        const status = resp.status || '';
        const info = [];
        const modalVisible = $('#modalTranscribir').hasClass('show');

        if (status) {
          info.push('Estado: ' + status);
        }
        if (resp.languageCode) {
          info.push('Idioma: ' + resp.languageCode);
        }
        if (resp.subtitleUris && resp.subtitleUris.length) {
          info.push('Subtítulos: ' + resp.subtitleUris.length);
        }

        if (modalVisible) {
          $('#txEstadoInfo').removeClass('d-none').text(info.join(' | '));
        }

        if (status === 'COMPLETED') {
          if (modalVisible) {
            $('#txCargando').addClass('d-none');
            $('#txResultado').val(resp.texto || '');
            $('#txResultadoBox').removeClass('d-none');
          }
          detenerPollingTranscripcion();
          showAlert('La transcripción terminó. Revisa esta misma carpeta para ver el archivo generado.');
          txCurrentJobName = '';
          txCurrentArchivoKey = '';
          return;
        }

        if (status === 'FAILED') {
          if (modalVisible) {
            $('#txCargando').addClass('d-none');
          }
          detenerPollingTranscripcion();
          showAlert(resp.message || 'La transcripción falló.');
          txCurrentJobName = '';
          txCurrentArchivoKey = '';
          return;
        }

        txPollingTimer = setTimeout(function () {
          consultarEstadoTranscripcion(jobName, intento + 1);
        }, 5000);
      })
      .fail(function (xhr) {
        detenerPollingTranscripcion();
        showAlert('Error al consultar estado de transcripción.');
        console.error(xhr.responseText || xhr);
      });
  }

  function iniciarTranscripcion() {
    const archivo = $.trim($('#txArchivoKey').val());
    const languageMode = $.trim($('#txLanguageMode').val()) || 'specific';
    const languageCode = $.trim($('#txLanguage').val()) || 'es-ES';
    const jobName = $.trim($('#txJobName').val()) || txFileNameWithoutExt(archivo) || 'transcripcion';
    const modelType = $.trim($('#txModelType').val()) || 'general';
    const customLanguageModelName = $.trim($('#txCustomLanguageModelName').val());
    const outputFolder = $.trim($('#txFolderKey').val());
    const subtitleFormats = txGetCheckedValues('txSubtitleFormats[]');
    const audioIdType = $.trim($('input[name="txAudioIdType"]:checked').val()) || 'none';
    const showAlternatives = $('#txShowAlternatives').is(':checked');
    const enableContentRedaction = $('#txEnableContentRedaction').is(':checked');
    const toxicityDetection = $('#txToxicityDetection').is(':checked');
    const $btn = $('#btnTxIniciar');

    if (!archivo) {
      showAlert('No se encontró el archivo de audio/video.');
      return;
    }

    if ($('#txMedicalPhi').is(':checked')) {
      showAlert('PHI requiere Amazon Transcribe Medical y no el flujo estándar de este modal.');
      return;
    }

    $('#txResultado').val('');
    $('#txResultadoBox').addClass('d-none');
    $('#txEstadoInfo').removeClass('d-none').text('Enviando trabajo...');
    $('#txCargando').removeClass('d-none');
    detenerPollingTranscripcion();
    setBtnLoading($btn, true, 'Iniciando...');

    ajaxPost(URLS.txIniciar, {
      archivo: archivo,
      jobName: jobName,
      languageMode: languageMode,
      languageCode: languageCode,
      languageOptions: txGetCheckedValues('txLanguageOptions[]'),
      modelType: modelType,
      customLanguageModelName: customLanguageModelName,
      outputMode: 'customer',
      outputS3Uri: outputFolder ? ('s3://' + window.AWS_BUCKET_NAME + '/' + outputFolder) : ('s3://' + window.AWS_BUCKET_NAME + '/'),
      outputKey: outputFolder,
      subtitleFormats: subtitleFormats,
      enableChannelIdentification: audioIdType === 'channel' ? 1 : 0,
      enableShowSpeakerLabels: audioIdType === 'speaker' ? 1 : 0,
      maxSpeakerLabels: $.trim($('#txMaxSpeakerLabels').val()) || '10',
      showAlternatives: showAlternatives ? 1 : 0,
      maxAlternatives: $.trim($('#txMaxAlternatives').val()) || '2',
      enableContentRedaction: enableContentRedaction ? 1 : 0,
      piiEntityTypes: $.trim($('#txPiiEntityTypes').val()),
      vocabularyName: $.trim($('#txVocabularyName').val()),
      vocabularyFilterName: $.trim($('#txVocabularyFilterName').val()),
      vocabularyFilterMethod: $.trim($('#txVocabularyFilterMethod').val()) || 'remove',
      toxicityDetection: toxicityDetection ? 1 : 0,
      toxicityCategories: txGetCheckedValues('txToxicityCategories[]')
    })
      .done(function (resp) {
        if (resp && resp.ok && resp.jobName) {
          txCurrentJobName = resp.jobName;
          txCurrentArchivoKey = archivo;
          $('#txEstadoInfo').removeClass('d-none').text('Trabajo creado: ' + resp.jobName);
          $('#txCargando').addClass('d-none');
          showAlert('La transcripción fue enviada correctamente. Se está procesando en segundo plano. Cuando termine, revisa esta misma carpeta.');
          $('#modalTranscribir').modal('hide');
          consultarEstadoTranscripcion(resp.jobName, 1);
        } else {
          $('#txCargando').addClass('d-none');
          showAlert((resp && resp.error) ? resp.error : 'No se pudo iniciar la transcripción.');
        }
      })
      .fail(function (xhr) {
        $('#txCargando').addClass('d-none');
        showAlert('Error al iniciar la transcripción.');
        console.error(xhr.responseText || xhr);
      })
      .always(function () {
        setBtnLoading($btn, false);
      });
  }

  $(document)
    .off('change.txUi', '#txLanguageMode, #txModelType, input[name="txAudioIdType"], #txShowAlternatives, #txEnableContentRedaction, #txToxicityDetection')
    .on('change.txUi', '#txLanguageMode, #txModelType, input[name="txAudioIdType"], #txShowAlternatives, #txEnableContentRedaction, #txToxicityDetection', actualizarUITranscripcion);

  $(document)
    .off('click.txIniciar', '#btnTxIniciar')
    .on('click.txIniciar', '#btnTxIniciar', iniciarTranscripcion);

  // =========================
  // MODAL POLLY
  // =========================
window.abrirModalPolly = function (key, nombre) {
  const archivoKey = $.trim(safeVal(key));
  const ext = archivoKey.split('.').pop().toLowerCase();

  $('#pollyArchivoKey').val(archivoKey);
  $('#pollyArchivoKeyView').val(archivoKey);
  $('#pollyTexto').val('');
  $('#pollyAudio').attr('src', '');
  $('#pollyDownload').attr('href', '#').attr('download', 'polly.mp3');
  $('#pollyS3Note').text('');
  $('#pollyPlayerBox').addClass('d-none');
  $('#pollyCargando').removeClass('d-none');

  $('#modalPollyTTS').modal('show');
  cargarVocesPolly();

  if (ext !== 'txt' && ext !== 'md') {
    $('#pollyCargando').addClass('d-none');
    $('#pollyTexto').val('');
    showAlert('Polly solo carga automáticamente archivos TXT o MD.');
    return;
  }

  ajaxPost(URLS.pollyTexto, {
    archivo: archivoKey
  })
    .done(function (resp) {
      if (resp && resp.ok) {
        $('#pollyTexto').val(resp.texto || '');
      } else {
        showAlert((resp && resp.error) ? resp.error : 'No se pudo cargar el contenido del TXT.');
      }
    })
    .fail(function (xhr) {
      showAlert('Error al cargar el contenido del TXT para Polly.');
      console.error(xhr.responseText || xhr);
    })
    .always(function () {
      $('#pollyCargando').addClass('d-none');
    });
};

  function renderPollyVoices(voices) {
    const $voice = $('#pollyVoice');
    const engineActual = $.trim($('#pollyEngine').val()) || 'neural';

    $voice.empty();

    if (!Array.isArray(voices) || !voices.length) {
      $voice.append('<option value="">No hay voces disponibles</option>');
      $('#pollyEngineHelp').text('');
      return;
    }

    voices.forEach(function (v) {
      const engines = Array.isArray(v.SupportedEngines) ? v.SupportedEngines : [];
      const label = [
        safeVal(v.Name, v.Id),
        v.Gender ? ('(' + v.Gender + ')') : '',
        engines.length ? ('- [' + engines.join(', ') + ']') : ''
      ].join(' ').replace(/\s+/g, ' ').trim();

      const $opt = $('<option>', {
        value: safeVal(v.Id),
        text: label
      });

      $opt.attr('data-engines', engines.join(','));
      $voice.append($opt);
    });

    const firstValid = voices.find(function (v) {
      return Array.isArray(v.SupportedEngines) && v.SupportedEngines.indexOf(engineActual) !== -1;
    });

    if (firstValid) {
      $voice.val(firstValid.Id);
    } else {
      $voice.prop('selectedIndex', 0);
    }

    actualizarAyudaMotorPolly();
  }

  function actualizarAyudaMotorPolly() {
    const $selected = $('#pollyVoice option:selected');
    const engines = ($selected.data('engines') || '').toString().split(',').filter(Boolean);
    const engine = $.trim($('#pollyEngine').val()) || 'neural';

    if (!engines.length) {
      $('#pollyEngineHelp').text('');
      return;
    }

    if (engines.indexOf(engine) === -1) {
      $('#pollyEngineHelp').text('La voz seleccionada no soporta ' + engine + '. Polly intentará fallback si aplica.');
    } else {
      $('#pollyEngineHelp').text('Motores soportados por esta voz: ' + engines.join(', '));
    }
  }

  function cargarVocesPolly() {
    const language = $.trim($('#pollyLanguage').val()) || '';
    const $voice = $('#pollyVoice');

    $voice.html('<option value="">Cargando voces...</option>');
    $('#pollyEngineHelp').text('');

    ajaxGet(URLS.pollyVoices, { language: language })
      .done(function (resp) {
        if (resp && resp.ok) {
          renderPollyVoices(resp.voices || []);
        } else {
          $voice.html('<option value="">Error al cargar voces</option>');
          showAlert((resp && resp.error) ? resp.error : 'No se pudieron cargar las voces.');
        }
      })
      .fail(function (xhr) {
        $voice.html('<option value="">Error al cargar voces</option>');
        showAlert('Error al cargar voces de Polly.');
        console.error(xhr.responseText || xhr);
      });
  }

function generarAudioPolly() {
  const fromKey = $.trim($('#pollyArchivoKey').val());
  const texto = $.trim($('#pollyTexto').val());
  const voiceId = $.trim($('#pollyVoice').val());
  const engine = $.trim($('#pollyEngine').val()) || 'neural';
  const format = $.trim($('#pollyFormat').val()) || 'mp3';
  const sampleRate = $.trim($('#pollySample').val()) || '22050';
  const toS3 = $('#pollyToS3').is(':checked') ? 1 : 0;
  const $btn = $('#btnPollyGenerar');

  if (!fromKey) {
    showAlert('No se encontró el archivo origen.');
    return;
  }

  if (!texto) {
    showAlert('No hay texto cargado para generar el audio.');
    return;
  }

  if (!voiceId) {
    showAlert('Debes seleccionar una voz.');
    return;
  }

  $('#pollyCargando').removeClass('d-none');
  $('#pollyPlayerBox').addClass('d-none');
  setBtnLoading($btn, true, 'Generando...');

  ajaxPost(URLS.pollyTts, {
    texto: texto,
    voiceId: voiceId,
    engine: engine,
    format: format,
    sampleRate: sampleRate,
    from_key: fromKey,
    to_s3: toS3
  })
    .done(function (resp) {
      if (resp && resp.ok) {
        const audioBase64 = resp.audioBase64 || '';
        if (!audioBase64) {
          showAlert('El servidor no devolvió audio válido.');
          return;
        }

        const blob = b64toBlob(audioBase64, resp.contentType || 'audio/mpeg');
        const audioUrl = URL.createObjectURL(blob);
        const filename = resp.filename || ('polly.' + (format === 'ogg_vorbis' ? 'ogg' : format));

        $('#pollyAudio').attr('src', audioUrl)[0].load();

        $('#pollyDownload')
          .attr('href', audioUrl)
          .attr('download', filename);

        if (resp.mode === 's3' && resp.s3_key) {
          $('#pollyS3Note').text('Guardado en S3: ' + resp.s3_key);
        } else {
          $('#pollyS3Note').text('Audio generado sin guardar en S3.');
        }

        $('#pollyPlayerBox').removeClass('d-none');
      } else {
        showAlert((resp && resp.error) ? resp.error : 'No se pudo generar el audio.');
      }
    })
    .fail(function (xhr) {
      let msg = 'Error al generar el audio con Polly.';
      if (xhr.responseJSON && xhr.responseJSON.error) {
        msg = xhr.responseJSON.error;
      }
      showAlert(msg);
      console.error(xhr.responseText || xhr);
    })
    .always(function () {
      $('#pollyCargando').addClass('d-none');
      setBtnLoading($btn, false);
    });
}

  // =========================
  // MODAL REKOGNITION
  // =========================
 // =========================
// MODAL REKOGNITION
// =========================
window.abrirModalRekognition = function (key) {
  rekognitionLastKey = safeVal(key);

  $('#rekogLoading').removeClass('d-none');
  $('#rekogMeta').html('');
  $('#rekogLabelsBody').html('');
  $('#rekogModerationList').html('');
  $('#rekogModerationBox').addClass('d-none');

  $('#modalRekognition').modal('show');

  ajaxPost(URLS.rekognition, {
    archivo: rekognitionLastKey,
    min_conf: 70,
    max_labels: 50
  })
    .done(function (resp) {
      if (resp && resp.ok) {
        pintarRekognition(resp);
      } else {
        showAlert((resp && resp.error) ? resp.error : 'No se pudo analizar la imagen.');
      }
    })
    .fail(function (xhr) {
      showAlert('Error al analizar la imagen.');
      console.error(xhr.responseText || xhr);
    })
    .always(function () {
      $('#rekogLoading').addClass('d-none');
    });
};

function pintarRekognition(resp) {
  const labels = Array.isArray(resp.labels) ? resp.labels : [];
  const moderation = Array.isArray(resp.moderation) ? resp.moderation : [];
  const savedText = resp.saved
    ? '<span class="text-success"> | Guardado automático en metadatos</span>'
    : '<span class="text-warning"> | Análisis realizado</span>';

  $('#rekogMeta').html(
    'Archivo: ' + escapeHtml(safeVal(resp.s3_key, '')) +
    ' | Min. conf: ' + escapeHtml(String(safeVal(resp.min_conf, ''))) +
    ' | Máx etiquetas: ' + escapeHtml(String(safeVal(resp.max_labels, ''))) +
    savedText
  );

  if (!labels.length) {
    $('#rekogLabelsBody').html(
      '<tr><td colspan="4" class="text-center text-muted">Sin etiquetas detectadas</td></tr>'
    );
  } else {
    const rows = labels.map(function (lab) {
      const parents = Array.isArray(lab.Parents) && lab.Parents.length
        ? lab.Parents.join(', ')
        : '-';

      return '<tr>' +
        '<td>' + escapeHtml(safeVal(lab.Name, '')) + '</td>' +
        '<td>' + escapeHtml(String(safeVal(lab.Confidence, ''))) + '</td>' +
        '<td>' + escapeHtml(parents) + '</td>' +
        '<td>' + escapeHtml(String(safeVal(lab.Instances, 0))) + '</td>' +
        '</tr>';
    }).join('');

    $('#rekogLabelsBody').html(rows);
  }

  if (moderation.length) {
    const items = moderation.map(function (m) {
      const txt = m.ParentName
        ? (m.ParentName + ' > ' + m.Name + ' (' + m.Confidence + '%)')
        : (m.Name + ' (' + m.Confidence + '%)');

      return '<li>' + escapeHtml(txt) + '</li>';
    }).join('');

    $('#rekogModerationList').html(items);
    $('#rekogModerationBox').removeClass('d-none');
  } else {
    $('#rekogModerationList').html('');
    $('#rekogModerationBox').addClass('d-none');
  }
}

  // =========================
  // TEXTRACT
  // =========================

window.copiarTextract = function () {
  copiarDesdeTextarea('textractResultado');
};

window.extraerTexto = function (archivoKey) {
  if (!archivoKey) {
    showAlert('Archivo inválido.');
    return;
  }

  $('#textractArchivoKey').val(archivoKey);
  $('#textractResultado').val('');
  $('#textractResultadoBox').addClass('d-none');
  $('#textractCargando').removeClass('d-none');
  $('#modalTextract').modal('show');

  ajaxPost(URLS.textract, {
    archivo: archivoKey
  })
    .done(function (resp) {
      if (resp && resp.ok) {
        window.mostrarResultadoTextract(resp.textoJ || '', archivoKey);
      } else {
        $('#textractCargando').addClass('d-none');
        showAlert((resp && resp.error) ? resp.error : 'No se pudo extraer el texto.');
      }
    })
    .fail(function (xhr) {
      $('#textractCargando').addClass('d-none');
      showAlert('Error al procesar Textract.');
      console.error(xhr.responseText || xhr);
    });
};

window.mostrarResultadoTextract = function (texto, nombre) {
  $('#textractArchivoKey').val(nombre || '');
  $('#textractResultado').val(texto || '');
  $('#textractResultadoBox').removeClass('d-none');
  $('#textractCargando').addClass('d-none');

  if (!$('#modalTextract').hasClass('show')) {
    $('#modalTextract').modal('show');
  }
};
  // =========================
  // GRABAR AUDIO
  // =========================
  window.abrirModalGrabarAudio = function () {
    $('#modalGrabarAudio').modal('show');
  };

  // =========================
  // EVENTOS
  // =========================
$(function () {
  bindModalFocusFlow();

  $('#btnTraducirAhora')
    .off('click.polly')
    .on('click.polly', traducirArchivo);

  $('#btnTxIniciar')
    .off('click.polly')
    .on('click.polly', iniciarTranscripcion);

  $('#btnPollyGenerar')
    .off('click.polly')
    .on('click.polly', generarAudioPolly);

  $('#pollyLanguage')
    .off('change.polly')
    .on('change.polly', cargarVocesPolly);

  $('#pollyEngine')
    .off('change.polly')
    .on('change.polly', actualizarAyudaMotorPolly);

  $('#pollyVoice')
    .off('change.polly')
    .on('change.polly', actualizarAyudaMotorPolly);

  $(document)
    .off('click.pollyTranscribir', '.js-transcribir')
    .on('click.pollyTranscribir', '.js-transcribir', function () {
      const key = $(this).data('key') || '';
      const nombre = $(this).data('nombre') || '';
      window.abrirModalTranscribir(key, nombre);
    });

  $(document)
    .off('click.pollyPolly', '.js-polly')
    .on('click.pollyPolly', '.js-polly', function () {
      const key = $(this).data('key') || '';
      const nombre = $(this).data('nombre') || '';
      window.abrirModalPolly(key, nombre);
    });

  $(document)
    .off('click.pollyTraducir', '.js-traducir')
    .on('click.pollyTraducir', '.js-traducir', function () {
      const key = $(this).data('key') || '';
      const nombre = $(this).data('nombre') || '';
      window.abrirModalTraducir(key, nombre);
    });

  $(document)
    .off('click.pollyRekognition', '.js-rekognition')
    .on('click.pollyRekognition', '.js-rekognition', function () {
      const key = $(this).data('key') || '';
      window.abrirModalRekognition(key);
    });

  $(document)
    .off('click.pollyTextract', '.js-textract')
    .on('click.pollyTextract', '.js-textract', function () {
      const key = $(this).data('key') || '';
      window.extraerTexto(key);
    });

  $(document)
    .off('click.pollyTextractCopy', '#btnCopiarTextract')
    .on('click.pollyTextractCopy', '#btnCopiarTextract', function () {
      window.copiarTextract();
    });
});

})(jQuery);