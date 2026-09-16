class TranscribeBackgroundModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.storageKey = 'arcadecloud.transcribeJobs.v1';
    this.pollMs = 5000;
    this.maxAgeMs = 30 * 24 * 60 * 60 * 1000;
    this.active = new Map();
  }

  init() {
    this.restore().forEach((task) => this.watch(task));
    this.bindStart();
    return this;
  }

  bindStart() {
    const $ = this.window.jQuery;
    if (!$) return;

    $(function () {
      $(document)
        .off('click.txIniciar', '#btnTxIniciar')
        .off('click.transcribeBackground', '#btnTxIniciar')
        .on('click.transcribeBackground', '#btnTxIniciar', (event) => {
          event.preventDefault();
          this.startFromModal();
        });
    }.bind(this));
  }

  async startFromModal() {
    const $ = this.window.jQuery;
    if (!$) return;

    const archivo = $.trim($('#txArchivoKey').val());
    const jobName = $.trim($('#txJobName').val());
    const languageMode = $.trim($('#txLanguageMode').val()) || 'specific';
    const languageCode = $.trim($('#txLanguage').val()) || 'es-ES';
    const modelType = $.trim($('#txModelType').val()) || 'general';
    const customLanguageModelName = $.trim($('#txCustomLanguageModelName').val());
    const audioIdType = $.trim($('input[name="txAudioIdType"]:checked').val()) || 'none';
    const showAlternatives = $('#txShowAlternatives').is(':checked');
    const enableContentRedaction = $('#txEnableContentRedaction').is(':checked');
    const toxicityDetection = $('#txToxicityDetection').is(':checked');
    const outputFolder = $.trim($('#txFolderKey').val());
    const $btn = $('#btnTxIniciar');

    if (!archivo) {
      this.notify('No se encontró el archivo a transcribir.', 'danger', 7000);
      return;
    }

    if ($('#txMedicalPhi').is(':checked')) {
      this.notify('PHI requiere Amazon Transcribe Medical y no el flujo estándar de este modal.', 'warning', 8000);
      return;
    }

    if (modelType === 'custom' && !customLanguageModelName) {
      this.notify('Debes indicar el nombre del Custom Language Model.', 'warning', 7000);
      return;
    }

    const subtitleFormats = this.checked('txSubtitleFormats[]');
    const languageOptions = this.checked('txLanguageOptions[]');
    const toxicityCategories = this.checked('txToxicityCategories[]');

    const payload = {
      archivo,
      jobName,
      languageMode,
      languageCode,
      languageOptions,
      modelType,
      customLanguageModelName,
      outputMode: 'customer',
      outputS3Uri: outputFolder && this.window.AWS_BUCKET_NAME
        ? `s3://${this.window.AWS_BUCKET_NAME}/${outputFolder}`
        : (this.window.AWS_BUCKET_NAME ? `s3://${this.window.AWS_BUCKET_NAME}/` : ''),
      outputKey: outputFolder,
      subtitleFormats,
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
      toxicityCategories
    };

    const originalHtml = $btn.html();
    $('#txResultadoBox').addClass('d-none');
    $('#txEstadoInfo').removeClass('d-none').text('Enviando trabajo...');
    $('#txCargando').removeClass('d-none');
    $btn.prop('disabled', true).text('Iniciando...');

    try {
      const response = await this.post('transcribir_iniciar.php', payload);
      if (!response || response.ok !== true || !response.jobName) {
        throw new Error((response && response.error) || 'No se pudo iniciar la transcripción.');
      }

      const task = {
        jobName: String(response.jobName),
        archivo,
        ruta: String(response.rutaDestino || outputFolder || ''),
        createdAt: Date.now()
      };

      this.remember(task);
      this.watch(task);

      $('#txEstadoInfo').removeClass('d-none').text(`Trabajo creado: ${task.jobName}`);
      $('#txCargando').addClass('d-none');
      try { $('#modalTranscribir').modal('hide'); } catch (_) {}

      this.notify(
        'Transcripción enviada. Puedes cerrar el modal, cambiar de página y seguir trabajando; el Drive continuará verificándola.',
        'info',
        8000
      );
    } catch (error) {
      $('#txCargando').addClass('d-none');
      this.notify(error && error.message ? error.message : 'Error al iniciar la transcripción.', 'danger', 9000);
    } finally {
      $btn.prop('disabled', false).html(originalHtml);
    }
  }

  checked(name) {
    const $ = this.window.jQuery;
    if (!$) return [];
    return $(`input[name="${name}"]:checked`).map(function () {
      return $.trim($(this).val());
    }).get();
  }

  async post(url, payload) {
    const body = new URLSearchParams();
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((item) => body.append(`${key}[]`, String(item)));
      } else if (value !== undefined && value !== null) {
        body.set(key, String(value));
      }
    });

    const response = await this.window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });

    const text = await response.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!response.ok || !json) {
      throw new Error((json && json.error) || text || `HTTP ${response.status}`);
    }
    return json;
  }

  watch(task) {
    if (!task || !task.jobName) return;
    const jobName = String(task.jobName);
    if (this.active.has(jobName)) return;

    const state = { stopped: false, networkErrors: 0 };
    this.active.set(jobName, state);

    const tick = async () => {
      if (state.stopped) return;

      try {
        const response = await this.post('transcribir_estado.php', {
          jobName,
          archivo: String(task.archivo || '')
        });

        if (response.error) throw new Error(response.error);

        state.networkErrors = 0;
        const status = String(response.status || '');

        if (status === 'COMPLETED') {
          state.stopped = true;
          this.active.delete(jobName);
          this.forget(jobName);
          this.notify(
            'Transcripción terminada. El texto, archivos derivados y costo atribuido ya fueron actualizados.',
            'success',
            8000
          );
          await this.refreshUi(response);
          this.dispatch('drive:transcribe-completed', response);

          if (/activity_costs\.php$/i.test(this.window.location.pathname)) {
            this.window.setTimeout(() => this.window.location.reload(), 900);
          }
          return;
        }

        if (status === 'FAILED') {
          state.stopped = true;
          this.active.delete(jobName);
          this.forget(jobName);
          this.notify(response.message || 'La transcripción falló.', 'danger', 10000);
          this.dispatch('drive:transcribe-failed', response);
          return;
        }
      } catch (error) {
        state.networkErrors++;
        console.warn('[transcribe-background] estado no disponible todavía:', error);
        if (state.networkErrors === 5) {
          this.notify('La transcripción sigue pendiente. Reintentando en segundo plano…', 'warning', 6000);
        }
      }

      if (!state.stopped) this.window.setTimeout(tick, this.pollMs);
    };

    this.window.setTimeout(tick, 800);
  }

  async refreshUi(response) {
    const route = String(response && response.ruta ? response.ruta : '').trim();
    try {
      if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.refreshUi === 'function') {
        await this.window.DriveMoveTasks.refreshUi(route ? { ruta_actual: route } : {});
        return;
      }
    } catch (_) {}

    try {
      if (typeof this.window.actualizarBloqueArchivos === 'function') {
        await this.window.actualizarBloqueArchivos(route ? { pagina: 1, ruta: route } : { pagina: 1 });
      }
    } catch (_) {}
  }

  remember(task) {
    const tasks = this.restore().filter((item) => item.jobName !== task.jobName);
    tasks.push(task);
    const trimmed = tasks
      .filter((item) => Date.now() - Number(item.createdAt || 0) <= this.maxAgeMs)
      .slice(-30);
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(trimmed)); } catch (_) {}
  }

  forget(jobName) {
    const tasks = this.restore().filter((item) => item.jobName !== jobName);
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(tasks)); } catch (_) {}
  }

  restore() {
    try {
      const raw = this.window.localStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) return [];
      return parsed
        .filter((item) => item && item.jobName)
        .filter((item) => Date.now() - Number(item.createdAt || 0) <= this.maxAgeMs)
        .map((item) => ({
          jobName: String(item.jobName),
          archivo: String(item.archivo || ''),
          ruta: String(item.ruta || ''),
          createdAt: Number(item.createdAt || Date.now())
        }));
    } catch (_) {
      return [];
    }
  }

  notify(message, type = 'info', timeout = 5000) {
    if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.notify === 'function') {
      this.window.DriveMoveTasks.notify(message, type, timeout);
      return;
    }

    let box = this.document.getElementById('transcribeBackgroundNotice');
    if (!box) {
      box = this.document.createElement('div');
      box.id = 'transcribeBackgroundNotice';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      Object.assign(box.style, {
        position: 'fixed',
        left: '1rem',
        right: '1rem',
        bottom: '1rem',
        zIndex: '2050',
        maxWidth: '560px',
        marginLeft: 'auto',
        padding: '0.8rem 1rem',
        borderRadius: '0.5rem',
        background: '#fff',
        color: '#111',
        boxShadow: '0 4px 20px rgba(0,0,0,.25)'
      });
      this.document.body.appendChild(box);
    }

    box.textContent = String(message || '');
    box.style.display = 'block';
    if (timeout > 0) {
      this.window.setTimeout(() => {
        if (box.textContent === String(message || '')) box.style.display = 'none';
      }, timeout);
    }
  }

  dispatch(name, detail) {
    try {
      this.document.dispatchEvent(new CustomEvent(name, { detail }));
    } catch (_) {}
  }

  static boot(win = window, doc = document) {
    if (win.TranscribeBackground instanceof TranscribeBackgroundModule) {
      return win.TranscribeBackground;
    }
    const instance = new TranscribeBackgroundModule(win, doc).init();
    win.TranscribeBackground = instance;
    return instance;
  }
}

TranscribeBackgroundModule.boot();
