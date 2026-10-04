class ArcadeCloudUploadCenter {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.api = String(win.UPLOAD_API || 'api/upload.php');
    this.csrf = String(win.DRIVE_UPLOAD_CSRF || '');
    this.tasks = new Map();
    this.localQueue = Promise.resolve();
    this.modal = null;
    this.openRoute = '';
    this.openContext = null;
    this.clipboard = { image: null, text: '' };
    this.eventsBound = false;
    this.terminalKeepMs = 15 * 60 * 1000;
  }

  init() {
    this.ensureUi();
    this.bindEvents();
    return this;
  }

  bindEvents() {
    if (this.eventsBound) return;
    this.eventsBound = true;

    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const button = target ? target.closest('[data-drive-upload-center]') : null;
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      this.open(this.contextForButton(button));
    });

    this.document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.modal && !this.modal.hidden) {
        this.close();
      }
    });
  }

  ensureUi() {
    if (this.document.getElementById('driveUploadCenter')) {
      this.modal = this.document.getElementById('driveUploadCenter');
      return this.modal;
    }

    const modal = this.document.createElement('section');
    modal.id = 'driveUploadCenter';
    modal.className = 'drive-upload-center';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'driveUploadCenterTitle');

    modal.innerHTML = `
      <div class="drive-upload-backdrop" data-upload-close></div>
      <div class="drive-upload-dialog">
        <header class="drive-upload-head">
          <div>
            <strong id="driveUploadCenterTitle"><i class="fas fa-cloud-arrow-up"></i> Subir</strong>
            <small data-upload-route></small>
          </div>
          <button type="button" class="drive-upload-close" data-upload-close aria-label="Cerrar">×</button>
        </header>

        <div class="drive-upload-body">
          <div class="drive-upload-grid">
            <article class="drive-upload-card">
              <h3><i class="fas fa-file-arrow-up"></i> Archivo</h3>
              <p>Subida directa del navegador a S3.</p>
              <label class="drive-upload-file-button">
                Seleccionar archivo
                <input type="file" data-upload-file hidden>
              </label>
            </article>

            <article class="drive-upload-card">
              <h3><i class="fas fa-layer-group"></i> Archivo grande</h3>
              <p>Multipart directo a S3, con progreso, reintentos y reanudación.</p>
              <label class="drive-upload-file-button">
                Seleccionar archivo grande
                <input type="file" data-upload-large hidden>
              </label>
            </article>

            <article class="drive-upload-card drive-upload-card-wide">
              <h3><i class="fas fa-cloud-arrow-up"></i> Dropzone / varios archivos</h3>
              <p>Arrastra varios archivos aquí o toca para seleccionarlos. Se procesan en cola.</p>
              <div class="drive-upload-dropzone" data-upload-dropzone tabindex="0">
                <i class="fas fa-copy"></i>
                <span>Arrastra aquí o selecciona varios archivos</span>
                <input type="file" data-upload-multiple multiple hidden>
              </div>
            </article>

            <article class="drive-upload-card drive-upload-card-wide">
              <h3><i class="fas fa-link"></i> Desde enlace</h3>
              <p>El servidor obtiene la URL y guarda el resultado en la carpeta fijada.</p>
              <div class="drive-upload-inline">
                <input type="url" data-upload-url placeholder="https://...">
                <button type="button" data-upload-url-submit>Subir enlace</button>
              </div>
            </article>

            <article class="drive-upload-card drive-upload-card-wide">
              <h3><i class="fas fa-clipboard"></i> Portapapeles</h3>
              <p data-upload-clipboard-status>Se revisará únicamente al abrir este panel.</p>
              <div class="drive-upload-clipboard-actions">
                <button type="button" data-upload-paste-image hidden><i class="fas fa-image"></i> Pegar imagen</button>
                <button type="button" data-upload-paste-text hidden><i class="fas fa-file-lines"></i> Pegar texto</button>
                <button type="button" data-upload-clipboard-scan><i class="fas fa-rotate"></i> Revisar otra vez</button>
              </div>
              <div class="drive-upload-paste-zone" data-upload-paste-zone tabindex="0">
                Si el navegador no permite leer automáticamente, toca aquí y usa Ctrl+V / Pegar.
              </div>
            </article>
          </div>

          <div class="drive-upload-status" data-upload-status>
            Selecciona una forma de subida. Puedes cerrar este panel después de iniciar; la tarea seguirá.
          </div>
        </div>
      </div>
    `;

    this.document.body.appendChild(modal);
    this.modal = modal;

    modal.querySelectorAll('[data-upload-close]').forEach((button) => {
      button.addEventListener('click', () => this.close());
    });

    const fileInput = modal.querySelector('[data-upload-file]');
    fileInput?.addEventListener('change', () => {
      const file = fileInput.files?.[0] || null;
      if (file) this.enqueueLocal(file, this.openRoute, 'Archivo');
      fileInput.value = '';
    });

    const largeInput = modal.querySelector('[data-upload-large]');
    largeInput?.addEventListener('change', () => {
      const file = largeInput.files?.[0] || null;
      if (file) this.startMultipart(file, this.openRoute);
      largeInput.value = '';
    });

    const multipleInput = modal.querySelector('[data-upload-multiple]');
    multipleInput?.addEventListener('change', () => {
      this.enqueueMany(Array.from(multipleInput.files || []), this.openRoute);
      multipleInput.value = '';
    });

    const dropzone = modal.querySelector('[data-upload-dropzone]');
    dropzone?.addEventListener('click', () => multipleInput?.click());
    dropzone?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        multipleInput?.click();
      }
    });
    ['dragenter', 'dragover'].forEach((name) => {
      dropzone?.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach((name) => {
      dropzone?.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
      });
    });
    dropzone?.addEventListener('drop', (event) => {
      this.enqueueMany(Array.from(event.dataTransfer?.files || []), this.openRoute);
    });

    modal.querySelector('[data-upload-url-submit]')?.addEventListener('click', () => {
      const input = modal.querySelector('[data-upload-url]');
      const url = String(input?.value || '').trim();
      if (!url) {
        this.setPanelStatus('Escribe una URL válida.', 'warning');
        return;
      }
      this.startRemoteUrl(url, this.openRoute);
      if (input) input.value = '';
    });

    modal.querySelector('[data-upload-clipboard-scan]')?.addEventListener('click', () => this.inspectClipboard());

    modal.querySelector('[data-upload-paste-image]')?.addEventListener('click', async () => {
      const blob = this.clipboard.image;
      if (!blob) return;
      const png = await this.toPng(blob);
      this.enqueueLocal(png, this.openRoute, 'Portapapeles', this.timestampName('screenshot', 'png'));
    });

    modal.querySelector('[data-upload-paste-text]')?.addEventListener('click', () => {
      const text = String(this.clipboard.text || '');
      if (!text) return;
      const blob = new Blob([text], { type: 'text/plain;charset=UTF-8' });
      this.enqueueLocal(blob, this.openRoute, 'Portapapeles', this.timestampName('clipboard', 'txt'));
    });

    const pasteZone = modal.querySelector('[data-upload-paste-zone]');
    pasteZone?.addEventListener('paste', async (event) => {
      const data = event.clipboardData;
      if (!data) return;
      const items = Array.from(data.items || []);
      const imageItem = items.find((item) => item.kind === 'file' && String(item.type || '').startsWith('image/'));
      if (imageItem) {
        event.preventDefault();
        const blob = imageItem.getAsFile();
        if (blob) {
          this.clipboard.image = blob;
          this.clipboard.text = '';
          this.renderClipboard();
        }
        return;
      }
      const text = data.getData('text/plain');
      if (text) {
        event.preventDefault();
        this.clipboard.image = null;
        this.clipboard.text = text;
        this.renderClipboard();
      }
    });

    return modal;
  }

  async open(context = null) {
    this.ensureUi();
    try {
      const route = this.captureRoute(context);
      this.openContext = Object.freeze({
        sourceWindowId: String(context?.sourceWindowId || ''),
        destinationRoute: route
      });
      this.openRoute = route;
    } catch (error) {
      this.notify(error?.message || 'No se pudo determinar la carpeta destino.', 'danger');
      return;
    }

    const route = this.modal.querySelector('[data-upload-route]');
    if (route) route.textContent = 'Destino fijado: ' + this.openRoute;

    this.modal.hidden = false;
    this.document.body.classList.add('drive-upload-open');
    this.setPanelStatus('Revisando el portapapeles…', 'info');
    await this.inspectClipboard();
  }

  close() {
    if (!this.modal) return;
    this.modal.hidden = true;
    this.document.body.classList.remove('drive-upload-open');
  }

  contextForButton(button) {
    if (this.window.DriveUploadDestination?.contextFromElement) {
      return this.window.DriveUploadDestination.contextFromElement(button);
    }
    const live = button?.closest?.('.os-explorer-live')
      || button?.closest?.('.os-explorer-window')?.querySelector?.('.os-explorer-live');
    const owner = button?.closest?.('.os-explorer-window, .os-window');
    return {
      route: String(live?.dataset?.explorerRoute || ''),
      sourceWindowId: String(owner?.dataset?.windowId || '')
    };
  }

  captureRoute(context = null) {
    if (this.window.DriveUploadDestination?.capture) {
      return this.window.DriveUploadDestination.capture(context);
    }
    const explicit = String(context?.route || context?.destinationRoute || '').trim();
    if (explicit) return explicit.endsWith('/') ? explicit : explicit + '/';
    // Compatibilidad exclusiva con Drive clásico, donde no existe una ventana
    // Explorer propietaria del botón.
    const legacyContext = this.document.getElementById('archivosContexto');
    const raw = String(
      legacyContext?.dataset?.rutaActual ||
      this.window.rutaActual ||
      this.window.DRIVE_INITIAL_ROUTE ||
      ''
    ).trim();
    if (!raw) throw new Error('No se pudo determinar la carpeta destino de la subida.');
    return raw.endsWith('/') ? raw : raw + '/';
  }

  async inspectClipboard() {
    this.clipboard = { image: null, text: '' };
    const status = this.modal?.querySelector('[data-upload-clipboard-status]');

    if (!this.window.isSecureContext || !this.window.navigator?.clipboard?.read) {
      if (status) status.textContent = 'El navegador no permite lectura directa. Usa el área Pegar de abajo.';
      this.renderClipboard();
      return;
    }

    try {
      const items = await this.window.navigator.clipboard.read();
      for (const item of items) {
        const types = Array.from(item.types || []);
        const imageType = types.find((type) => String(type).startsWith('image/'));
        if (imageType && !this.clipboard.image) {
          this.clipboard.image = await item.getType(imageType);
        }
        if (types.includes('text/plain') && !this.clipboard.text) {
          const textBlob = await item.getType('text/plain');
          this.clipboard.text = await textBlob.text();
        }
      }
      this.renderClipboard();
    } catch (error) {
      if (status) status.textContent = 'No se pudo leer automáticamente. Toca el área de Pegar y usa Ctrl+V / Pegar.';
      this.renderClipboard();
    }
  }

  renderClipboard() {
    if (!this.modal) return;
    const image = this.modal.querySelector('[data-upload-paste-image]');
    const text = this.modal.querySelector('[data-upload-paste-text]');
    const status = this.modal.querySelector('[data-upload-clipboard-status]');

    const hasImage = Boolean(this.clipboard.image);
    const hasText = String(this.clipboard.text || '').trim() !== '';

    if (image) image.hidden = !hasImage;
    if (text) text.hidden = !hasText;

    if (status) {
      if (hasImage && hasText) status.textContent = 'Encontré una imagen y texto en el portapapeles.';
      else if (hasImage) status.textContent = 'Encontré una imagen lista para guardar como PNG.';
      else if (hasText) status.textContent = 'Encontré texto listo para guardar como TXT.';
      else if (!status.textContent.includes('No se pudo')) status.textContent = 'No encontré imagen ni texto para pegar.';
    }
  }

  enqueueMany(files, route) {
    if (!files.length) return;
    files.forEach((file) => this.enqueueLocal(file, route, 'Dropzone'));
    this.setPanelStatus(files.length + ' archivo(s) agregados a Tareas. Puedes cerrar este panel.', 'success');
  }

  enqueueLocal(blob, route, source, forcedName = '') {
    const name = String(forcedName || blob?.name || 'archivo.bin');
    const task = this.createTask(name, route, source || 'Archivo', Number(blob?.size || 0), 'local_put');
    this.localQueue = this.localQueue
      .then(() => this.runLocal(task, blob, name, route))
      .catch(() => {});
    this.setPanelStatus(name + ' agregado a Tareas. Puedes seguir navegando.', 'success');
    return task.id;
  }

  async runLocal(task, blob, name, route) {
    this.updateTask(task.id, {
      status: 'running',
      progress: 1,
      detail: 'Preparando subida directa a S3.'
    });

    let uploadToken = '';
    let completeStarted = false;

    try {
      const sha256 = await this.sha256Hex(blob);
      const init = new URLSearchParams({
        mode: 'local_put',
        action: 'init',
        nombre: name,
        ruta_objetivo: route
      });
      if (sha256) init.set('sha256', sha256);

      const initResponse = await fetch(this.api + '?mode=local_put&action=init', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: this.apiHeaders(),
        body: init.toString()
      });
      const initJson = await this.safeJson(initResponse);
      if (!initResponse.ok || !initJson?.url || !initJson?.upload_token) {
        throw new Error(initJson?.error || ('No se pudo iniciar la subida. HTTP ' + initResponse.status));
      }

      uploadToken = String(initJson.upload_token);
      this.updateTask(task.id, { progress: 4, detail: 'Subiendo directamente a Amazon S3.' });

      await this.putBlob(String(initJson.url), blob, (loaded, total) => {
        const ratio = total > 0 ? loaded / total : 0;
        this.updateTask(task.id, {
          progress: Math.max(4, Math.min(94, Math.round(4 + ratio * 90))),
          detail: 'Subiendo a S3 · ' + this.formatBytes(loaded) + ' de ' + this.formatBytes(total)
        }, true);
      });

      completeStarted = true;
      this.updateTask(task.id, { progress: 96, detail: 'Registrando archivo en FileS3.' });

      const complete = new URLSearchParams({
        upload_token: uploadToken,
        tamano: String(Number(blob.size || 0))
      });
      const completeResponse = await fetch(this.api + '?mode=local_put&action=complete', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: this.apiHeaders(),
        body: complete.toString()
      });
      const completeJson = await this.safeJson(completeResponse);
      if (!completeResponse.ok || completeJson?.ok !== true) {
        throw new Error(completeJson?.error || 'La subida llegó a S3 pero no pudo confirmarse en FileS3.');
      }

      this.completeTask(task.id, 'Archivo guardado en la carpeta fijada.');
      await this.afterSuccess(route);
    } catch (error) {
      if (uploadToken && !completeStarted) {
        await this.cancelLocal(uploadToken);
      }
      this.failTask(task.id, error);
    }
  }

  async startRemoteUrl(url, route) {
    let title = 'Archivo desde URL';
    try {
      const parsed = new URL(url);
      title = decodeURIComponent(parsed.pathname.split('/').filter(Boolean).pop() || parsed.hostname || title);
    } catch (_) {}

    const task = this.createTask(title, route, 'Enlace', 0, 'remote_url');
    this.updateTask(task.id, { status: 'running', progress: 8, detail: 'El servidor está obteniendo el enlace.' });
    this.setPanelStatus('Subida desde enlace enviada a Tareas. Puedes cerrar este panel.', 'success');

    try {
      const body = new URLSearchParams({
        url,
        u64: this.base64Utf8(url),
        ruta_objetivo: route
      });
      const response = await fetch(this.api + '?mode=remote_url&action=init', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: this.apiHeaders(),
        body: body.toString()
      });
      const json = await this.safeJson(response);
      if (!response.ok || !(json && (json.ok === true || json.key))) {
        throw new Error(json?.error || ('No se pudo subir desde URL. HTTP ' + response.status));
      }
      this.updateTask(task.id, {
        title: String(json.nombreOriginal || title),
        metadata: {
          ...task.metadata,
          bytes_total: Number(json.bytes || 0)
        }
      });
      this.completeTask(task.id, 'Archivo obtenido y guardado en la carpeta fijada.');
      await this.afterSuccess(route);
    } catch (error) {
      this.failTask(task.id, error);
    }
  }

  async startMultipart(file, requestedRoute) {
    if (!file) return;
    const task = this.createTask(file.name, requestedRoute, 'Multipart', Number(file.size || 0), 'chunked');
    this.updateTask(task.id, { status: 'running', progress: 1, detail: 'Preparando subida multipart.' });
    this.setPanelStatus(file.name + ' enviado a Tareas como subida multipart.', 'success');

    const saved = this.loadMultipartSession(file, requestedRoute);
    let route = requestedRoute;
    let uploadId = '';
    let key = '';
    let stateId = '';
    let chunkSize = this.chooseChunkSize(file);
    let etags = {};

    try {
      if (saved?.uploadId && saved?.key) {
        uploadId = String(saved.uploadId);
        key = String(saved.key);
        stateId = String(saved.stateId || '');
        route = String(saved.rutaObjetivo || requestedRoute);
        chunkSize = Number(saved.chunkSize || chunkSize);
        this.updateTask(task.id, {
          metadata: { ...task.metadata, destination: route },
          detail: 'Reanudando sesión multipart en su destino original.'
        });
      } else {
        const init = new URLSearchParams({
          ruta_objetivo: route,
          filename: file.name,
          filesize: String(file.size),
          mime: file.type || 'application/octet-stream'
        });
        const response = await fetch(this.api + '?mode=chunked&action=init', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: this.apiHeaders(),
          body: init.toString()
        });
        const json = await this.safeJson(response);
        if (!response.ok || !json?.uploadId || !json?.key) {
          throw new Error(json?.error || ('No se pudo iniciar multipart. HTTP ' + response.status));
        }
        uploadId = String(json.uploadId);
        key = String(json.key);
        stateId = String(json.stateId || '');
        this.saveMultipartSession(file, route, { stateId, uploadId, key, chunkSize, rutaObjetivo: route });
      }

      try {
        const resume = new URLSearchParams({ step: 'resume', uploadId, key });
        if (stateId) resume.set('stateId', stateId);
        const response = await fetch(this.api + '?mode=chunked&action=part', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: this.apiHeaders(),
          body: resume.toString()
        });
        const json = await this.safeJson(response);
        if (response.ok && json?.etags) etags = { ...json.etags };
      } catch (_) {}

      const totalParts = Math.ceil(file.size / chunkSize);
      let uploadedBytes = 0;
      Object.keys(etags).forEach((partKey) => {
        const part = parseInt(partKey, 10);
        if (!Number.isFinite(part) || part <= 0) return;
        const start = (part - 1) * chunkSize;
        uploadedBytes += Math.min(file.size, start + chunkSize) - start;
      });

      for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
        if (etags[String(partNumber)]) continue;

        const start = (partNumber - 1) * chunkSize;
        const end = Math.min(file.size, start + chunkSize);
        const chunk = file.slice(start, end);
        const sign = new URLSearchParams({
          step: 'sign',
          uploadId,
          key,
          partNumber: String(partNumber),
          contentLength: String(end - start)
        });
        if (stateId) sign.set('stateId', stateId);

        const signResponse = await fetch(this.api + '?mode=chunked&action=part', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: this.apiHeaders(),
          body: sign.toString()
        });
        const signJson = await this.safeJson(signResponse);
        if (!signResponse.ok || !signJson?.url) {
          throw new Error(signJson?.error || ('No se pudo firmar la parte ' + partNumber + '.'));
        }

        const etag = await this.putPartWithRetry(String(signJson.url), chunk, (loaded) => {
          const totalLoaded = uploadedBytes + loaded;
          const pct = Math.max(1, Math.min(98, Math.floor((totalLoaded / file.size) * 98)));
          this.updateTask(task.id, {
            progress: pct,
            detail: 'Parte ' + partNumber + ' de ' + totalParts + ' · ' + this.formatBytes(totalLoaded) + ' de ' + this.formatBytes(file.size)
          }, true);
        });

        etags[String(partNumber)] = etag;
        uploadedBytes += end - start;
        this.saveMultipartSession(file, route, {
          stateId, uploadId, key, chunkSize, etags, rutaObjetivo: route
        });
      }

      this.updateTask(task.id, { progress: 99, detail: 'Completando multipart y registrando FileS3.' });
      const complete = new URLSearchParams({
        uploadId,
        key,
        etags: JSON.stringify(etags),
        filesize: String(file.size)
      });
      if (stateId) complete.set('stateId', stateId);

      const completeResponse = await fetch(this.api + '?mode=chunked&action=complete', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: this.apiHeaders(),
        body: complete.toString()
      });
      const completeJson = await this.safeJson(completeResponse);
      if (!completeResponse.ok || completeJson?.ok === false) {
        throw new Error(completeJson?.error || 'No se pudo completar la subida multipart.');
      }

      this.clearMultipartSession(file, route);
      this.completeTask(task.id, 'Archivo multipart guardado en la carpeta fijada.');
      await this.afterSuccess(route);
    } catch (error) {
      this.failTask(task.id, error, 'La sesión multipart se conserva para reanudar.');
    }
  }

  createTask(title, route, source, bytes, mode) {
    const id = 'upload:' + this.randomId();
    const now = new Date().toISOString();
    const task = {
      id,
      kind: 'upload',
      category: 'Subida',
      service: mode === 'remote_url' ? 'ArcadeCloud + S3' : 'Amazon S3',
      provider: 'ArcadeCloud',
      title: String(title || 'Archivo'),
      status: 'queued',
      progress: 0,
      detail: 'En cola.',
      created_at: now,
      updated_at: now,
      estimated_cost: null,
      currency: 'USD',
      actions: [],
      metadata: {
        destination: String(route || ''),
        bytes_total: Math.max(0, Number(bytes || 0)),
        source: String(source || ''),
        upload_mode: String(mode || '')
      }
    };
    this.tasks.set(id, task);
    this.emitTask(task);
    return task;
  }

  updateTask(id, changes, throttled = false) {
    const task = this.tasks.get(id);
    if (!task) return;
    const now = Date.now();
    if (throttled && task._lastEmit && now - task._lastEmit < 350) {
      Object.assign(task, changes, { updated_at: new Date().toISOString() });
      return;
    }
    Object.assign(task, changes, { updated_at: new Date().toISOString(), _lastEmit: now });
    this.emitTask(task);
  }

  completeTask(id, detail) {
    this.updateTask(id, {
      status: 'completed',
      progress: 100,
      detail: detail || 'Subida terminada.',
      actions: [{ id: 'dismiss', label: 'Eliminar de Tareas', tone: 'muted', confirm: true }]
    });
  }

  failTask(id, error, suffix = '') {
    const message = String(error?.message || error || 'Error desconocido');
    this.updateTask(id, {
      status: 'failed',
      progress: 100,
      detail: message + (suffix ? ' ' + suffix : ''),
      actions: [{ id: 'dismiss', label: 'Eliminar de Tareas', tone: 'muted', confirm: true }]
    });
    this.notify('Subida con error: ' + message, 'danger');
  }

  dismissTask(id) {
    const key = String(id || '');
    const task = this.tasks.get(key);
    if (!task) return false;

    const status = String(task.status || '').toLowerCase();
    if (!['completed', 'failed', 'cancelled'].includes(status)) return false;

    this.tasks.delete(key);
    this.document.dispatchEvent(new CustomEvent('drive:client-upload-task', {
      detail: { removed: key }
    }));
    return true;
  }

  taskSnapshots() {
    const cutoff = Date.now() - this.terminalKeepMs;
    return Array.from(this.tasks.values())
      .filter((task) => {
        if (['queued', 'running', 'pending'].includes(String(task.status))) return true;
        const updated = Date.parse(task.updated_at || '') || 0;
        return updated >= cutoff;
      })
      .map((task) => {
        const copy = { ...task };
        delete copy._lastEmit;
        return copy;
      })
      .sort((a, b) => String(b.updated_at).localeCompare(String(a.updated_at)));
  }

  emitTask(task) {
    this.document.dispatchEvent(new CustomEvent('drive:client-upload-task', {
      detail: { task: { ...task } }
    }));

    // Keep the unified Task Center synchronized even if its listener was
    // initialized before/after the upload module on a proxied node.
    const center = this.window.BackgroundTaskCenter;
    if (center && typeof center.mergeClientTasks === 'function') {
      try {
        center.mergeClientTasks();
        if (typeof center.render === 'function') center.render();
      } catch (_) {}
    }
  }

  async afterSuccess(route) {
    try {
      if (this.window.DriveUploadDestination?.afterSuccess) {
        await this.window.DriveUploadDestination.afterSuccess(route);
      } else {
        this.document.dispatchEvent(new CustomEvent('drive:storage-changed', { detail: { route } }));
      }
    } catch (_) {}
  }

  apiHeaders() {
    return {
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Drive-CSRF': this.csrf
    };
  }

  async cancelLocal(uploadToken) {
    try {
      const body = new URLSearchParams({ upload_token: uploadToken, cancel: '1' });
      await fetch(this.api + '?mode=local_put&action=part', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: this.apiHeaders(),
        body: body.toString()
      });
    } catch (_) {}
  }

  putBlob(url, blob, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.setRequestHeader('Content-Type', blob.type || 'application/octet-stream');
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && typeof onProgress === 'function') {
          onProgress(event.loaded, event.total);
        }
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) resolve();
        else reject(new Error('S3 rechazó la subida. HTTP ' + xhr.status));
      };
      xhr.onerror = () => reject(new Error(
        'No se pudo enviar el archivo directamente a S3. Revisa conectividad y CORS para ' +
        String(this.window.location.origin || 'este origen') + '.'
      ));
      xhr.send(blob);
    });
  }

  putPart(url, blob, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && typeof onProgress === 'function') onProgress(event.loaded);
      };
      xhr.onload = () => {
        if (xhr.status < 200 || xhr.status >= 300) {
          reject(new Error('S3 rechazó una parte. HTTP ' + xhr.status));
          return;
        }
        const etag = String(xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag') || '').replace(/"/g, '').trim();
        if (!etag) {
          reject(new Error('S3 no expuso ETag. Revisa CORS del bucket.'));
          return;
        }
        resolve(etag);
      };
      xhr.onerror = () => reject(new Error(
        'No se pudo enviar una parte multipart a S3. Revisa conectividad y CORS para ' +
        String(this.window.location.origin || 'este origen') + '.'
      ));
      xhr.send(blob);
    });
  }

  async putPartWithRetry(url, blob, onProgress) {
    let last = null;
    for (let attempt = 1; attempt <= 5; attempt++) {
      try {
        return await this.putPart(url, blob, onProgress);
      } catch (error) {
        last = error;
        if (attempt >= 5) break;
        await new Promise((resolve) => this.window.setTimeout(resolve, 800 * attempt));
      }
    }
    throw last || new Error('Falló la parte multipart después de varios intentos.');
  }

  chooseChunkSize(file) {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
    const type = String(connection?.effectiveType || '').toLowerCase();
    const downlink = Number(connection?.downlink || 0);
    // Keep browser parts moderate. Very large parts (64-256 MiB) make a
    // transient mobile/network failure expensive because the whole part must
    // be retried. S3 only requires 5 MiB minimum except for the final part.
    let mb = 16;
    if (type === 'slow-2g' || type === '2g') mb = 8;
    else if (type === '3g' || (downlink > 0 && downlink < 5)) mb = 12;
    else if (downlink >= 25) mb = 32;
    else if (downlink >= 10 || type === '4g') mb = 24;
    const minimumByParts = Math.ceil(file.size / 9000 / (1024 * 1024));
    mb = Math.max(mb, minimumByParts, 8);
    return Math.min(128, mb) * 1024 * 1024;
  }

  multipartKey(file, route) {
    return 's3v2_chunked_' + [route, file.name, String(file.size), String(file.lastModified || 0)].join('|');
  }

  loadMultipartSession(file, route) {
    try {
      const raw = localStorage.getItem(this.multipartKey(file, route));
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  saveMultipartSession(file, route, session) {
    try {
      localStorage.setItem(this.multipartKey(file, route), JSON.stringify({
        ...session,
        filename: file.name,
        filesize: file.size,
        lastModified: file.lastModified || 0,
        updatedAt: Date.now()
      }));
    } catch (_) {}
  }

  clearMultipartSession(file, route) {
    try { localStorage.removeItem(this.multipartKey(file, route)); } catch (_) {}
  }

  async sha256Hex(blob) {
    const max = 256 * 1024 * 1024;
    if (!blob || !blob.size || blob.size > max || !crypto?.subtle || typeof blob.arrayBuffer !== 'function') return '';
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  async toPng(blob) {
    if (String(blob?.type || '').toLowerCase() === 'image/png') return blob;

    let source = null;
    let width = 0;
    let height = 0;
    let cleanup = () => {};

    if (typeof createImageBitmap === 'function') {
      source = await createImageBitmap(blob);
      width = source.width;
      height = source.height;
      cleanup = () => source.close?.();
    } else {
      const url = URL.createObjectURL(blob);
      source = new Image();
      await new Promise((resolve, reject) => {
        source.onload = resolve;
        source.onerror = () => reject(new Error('No se pudo decodificar la imagen del portapapeles.'));
        source.src = url;
      });
      width = source.naturalWidth || source.width;
      height = source.naturalHeight || source.height;
      cleanup = () => URL.revokeObjectURL(url);
    }

    const canvas = this.document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      cleanup();
      throw new Error('El navegador no pudo convertir la imagen a PNG.');
    }
    ctx.drawImage(source, 0, 0);
    cleanup();

    return await new Promise((resolve, reject) => {
      canvas.toBlob((result) => result ? resolve(result) : reject(new Error('No se pudo crear el PNG.')), 'image/png');
    });
  }

  timestampName(prefix, ext) {
    const now = new Date();
    const pad = (value, size = 2) => String(value).padStart(size, '0');
    return prefix + '-' +
      now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + '-' +
      pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '-' +
      pad(now.getMilliseconds(), 3) + '.' + ext;
  }

  base64Utf8(value) {
    const bytes = new TextEncoder().encode(String(value));
    let binary = '';
    bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
    return btoa(binary);
  }

  randomId() {
    if (crypto?.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
  }

  formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
    return (value / Math.pow(1024, index)).toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
  }

  async safeJson(response) {
    try { return await response.json(); } catch (_) { return null; }
  }

  setPanelStatus(message, tone = 'info') {
    const node = this.modal?.querySelector('[data-upload-status]');
    if (!node) return;
    node.textContent = String(message || '');
    node.dataset.tone = tone;
  }

  notify(message, type = 'info') {
    if (this.window.DriveMoveTasks?.notify) {
      this.window.DriveMoveTasks.notify(message, type, 7000);
      return;
    }
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type, 7000);
      return;
    }
    console.log('[ArcadeCloud Upload]', message);
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudUploadManager instanceof ArcadeCloudUploadCenter) return win.ArcadeCloudUploadManager;
    const instance = new ArcadeCloudUploadCenter(win, doc).init();
    win.ArcadeCloudUploadManager = instance;
    return instance;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudUploadCenter };
if (typeof window !== 'undefined' && typeof document !== 'undefined') ArcadeCloudUploadCenter.boot();
