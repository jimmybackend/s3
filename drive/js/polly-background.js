class PollyBackgroundModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.$ = win.jQuery;
    this.startUrl = 'polly_tts.php';
  }

  init() {
    if (!this.$) return this;
    this.bindUi();
    this.updateUiHints();
    return this;
  }

  bindUi() {
    const $ = this.$;

    $('#btnPollyGenerar')
      .off('click.polly')
      .off('click.pollyBackground')
      .on('click.pollyBackground', () => this.generate());

    $('#pollyEngine')
      .off('change.polly')
      .off('change.pollyBackground')
      .on('change.pollyBackground', () => {
        this.ensureVoiceSupportsSelectedEngine(true);
        this.updateEngineHelp();
      });

    $('#pollyVoice')
      .off('change.polly')
      .off('change.pollyBackground')
      .on('change.pollyBackground', () => {
        this.ensureEngineSupportsSelectedVoice(true);
        this.updateEngineHelp();
      });

    $('#modalPollyTTS')
      .off('shown.bs.modal.pollyBackground')
      .on('shown.bs.modal.pollyBackground', () => {
        this.window.setTimeout(() => {
          this.ensureVoiceSupportsSelectedEngine(false);
          this.updateEngineHelp();
        }, 250);
      });
  }

  updateUiHints() {
    const $ = this.$;
    $('#pollyTexto').attr(
      'placeholder',
      'Escribe o pega texto. Si se guarda en S3, el servidor lo procesará en segundo plano.'
    );

    const $switchHelp = $('#pollyToS3').closest('.custom-control').next('small');
    if ($switchHelp.length) {
      $switchHelp.text(
        'Al guardar en S3, la tarea queda registrada en el servidor. Puedes cerrar el navegador; el audio y sus costos se finalizarán automáticamente.'
      );
    }
  }

  optionEngines($option) {
    return String($option.attr('data-engines') || $option.data('engines') || '')
      .split(',')
      .map((value) => String(value || '').trim().toLowerCase())
      .filter(Boolean);
  }

  selectedEngines() {
    return this.optionEngines(this.$('#pollyVoice option:selected'));
  }

  ensureVoiceSupportsSelectedEngine(notifyChange) {
    const $ = this.$;
    const engine = String($('#pollyEngine').val() || '').trim().toLowerCase();
    const $selected = $('#pollyVoice option:selected');
    const currentEngines = this.optionEngines($selected);

    if (!engine || !currentEngines.length || currentEngines.includes(engine)) return true;

    let replacement = null;
    $('#pollyVoice option').each((_, option) => {
      if (replacement) return;
      const $option = $(option);
      if (this.optionEngines($option).includes(engine)) replacement = $option;
    });

    if (!replacement) return false;
    $('#pollyVoice').val(replacement.val());
    if (notifyChange) {
      this.notify('Cambié la voz por una compatible con el motor ' + engine + '.', 'info', 4500);
    }
    return true;
  }

  ensureEngineSupportsSelectedVoice(notifyChange) {
    const $ = this.$;
    const engines = this.selectedEngines();
    const current = String($('#pollyEngine').val() || '').trim().toLowerCase();

    if (!engines.length || engines.includes(current)) return true;

    const preferred = ['neural', 'standard'].find((engine) =>
      engines.includes(engine) && $('#pollyEngine option[value="' + engine + '"]').length
    );
    if (!preferred) return false;

    $('#pollyEngine').val(preferred);
    if (notifyChange) {
      this.notify('La voz elegida usa el motor ' + preferred + '; lo seleccioné automáticamente.', 'info', 4500);
    }
    return true;
  }

  updateEngineHelp() {
    const $ = this.$;
    const engines = this.selectedEngines();
    const engine = String($('#pollyEngine').val() || '').trim().toLowerCase();
    const $help = $('#pollyEngineHelp');

    if (!engines.length) {
      $help.text('');
      return;
    }
    if (engines.includes(engine)) {
      $help.text('Compatible con ' + engine + '. Motores de esta voz: ' + engines.join(', ') + '.');
      return;
    }
    $help.text('Esta voz no soporta ' + engine + '. Motores disponibles: ' + engines.join(', ') + '.');
  }

  async generate() {
    const $ = this.$;
    const fromKey = String($('#pollyArchivoKey').val() || '').trim();
    const texto = String($('#pollyTexto').val() || '').trim();
    const voiceId = String($('#pollyVoice').val() || '').trim();
    const engine = String($('#pollyEngine').val() || 'neural').trim().toLowerCase();
    const format = String($('#pollyFormat').val() || 'mp3').trim();
    const sampleRate = String($('#pollySample').val() || '22050').trim();
    const $btn = $('#btnPollyGenerar');

    if (!fromKey) return this.alert('No se encontró el archivo origen.');
    if (!texto) return this.alert('No hay texto cargado para generar el audio.');
    if (!voiceId) return this.alert('Debes seleccionar una voz.');

    const engines = this.selectedEngines();
    if (engines.length && !engines.includes(engine)) {
      this.ensureEngineSupportsSelectedVoice(false);
      this.updateEngineHelp();
      return this.alert('La voz y el motor no son compatibles. Ya ajusté el motor; revisa la selección y vuelve a generar.');
    }

    const characters = Array.from(texto).length;
    if (characters > 100000) {
      return this.alert('El texto supera 100,000 caracteres. Divide el contenido en dos audios para procesarlo con Polly.');
    }

    let toS3 = $('#pollyToS3').is(':checked') ? 1 : 0;
    if (characters > 3000 && !toS3) {
      $('#pollyToS3').prop('checked', true);
      toS3 = 1;
      this.notify('El texto es largo: activé Guardar en S3 para procesarlo en segundo plano.', 'info', 6000);
    }

    this.setLoading($btn, true, toS3 ? 'Enviando…' : 'Generando…');
    $('#pollyCargando').removeClass('d-none').text(toS3 ? 'Registrando tarea en el servidor…' : 'Generando audio…');
    $('#pollyPlayerBox').addClass('d-none');

    try {
      const response = await this.post(this.startUrl, {
        texto,
        voiceId,
        engine,
        format,
        sampleRate,
        from_key: fromKey,
        to_s3: toS3
      });

      if (!response || response.ok !== true) {
        throw new Error((response && response.error) || 'No se pudo generar el audio.');
      }

      if (response.mode === 'task' && response.task_id) {
        $('#pollyCargando').addClass('d-none');
        $('#pollyS3Note').text('Tarea registrada en el servidor. Puedes cerrar el navegador.');
        $('#modalPollyTTS').modal('hide');

        this.notify(
          'Audio enviado a Tareas. El servidor lo terminará aunque cierres el navegador.',
          'info',
          8000
        );
        this.dispatch('drive:background-task-started', {
          kind: 'polly',
          id: String(response.task_id),
          status: String(response.task_status || 'scheduled')
        });
        this.dispatch('drive:background-tasks-refresh', { kind: 'polly' });
        return;
      }

      this.renderInlineAudio(response, format);
    } catch (error) {
      this.alert(error && error.message ? error.message : 'Error al generar el audio con Polly.');
    } finally {
      $('#pollyCargando').addClass('d-none');
      this.setLoading($btn, false);
    }
  }

  renderInlineAudio(response, format) {
    const $ = this.$;
    const audioBase64 = String(response.audioBase64 || '');
    if (!audioBase64) throw new Error('El servidor no devolvió audio válido.');

    const blob = this.base64ToBlob(audioBase64, response.contentType || 'audio/mpeg');
    const audioUrl = this.window.URL.createObjectURL(blob);
    const filename = response.filename || ('polly.' + (format === 'ogg_vorbis' ? 'ogg' : format));

    $('#pollyAudio').attr('src', audioUrl)[0].load();
    $('#pollyDownload').attr('href', audioUrl).attr('download', filename);
    $('#pollyS3Note').text('Audio generado para reproducción/descarga.');
    $('#pollyPlayerBox').removeClass('d-none');
  }

  post(url, data) {
    const body = new URLSearchParams();
    Object.entries(data || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null) body.set(key, String(value));
    });

    return this.window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    }).then(async (response) => {
      const text = await response.text();
      let json = null;
      try { json = JSON.parse(text); } catch (_) {}
      if (!response.ok) {
        throw new Error((json && (json.error || json.mensaje)) || text || ('HTTP ' + response.status));
      }
      if (!json) throw new Error('Respuesta inválida del servidor.');
      return json;
    });
  }

  setLoading($button, loading, text) {
    if (!$button || !$button.length) return;
    if (loading) {
      if (!$button.data('polly-background-html')) {
        $button.data('polly-background-html', $button.html());
      }
      $button.prop('disabled', true).text(text || 'Procesando…');
      return;
    }
    $button.prop('disabled', false).html($button.data('polly-background-html') || 'Generar audio');
  }

  notify(message, type = 'info', timeout = 5000) {
    if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.notify === 'function') {
      this.window.DriveMoveTasks.notify(message, type, timeout);
      return;
    }
    this.alert(message);
  }

  alert(message) {
    this.window.alert(String(message || ''));
  }

  base64ToBlob(base64, contentType) {
    const chars = this.window.atob(base64);
    const bytes = new Uint8Array(chars.length);
    for (let i = 0; i < chars.length; i += 1) bytes[i] = chars.charCodeAt(i);
    return new Blob([bytes], { type: contentType || 'application/octet-stream' });
  }

  dispatch(name, detail) {
    try { this.document.dispatchEvent(new CustomEvent(name, { detail })); } catch (_) {}
  }

  static boot(win = window, doc = document) {
    if (win.PollyBackground instanceof PollyBackgroundModule) return win.PollyBackground;
    const instance = new PollyBackgroundModule(win, doc).init();
    win.PollyBackground = instance;
    return instance;
  }
}

PollyBackgroundModule.boot();
