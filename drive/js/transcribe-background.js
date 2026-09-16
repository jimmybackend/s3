class TranscribeBackgroundModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.storageKey = 'arcadecloud.transcribeJobs.v1';
    this.pollMs = 5000;
    this.maxAgeMs = 30 * 24 * 60 * 60 * 1000;
    this.historyAgeMs = 24 * 60 * 60 * 1000;
    this.active = new Map();
    this.panelOpen = false;
    this.renderTimer = null;
  }

  init() {
    this.ensureTaskCenter();
    const tasks = this.restore();
    tasks.filter((task) => !this.isTerminal(task.status)).forEach((task) => this.watch(task));
    this.bindStart();
    this.renderTaskCenter();

    this.renderTimer = this.window.setInterval(() => {
      if (this.panelOpen || this.active.size > 0) this.renderTaskCenter();
    }, 10000);

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
      this.renderTaskCenter();

      $('#txEstadoInfo').removeClass('d-none').text(`Trabajo creado: ${task.jobName}`);
      $('#txCargando').addClass('d-none');
      try { $('#modalTranscribir').modal('hide'); } catch (_) {}

      this.notify(
        'Transcripción enviada. Puedes seguir trabajando. Consulta “Tareas AWS” para ver su estado.',
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
    if (!task || !task.jobName || this.isTerminal(task.status)) return;
    const jobName = String(task.jobName);
    if (this.active.has(jobName)) return;

    const state = { stopped: false, networkErrors: 0 };
    this.active.set(jobName, state);
    this.renderTaskCenter();

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
        const status = String(response.status || 'UNKNOWN');
        const now = Date.now();
        const patch = {
          status,
          lastCheckedAt: now,
          message: String(response.message || ''),
          languageCode: String(response.languageCode || current.languageCode || ''),
          ruta: String(response.ruta || current.ruta || '')
        };

        if (status === 'COMPLETED') {
          patch.completedAt = now;
          const attribution = response.cost_attribution && typeof response.cost_attribution === 'object'
            ? response.cost_attribution
            : {};
          patch.billableSeconds = Number(attribution.billable_seconds_reference || 0);
          patch.durationSeconds = Number(attribution.duration_seconds_observed || 0);
        }

        this.patchTask(jobName, patch);
        this.renderTaskCenter();

        if (status === 'COMPLETED') {
          state.stopped = true;
          this.active.delete(jobName);
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
          this.patchTask(jobName, { completedAt: now });
          this.renderTaskCenter();
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
        this.renderTaskCenter();
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

  ensureTaskCenter() {
    if (!this.document.body) {
      this.document.addEventListener('DOMContentLoaded', () => {
        this.ensureTaskCenter();
        this.renderTaskCenter();
      }, { once: true });
      return;
    }

    if (!this.document.getElementById('transcribeTaskCenterStyle')) {
      const style = this.document.createElement('style');
      style.id = 'transcribeTaskCenterStyle';
      style.textContent = `
        #transcribeTaskCenterButton {
          position: fixed; right: 1rem; bottom: 1rem; z-index: 2065;
          border: 0; border-radius: 999px; padding: .72rem 1rem;
          background: #111827; color: #fff; font-weight: 700;
          box-shadow: 0 8px 28px rgba(0,0,0,.28);
        }
        #transcribeTaskCenterButton .tx-task-count {
          display: inline-flex; align-items: center; justify-content: center;
          min-width: 1.55rem; height: 1.55rem; margin-left: .45rem;
          padding: 0 .35rem; border-radius: 999px; background: #0ea5e9; color: #fff;
          font-size: .82rem;
        }
        #transcribeTaskCenterPanel {
          position: fixed; right: 1rem; bottom: 4.8rem; z-index: 2064;
          width: min(430px, calc(100vw - 2rem)); max-height: min(70vh, 650px);
          overflow: hidden; display: none; border-radius: .85rem;
          background: #111827; color: #f9fafb; box-shadow: 0 16px 40px rgba(0,0,0,.42);
          border: 1px solid rgba(255,255,255,.12);
        }
        #transcribeTaskCenterPanel.tx-open { display: flex; flex-direction: column; }
        .tx-task-head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.9rem 1rem; border-bottom:1px solid rgba(255,255,255,.12); }
        .tx-task-head strong { font-size:1rem; }
        .tx-task-head small { display:block; color:#9ca3af; margin-top:.1rem; }
        .tx-task-actions { display:flex; gap:.35rem; }
        .tx-task-actions button { border:1px solid rgba(255,255,255,.2); background:transparent; color:#fff; border-radius:.4rem; padding:.3rem .5rem; }
        .tx-task-list { overflow:auto; padding:.65rem; }
        .tx-task-empty { padding:1.1rem; text-align:center; color:#cbd5e1; }
        .tx-task-item { background:#1f2937; border:1px solid rgba(255,255,255,.08); border-radius:.65rem; padding:.75rem; margin-bottom:.6rem; }
        .tx-task-row { display:flex; justify-content:space-between; align-items:flex-start; gap:.6rem; }
        .tx-task-name { font-weight:700; overflow-wrap:anywhere; line-height:1.25; }
        .tx-task-job { color:#9ca3af; font-size:.75rem; overflow-wrap:anywhere; margin-top:.2rem; }
        .tx-task-badge { flex:0 0 auto; border-radius:999px; padding:.2rem .5rem; font-size:.72rem; font-weight:700; }
        .tx-status-queued, .tx-status-pending { background:#334155; color:#e2e8f0; }
        .tx-status-running { background:#075985; color:#e0f2fe; }
        .tx-status-done { background:#14532d; color:#dcfce7; }
        .tx-status-failed { background:#7f1d1d; color:#fee2e2; }
        .tx-task-meta { display:flex; flex-wrap:wrap; gap:.35rem .8rem; color:#cbd5e1; font-size:.78rem; margin-top:.55rem; }
        .tx-progress { position:relative; overflow:hidden; height:.42rem; margin-top:.65rem; background:#374151; border-radius:999px; }
        .tx-progress > span { display:block; height:100%; border-radius:999px; }
        .tx-progress.tx-queued > span { width:18%; background:#64748b; }
        .tx-progress.tx-running > span { width:45%; background:#0ea5e9; animation:txProgressMove 1.45s ease-in-out infinite alternate; }
        .tx-progress.tx-done > span { width:100%; background:#22c55e; }
        .tx-progress.tx-failed > span { width:100%; background:#ef4444; }
        .tx-task-progress-label { color:#9ca3af; font-size:.73rem; margin-top:.3rem; }
        .tx-task-error { margin-top:.45rem; color:#fecaca; font-size:.76rem; overflow-wrap:anywhere; }
        @keyframes txProgressMove { from { transform:translateX(-55%); } to { transform:translateX(125%); } }
        @media (max-width: 575.98px) {
          #transcribeTaskCenterButton { right:.65rem; bottom:.65rem; }
          #transcribeTaskCenterPanel { right:.65rem; bottom:4.35rem; width:calc(100vw - 1.3rem); max-height:72vh; }
        }
      `;
      this.document.head.appendChild(style);
    }

    if (!this.document.getElementById('transcribeTaskCenterButton')) {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.id = 'transcribeTaskCenterButton';
      button.setAttribute('aria-controls', 'transcribeTaskCenterPanel');
      button.setAttribute('aria-expanded', 'false');
      button.innerHTML = 'Tareas AWS <span class="tx-task-count">0</span>';
      button.addEventListener('click', () => {
        this.panelOpen = !this.panelOpen;
        this.renderTaskCenter();
      });
      this.document.body.appendChild(button);
    }

    if (!this.document.getElementById('transcribeTaskCenterPanel')) {
      const panel = this.document.createElement('aside');
      panel.id = 'transcribeTaskCenterPanel';
      panel.setAttribute('aria-label', 'Estado de tareas de Amazon Transcribe');
      panel.innerHTML = `
        <div class="tx-task-head">
          <div>
            <strong>Amazon Transcribe</strong>
            <small>Estado real informado por AWS</small>
          </div>
          <div class="tx-task-actions">
            <button type="button" data-tx-clear title="Limpiar tareas terminadas">Limpiar</button>
            <button type="button" data-tx-close aria-label="Cerrar">×</button>
          </div>
        </div>
        <div class="tx-task-list"></div>
      `;
      panel.querySelector('[data-tx-close]').addEventListener('click', () => {
        this.panelOpen = false;
        this.renderTaskCenter();
      });
      panel.querySelector('[data-tx-clear]').addEventListener('click', () => {
        this.clearFinished();
      });
      this.document.body.appendChild(panel);
    }
  }

  renderTaskCenter() {
    this.ensureTaskCenter();
    const button = this.document.getElementById('transcribeTaskCenterButton');
    const panel = this.document.getElementById('transcribeTaskCenterPanel');
    if (!button || !panel) return;

    const tasks = this.restore().sort((a, b) => Number(b.createdAt || 0) - Number(a.createdAt || 0));
    const activeCount = tasks.filter((task) => !this.isTerminal(task.status)).length;
    const count = button.querySelector('.tx-task-count');
    if (count) count.textContent = String(activeCount);

    button.setAttribute('aria-expanded', this.panelOpen ? 'true' : 'false');
    panel.classList.toggle('tx-open', this.panelOpen);

    const list = panel.querySelector('.tx-task-list');
    if (!list) return;

    if (tasks.length === 0) {
      list.innerHTML = '<div class="tx-task-empty">No hay transcripciones recientes.</div>';
      return;
    }

    list.innerHTML = tasks.map((task) => this.taskHtml(task)).join('');
  }

  taskHtml(task) {
    const status = this.normalizeStatus(task.status);
    const visual = this.statusVisual(status);
    const name = task.archivoVisible || this.basename(task.archivo) || task.jobName;
    const elapsedEnd = Number(task.completedAt || 0) > 0 ? Number(task.completedAt) : Date.now();
    const elapsed = this.formatElapsed(Math.max(0, elapsedEnd - Number(task.createdAt || elapsedEnd)));
    const checked = task.lastCheckedAt ? this.relativeAge(Number(task.lastCheckedAt)) : 'sin consulta todavía';
    const duration = Number(task.durationSeconds || 0);
    const billable = Number(task.billableSeconds || 0);
    const details = [];

    details.push(`Tiempo: ${elapsed}`);
    details.push(`Última consulta: ${checked}`);
    if (task.languageCode) details.push(`Idioma: ${this.escapeHtml(task.languageCode)}`);
    if (status === 'COMPLETED' && billable > 0) details.push(`Facturable: ${billable}s`);
    else if (status === 'COMPLETED' && duration > 0) details.push(`Duración: ${Math.ceil(duration)}s`);

    const error = task.lastCheckError && !this.isTerminal(status)
      ? `<div class="tx-task-error">Último intento: ${this.escapeHtml(task.lastCheckError)}</div>`
      : (status === 'FAILED' && task.message
        ? `<div class="tx-task-error">${this.escapeHtml(task.message)}</div>`
        : '');

    return `
      <article class="tx-task-item">
        <div class="tx-task-row">
          <div>
            <div class="tx-task-name">${this.escapeHtml(name)}</div>
            <div class="tx-task-job">${this.escapeHtml(task.jobName)}</div>
          </div>
          <span class="tx-task-badge ${visual.badgeClass}">${visual.label}</span>
        </div>
        <div class="tx-task-meta">${details.map((item) => `<span>${item}</span>`).join('')}</div>
        <div class="tx-progress ${visual.progressClass}" aria-label="${this.escapeHtml(visual.progressText)}"><span></span></div>
        <div class="tx-task-progress-label">${this.escapeHtml(visual.progressText)}</div>
        ${error}
      </article>
    `;
  }

  statusVisual(status) {
    switch (status) {
      case 'QUEUED':
        return {
          label: 'EN COLA',
          badgeClass: 'tx-status-queued',
          progressClass: 'tx-queued',
          progressText: 'Etapa 1 de 3 · esperando turno en AWS'
        };
      case 'IN_PROGRESS':
        return {
          label: 'PROCESANDO',
          badgeClass: 'tx-status-running',
          progressClass: 'tx-running',
          progressText: 'Etapa 2 de 3 · AWS no informa un porcentaje exacto'
        };
      case 'COMPLETED':
        return {
          label: 'TERMINADO',
          badgeClass: 'tx-status-done',
          progressClass: 'tx-done',
          progressText: 'Etapa 3 de 3 · 100% terminado'
        };
      case 'FAILED':
        return {
          label: 'FALLÓ',
          badgeClass: 'tx-status-failed',
          progressClass: 'tx-failed',
          progressText: 'El trabajo terminó con error'
        };
      default:
        return {
          label: 'PENDIENTE',
          badgeClass: 'tx-status-pending',
          progressClass: 'tx-queued',
          progressText: 'Consultando estado real en AWS'
        };
    }
  }

  clearFinished() {
    const tasks = this.restore().filter((task) => !this.isTerminal(task.status));
    this.save(tasks);
    this.renderTaskCenter();
  }

  patchTask(jobName, patch) {
    const tasks = this.restore();
    const index = tasks.findIndex((item) => item.jobName === jobName);
    if (index < 0) return;
    tasks[index] = { ...tasks[index], ...patch };
    if (!patch.lastCheckError) delete tasks[index].lastCheckError;
    this.save(tasks);
  }

  findTask(jobName) {
    return this.restore().find((task) => task.jobName === jobName) || null;
  }

  remember(task) {
    const tasks = this.restore().filter((item) => item.jobName !== task.jobName);
    tasks.push(task);
    this.save(tasks);
  }

  save(tasks) {
    const now = Date.now();
    const trimmed = (Array.isArray(tasks) ? tasks : [])
      .filter((item) => item && item.jobName)
      .filter((item) => {
        const createdAt = Number(item.createdAt || 0);
        const completedAt = Number(item.completedAt || 0);
        if (this.isTerminal(item.status)) {
          const reference = completedAt || createdAt;
          return reference > 0 && now - reference <= this.historyAgeMs;
        }
        return createdAt <= 0 || now - createdAt <= this.maxAgeMs;
      })
      .slice(-30);

    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(trimmed)); } catch (_) {}
  }

  restore() {
    try {
      const raw = this.window.localStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) return [];

      const now = Date.now();
      return parsed
        .filter((item) => item && item.jobName)
        .map((item) => ({
          jobName: String(item.jobName),
          archivo: String(item.archivo || ''),
          archivoVisible: String(item.archivoVisible || ''),
          ruta: String(item.ruta || ''),
          status: this.normalizeStatus(item.status),
          createdAt: Number(item.createdAt || now),
          lastCheckedAt: Number(item.lastCheckedAt || 0),
          completedAt: Number(item.completedAt || 0),
          message: String(item.message || ''),
          languageCode: String(item.languageCode || ''),
          billableSeconds: Number(item.billableSeconds || 0),
          durationSeconds: Number(item.durationSeconds || 0),
          lastCheckError: String(item.lastCheckError || '')
        }))
        .filter((item) => {
          const reference = item.completedAt || item.createdAt;
          const age = now - reference;
          return this.isTerminal(item.status) ? age <= this.historyAgeMs : age <= this.maxAgeMs;
        });
    } catch (_) {
      return [];
    }
  }

  normalizeStatus(status) {
    const value = String(status || '').toUpperCase();
    if (['QUEUED', 'IN_PROGRESS', 'COMPLETED', 'FAILED'].includes(value)) return value;
    return 'PENDING';
  }

  isTerminal(status) {
    const value = this.normalizeStatus(status);
    return value === 'COMPLETED' || value === 'FAILED';
  }

  basename(path) {
    const clean = String(path || '').replace(/\\/g, '/');
    const parts = clean.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : '';
  }

  formatElapsed(ms) {
    let seconds = Math.max(0, Math.floor(Number(ms || 0) / 1000));
    const hours = Math.floor(seconds / 3600);
    seconds -= hours * 3600;
    const minutes = Math.floor(seconds / 60);
    seconds -= minutes * 60;

    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m ${seconds}s`;
    return `${seconds}s`;
  }

  relativeAge(timestamp) {
    const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
    if (seconds < 10) return 'ahora';
    if (seconds < 60) return `hace ${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `hace ${minutes}m`;
    return `hace ${Math.floor(minutes / 60)}h`;
  }

  escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
