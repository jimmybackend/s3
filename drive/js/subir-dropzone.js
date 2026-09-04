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

    /*
     * ============================================================
     * IMPORTANTE
     * ============================================================
     * NO declaramos aquí:
     *
     *   addedfile
     *   sending
     *   uploadprogress
     *   success
     *   error
     *   removedfile
     *
     * porque son callbacks internos de Dropzone.
     *
     * Si los sustituimos se pierde:
     * - preview
     * - miniatura
     * - progreso
     * - estados
     * - cancelar/quitar
     *
     * Nuestra lógica se registra más abajo con drop.on().
     * ============================================================
     */
    const drop = new Dropzone('#dropzonePublico', {
      url: `${API}?mode=dropbox&action=init`,
      paramName: 'file',

      addRemoveLinks: true,
      withCredentials: true,

      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },

      /*
       * Miniaturas de imágenes.
       */
      createImageThumbnails: true,
      thumbnailWidth: 120,
      thumbnailHeight: 120,
      thumbnailMethod: 'contain',

      /*
       * Textos visibles.
       */
      dictDefaultMessage: 'Arrastra aquí o haz clic para subir',
      dictFallbackMessage: 'Tu navegador no permite subida mediante arrastrar y soltar.',
      dictFileTooBig: 'El archivo es demasiado grande.',
      dictInvalidFileType: 'Tipo de archivo no permitido.',
      dictResponseError: 'Error del servidor: {{statusCode}}',
      dictCancelUpload: 'Cancelar',
      dictCancelUploadConfirmation: '¿Cancelar esta subida?',
      dictRemoveFile: 'Quitar'
    });


    /*
     * ============================================================
     * HELPERS VISUALES
     * ============================================================
     */

    function statusNode(file) {
      if (!file || !file.previewElement) return null;

      let node = file.previewElement.querySelector('.drive-upload-status');

      if (!node) {
        node = document.createElement('div');
        node.className = 'drive-upload-status';
        node.style.marginTop = '6px';
        node.style.fontSize = '12px';
        node.style.fontWeight = '600';
        node.style.textAlign = 'center';

        file.previewElement.appendChild(node);
      }

      return node;
    }


    function percentNode(file) {
      if (!file || !file.previewElement) return null;

      let node = file.previewElement.querySelector('.drive-upload-percent');

      if (!node) {
        node = document.createElement('div');
        node.className = 'drive-upload-percent';
        node.style.marginTop = '3px';
        node.style.fontSize = '12px';
        node.style.textAlign = 'center';
        node.textContent = '0%';

        const progress =
          file.previewElement.querySelector('.dz-progress');

        if (progress && progress.parentNode) {
          progress.parentNode.insertBefore(
            node,
            progress.nextSibling
          );
        } else {
          file.previewElement.appendChild(node);
        }
      }

      return node;
    }


    function setStatus(file, text) {
      const node = statusNode(file);

      if (node) {
        node.textContent = text;
      }
    }


    /*
     * ============================================================
     * ARCHIVO AGREGADO
     * ============================================================
     *
     * El listener nativo de Dropzone YA creó el preview antes de
     * llegar aquí.
     * ============================================================
     */
    drop.on('addedfile', function(file) {
      try {
        file._driveTargetRoute =
          window.DriveUploadDestination.capture();

        percentNode(file);
        setStatus(file, 'Preparando…');

      } catch (error) {
        console.error(error);

        setStatus(
          file,
          error.message || String(error)
        );

        drop.removeFile(file);

        alert(error.message || error);
      }
    });


    /*
     * ============================================================
     * ENVIANDO
     * ============================================================
     */
    drop.on('sending', function(file, xhr, formData) {
      try {
        const route =
          file._driveTargetRoute ||
          window.DriveUploadDestination.capture();

        file._driveTargetRoute = route;

        formData.append(
          'ruta_objetivo',
          route
        );

        setStatus(file, 'Subiendo…');

      } catch (error) {
        console.error(error);

        try {
          xhr.abort();
        } catch (_) {}

        setStatus(
          file,
          'Error: ' + (error.message || error)
        );
      }
    });


    /*
     * ============================================================
     * PROGRESO
     * ============================================================
     */
    drop.on('uploadprogress', function(file, progress) {
      const value = Math.max(
        0,
        Math.min(100, Number(progress) || 0)
      );

      const node = percentNode(file);

      if (node) {
        node.textContent =
          value >= 100
            ? '100%'
            : `${value.toFixed(1)}%`;
      }

      setStatus(
        file,
        value >= 100
          ? 'Procesando en el servidor…'
          : 'Subiendo…'
      );
    });


    /*
     * ============================================================
     * ÉXITO
     * ============================================================
     */
    drop.on('success', async function(file, response) {
      let payload = response;

      if (typeof payload === 'string') {
        try {
          payload = JSON.parse(payload);
        } catch (_) {
          payload = null;
        }
      }

      const first =
        payload &&
        Array.isArray(payload.resultados)
          ? payload.resultados[0]
          : null;

      if (
        !payload ||
        payload.ok !== true ||
        (first && first.estado !== 'ok')
      ) {
        const message =
          (first && first.mensaje) ||
          (payload && payload.error) ||
          'La subida no se confirmó correctamente.';

        /*
         * Dropzone recibió HTTP 200 pero nuestro backend
         * informó un fallo lógico.
         */
        if (file.previewElement) {
          file.previewElement.classList.remove('dz-success');
        }

        drop.emit(
          'error',
          file,
          message
        );

        return;
      }

      const node = percentNode(file);

      if (node) {
        node.textContent = '100%';
      }

      setStatus(
        file,
        '✓ Subida completada'
      );

      console.log(
        '✅ Archivo subido:',
        payload
      );

      try {
        await window.DriveUploadDestination.afterSuccess(
          file._driveTargetRoute || ''
        );
      } catch (error) {
        console.error(
          '[Dropzone] Error actualizando vista:',
          error
        );
      }
    });


    /*
     * ============================================================
     * ERROR
     * ============================================================
     */
    drop.on('error', function(file, response) {
      let message = response;

      if (
        response &&
        typeof response === 'object'
      ) {
        message =
          response.error ||
          response.message ||
          JSON.stringify(response);
      }

      message =
        String(message || 'Error desconocido');

      setStatus(
        file,
        '✗ Error: ' + message
      );

      console.error(
        '❌ Error al subir:',
        response
      );
    });


    /*
     * ============================================================
     * CANCELADO
     * ============================================================
     *
     * Dropzone aborta el XHR automáticamente.
     *
     * En mode=dropbox no tenemos JSON multipart ni archivos
     * temporales propios:
     * el temporal pertenece a PHP y PHP lo descarta cuando
     * termina/aborta la petición.
     * ============================================================
     */
    drop.on('canceled', function(file) {
      setStatus(
        file,
        'Subida cancelada'
      );

      const node = percentNode(file);

      if (node) {
        node.textContent = 'Cancelado';
      }

      console.log(
        '⛔ Subida cancelada:',
        file.name
      );
    });


    /*
     * ============================================================
     * COLA COMPLETA
     * ============================================================
     */
    drop.on('queuecomplete', function() {
      console.log(
        '✅ Cola de subida terminada'
      );
    });


    /*
     * Compatibilidad con código existente.
     */
    if (
      typeof API !== 'undefined' &&
      typeof window.API === 'undefined'
    ) {
      window.API = API;
    }

    if (
      typeof drop !== 'undefined' &&
      typeof window.drop === 'undefined'
    ) {
      window.drop = drop;
    }

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive =
      win.ArcadeCloudDrive || {
        modules: {}
      };

    win.ArcadeCloudDrive.modules =
      win.ArcadeCloudDrive.modules || {};

    const instance =
      new SubirDropzoneModule(
        win,
        doc
      ).init();

    win.ArcadeCloudDrive.modules[
      'subir-dropzone'
    ] = instance;

    return instance;
  }
}

SubirDropzoneModule.boot();
