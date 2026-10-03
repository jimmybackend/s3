/* File -> type -> application -> window instance orchestration for ArcadeCloud OS. */
class ArcadeCloudFileApplicationService {
  constructor(win, doc, runtime) {
    this.window = win;
    this.document = doc;
    this.runtime = runtime;
    this.manager = runtime?.manager || win.ArcadeCloudWindowManager;
    this.instances = new Map();
    this.mediaPlaying = null;
  }

  registerApplications() {
    const definitions = [
      ['image', 'Visor de imágenes', 'fa-file-image', ['image/*'], ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff','svg']],
      ['pdf', 'Visor PDF', 'fa-file-pdf', ['application/pdf'], ['pdf']],
      ['text', 'Editor de texto', 'fa-file-lines', ['text/*','application/json','application/xml','application/javascript','application/x-javascript','application/yaml','application/x-yaml'], ['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml']],
      ['text-preview', 'Visor de texto', 'fa-eye', ['text/*','application/json','application/xml','application/javascript','application/x-javascript','application/yaml','application/x-yaml'], ['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml']],
      ['audio', 'Reproductor de audio', 'fa-file-audio', ['audio/*'], ['mp3','wav','ogg','opus','m4a','aac','flac','amr']],
      ['video', 'Reproductor de video', 'fa-file-video', ['video/*'], ['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg']],
      ['office', 'Office', 'fa-file-word', [
        'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text','application/vnd.oasis.opendocument.spreadsheet','application/vnd.oasis.opendocument.presentation','application/rtf','text/rtf'
      ], ['doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp']]
    ];
    definitions.forEach(([appId,title,icon,supportedTypes,supportedExtensions]) => {
      this.manager.registerApp(appId, { appId, title, icon, multiInstance: true, lifecycle: 'dynamic', supportedTypes, supportedExtensions });
    });
  }

  fileFromEntry(entry) {
    const data = entry?.dataset || {};
    const rawFileId = String(data.fileId || '').trim();
    return {
      fileId: /^\d+$/.test(rawFileId) && Number(rawFileId) > 0 ? rawFileId : '', name: String(data.name || 'Archivo'),
      key: String(data.key || ''), mime: String(data.mime || '').toLowerCase().split(';')[0],
      extension: String(data.ext || '').toLowerCase(), openUrl: String(data.openUrl || ''),
      editUrl: String(data.editUrl || ''), officeUrl: String(data.officeUrl || ''),
      downloadUrl: String(data.downloadUrl || ''), wallpaperUrl: String(data.wallpaperUrl || ''),
      updatedAt: String(data.updatedAt || ''), locked: data.locked === '1'
    };
  }

  supports(definition, file) {
    if (!definition || !file || file.locked) return false;
    if (definition.appId === 'office' && !file.officeUrl) return false;
    if (definition.appId === 'text' && !file.editUrl) return false;
    if (definition.appId === 'text-preview' && !file.openUrl) return false;
    if (definition.appId !== 'office' && !file.openUrl && !file.editUrl) return false;
    const mime = String(file.mime || '').toLowerCase();
    const reliableMime = mime && mime !== 'application/octet-stream' && mime !== 'binary/octet-stream';
    const mimeMatch = reliableMime && (definition.supportedTypes || []).some(type => type.endsWith('/*') ? mime.startsWith(type.slice(0, -1)) : mime === type);
    if (reliableMime) return Boolean(mimeMatch);
    return (definition.supportedExtensions || []).includes(String(file.extension || '').toLowerCase());
  }

  applicationsFor(file) {
    return [...this.manager.apps.entries()].map(([appId, definition]) => ({ appId, ...definition }))
      .filter(definition => this.supports(definition, file));
  }

  defaultApplication(file) {
    const apps = this.applicationsFor(file);
    const order = ['image','pdf','text','audio','video','office'];
    return order.map(id => apps.find(app => app.appId === id)).find(Boolean) || apps[0] || null;
  }

  identity(appId, file) {
    return `${appId}:${file.fileId ? `id:${file.fileId}` : `key:${file.key}`}`;
  }

  openFile(fileOrEntry, options = {}) {
    const file = fileOrEntry?.dataset ? this.fileFromEntry(fileOrEntry) : { ...fileOrEntry };
    const application = options.appId
      ? this.applicationsFor(file).find(app => app.appId === options.appId)
      : this.defaultApplication(file);
    if (!application) throw new Error('Este archivo no tiene una aplicación compatible disponible.');
    const identity = this.identity(application.appId, file);
    const existingId = this.instances.get(identity);
    const existing = existingId && this.manager.record(existingId);
    if (existing && options.forceNew !== true) { this.manager.open(existing.id); return existing; }
    if (application.appId === 'office') {
      const opened = this.window.open(file.officeUrl, '_blank');
      if (opened) opened.opener = null;
      else throw new Error('El navegador bloqueó la nueva pestaña de Office.');
      return null;
    }
    return this.createInstance(application, file, identity);
  }

  createInstance(application, file, identity) {
    const element = this.document.createElement('section');
    element.className = `os-window os-document-window os-${application.appId}-window`;
    element.dataset.windowTitle = file.name;
    element.dataset.dynamicWindow = '1';
    element.dataset.fileId = file.fileId;
    element.dataset.fileKey = file.key;
    element.innerHTML = '<div class="os-window-titlebar" data-window-drag-handle><div class="os-window-title"><i class="fas"></i><span></span></div><div class="os-window-controls"><button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button><button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button><button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button></div></div><div class="os-window-body os-viewer-body"><div class="os-viewer-loading">Cargando…</div></div><div class="os-statusbar"><span></span><span>ArcadeCloud OS</span></div>';
    element.querySelector('.os-window-title i').className = `fas ${application.icon}`;
    element.querySelector('.os-window-title span').textContent = file.name;
    element.querySelector('.os-statusbar span').textContent = application.title;
    (this.document.getElementById('osDesktop') || this.document.body).append(element);
    const state = { file: { ...file }, application: application.appId, unavailable: false };
    const record = this.manager.register(element, application.appId, state);
    this.instances.set(identity, record.id);
    this.runtime.bindWindowChrome(element);
    const unsubscribe = this.runtime.bus.on('filesystem:changed', event => this.onFilesystemChanged(record.id, event.detail || {}));
    this.manager.addCleanup(record.id, () => { unsubscribe(); if (this.instances.get(identity) === record.id) this.instances.delete(identity); });
    this.render(record);
    this.manager.open(record.id);
    return record;
  }

  render(record) {
    const { file, application } = record.state;
    const body = record.element.querySelector('.os-viewer-body');
    const content = this.document.createElement('div');
    content.className = 'os-viewer-content';
    let viewer;
    const source = application === 'text' && file.editUrl ? file.editUrl : file.openUrl;
    if (application === 'image') {
      viewer = this.document.createElement('img'); viewer.className = 'os-viewer-image'; viewer.alt = file.name;
    } else if (application === 'audio' || application === 'video') {
      viewer = this.document.createElement(application); viewer.className = `os-viewer-${application}`; viewer.controls = true; viewer.preload = 'metadata';
      if (application === 'video') viewer.playsInline = true;
      const playing = () => { if (this.mediaPlaying && this.mediaPlaying !== viewer) this.mediaPlaying.pause(); this.mediaPlaying = viewer; };
      viewer.addEventListener('play', playing);
      this.manager.addCleanup(record.id, () => { viewer.removeEventListener('play', playing); if (this.mediaPlaying === viewer) this.mediaPlaying = null; });
    } else {
      viewer = this.document.createElement('iframe'); viewer.className = 'os-viewer-frame'; viewer.title = `${application === 'text' ? 'Editor' : 'Visor'} de ${file.name}`;
    }
    const loaded = () => body.classList.remove('is-loading');
    const failed = () => this.showError(record, 'No se pudo cargar el archivo.');
    viewer.addEventListener('load', loaded, { once: true });
    if (application === 'audio' || application === 'video') viewer.addEventListener('loadedmetadata', loaded, { once: true });
    viewer.addEventListener('error', failed, { once: true });
    viewer.src = source; content.append(viewer); body.replaceChildren(content); body.classList.add('is-loading');
    const toolbar = this.document.createElement('div'); toolbar.className = 'os-viewer-toolbar';
    if (file.downloadUrl) toolbar.append(this.actionLink(file.downloadUrl, 'fa-download', 'Descargar', true));
    if (application === 'image' && file.wallpaperUrl) {
      const wallpaper = this.document.createElement('button'); wallpaper.type = 'button'; wallpaper.innerHTML = '<i class="fas fa-panorama"></i><span>Usar como fondo</span>';
      wallpaper.addEventListener('click', () => this.window.ArcadeCloudOsAppearance?.setWallpaper(file.wallpaperUrl, file.name)); toolbar.append(wallpaper);
    }
    if (toolbar.children.length) {
      if (application === 'image') {
        toolbar.classList.add('os-viewer-toolbar-bottom');
        const status = record.element.querySelector('.os-statusbar');
        if (status) status.append(toolbar);
        else body.append(toolbar);
      } else {
        body.prepend(toolbar);
      }
    }
  }

  actionLink(url, icon, label, download) {
    const link = this.document.createElement('a'); link.href = url; if (download) link.setAttribute('download', '');
    link.innerHTML = `<i class="fas ${icon}"></i><span></span>`; link.querySelector('span').textContent = label; return link;
  }

  affected(file, detail) {
    if (file.fileId && String(detail.fileId || detail.file_id || '') === file.fileId) return true;
    return (detail.items || []).map(String).includes(String(file.key || ''));
  }

  onFilesystemChanged(windowId, detail) {
    const record = this.manager.record(windowId); if (!record || !this.affected(record.state.file, detail)) return;
    if (/delete/.test(String(detail.operation || ''))) {
      record.state.unavailable = true;
      this.showError(record, 'Este archivo ya no está disponible.');
      return;
    }
    if (/rename/.test(String(detail.operation || ''))) {
      const nextName = String(detail.name || detail.newName || detail.result?.name || '');
      if (nextName) { record.state.file.name = nextName; this.manager.setTitle(record.id, nextName); }
    }
    if (/modify|update|save/.test(String(detail.operation || ''))) record.element.querySelector('.os-statusbar span:last-child').textContent = 'El archivo cambió; vuelve a abrirlo para actualizar.';
  }

  showError(record, message) {
    const body = record.element.querySelector('.os-viewer-body');
    body.classList.remove('is-loading'); body.replaceChildren();
    const error = this.document.createElement('div'); error.className = 'os-viewer-error'; error.innerHTML = '<i class="fas fa-triangle-exclamation"></i><p></p>';
    error.querySelector('p').textContent = message; body.append(error);
  }

  chooseApplication(fileOrEntry) {
    const file = fileOrEntry?.dataset ? this.fileFromEntry(fileOrEntry) : { ...fileOrEntry };
    const apps = this.applicationsFor(file);
    if (apps.length < 2) return Promise.resolve(null);
    return new Promise(resolve => {
      const overlay = this.document.createElement('div'); overlay.className = 'os-decision-overlay';
      const dialog = this.document.createElement('section'); dialog.className = 'os-decision-dialog os-open-with-dialog'; dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true');
      dialog.innerHTML = '<header><h2>Abrir con</h2></header><div class="os-decision-body"><p data-file-name></p><div class="os-open-with-apps"></div></div><footer><button type="button" data-cancel>Cancelar</button></footer>';
      dialog.querySelector('[data-file-name]').textContent = file.name;
      const list = dialog.querySelector('.os-open-with-apps');
      apps.forEach(app => { const button = this.document.createElement('button'); button.type = 'button'; button.dataset.appId = app.appId; button.innerHTML = `<i class="fas ${app.icon}"></i><span></span>`; button.querySelector('span').textContent = app.title; list.append(button); });
      overlay.append(dialog); this.document.body.append(overlay);
      const finish = appId => { this.document.removeEventListener('keydown', onKey); overlay.remove(); resolve(appId); };
      const onKey = event => { if (event.key === 'Escape') finish(null); };
      overlay.addEventListener('click', event => { const button = event.target.closest?.('[data-app-id]'); if (button) finish(button.dataset.appId); else if (event.target === overlay || event.target.closest?.('[data-cancel]')) finish(null); });
      this.document.addEventListener('keydown', onKey); list.querySelector('button')?.focus();
    });
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudFileApplicationService };
if (typeof window !== 'undefined' && window.ArcadeCloudDesktop) {
  window.ArcadeCloudFileApplications = new ArcadeCloudFileApplicationService(window, document, window.ArcadeCloudDesktop);
  window.ArcadeCloudFileApplications.registerApplications();
}
