class PollyBackgroundModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.$ = win.jQuery;
    this.storageKey = 'arcadecloud.pollyTasks';
    this.active = new Map();
    this.pollMs = 4000;
    this.statusUrl = 'polly_task_status.php';
    this.tasksUrl = 'polly_tasks.php';
    this.startUrl = 'polly_tts.php';
    this.serverTasks = [];
    this.lastServerStatuses = new Map();
    this.serverTimer = null;
  }

  init() {
    if (!this.$) return this;

    this.bindUi();
    this.updateUiHints();

    // La finalización asíncrona ya no depende de este navegador. El servidor
    // reconcilia Polly mediante systemd; aquí sólo consultamos el estado para UI.
    this.window.setTimeout(() => this.refreshServerTasks(), 900);
    this.serverTimer = this.window.setInterval(() => this.refreshServerTasks(), 10000);
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
      'Escribe o pega texto. Si es largo y se guarda en S3, el servidor lo procesará en segundo plano.'
    );

    const $switchHelp = $('#pollyToS3').closest('.custom-control').next('small');
    if ($switchHelp.length) {
      $switchHelp.text(
        'Al guardar en S3, la tarea queda registrada en el servidor. Puedes cerrar el navegador; el audio se finalizará y costeará automáticamente.'
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
      this.notify('El texto es largo: activé Guardar en S3 para procesarlo en el servidor.', 'info', 6000);
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
        const task = {
          task_id: String(response.task_id),
          from_key: fromKey,
          nombre: String(response.nombre || response.filename || 'audio'),
          ruta: String(response.ruta || ''),
          s3_key: String(response.s3_key || ''),
          created_at: Date.now()
        };
        this.remember(task);

        $('#pollyCargando').addClass('d-none');
        $('#pollyS3Note').text('Tarea registrada en el servidor. Puedes cerrar el navegador.');
        $('#modalPollyTTS').modal('hide');

        this.notify(
          'Audio enviado a Tareas AWS. El servidor lo terminará aunque cierres el navegador.',
          'info',
          8000
        );
        this.window.setTimeout(() => this.refreshServerTasks(), 500);
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

  async refreshServerTasks() {
    try {
      const url = new URL(this.tasksUrl, this.window.location.href);
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const text = await response.text();
      let json = null;
      try { json = JSON.parse(text); } catch (_) {}
      if (!response.ok || !json || json.ok !== true || !Array.isArray(json.tasks)) return;

      const previous = new Map(this.serverTasks.map((task) => [String(task.task_id), String(task.status || '')]));
      this.serverTasks = json.tasks;

      for (const task of this.serverTasks) {
        const taskId = String(task.task_id || '');
        const status = String(task.status || '').toLowerCase();
        const prior = previous.get(taskId) || this.lastServerStatuses.get(taskId) || '';
        if (status === 'completed' && prior && prior !== 'completed') {
          this.notify('Audio Polly terminado: ' + String(task.output_name || task.file_name || 'audio') + '.', 'success', 8000);
          this.refreshUi({});
        }
        this.lastServerStatuses.set(taskId, status);
      }

      this.renderServerTaskSection();
    } catch (error) {
      console.warn('[polly-background] no se pudo consultar tareas del servidor:', error);
    }
  }

  renderServerTaskSection() {
    const panel = this.document.getElementById('transcribeTaskCenterPanel');
    const button = this.document.getElementById('transcribeTaskCenterButton');
    if (!panel || !button) {
      this.window.setTimeout(() => this.renderServerTaskSection(), 800);
      return;
    }

    const headTitle = panel.querySelector('.tx-task-head strong');
    const headSmall = panel.querySelector('.tx-task-head small');
    if (headTitle) headTitle.textContent = 'Tareas AWS';
    if (headSmall) headSmall.textContent = 'Amazon Transcribe y Polly · estado persistente';

    let section = this.document.getElementById('pollyServerTasksSection');
    if (!section) {
      section = this.document.createElement('section');
      section.id = 'pollyServerTasksSection';
      section.style.borderBottom = '1px solid rgba(255,255,255,.12)';
      section.style.maxHeight = '36vh';
      section.style.overflow = 'auto';
      const list = panel.querySelector('.tx-task-list');
      if (list) panel.insertBefore(section, list);
      else panel.appendChild(section);
    }

    if (!this.serverTasks.length) {
      section.innerHTML = '<div class="tx-task-empty"><strong>Amazon Polly</strong><br>Sin tareas recientes del servidor.</div>';
    } else {
      section.innerHTML = '<div style="padding:.65rem .65rem .15rem;font-weight:700">Amazon Polly</div>' +
        this.serverTasks.map((task) => this.serverTaskHtml(task)).join('');
    }

    let transcribeActive = 0;
    try {
      const tx = this.window.TranscribeBackground;
      if (tx && typeof tx.restore === 'function' && typeof tx.isTerminal === 'function') {
        transcribeActive = tx.restore().filter((task) => !tx.isTerminal(task.status)).length;
      }
    } catch (_) {}
    const pollyActive = this.serverTasks.filter((task) => !['completed', 'failed'].includes(String(task.status || '').toLowerCase())).length;
    const count = button.querySelector('.tx-task-count');
    if (count) count.textContent = String(transcribeActive + pollyActive);
  }

  serverTaskHtml(task) {
    const status = String(task.status || '').toLowerCase();
    let label = 'PENDIENTE';
    let badge = 'tx-status-pending';
    let progress = 'tx-queued';
    let progressText = 'Esperando estado del servidor';
    if (status === 'scheduled') {
      label = 'EN COLA';
      badge = 'tx-status-queued';
      progressText = 'Registrado en Amazon Polly';
    } else if (['inprogress', 'in_progress', 'running'].includes(status)) {
      label = 'PROCESANDO';
      badge = 'tx-status-running';
      progress = 'tx-running';
      progressText = 'Amazon Polly está generando el audio';
    } else if (status === 'completed') {
      label = 'TERMINADO';
      badge = 'tx-status-done';
      progress = 'tx-done';
      progressText = '100% terminado por el servidor';
    } else if (status === 'failed') {
      label = 'FALLÓ';
      badge = 'tx-status-failed';
      progress = 'tx-failed';
      progressText = 'La tarea terminó con error';
    }

    const name = this.escapeHtml(task.output_name || task.file_name || 'Audio Polly');
    const engine = this.escapeHtml(task.engine || '');
    const chars = Number(task.characters || 0);
    const cost = task.estimated_cost == null ? '' : `${String(task.currency || 'USD')} $${Number(task.estimated_cost).toFixed(4)}`;
    const reason = task.reason ? `<div class="tx-task-error">${this.escapeHtml(task.reason)}</div>` : '';

    return `
      <article class="tx-task-item" style="margin:.55rem .65rem">
        <div class="tx-task-row">
          <div>
            <div class="tx-task-name">${name}</div>
            <div class="tx-task-job">Polly · ${engine || 'motor AWS'}</div>
          </div>
          <span class="tx-task-badge ${badge}">${label}</span>
        </div>
        <div class="tx-task-meta">
          ${chars > 0 ? `<span>${chars} caracteres</span>` : ''}
          ${cost ? `<span>Costo Polly: ${this.escapeHtml(cost)}</span>` : ''}
        </div>
        <div class="tx-progress ${progress}"><span></span></div>
        <div class="tx-task-progress-label">${this.escapeHtml(progressText)}</div>
        ${reason}
      </article>`;
  }

  // Compatibilidad con tareas locales antiguas: si existieran, aún pueden
  // consultarse manualmente, pero las nuevas tareas dependen del worker server.
  watch(task) {
    const taskId = String(task && task.task_id || '').trim();
    const fromKey = String(task && task.from_key || '').trim();
    if (!taskId || !fromKey || this.active.has(taskId)) return;

    const state = { stopped: false, networkErrors: 0 };
    this.active.set(taskId, state);
    const tick = async () => {
      if (state.stopped) return;
      try {
        const response = await this.post(this.statusUrl, { task_id: taskId, from_key: fromKey });
        if (!response || response.ok !== true) throw new Error((response && response.error) || 'Estado no disponible.');
        const status = String(response.task_status || '').toLowerCase();
        if (['completed', 'failed'].includes(status)) {
          state.stopped = true;
          this.active.delete(taskId);
          this.forget(taskId);
          this.refreshServerTasks();
          return;
        }
      } catch (_) {
        state.networkErrors += 1;
      }
      if (!state.stopped) this.window.setTimeout(tick, this.pollMs);
    };
    this.window.setTimeout(tick, 800);
  }

  async refreshUi(response) {
    const route = String(response && response.ruta || '').trim();
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
      if (!response.ok) throw new Error((json && (json.error || json.mensaje)) || text || ('HTTP ' + response.status));
      if (!json) throw new Error('Respuesta inválida del servidor.');
      return json;
    });
  }

  remember(task) {
    const tasks = this.restore().filter((item) => item.task_id !== task.task_id);
    tasks.push(task);
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(tasks.slice(-20))); } catch (_) {}
  }

  forget(taskId) {
    const tasks = this.restore().filter((item) => item.task_id !== taskId);
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(tasks)); } catch (_) {}
  }

  restore() {
    try {
      const raw = this.window.localStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) return [];
      const cutoff = Date.now() - (7 * 24 * 60 * 60 * 1000);
      return parsed.filter((item) => item && item.task_id && item.from_key && (!item.created_at || Number(item.created_at) >= cutoff));
    } catch (_) {
      return [];
    }
  }

  setLoading($button, loading, text) {
    if (!$button || !$button.length) return;
    if (loading) {
      if (!$button.data('polly-background-html')) $button.data('polly-background-html', $button.html());
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

  escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
