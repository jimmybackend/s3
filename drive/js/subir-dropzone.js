class SubirDropzoneModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    Dropzone.autoDiscover = false;

    const API = window.UPLOAD_API || 'api/upload.php';

    const drop = new Dropzone('#dropzonePublico', {
      url: `${API}?mode=dropbox&action=init`,
      paramName: 'file',
      addRemoveLinks: true,
      withCredentials: true,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },

      addedfile(file) {
        try {
          file._driveTargetRoute = window.DriveUploadDestination.capture();
        } catch (error) {
          console.error(error);
          this.removeFile(file);
          alert(error.message || error);
        }
      },

      sending(file, xhr, formData) {
        const route = file._driveTargetRoute || window.DriveUploadDestination.capture();
        formData.append('ruta_objetivo', route);
      },

      async success(file, response) {
        let payload = response;
        if (typeof payload === 'string') {
          try { payload = JSON.parse(payload); } catch (_) { payload = null; }
        }
        const first = payload && Array.isArray(payload.resultados) ? payload.resultados[0] : null;
        if (!payload || payload.ok !== true || (first && first.estado !== 'ok')) {
          const message = (first && first.mensaje) || (payload && payload.error) || 'La subida no se confirmó correctamente.';
          this.emit('error', file, message);
          return;
        }

        console.log('✅ Archivo subido:', payload);
        await window.DriveUploadDestination.afterSuccess(file._driveTargetRoute || '');
      },

      error(file, response) {
        console.error('❌ Error al subir:', response);
      }
    });
    if (typeof API !== 'undefined' && typeof window.API === 'undefined') window.API = API;
    if (typeof drop !== 'undefined' && typeof window.drop === 'undefined') window.drop = drop;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new SubirDropzoneModule(win, doc).init();
    win.ArcadeCloudDrive.modules['subir-dropzone'] = instance;
    return instance;
  }
}

SubirDropzoneModule.boot();
