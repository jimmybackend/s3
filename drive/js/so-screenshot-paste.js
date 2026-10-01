class ArcadeCloudOsScreenshotPaste {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.busy = false;
    this.eventsBound = false;
  }

  init() {
    this.ensureButton();
    this.bindEvents();
    return this;
  }

  ensureButton() {
    this.document.querySelectorAll('.os-explorer-live .os-explorer-toolbar').forEach((toolbar) => {
      if (toolbar.querySelector('[data-os-screenshot-paste]')) return;

      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'os-screenshot-paste';
      button.dataset.osScreenshotPaste = '1';
      button.title = 'Pegar una imagen del portapapeles en esta carpeta';

      const icon = this.document.createElement('i');
      icon.className = 'fas fa-camera';
      const label = this.document.createElement('span');
      label.textContent = 'Pegar screenshot';

      button.appendChild(icon);
      button.appendChild(label);

      const pager = toolbar.querySelector('.os-folder-pagination');
      toolbar.insertBefore(button, pager || null);

      button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const live = button.closest('.os-explorer-live');
        const owner = button.closest('.os-explorer-window, .os-window');
        await this.pasteFromClipboard({
          route: String(live?.dataset?.explorerRoute || ''),
          sourceWindowId: String(owner?.dataset?.windowId || '')
        });
      });

      this.setBusy(this.busy);
    });
  }

  bindEvents() {
    if (this.eventsBound) return;
    this.eventsBound = true;

    this.document.addEventListener('arcadeos:explorer-updated', () => {
      this.ensureButton();
      this.setBusy(this.busy);
    });

    this.document.addEventListener('paste', async (event) => {
      if (this.busy || this.isEditableTarget(event.target)) return;

      const blob = this.imageFromPasteEvent(event);
      if (!blob) return;

      event.preventDefault();
      event.stopPropagation();
      await this.saveScreenshot(blob);
    });
  }

  isEditableTarget(target) {
    if (!(target instanceof Element)) return false;
    return Boolean(target.closest('input, textarea, select, [contenteditable="true"], [contenteditable=""]'));
  }

  imageFromPasteEvent(event) {
    const items = Array.from(event.clipboardData?.items || []);
    for (const item of items) {
      if (item.kind !== 'file' || !String(item.type || '').toLowerCase().startsWith('image/')) continue;
      const file = item.getAsFile();
      if (file) return file;
    }
    return null;
  }

  async pasteFromClipboard(context = null) {
    if (this.busy) return;

    if (!this.window.isSecureContext || !this.window.navigator?.clipboard?.read) {
      this.notify('Tu navegador no permite leer el portapapeles desde este botón. Usa Ctrl+V o Pegar dentro de Mis datos.', 'warning');
      return;
    }

    try {
      const items = await this.window.navigator.clipboard.read();
      for (const item of items) {
        const imageType = Array.from(item.types || []).find((type) =>
          String(type || '').toLowerCase().startsWith('image/')
        );
        if (!imageType) continue;

        const blob = await item.getType(imageType);
        await this.saveScreenshot(blob, context);
        return;
      }

      this.notify('El portapapeles no contiene una imagen.', 'warning');
    } catch (error) {
      const name = String(error?.name || '');
      if (name === 'NotAllowedError' || name === 'SecurityError') {
        this.notify('El navegador bloqueó el acceso al portapapeles. Autoriza el permiso o usa Ctrl+V/Pegar.', 'warning');
        return;
      }
      this.notify('No se pudo leer la imagen del portapapeles.', 'warning');
    }
  }

  async saveScreenshot(sourceBlob, context = null) {
    if (this.busy || !sourceBlob) return;

    const route = String(context?.route || this.currentRoute()).trim();
    const csrf = String(this.window.DRIVE_UPLOAD_CSRF || '');
    const api = String(this.window.UPLOAD_API || 'api/upload.php');

    if (!route || !csrf) {
      this.notify('No se pudo determinar la carpeta actual o la sesión de subida.', 'danger');
      return;
    }

    this.busy = true;
    this.setBusy(true);

    try {
      const pngBlob = await this.toPng(sourceBlob);
      const fileName = this.screenshotName();

      this.notify('Guardando ' + fileName + '…', 'info');

      const initParams = new URLSearchParams({
        mode: 'local_put',
        action: 'init',
        nombre: fileName,
        ruta_objetivo: route
      });

      const initResponse = await fetch(api + '?mode=local_put&action=init', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Drive-CSRF': csrf
        },
        body: initParams.toString()
      });

      const initJson = await this.safeJson(initResponse);
      if (!initResponse.ok || !initJson || initJson.ok !== true || !initJson.url || !initJson.upload_token) {
        throw new Error(
          String(initJson?.error || initJson?.message || ('No se pudo iniciar la subida. HTTP ' + initResponse.status))
        );
      }

      const putResponse = await fetch(String(initJson.url), {
        method: 'PUT',
        headers: { 'Content-Type': 'image/png' },
        body: pngBlob
      });

      if (!putResponse.ok) {
        throw new Error('S3 rechazó la imagen. HTTP ' + putResponse.status);
      }

      const completeBody = new URLSearchParams({
        upload_token: String(initJson.upload_token),
        tamano: String(pngBlob.size || 0)
      });

      const completeResponse = await fetch(api + '?mode=local_put&action=complete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Drive-CSRF': csrf
        },
        body: completeBody.toString()
      });

      const completeJson = await this.safeJson(completeResponse);
      if (!completeResponse.ok || !completeJson || completeJson.ok !== true) {
        throw new Error(
          String(completeJson?.error || completeJson?.message || 'La imagen llegó a S3 pero no pudo registrarse en FileS3.')
        );
      }

      this.notify('Screenshot guardado: ' + fileName, 'success');

      if (this.window.ArcadeCloudOsShell?.refreshExplorer) {
        await this.window.ArcadeCloudOsShell.refreshExplorer(route, {
          page: 1,
          replaceHistory: true
        });
      }
    } catch (error) {
      this.notify('No se pudo guardar el screenshot: ' + String(error?.message || error), 'danger');
    } finally {
      this.busy = false;
      this.ensureButton();
      this.setBusy(false);
    }
  }

  currentRoute() {
    const explorer = this.document.querySelector('.os-explorer-window.is-active .os-explorer-live');
    return String(explorer?.dataset.explorerRoute || this.window.DRIVE_INITIAL_ROUTE || '').trim();
  }

  screenshotName() {
    const now = new Date();
    const pad = (value, size = 2) => String(value).padStart(size, '0');
    const date =
      now.getFullYear() +
      pad(now.getMonth() + 1) +
      pad(now.getDate());
    const time =
      pad(now.getHours()) +
      pad(now.getMinutes()) +
      pad(now.getSeconds());
    const millis = pad(now.getMilliseconds(), 3);

    return 'screenshot-' + date + '-' + time + '-' + millis + '.png';
  }

  async toPng(blob) {
    if (String(blob.type || '').toLowerCase() === 'image/png') {
      return blob;
    }

    let width = 0;
    let height = 0;
    let draw = null;
    let cleanup = () => {};

    if (typeof this.window.createImageBitmap === 'function') {
      const bitmap = await this.window.createImageBitmap(blob);
      width = bitmap.width;
      height = bitmap.height;
      draw = (ctx) => ctx.drawImage(bitmap, 0, 0);
      cleanup = () => {
        if (typeof bitmap.close === 'function') bitmap.close();
      };
    } else {
      const url = URL.createObjectURL(blob);
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error('No se pudo decodificar la imagen copiada.'));
        image.src = url;
      });
      width = image.naturalWidth || image.width;
      height = image.naturalHeight || image.height;
      draw = (ctx) => ctx.drawImage(image, 0, 0);
      cleanup = () => URL.revokeObjectURL(url);
    }

    if (width <= 0 || height <= 0) {
      cleanup();
      throw new Error('La imagen copiada no tiene dimensiones válidas.');
    }

    const canvas = this.document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');

    if (!ctx || !draw) {
      cleanup();
      throw new Error('El navegador no pudo preparar la conversión a PNG.');
    }

    draw(ctx);
    cleanup();

    return await new Promise((resolve, reject) => {
      canvas.toBlob((result) => {
        if (result) resolve(result);
        else reject(new Error('No se pudo convertir la imagen a PNG.'));
      }, 'image/png');
    });
  }

  setBusy(busy) {
    this.document.querySelectorAll('[data-os-screenshot-paste]').forEach((button) => {
      button.disabled = Boolean(busy);
      const icon = button.querySelector('i');
      const label = button.querySelector('span');

      if (icon) icon.className = busy ? 'fas fa-spinner fa-spin' : 'fas fa-camera';
      if (label) label.textContent = busy ? 'Guardando…' : 'Pegar screenshot';
    });
  }

  async safeJson(response) {
    try {
      return await response.json();
    } catch (_) {
      return null;
    }
  }

  notify(message, type) {
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
      return;
    }
    if (type === 'danger') this.window.alert(message);
    else console.log('[ArcadeCloud OS]', message);
  }
}

(function bootArcadeCloudOsScreenshotPaste(win, doc) {
  const boot = () => {
    if (!doc.querySelector('.os-explorer-live')) return;

    if (win.ArcadeCloudOsScreenshotPaste instanceof ArcadeCloudOsScreenshotPaste) {
      win.ArcadeCloudOsScreenshotPaste.ensureButton();
      return;
    }

    win.ArcadeCloudOsScreenshotPaste = new ArcadeCloudOsScreenshotPaste(win, doc).init();
  };

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})(window, document);
