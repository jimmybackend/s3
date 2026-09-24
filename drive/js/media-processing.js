class MediaProcessingModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.endpoint = 'media_processing.php';
    this.maxSourceBytes = 8 * 1024 * 1024 * 1024;
    this.currentSplitButton = null;
    this.currentOperation = '';
  }

  init() {
    if (this.window.__mediaProcessingBound) {
      return this;
    }
    this.window.__mediaProcessingBound = true;

    this.document.addEventListener('click', (event) => this.handleClick(event));

    const submit = this.document.getElementById('btnMediaSplitSubmit');
    if (submit) {
      submit.addEventListener('click', () => this.submitSplit());
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

  showSplitStatus(type, message) {
    const box = this.document.getElementById('mediaSplitStatus');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
  }

  clearSplitStatus() {
    const box = this.document.getElementById('mediaSplitStatus');
    if (!box) return;
    box.className = 'alert d-none';
    box.textContent = '';
  }

  openSplitModal(button, operation) {
    const key = (button.dataset.key || '').trim();
    const name = (button.dataset.nombre || key).trim();
    const bytes = Number(button.dataset.bytes || 0);

    if (!key) {
      return;
    }

    this.currentSplitButton = button;
    this.currentOperation = operation;

    const title = this.document.getElementById('mediaSplitTitle');
    const file = this.document.getElementById('mediaSplitFile');
    const size = this.document.getElementById('mediaSplitSize');
    const parts = this.document.getElementById('mediaSplitParts');
    const submit = this.document.getElementById('btnMediaSplitSubmit');

    if (title) {
      title.textContent = operation === 'split_audio' ? 'Dividir audio' : 'Dividir video';
    }
    if (file) file.textContent = name || key;
    if (size) size.textContent = this.formatBytes(bytes);
    if (parts) {
      parts.value = '2';
      parts.disabled = false;
    }
    if (submit) {
      submit.disabled = false;
      submit.textContent = 'Enviar a procesamiento';
    }
    this.clearSplitStatus();

    if (bytes > this.maxSourceBytes) {
      if (parts) parts.disabled = true;
      if (submit) submit.disabled = true;
      this.showSplitStatus(
        'danger',
        'Este archivo supera el máximo de 8 GB permitido para procesamiento multimedia.'
      );
    }

    if (this.window.jQuery && this.window.jQuery.fn.modal) {
      this.window.jQuery('#modalMediaSplit').modal('show');
    } else {
      alert('No se pudo abrir el formulario de división. Recarga la página e inténtalo de nuevo.');
    }
  }

  async submitSplit() {
    const button = this.currentSplitButton;
    const operation = this.currentOperation;
    const partsInput = this.document.getElementById('mediaSplitParts');
    const submit = this.document.getElementById('btnMediaSplitSubmit');

    if (!button || !operation || !partsInput) {
      this.showSplitStatus('danger', 'No se pudo identificar el archivo a dividir.');
      return;
    }

    const parts = parseInt(partsInput.value, 10);
    if (!Number.isInteger(parts) || parts < 2 || parts > 50) {
      this.showSplitStatus('warning', 'Indica una cantidad de partes entre 2 y 50.');
      partsInput.focus();
      return;
    }

    if (submit) submit.disabled = true;
    partsInput.disabled = true;
    this.showSplitStatus('info', 'Enviando la tarea al nodo multimedia…');

    try {
      const data = await this.enqueue(button, operation, parts);
      this.showSplitStatus(
        'success',
        data.message || 'La tarea fue creada. Puedes cerrar esta ventana y seguir trabajando.'
      );
      if (submit) submit.textContent = 'Tarea creada';
      this.document.dispatchEvent(new CustomEvent('background-tasks:refresh'));
    } catch (error) {
      this.showSplitStatus('danger', error.message || 'No se pudo crear la tarea multimedia.');
      if (submit) submit.disabled = false;
      partsInput.disabled = false;
    }
  }

  async enqueue(button, operation, parts) {
    const key = (button.dataset.key || '').trim();
    if (!key) {
      throw new Error('No se pudo identificar el archivo.');
    }

    const bytes = Number(button.dataset.bytes || 0);
    if (bytes > this.maxSourceBytes) {
      throw new Error('El procesamiento multimedia admite archivos de hasta 8 GB.');
    }

    const body = new URLSearchParams({
      archivo: key,
      operation: operation,
      parts: String(parts || 1),
      overlap_before: '10',
      overlap_after: '10'
    });

    const response = await fetch(this.endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    });

    let data = null;
    try {
      data = await response.json();
    } catch (_error) {
      throw new Error('El servidor no devolvió una respuesta válida.');
    }

    if (!response.ok || !data || !data.ok) {
      throw new Error((data && (data.error || data.mensaje)) || 'No se pudo crear la tarea multimedia.');
    }

    return data;
  }

  async extractMp3(button) {
    button.disabled = true;
    try {
      const data = await this.enqueue(button, 'extract_mp3', 1);
      const box = this.document.getElementById('mensajeOperacion');
      if (box) {
        box.className = 'alert alert-success';
        box.textContent = data.message || 'Extracción MP3 enviada al nodo multimedia.';
      }
      this.document.dispatchEvent(new CustomEvent('background-tasks:refresh'));
    } catch (error) {
      alert(error.message || 'No se pudo crear la tarea de extracción MP3.');
    } finally {
      button.disabled = false;
    }
  }

  handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const splitVideo = target.closest('.js-media-split-video');
    if (splitVideo) {
      event.preventDefault();
      this.openSplitModal(splitVideo, 'split_video');
      return;
    }

    const splitAudio = target.closest('.js-media-split-audio');
    if (splitAudio) {
      event.preventDefault();
      this.openSplitModal(splitAudio, 'split_audio');
      return;
    }

    const mp3 = target.closest('.js-media-extract-mp3');
    if (mp3) {
      event.preventDefault();
      this.extractMp3(mp3);
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
