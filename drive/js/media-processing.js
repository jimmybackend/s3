class MediaProcessingModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.endpoint = 'media_processing.php';
    this.maxSourceBytes = 8 * 1024 * 1024 * 1024;
    this.currentButton = null;
    this.currentOperation = '';
    this.node = null;
    this.nodeStatusLoading = false;
    this.fileTooLarge = false;
  }

  init() {
    if (this.window.__mediaProcessingBound) return this;
    this.window.__mediaProcessingBound = true;

    this.document.addEventListener('click', (event) => this.handleClick(event));

    const submit = this.document.getElementById('btnMediaSplitSubmit');
    if (submit) {
      submit.addEventListener('click', () => this.submit());
    }

    const authorization = this.document.getElementById('mediaNodeAuthorization');
    if (authorization) {
      authorization.addEventListener('change', () => this.updateSubmitAvailability());
    }

    return this;
  }

  formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value <= 0) return 'Tamaño no disponible';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
      size /= 1024;
      unit++;
    }
    return (unit === 0 ? Math.round(size) : size.toFixed(size >= 10 ? 1 : 2)) + ' ' + units[unit];
  }

  money(value) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '';
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 4
      }).format(amount);
    } catch (_) {
      return 'USD ' + amount.toFixed(4);
    }
  }

  showStatus(type, message) {
    const box = this.document.getElementById('mediaSplitStatus');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
  }

  clearStatus() {
    const box = this.document.getElementById('mediaSplitStatus');
    if (!box) return;
    box.className = 'alert d-none';
    box.textContent = '';
  }

  operationLabel(operation) {
    return {
      split_video: 'Dividir video',
      split_audio: 'Dividir audio',
      extract_mp3: 'Extraer audio MP3'
    }[operation] || 'Procesar archivo';
  }

  openModal(button, operation) {
    const key = (button.dataset.key || '').trim();
    const name = (button.dataset.nombre || key).trim();
    const bytes = Number(button.dataset.bytes || 0);
    if (!key) return;

    this.currentButton = button;
    this.currentOperation = operation;
    this.node = null;
    this.fileTooLarge = bytes > this.maxSourceBytes;

    const title = this.document.getElementById('mediaSplitTitle');
    const file = this.document.getElementById('mediaSplitFile');
    const size = this.document.getElementById('mediaSplitSize');
    const parts = this.document.getElementById('mediaSplitParts');
    const partsGroup = this.document.getElementById('mediaSplitPartsGroup');
    const overlap = this.document.getElementById('mediaSplitOverlapInfo');
    const resultInfo = this.document.getElementById('mediaSplitResultInfo');
    const authorization = this.document.getElementById('mediaNodeAuthorization');
    const authorizationWrap = this.document.getElementById('mediaNodeAuthorizationWrap');
    const nodeBox = this.document.getElementById('mediaNodeStatus');

    if (title) title.textContent = this.operationLabel(operation);
    if (file) file.textContent = name || key;
    if (size) size.textContent = this.formatBytes(bytes);
    if (parts) {
      parts.value = '2';
      parts.disabled = operation === 'extract_mp3';
    }
    if (partsGroup) partsGroup.classList.toggle('d-none', operation === 'extract_mp3');
    if (overlap) overlap.classList.toggle('d-none', operation === 'extract_mp3');
    if (resultInfo) {
      resultInfo.innerHTML = operation === 'extract_mp3'
        ? '<i class="fas fa-shield-alt mr-1"></i>El video original permanece intacto. El MP3 se guardará en la misma carpeta.'
        : '<i class="fas fa-shield-alt mr-1"></i>El original permanece intacto. Las partes se guardarán en la misma carpeta como <code>-parte1</code>, <code>-parte2</code>, etc.';
    }
    if (authorization) authorization.checked = false;
    if (authorizationWrap) authorizationWrap.classList.add('d-none');
    if (nodeBox) {
      nodeBox.className = 'alert alert-secondary mb-3';
      nodeBox.textContent = 'Comprobando el nodo de procesamiento…';
    }

    this.clearStatus();
    if (this.fileTooLarge) {
      this.showStatus('danger', 'Este archivo supera el máximo de 8 GB permitido para procesamiento multimedia.');
    }

    if (this.window.jQuery && this.window.jQuery.fn.modal) {
      this.window.jQuery('#modalMediaSplit').modal('show');
      this.loadNodeStatus();
    } else {
      alert('No se pudo abrir el formulario de procesamiento. Recarga la página e inténtalo de nuevo.');
    }

    this.updateSubmitAvailability();
  }

  async loadNodeStatus() {
    if (this.nodeStatusLoading) return;
    this.nodeStatusLoading = true;
    try {
      const url = new URL(this.endpoint, this.window.location.href);
      url.searchParams.set('node_status', '1');
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      const data = await response.json();
      if (!response.ok || !data || data.ok !== true) {
        throw new Error((data && data.error) || 'No se pudo consultar el nodo multimedia.');
      }
      this.node = data.node || {configured: false, state: 'unconfigured'};
      this.renderNodeStatus();
    } catch (error) {
      this.node = {configured: false, state: 'unknown', status_error: true};
      const box = this.document.getElementById('mediaNodeStatus');
      if (box) {
        box.className = 'alert alert-warning mb-3';
        box.textContent = 'No se pudo consultar la EC2 multimedia. La tarea puede quedar en cola hasta que haya un worker disponible.';
      }
    } finally {
      this.nodeStatusLoading = false;
      this.updateSubmitAvailability();
    }
  }

  renderNodeStatus() {
    const box = this.document.getElementById('mediaNodeStatus');
    const authorizationWrap = this.document.getElementById('mediaNodeAuthorizationWrap');
    const authorization = this.document.getElementById('mediaNodeAuthorization');
    const cost = this.document.getElementById('mediaNodeCost');
    if (!box || !authorizationWrap || !authorization) return;

    const node = this.node || {};
    const state = String(node.state || 'unknown');
    authorizationWrap.classList.add('d-none');
    authorization.checked = false;

    if (node.configured !== true) {
      box.className = 'alert alert-warning mb-3';
      box.textContent = 'No hay una EC2 multimedia de encendido automático configurada. La tarea quedará en cola hasta que haya un worker disponible.';
    } else if (state === 'stopped') {
      box.className = 'alert alert-warning mb-3';
      if (node.cost_configured === false) {
        box.textContent = 'El nodo está APAGADO, pero falta configurar su tarifa de referencia antes de permitir un encendido pagado.';
      } else {
        box.textContent = 'El nodo de alto rendimiento está APAGADO. Para esta tarea debes autorizar su encendido.';
        authorizationWrap.classList.remove('d-none');
      }
    } else if (state === 'pending') {
      box.className = 'alert alert-info mb-3';
      box.textContent = 'El nodo de alto rendimiento se está encendiendo. La tarea puede enviarse y esperará al worker.';
    } else if (state === 'running') {
      box.className = 'alert alert-success mb-3';
      box.textContent = 'Nodo de alto rendimiento disponible.';
    } else if (state === 'stopping') {
      box.className = 'alert alert-danger mb-3';
      box.textContent = 'El nodo se está apagando. Espera a que termine antes de enviar otra tarea.';
    } else {
      box.className = 'alert alert-warning mb-3';
      box.textContent = 'Estado del nodo multimedia: ' + state + '.';
    }

    if (cost) {
      const hourly = Number(node.hourly_usd);
      const grace = Number(node.idle_grace_seconds || 0);
      if (Number.isFinite(hourly)) {
        cost.textContent = 'Referencia configurada: ' + this.money(hourly) + '/hora. Se registrará el tiempo real de encendido.';
      } else {
        cost.textContent = 'Se registrará el tiempo de uso. Configura ARCADECLOUD_MEDIA_WORKER_HOURLY_USD para estimar el costo en USD.';
      }
      if (grace > 0) {
        cost.textContent += ' El nodo se apagará tras ' + Math.round(grace / 60) + ' min sin tareas.';
      }
    }
  }

  updateSubmitAvailability() {
    const submit = this.document.getElementById('btnMediaSplitSubmit');
    if (!submit) return;

    let disabled = this.fileTooLarge || this.nodeStatusLoading;
    const node = this.node || {};
    const state = String(node.state || '');

    if (state === 'stopping') disabled = true;
    if (node.configured === true && state === 'stopped') {
      if (node.cost_configured === false) {
        disabled = true;
      } else {
        const authorization = this.document.getElementById('mediaNodeAuthorization');
        if (!authorization || !authorization.checked) disabled = true;
      }
    }

    submit.disabled = disabled;
    if (!disabled && submit.textContent === 'Tarea creada') {
      submit.textContent = 'Enviar a procesamiento';
    }
  }

  async submit() {
    const button = this.currentButton;
    const operation = this.currentOperation;
    const partsInput = this.document.getElementById('mediaSplitParts');
    const submit = this.document.getElementById('btnMediaSplitSubmit');
    if (!button || !operation) {
      this.showStatus('danger', 'No se pudo identificar el archivo a procesar.');
      return;
    }

    let parts = 1;
    if (operation !== 'extract_mp3') {
      if (!partsInput) return;
      parts = parseInt(partsInput.value, 10);
      if (!Number.isInteger(parts) || parts < 2 || parts > 50) {
        this.showStatus('warning', 'Indica una cantidad de partes entre 2 y 50.');
        partsInput.focus();
        return;
      }
    }

    const authorization = this.document.getElementById('mediaNodeAuthorization');
    const authorizeNodeStart = !!(authorization && authorization.checked);

    if (submit) submit.disabled = true;
    if (partsInput) partsInput.disabled = true;
    this.showStatus('info', 'Enviando la tarea al nodo multimedia…');

    try {
      const data = await this.enqueue(button, operation, parts, authorizeNodeStart);
      const nodeMessage = data.node && data.node.started_on_demand
        ? ' La EC2 de alto rendimiento se está encendiendo.'
        : '';
      this.showStatus(
        'success',
        (data.message || 'La tarea fue creada. Puedes cerrar esta ventana y seguir trabajando.') + nodeMessage
      );
      if (submit) submit.textContent = 'Tarea creada';
      this.document.dispatchEvent(new CustomEvent('background-tasks:refresh'));
      this.document.dispatchEvent(new CustomEvent('drive:storage-changed'));
    } catch (error) {
      const message = error && error.message ? error.message : 'No se pudo crear la tarea multimedia.';
      this.showStatus(
        'danger',
        message.replace(/^\[(NODE_START_AUTH_REQUIRED|NODE_RATE_REQUIRED)\]\s*/, '')
      );
      if (message.includes('[NODE_START_AUTH_REQUIRED]')) {
        await this.loadNodeStatus();
      }
      if (partsInput && operation !== 'extract_mp3') partsInput.disabled = false;
      this.updateSubmitAvailability();
    }
  }

  async enqueue(button, operation, parts, authorizeNodeStart) {
    const key = (button.dataset.key || '').trim();
    if (!key) throw new Error('No se pudo identificar el archivo.');

    const bytes = Number(button.dataset.bytes || 0);
    if (bytes > this.maxSourceBytes) {
      throw new Error('El procesamiento multimedia admite archivos de hasta 8 GB.');
    }

    const body = new URLSearchParams({
      archivo: key,
      operation: operation,
      parts: String(parts || 1),
      overlap_before: '10',
      overlap_after: '10',
      authorize_node_start: authorizeNodeStart ? '1' : '0'
    });

    const response = await this.window.fetch(this.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Drive-CSRF': String(this.window.DRIVE_UPLOAD_CSRF || '')
      },
      body: body.toString()
    });

    let data = null;
    try {
      data = await response.json();
    } catch (_) {
      throw new Error('El servidor no devolvió una respuesta válida.');
    }

    if (!response.ok || !data || !data.ok) {
      throw new Error((data && (data.error || data.mensaje)) || 'No se pudo crear la tarea multimedia.');
    }
    return data;
  }

  handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const splitVideo = target.closest('.js-media-split-video');
    if (splitVideo) {
      event.preventDefault();
      this.openModal(splitVideo, 'split_video');
      return;
    }

    const splitAudio = target.closest('.js-media-split-audio');
    if (splitAudio) {
      event.preventDefault();
      this.openModal(splitAudio, 'split_audio');
      return;
    }

    const mp3 = target.closest('.js-media-extract-mp3');
    if (mp3) {
      event.preventDefault();
      this.openModal(mp3, 'extract_mp3');
    }
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || {modules: {}};
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new MediaProcessingModule(win, doc).init();
    win.ArcadeCloudDrive.modules.mediaProcessing = instance;
    return instance;
  }
}

MediaProcessingModule.boot();
