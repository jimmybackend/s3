class SubirDropzoneModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    Dropzone.autoDiscover = false;

    const API =
      window.UPLOAD_API ||
      'api/upload.php';

    /*
     * Procesamos un archivo a la vez.
     * Preferimos estabilidad.
     */
    let uploadQueue =
      Promise.resolve();

    const drop =
      new Dropzone(
        '#dropzonePublico',
        {
          url:
            `${API}?mode=local_put&action=init`,

          autoProcessQueue: false,

          addRemoveLinks: true,

          createImageThumbnails: true,

          thumbnailWidth: 120,
          thumbnailHeight: 120,
          thumbnailMethod: 'contain',

          dictDefaultMessage:
            'Arrastra aquí o haz clic para subir',

          dictCancelUpload:
            'Cancelar',

          dictCancelUploadConfirmation:
            '¿Cancelar esta subida?',

          dictRemoveFile:
            'Quitar',

          dictResponseError:
            'Error del servidor: {{statusCode}}'
        }
      );


    function statusNode(file) {
      if (!file?.previewElement) {
        return null;
      }

      let node =
        file.previewElement
          .querySelector(
            '.drive-upload-status'
          );

      if (!node) {
        node =
          document.createElement(
            'div'
          );

        node.className =
          'drive-upload-status';

        node.style.marginTop =
          '6px';

        node.style.fontSize =
          '12px';

        node.style.fontWeight =
          '600';

        node.style.textAlign =
          'center';

        file.previewElement
          .appendChild(node);
      }

      return node;
    }


    function percentNode(file) {
      if (!file?.previewElement) {
        return null;
      }

      let node =
        file.previewElement
          .querySelector(
            '.drive-upload-percent'
          );

      if (!node) {
        node =
          document.createElement(
            'div'
          );

        node.className =
          'drive-upload-percent';

        node.style.fontSize =
          '12px';

        node.style.textAlign =
          'center';

        node.style.marginTop =
          '3px';

        node.textContent =
          '0%';

        file.previewElement
          .appendChild(node);
      }

      return node;
    }


    function setStatus(
      file,
      text
    ) {
      const node =
        statusNode(file);

      if (node) {
        node.textContent =
          text;
      }
    }


    async function apiRequest(
      file,
      action,
      values
    ) {
      const body =
        new URLSearchParams();

      Object.entries(
        values || {}
      ).forEach(
        ([key, value]) => {
          if (
            value !== undefined &&
            value !== null
          ) {
            body.set(
              key,
              String(value)
            );
          }
        }
      );

      const controller =
        new AbortController();

      file._driveApiController =
        controller;

      try {
        const response =
          await fetch(
            `${API}?mode=local_put&action=${action}`,
            {
              method: 'POST',

              credentials:
                'same-origin',

              cache:
                'no-store',

              headers: {
                'X-Requested-With':
                  'XMLHttpRequest',

                'Content-Type':
                  'application/x-www-form-urlencoded;charset=UTF-8'
              },

              body:
                body.toString(),

              signal:
                controller.signal
            }
          );

        const raw =
          await response.text();

        let json = null;

        try {
          json =
            raw
              ? JSON.parse(raw)
              : null;
        } catch (_) {}

        if (
          !response.ok ||
          !json ||
          json.ok !== true
        ) {
          throw new Error(
            (
              json &&
              (
                json.error ||
                json.message
              )
            ) ||
            raw ||
            `HTTP ${response.status}`
          );
        }

        return json;

      } finally {
        if (
          file._driveApiController ===
          controller
        ) {
          file._driveApiController =
            null;
        }
      }
    }


    function putToS3(
      file,
      url
    ) {
      return new Promise(
        (resolve, reject) => {
          const xhr =
            new XMLHttpRequest();

          file.xhr = xhr;

          xhr.open(
            'PUT',
            url,
            true
          );

          xhr.setRequestHeader(
            'Content-Type',
            file.type ||
            'application/octet-stream'
          );

          xhr.upload.onprogress =
            function(event) {
              if (
                !event.lengthComputable
              ) {
                return;
              }

              const percentage =
                (
                  event.loaded /
                  event.total
                ) * 100;

              drop.emit(
                'uploadprogress',
                file,
                percentage,
                event.loaded
              );
            };

          xhr.onload =
            function() {
              if (
                xhr.status >= 200 &&
                xhr.status < 300
              ) {
                resolve();
                return;
              }

              reject(
                new Error(
                  `S3 HTTP ${xhr.status}`
                )
              );
            };

          xhr.onerror =
            function() {
              reject(
                new Error(
                  'La conexión con S3 se interrumpió.'
                )
              );
            };

          xhr.onabort =
            function() {
              const error =
                new Error(
                  'Subida cancelada'
                );

              error.name =
                'AbortError';

              reject(error);
            };

          xhr.send(file);
        }
      );
    }


    async function cancelPending(
      file
    ) {
      if (
        file._driveCompleted ||
        file._driveCancelling ||
        !file._driveUploadToken
      ) {
        return;
      }

      file._driveCancelling =
        true;

      try {
        await apiRequest(
          file,
          'part',
          {
            cancel: 1,
            upload_token:
              file._driveUploadToken
          }
        );

        file._driveUploadToken =
          '';

      } catch (error) {
        if (
          error?.name !==
          'AbortError'
        ) {
          console.warn(
            '[Dropzone] No se pudo limpiar subida cancelada:',
            error
          );
        }

      } finally {
        file._driveCancelling =
          false;
      }
    }


    async function uploadOne(
      file
    ) {
      if (file._driveRemoved) {
        return;
      }

      try {
        const route =
          file._driveTargetRoute ||
          window
            .DriveUploadDestination
            .capture();

        file._driveTargetRoute =
          route;

        setStatus(
          file,
          'Preparando subida…'
        );

        /*
         * 1. PHP crea intención + URL firmada.
         */
        const init =
          await apiRequest(
            file,
            'init',
            {
              nombre:
                file.name,

              ruta_objetivo:
                route
            }
          );

        file._driveUploadToken =
          init.upload_token || '';

        file._driveS3Key =
          init.key || '';

        if (file._driveRemoved) {
          await cancelPending(
            file
          );

          return;
        }

        /*
         * Marcamos el archivo como uploading para que Dropzone
         * cambie Quitar -> Cancelar y conserve su UI normal.
         */
        file.status =
          Dropzone.UPLOADING;

        drop.emit(
          'processing',
          file
        );

        setStatus(
          file,
          'Subiendo directamente a S3…'
        );

        /*
         * 2. Archivo directo navegador -> S3.
         *
         * Reintentamos hasta 3 veces si la red falla.
         */
        let putError = null;

        for (
          let attempt = 1;
          attempt <= 3;
          attempt++
        ) {
          try {
            await putToS3(
              file,
              init.url
            );

            putError = null;

            break;

          } catch (error) {
            putError = error;

            if (
              error?.name ===
                'AbortError' ||
              file._driveRemoved ||
              file.status ===
                Dropzone.CANCELED
            ) {
              throw error;
            }

            if (attempt < 3) {
              setStatus(
                file,
                `Conexión interrumpida. Reintento ${attempt + 1}/3…`
              );

              await new Promise(
                resolve =>
                  setTimeout(
                    resolve,
                    attempt * 1500
                  )
              );
            }
          }
        }

        if (putError) {
          throw putError;
        }

        if (
          file._driveRemoved ||
          file.status ===
            Dropzone.CANCELED
        ) {
          await cancelPending(
            file
          );

          return;
        }

        drop.emit(
          'uploadprogress',
          file,
          100,
          file.size
        );

        setStatus(
          file,
          'Registrando en la base de datos…'
        );

        /*
         * 3. PHP confirma S3 y registra FileS3.
         * Este POST contiene solo unos cuantos bytes.
         */
        let completed = null;
        let completeError = null;

        /*
         * COMPLETE es idempotente en PHP.
         * Podemos repetirlo sin crear duplicados.
         */
        for (let attempt = 1; attempt <= 3; attempt++) {
          try {
            completed =
              await apiRequest(
                file,
                'complete',
                {
                  upload_token:
                    file._driveUploadToken,

                  tamano:
                    file.size || 0
                }
              );

            completeError = null;
            break;

          } catch (error) {
            completeError = error;

            if (attempt < 3) {
              setStatus(
                file,
                `Confirmando en BD. Reintento ${attempt + 1}/3…`
              );

              await new Promise(
                resolve =>
                  setTimeout(
                    resolve,
                    attempt * 1000
                  )
              );
            }
          }
        }

        if (!completed) {
          const error =
            completeError ||
            new Error(
              'No se pudo confirmar el registro en la base de datos.'
            );

          error.driveCompleteAmbiguous = true;
          throw error;
        }

        file._driveCompleted =
          true;

        file._driveUploadToken =
          '';

        file.status =
          Dropzone.SUCCESS;

        drop.emit(
          'success',
          file,
          completed
        );

        drop.emit(
          'complete',
          file
        );

        const percent =
          percentNode(file);

        if (percent) {
          percent.textContent =
            '100%';
        }

        setStatus(
          file,
          '✓ Subida completada'
        );

        try {
          await window
            .DriveUploadDestination
            .afterSuccess(route);

        } catch (error) {
          console.error(
            '[Dropzone] Error refrescando Drive:',
            error
          );
        }

      } catch (error) {
        const cancelled =
          error?.name ===
            'AbortError' ||
          file._driveRemoved ||
          file.status ===
            Dropzone.CANCELED;

        if (cancelled) {
          await cancelPending(
            file
          );

          setStatus(
            file,
            'Subida cancelada'
          );

          return;
        }

        /*
         * Si PUT falló antes de complete sí limpiamos.
         *
         * Pero si complete es ambiguo NO borramos S3:
         * MySQL podría haber registrado correctamente y haberse
         * perdido únicamente la respuesta HTTP.
         */
        if (!error?.driveCompleteAmbiguous) {
          await cancelPending(
            file
          );
        }

        file.status =
          Dropzone.ERROR;

        const message =
          error?.message ||
          String(error);

        drop.emit(
          'error',
          file,
          message
        );

        drop.emit(
          'complete',
          file
        );

        setStatus(
          file,
          error?.driveCompleteAmbiguous
            ? '⚠ Archivo recibido por S3; no se pudo confirmar la respuesta de BD. Usa Sincronizar antes de volver a subirlo.'
            : '✗ Error: ' + message
        );

        console.error(
          '❌ Error al subir:',
          error
        );
      }
    }


    drop.on(
      'addedfile',
      function(file) {
        try {
          file._driveTargetRoute =
            window
              .DriveUploadDestination
              .capture();

          percentNode(file);

          setStatus(
            file,
            'En cola…'
          );

          /*
           * Un archivo por vez.
           */
          uploadQueue =
            uploadQueue
              .then(
                () =>
                  uploadOne(file)
              )
              .catch(
                error =>
                  console.error(
                    error
                  )
              );

        } catch (error) {
          setStatus(
            file,
            'Error: ' +
            (
              error?.message ||
              error
            )
          );

          drop.removeFile(
            file
          );
        }
      }
    );


    drop.on(
      'uploadprogress',
      function(
        file,
        progress
      ) {
        const value =
          Math.max(
            0,
            Math.min(
              100,
              Number(progress) || 0
            )
          );

        const node =
          percentNode(file);

        if (node) {
          node.textContent =
            `${value.toFixed(1)}%`;
        }
      }
    );


    drop.on(
      'canceled',
      function(file) {
        setStatus(
          file,
          'Subida cancelada'
        );

        cancelPending(file);
      }
    );


    drop.on(
      'removedfile',
      function(file) {
        file._driveRemoved =
          true;

        try {
          file._driveApiController
            ?.abort();
        } catch (_) {}

        if (
          file.xhr &&
          file.xhr.readyState !== 4
        ) {
          try {
            file.xhr.abort();
          } catch (_) {}
        }

        cancelPending(file);
      }
    );


    if (
      typeof window.API ===
      'undefined'
    ) {
      window.API = API;
    }

    window.drop =
      drop;

    return this;
  }

  static boot(
    win = window,
    doc = document
  ) {
    win.ArcadeCloudDrive =
      win.ArcadeCloudDrive || {
        modules: {}
      };

    win.ArcadeCloudDrive.modules =
      win.ArcadeCloudDrive.modules ||
      {};

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
