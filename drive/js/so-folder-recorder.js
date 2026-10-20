/* Folder-owned audio/video recorder. Never sends media through PHP uploads:
 * hands a File to the existing signed direct-to-S3 UploadCenter and its Tasks.
 * All calls to getUserMedia are initiated by an explicit user gesture. */
class ArcadeCloudFolderRecorder {
  constructor(win, doc) {
    this.win = win;
    this.doc = doc;
    this.route = '';
    this.folderName = '';
    this.mode = '';
    this.stream = null;
    this.recorder = null;
    this.chunks = [];
    this.chunkBytes = 0;
    this.mediaBlob = null;
    this.mediaUrl = '';
    this.startedAt = 0;
    this.timer = null;
    this.closing = false;
    this.active = false;
    this.maxBytes = 180 * 1024 * 1024;
    this.maxMs = 5 * 60 * 1000;
  }

  boot() {
    if (this.doc.getElementById('osFolderRecorder')) return this;
    const dialog = this.doc.createElement('section');
    dialog.id = 'osFolderRecorder';
    dialog.className = 'os-folder-recorder';
    dialog.hidden = true;
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'osFolderRecorderTitle');
    dialog.innerHTML = '<div class="os-folder-recorder-backdrop" data-recorder-close></div>' +
      '<div class="os-folder-recorder-panel">' +
      '<header><div><strong id="osFolderRecorderTitle">Grabar</strong>' +
      '<small id="osFolderRecorderRoute"></small></div>' +
      '<button type="button" data-recorder-close aria-label="Cerrar grabadora">×</button></header>' +
      '<p id="osFolderRecorderMessage" role="status" aria-live="polite"></p>' +
      '<video id="osFolderRecorderVideo" autoplay playsinline muted hidden></video>' +
      '<audio id="osFolderRecorderAudio" controls hidden></audio>' +
      '<img id="osFolderRecorderPhoto" alt="Vista previa de foto" hidden>' +
      '<canvas id="osFolderRecorderCanvas" hidden></canvas>' +
      '<div class="os-folder-recorder-controls">' +
      '<button type="button" data-recorder-start>Comenzar grabación</button>' +
      '<button type="button" data-recorder-stop disabled>Detener</button>' +
      '<button type="button" data-recorder-snap hidden>Tomar foto</button>' +
      '<button type="button" data-recorder-save disabled>Guardar en esta carpeta</button>' +
      '</div><small>Al guardar, el archivo pasa a Tareas y se sube directamente a S3. ' +
      'El video está limitado a 5 minutos o 180 MB para proteger la memoria del teléfono.</small>' +
      '</div>';
    this.doc.body.appendChild(dialog);
    this.dialog = dialog;
    dialog.querySelector('[data-recorder-start]').addEventListener('click', () => this.start());
    dialog.querySelector('[data-recorder-stop]').addEventListener('click', () => this.stop());
    dialog.querySelector('[data-recorder-snap]').addEventListener('click', () => this.snap());
    dialog.querySelector('[data-recorder-save]').addEventListener('click', () => this.save());
    dialog.querySelectorAll('[data-recorder-close]').forEach(btn => btn.addEventListener('click', () => this.close()));
    this.doc.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.dialog && !this.dialog.hidden) this.close();
    });
    this.win.addEventListener('pagehide', () => this.close());
    return this;
  }

  open(folder, mode) {
    const route = String(folder?.route || '').trim().replace(/\\/g, '/');
    if (!route || !['audio', 'video', 'photo'].includes(mode)) return false;
    if (!this.dialog) this.boot();
    if (this.active) {
      this.message('Primero detén la grabación anterior.');
      return false;
    }
    this.reset();
    // Freeze destination: subsequent Explorer navigation never changes this route.
    this.route = route.endsWith('/') ? route : route + '/';
    this.folderName = String(folder?.name || this.route);
    this.mode = mode;
    this.dialog.querySelector('#osFolderRecorderTitle').textContent =
      mode === 'audio' ? 'Grabar nota de voz' : mode === 'video' ? 'Grabar video' : 'Tomar fotografía';
    this.dialog.querySelector('#osFolderRecorderRoute').textContent = 'Carpeta: ' + this.folderName;
    this.dialog.hidden = false;
    this.dialog.querySelector('[data-recorder-snap]').hidden = mode !== 'photo';
    this.dialog.querySelector('[data-recorder-snap]').disabled = true;
    this.message(mode === 'audio'
      ? 'Pulsa Comenzar para activar el micrófono.'
      : mode === 'video' ? 'Pulsa Comenzar para activar la cámara y el micrófono.'
      : 'Pulsa Comenzar para activar la cámara.');
    this.dialog.querySelector('[data-recorder-start]').focus();
    return true;
  }

  chooseFormat() {
    if (!this.win.MediaRecorder) return null;
    const candidates = this.mode === 'audio'
      ? ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
      : ['video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    return candidates.find(type => this.win.MediaRecorder.isTypeSupported(type)) || '';
  }

  async start() {
    if (this.active || this.dialog.hidden || !this.route) return;
    if (!this.win.isSecureContext || !this.win.navigator.mediaDevices?.getUserMedia ||
        (this.mode !== 'photo' && typeof this.win.MediaRecorder !== 'function')) {
      this.message('Este navegador no permite grabar. Revisa HTTPS y los permisos.', true);
      return;
    }
    // Prevent double taps while the Android permission prompt is visible.
    this.active = true;
    this.dialog.querySelector('[data-recorder-start]').disabled = true;
    this.dialog.querySelector('[data-recorder-save]').disabled = true;
    this.clearMedia();
    try {
      const options = this.mode === 'audio'
        ? { audio: true, video: false }
        : { audio: this.mode === 'video', video: { facingMode: { ideal: 'environment' } } };
      this.stream = await this.win.navigator.mediaDevices.getUserMedia(options);
      if (this.dialog.hidden || this.closing) {
        this.releaseTracks();
        this.active = false;
        return;
      }
      if (this.mode === 'photo') {
        const camera = this.dialog.querySelector('#osFolderRecorderVideo');
        camera.srcObject = this.stream;
        camera.hidden = false;
        camera.muted = true;
        camera.controls = false;
        await camera.play();
        this.dialog.querySelector('[data-recorder-snap]').disabled = false;
        this.message('Cámara lista. Pulsa Tomar foto.');
        return;
      }
      const mime = this.chooseFormat();
      if (!mime) throw new Error('MediaRecorder no admite un formato de grabación compatible.');
      this.recorder = new this.win.MediaRecorder(this.stream, { mimeType: mime });
      this.chunks = [];
      this.chunkBytes = 0;
      this.startedAt = Date.now();
      const currentRecorder = this.recorder;
      currentRecorder.addEventListener('dataavailable', e => {
        if (!e.data || !e.data.size) return;
        this.chunks.push(e.data);
        this.chunkBytes += e.data.size;
        if (this.chunkBytes >= this.maxBytes) this.stop();
      });
      currentRecorder.addEventListener('stop', () => {
        this.releaseTracks();
        if (this.timer) this.win.clearInterval(this.timer);
        this.timer = null;
        this.active = false;
        const blob = new Blob(this.chunks, { type: currentRecorder.mimeType });
        this.chunks = [];
        if (this.closing || this.dialog.hidden) {
          this.clearMedia();
          return;
        }
        if (blob.size <= 0 || blob.size > this.maxBytes) {
          this.message('Grabación vacía o superior a 180 MB. Graba un archivo más corto.', true);
          this.dialog.querySelector('[data-recorder-start]').disabled = false;
          return;
        }
        this.mediaBlob = blob;
        this.mediaUrl = URL.createObjectURL(blob);
        const preview = this.dialog.querySelector(this.mode === 'video' ? '#osFolderRecorderVideo' : '#osFolderRecorderAudio');
        preview.src = this.mediaUrl;
        preview.hidden = false;
        if (this.mode === 'video') {
          preview.muted = false;
          preview.controls = true;
        }
        this.dialog.querySelector('[data-recorder-start]').disabled = false;
        this.dialog.querySelector('[data-recorder-stop]').disabled = true;
        this.dialog.querySelector('[data-recorder-save]').disabled = false;
        this.message('Grabación lista. Reprodúcela o guárdala en ' + this.folderName + '.');
      }, { once: true });
      currentRecorder.addEventListener('error', () => {
        this.releaseTracks();
        this.active = false;
        this.message('Falló la grabación. Intenta nuevamente.', true);
        this.dialog.querySelector('[data-recorder-start]').disabled = false;
      });
      if (this.mode === 'video') {
        const video = this.dialog.querySelector('#osFolderRecorderVideo');
        video.srcObject = this.stream;
        video.muted = true;
        video.controls = false;
        video.hidden = false;
        await video.play();
      }
      currentRecorder.start(1000);
      this.dialog.querySelector('[data-recorder-stop]').disabled = false;
      this.message('Grabando. Pulsa Detener para preparar el archivo.');
      this.timer = this.win.setInterval(() => {
        if (Date.now() - this.startedAt >= this.maxMs) this.stop();
      }, 1000);
    } catch (error) {
      this.releaseTracks();
      this.active = false;
      this.dialog.querySelector('[data-recorder-start]').disabled = false;
      this.dialog.querySelector('[data-recorder-stop]').disabled = true;
      this.message('No se pudo iniciar la grabación. Autoriza los permisos de cámara/micrófono o revisa su disponibilidad.', true);
    }
  }

  snap() {
    if (this.mode !== 'photo' || !this.active || !this.stream || this.dialog.hidden) return;
    const camera = this.dialog.querySelector('#osFolderRecorderVideo');
    if (!camera.videoWidth || !camera.videoHeight) {
      this.message('Espera a que la cámara esté lista.', true);
      return;
    }
    const canvas = this.dialog.querySelector('#osFolderRecorderCanvas');
    canvas.width = camera.videoWidth;
    canvas.height = camera.videoHeight;
    canvas.getContext('2d').drawImage(camera, 0, 0);
    this.dialog.querySelector('[data-recorder-snap]').disabled = true;
    canvas.toBlob(blob => {
      this.releaseTracks();
      this.active = false;
      if (this.dialog.hidden || this.closing || !blob) return;
      this.mediaBlob = blob;
      this.mediaUrl = URL.createObjectURL(blob);
      const preview = this.dialog.querySelector('#osFolderRecorderPhoto');
      preview.src = this.mediaUrl;
      preview.hidden = false;
      camera.hidden = true;
      this.dialog.querySelector('[data-recorder-start]').disabled = false;
      this.dialog.querySelector('[data-recorder-save]').disabled = false;
      this.message('Fotografía lista. Pulsa Guardar en esta carpeta.');
    }, 'image/jpeg', 0.88);
  }

  stop() {
    if (this.recorder?.state === 'recording') {
      this.dialog.querySelector('[data-recorder-stop]').disabled = true;
      this.recorder.stop();
    }
  }

  save() {
    if (this.active || !this.mediaBlob || !this.route) return;
    const upload = this.win.ArcadeCloudUploadManager;
    if (!upload || typeof upload.enqueueLocal !== 'function' || typeof upload.startMultipart !== 'function') {
      this.message('El administrador de subidas no está disponible.', true);
      return;
    }
    const blob = this.mediaBlob;
    const mime = blob.type.split(';')[0].toLowerCase();
    const extension = this.mode === 'photo' ? 'jpg' : mime.includes('mp4') ? 'mp4' : 'webm';
    const name = (this.mode === 'audio' ? 'Nota_voz_' : this.mode === 'video' ? 'Video_' : 'Foto_') +
      new Date().toISOString().replace(/[:.]/g, '-') + '.' + extension;
    const file = new File([blob], name, { type: mime || (this.mode === 'audio' ? 'audio/webm' : this.mode === 'video' ? 'video/webm' : 'image/jpeg') });
    const route = this.route;
    try {
      if (file.size >= 8 * 1024 * 1024) {
        // Multipart is signed server-side and sends every part directly to S3.
        Promise.resolve(upload.startMultipart(file, route)).catch(error => {
          this.win.ArcadeCloudOsShell?.notify?.(error.message || 'Falló la subida del video.', 'danger');
        });
      } else {
        upload.enqueueLocal(file, route, this.mode === 'audio' ? 'Nota de voz' : this.mode === 'video' ? 'Video' : 'Foto');
      }
      this.win.ArcadeCloudOsShell?.notify?.(
        name + ' enviado a Tareas para guardar en ' + this.folderName + '.', 'success');
      // A queued upload is not the same as a completed upload.
      this.close();
    } catch (error) {
      this.message('No se pudo agregar a Tareas: ' + (error.message || 'Error inesperado'), true);
    }
  }

  releaseTracks() {
    this.stream?.getTracks().forEach(track => track.stop());
    this.stream = null;
    const video = this.dialog?.querySelector('#osFolderRecorderVideo');
    if (video) video.srcObject = null;
  }

  clearMedia() {
    if (this.mediaUrl) URL.revokeObjectURL(this.mediaUrl);
    this.mediaUrl = '';
    this.mediaBlob = null;
    this.chunks = [];
    this.chunkBytes = 0;
    this.dialog?.querySelectorAll('audio,video').forEach(media => {
      media.pause();
      media.removeAttribute('src');
      media.hidden = true;
    });
    const image = this.dialog?.querySelector('#osFolderRecorderPhoto');
    if (image) { image.hidden = true; image.removeAttribute('src'); }
  }

  reset() {
    this.closing = false;
    this.releaseTracks();
    if (this.timer) this.win.clearInterval(this.timer);
    this.timer = null;
    this.clearMedia();
    if (this.dialog) {
      this.dialog.querySelector('[data-recorder-start]').disabled = false;
      this.dialog.querySelector('[data-recorder-stop]').disabled = true;
      this.dialog.querySelector('[data-recorder-save]').disabled = true;
      this.dialog.querySelector('[data-recorder-snap]').disabled = true;
    }
  }

  close() {
    if (!this.dialog) return;
    this.closing = true;
    this.dialog.hidden = true;
    if (this.recorder?.state === 'recording') this.recorder.stop();
    this.reset();
    this.route = '';
    this.mode = '';
  }

  message(text, error = false) {
    const node = this.dialog?.querySelector('#osFolderRecorderMessage');
    if (node) { node.textContent = text; node.dataset.error = error ? 'true' : 'false'; }
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudFolderRecorder };
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  window.ArcadeCloudFolderRecorder = new ArcadeCloudFolderRecorder(window, document).boot();
}
