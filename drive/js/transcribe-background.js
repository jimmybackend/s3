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
    this.bindStart();
    this.restore()
      .filter((task) => task && task.jobName && !this.isTerminal(task.status))
      .forEach((task) => this.watch(task));
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

    if (!archivo) return this.notify('No se encontró el archivo a transcribir.', 'danger', 7000);
    if ($('#txMedicalPhi').is(':checked')) {
      return this.notify('PHI requiere Amazon Transcribe Medical y no el flujo estándar de este modal.', 'warning', 8000);
    }
    if (modelType === 'custom' && !customLanguageModelName) {
      return this.notify('Debes indicar el nombre del Custom Language Model.', 'warning', 7000);
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
        archivoVisible: String(response.archivoVisible || ''),
        ruta: String(response.rutaDestino || outputFolder || ''),
        status: String(response.status || 'QUEUED'),
        createdAt: Date.now(),
        lastCheckedAt: Date.now(),
        completedAt: 0,
        message: ''
      };

      this.remember(task);
      this.watch(task);
      $('#txEstadoInfo').removeClass('d-none').text(`Trabajo creado: ${task.jobName}`);
      $('#txCargando').addClass('d-none');
      try { $('#modalTranscribir').modal('hide'); } catch (_) {}

      this.notify(
        'Transcripción enviada. Puedes seguir trabajando o cerrar el navegador. Consulta “Tareas” para ver su estado.',
        'info',
        8000
      );
      this.dispatch('drive:background-task-started', { kind: 'transcribe', id: task.jobName });
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
    if (!task || !task.jobName || this.isTerminal(task.status)) return;
    const jobName = String(task.jobName);
    if (this.active.has(jobName)) return;

    const state = { stopped: false, networkErrors: 0 };
    this.active.set(jobName, state);

    const tick = async () => {
      if (state.stopped) return;

      try {
        const current = this.findTask(jobName) || task;
        const response = await this.post('transcribir_estado.php', {
          jobName,
          archivo: String(current.archivo || task.archivo || '')
        });
        if (response.error) throw new Error(response.error);

        state.networkErrors = 0;
        const status = String(response.status || 'UNKNOWN').toUpperCase();
        const now = Date.now();
        this.patchTask(jobName, {
          status,
          lastCheckedAt: now,
          message: String(response.message || ''),
          ruta: String(response.ruta || current.ruta || ''),
          completedAt: this.isTerminal(status) ? now : 0
        });

        this.dispatch('drive:background-tasks-refresh', { kind: 'transcribe', id: jobName, status });

        if (status === 'COMPLETED') {
          state.stopped = true;
          this.active.delete(jobName);
          this.notify('Transcripción terminada. El resultado y el costo atribuido ya fueron actualizados.', 'success', 8000);
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
          this.notify(response.message || 'La transcripción falló.', 'danger', 10000);
          this.dispatch('drive:transcribe-failed', response);
          return;
        }
      } catch (error) {
        state.networkErrors++;
        this.patchTask(jobName, {
          lastCheckedAt: Date.now(),
          lastCheckError: error && error.message ? String(error.message) : 'Estado no disponible'
        });
        console.warn('[transcribe-background] estado no disponible todavía:', error);
        if (state.networkErrors === 5) {
          this.notify('La transcripción sigue en segundo plano. El servidor continuará aunque cierres el navegador.', 'warning', 6000);
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
    this.save(tasks);
  }

  patchTask(jobName, patch) {
    const tasks = this.restore();
    const index = tasks.findIndex((item) => item.jobName === jobName);
    if (index < 0) return;
    tasks[index] = { ...tasks[index], ...patch };
    this.save(tasks);
  }

  findTask(jobName) {
    return this.restore().find((task) => task.jobName === jobName) || null;
  }

  save(tasks) {
    const now = Date.now();
    const clean = (Array.isArray(tasks) ? tasks : [])
      .filter((item) => item && item.jobName)
      .filter((item) => now - Number(item.createdAt || now) <= this.maxAgeMs)
      .slice(-30);
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(clean)); } catch (_) {}
  }

  restore() {
    try {
      const raw = this.window.localStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      return [];
    }
  }

  isTerminal(status) {
    const value = String(status || '').toUpperCase();
    return value === 'COMPLETED' || value === 'FAILED';
  }

  notify(message, type = 'info', timeout = 5000) {
    if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.notify === 'function') {
      this.window.DriveMoveTasks.notify(message, type, timeout);
    }
  }

  dispatch(name, detail) {
    try { this.document.dispatchEvent(new CustomEvent(name, { detail })); } catch (_) {}
  }

  static boot(win = window, doc = document) {
    if (win.TranscribeBackground instanceof TranscribeBackgroundModule) return win.TranscribeBackground;
    const instance = new TranscribeBackgroundModule(win, doc).init();
    win.TranscribeBackground = instance;
    return instance;
  }
}

TranscribeBackgroundModule.boot();
