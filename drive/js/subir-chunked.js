class SubirChunkedModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    document.addEventListener('DOMContentLoaded', function () {
      const API = window.UPLOAD_API || 'api/upload.php';
      const LEGACY_CHUNK_SIZE = 15 * 1024 * 1024;
      const MAX_RETRIES = 5;

      const input = document.getElementById('archivoGrande');
      const fileName = document.getElementById('fileNameGrande');
      const btnSubir = document.getElementById('btnSubirGrande');
      const btnCancelar = document.getElementById('btnCancelarGrande');
      const spinner = document.getElementById('spinnerGrande');
      const btnTxt = document.getElementById('btnTxtGrande');
      const result = document.getElementById('uploadResultGrande');
      const progressBar = document.getElementById('progressBarGrande');
      const progressInner = document.getElementById('progressBarGrandeInner');
      const card = document.getElementById('chunkedCard');

      let cancelRequested = false;
      let chunkSize = 32 * 1024 * 1024;

      // La carga multipart pesada viaja navegador -> S3 mediante URLs firmadas.
      // PHP sólo autoriza, firma las partes y registra el resultado final en MySQL.
      function chooseChunkSize(file) {
        const connection =
          navigator.connection ||
          navigator.mozConnection ||
          navigator.webkitConnection ||
          null;

        const type = String(connection?.effectiveType || '').toLowerCase();
        const downlink = Number(connection?.downlink || 0);
        let mb = 32;

        if (type === 'slow-2g' || type === '2g') {
          mb = 8;
        } else if (type === '3g' || (downlink > 0 && downlink < 5)) {
          mb = 16;
        } else if (downlink >= 50) {
          mb = 128;
        } else if (downlink >= 10 || type === '4g') {
          mb = 64;
        }

        if (file && file.size < 100 * 1024 * 1024) {
          mb = Math.min(mb, 16);
        }

        // S3 Multipart admite hasta 10,000 partes. Dejamos margen.
        if (file && file.size > 0) {
          const MB = 1024 * 1024;
          const minimumByParts = Math.ceil(file.size / 9900 / MB);
          mb = Math.max(mb, minimumByParts);
        }

        mb = Math.max(8, Math.min(256, mb));
        return mb * 1024 * 1024;
      }

      function installDirectUploadLabels() {
        if (card) {
          const heading = card.querySelector('h6');
          if (heading) {
            heading.innerHTML = '<i class="fas fa-layer-group"></i> Subida grande directa a S3';
          }

          const description = card.querySelector('.card-body.small');
          if (description) {
            description.textContent =
              'Recomendado para archivos grandes. Los datos viajan directamente del navegador a Amazon S3, con paquetes adaptativos, progreso, reintentos y reanudación.';
          }
        }

        if (btnTxt) btnTxt.textContent = 'Subir';
        setStatus('Selecciona un archivo y pulsa Subir. Los datos viajan directamente a S3.', 'muted');
      }

      // ---------- LocalStorage para reanudar ----------
      const LS_PREFIX = 's3v2_chunked_';

      function fileFingerprint(file) {
        return [file.name, String(file.size), String(file.lastModified || 0)].join('|');
      }

      function loadSession(file) {
        const k = LS_PREFIX + fileFingerprint(file);
        try {
          const raw = localStorage.getItem(k);
          return raw ? JSON.parse(raw) : null;
        } catch (e) {
          return null;
        }
      }

      function saveSession(file, sessionObj) {
        const k = LS_PREFIX + fileFingerprint(file);
        try {
          localStorage.setItem(k, JSON.stringify(sessionObj));
        } catch (e) {}
      }

      function clearSession(file) {
        const k = LS_PREFIX + fileFingerprint(file);
        try { localStorage.removeItem(k); } catch (e) {}
      }

      // ---------- UI ----------
      installDirectUploadLabels();

      if (input) {
        input.addEventListener('change', function () {
          const file = input.files && input.files[0] ? input.files[0] : null;
          if (!file) {
            if (fileName) fileName.textContent = '';
            setStatus('Selecciona un archivo y pulsa Subir. Los datos viajan directamente a S3.', 'muted');
            return;
          }

          if (fileName) {
            fileName.textContent = 'Archivo: ' + file.name + ' (' + formatFileSize(file.size) + ')';
          }

          const saved = loadSession(file);
          if (saved && saved.uploadId && saved.key) {
            const savedChunk = Number(saved.chunkSize) > 0
              ? Number(saved.chunkSize)
              : LEGACY_CHUNK_SIZE;
            setStatus(
              'Sesión previa detectada. Puedes reanudarla con paquetes de ' + formatFileSize(savedChunk) + '.',
              'warning'
            );
          } else {
            const recommended = chooseChunkSize(file);
            setStatus(
              'Listo para subida directa a S3 · paquetes adaptativos de ' + formatFileSize(recommended) + '.',
              'muted'
            );
          }
        });
      }

      if (btnSubir) {
        btnSubir.addEventListener('click', function () {
          subirChunked().catch(function (error) {
            console.error(error);
          });
        });
      }

      if (btnCancelar) {
        btnCancelar.addEventListener('click', function (event) {
          event.preventDefault();
          cancelRequested = true;
          setStatus('Cancelando… (puedes reanudar luego)', 'warning');
          btnCancelar.classList.add('disabled');
          btnCancelar.setAttribute('aria-disabled', 'true');
        });
      }

      // ---------- Main ----------
      async function subirChunked() {
        const file = input && input.files ? input.files[0] : null;
        if (!file) {
          setStatus('❌ Selecciona un archivo.', 'danger');
          return;
        }

        cancelRequested = false;
        toggleBusy(true);
        showProgress(true);
        setProgress(0);

        let uploadId = null;
        let key = null;
        let stateId = null;
        let rutaObjetivo = null;

        try {
          // 0) Si hay sesión guardada, conservar exactamente el tamaño de parte original.
          // Las sesiones antiguas no guardaban chunkSize y usaban 15 MB.
          const saved = loadSession(file);
          if (saved && saved.uploadId && saved.key && saved.stateId) {
            uploadId = saved.uploadId;
            key = saved.key;
            stateId = saved.stateId;
            chunkSize = Number(saved.chunkSize) > 0
              ? Number(saved.chunkSize)
              : LEGACY_CHUNK_SIZE;
            rutaObjetivo = saved.rutaObjetivo || (
              String(key).includes('/')
                ? String(key).slice(0, String(key).lastIndexOf('/') + 1)
                : ''
            );
            setStatus(
              'Reanudando subida directa a S3 · paquetes de ' + formatFileSize(chunkSize) + '…',
              'primary'
            );
          } else {
            // 1) INIT
            chunkSize = chooseChunkSize(file);
            setStatus(
              'Iniciando multipart directo a S3 · paquetes de ' + formatFileSize(chunkSize) + '…',
              'primary'
            );

            rutaObjetivo = window.DriveUploadDestination.capture();
            const initBody = new URLSearchParams();
            initBody.append('ruta_objetivo', rutaObjetivo);
            initBody.append('filename', file.name);
            initBody.append('filesize', String(file.size));
            initBody.append('mime', file.type || 'application/octet-stream');

            const initResp = await fetch(API + '?mode=chunked&action=init', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin',
              body: initBody.toString()
            });

            const initJson = await safeJson(initResp);
            if (!initResp.ok || !initJson || !initJson.uploadId || !initJson.key) {
              throw new Error(
                (initJson && initJson.error)
                  ? initJson.error
                  : ('init falló HTTP ' + initResp.status)
              );
            }

            uploadId = initJson.uploadId;
            key = initJson.key;
            stateId = initJson.stateId || '';

            saveSession(file, {
              stateId: stateId,
              uploadId: uploadId,
              key: key,
              filename: file.name,
              filesize: file.size,
              lastModified: file.lastModified || 0,
              chunkSize: chunkSize,
              rutaObjetivo: rutaObjetivo,
              createdAt: Date.now()
            });
          }

          // 2) RESUME: consultar partes ya subidas
          let existingEtags = {};
          try {
            const resumeBody = new URLSearchParams();
            resumeBody.append('step', 'resume');
            if (stateId) resumeBody.append('stateId', stateId);
            resumeBody.append('uploadId', uploadId);
            resumeBody.append('key', key);

            const resumeResp = await fetch(API + '?mode=chunked&action=part', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin',
              body: resumeBody.toString()
            });

            const resumeJson = await safeJson(resumeResp);
            if (resumeResp.ok && resumeJson && resumeJson.etags) {
              existingEtags = resumeJson.etags || {};
            }
          } catch (e) {
            // La consulta de reanudación no bloquea una sesión nueva.
          }

          // 3) Upload loop
          const totalParts = Math.ceil(file.size / chunkSize);
          const etags = {};

          for (const partKey in existingEtags) {
            if (Object.prototype.hasOwnProperty.call(existingEtags, partKey)) {
              etags[partKey] = existingEtags[partKey];
            }
          }

          let uploadedBytes = 0;
          for (const partStr in etags) {
            const part = parseInt(partStr, 10);
            if (!isFinite(part) || part <= 0) continue;
            const start = (part - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            uploadedBytes += (end - start);
          }
          setProgress(Math.floor((uploadedBytes / file.size) * 100));

          setStatus('Subiendo partes directamente a Amazon S3…', 'primary');

          for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
            if (cancelRequested) {
              throw new Error('Cancelado por el usuario.');
            }

            if (etags[String(partNumber)]) {
              setStatus(
                'Reanudado: parte ' + partNumber + '/' + totalParts + ' ya estaba subida.',
                'muted'
              );
              continue;
            }

            const start = (partNumber - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const chunk = file.slice(start, end);
            const contentLength = end - start;

            // 3a) PHP firma la parte; no recibe el cuerpo del archivo.
            const signBody = new URLSearchParams();
            signBody.append('step', 'sign');
            if (stateId) signBody.append('stateId', stateId);
            signBody.append('uploadId', uploadId);
            signBody.append('key', key);
            signBody.append('partNumber', String(partNumber));
            signBody.append('contentLength', String(contentLength));

            const signResp = await fetch(API + '?mode=chunked&action=part', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin',
              body: signBody.toString()
            });

            const signJson = await safeJson(signResp);
            if (!signResp.ok || !signJson || !signJson.url) {
              throw new Error(
                (signJson && signJson.error)
                  ? signJson.error
                  : ('sign falló HTTP ' + signResp.status)
              );
            }

            // 3b) El blob viaja directamente del navegador a la URL firmada de S3.
            setStatus(
              'Subiendo a S3: parte ' + partNumber + '/' + totalParts + '…',
              'primary'
            );

            const etag = await putChunkWithRetry(
              signJson.url,
              chunk,
              function (loaded) {
                const totalLoaded = uploadedBytes + loaded;
                const pct = Math.floor((totalLoaded / file.size) * 100);
                setProgress(pct);
              },
              MAX_RETRIES
            );

            etags[String(partNumber)] = etag;
            uploadedBytes += contentLength;
            setProgress(Math.floor((uploadedBytes / file.size) * 100));

            saveSession(file, {
              stateId: stateId,
              uploadId: uploadId,
              key: key,
              filename: file.name,
              filesize: file.size,
              lastModified: file.lastModified || 0,
              chunkSize: chunkSize,
              etags: etags,
              rutaObjetivo: rutaObjetivo,
              updatedAt: Date.now()
            });
          }

          // 4) COMPLETE: PHP completa multipart y registra FileS3 en MySQL.
          setStatus('Completando multipart y registrando el archivo…', 'primary');

          const completeBody = new URLSearchParams();
          if (stateId) completeBody.append('stateId', stateId);
          completeBody.append('uploadId', uploadId);
          completeBody.append('key', key);
          completeBody.append('etags', JSON.stringify(etags));

          const completeResp = await fetch(API + '?mode=chunked&action=complete', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: completeBody.toString()
          });

          const completeJson = await safeJson(completeResp);
          if (!completeResp.ok || !completeJson || completeJson.ok === false) {
            throw new Error(
              (completeJson && completeJson.error)
                ? completeJson.error
                : ('complete falló HTTP ' + completeResp.status)
            );
          }

          setProgress(100);
          setStatus(
            '✅ Archivo subido correctamente (' + formatFileSize(file.size) + ').',
            'success'
          );

          await window.DriveUploadDestination.afterSuccess(rutaObjetivo);

          clearSession(file);
          if (input) input.value = '';
          if (fileName) fileName.textContent = '';
          return;

        } catch (error) {
          const message = error && error.message ? error.message : String(error);
          setStatus('❌ Error: ' + message, 'danger');
          console.error(error);
          // Conservamos la sesión para reanudar después de una falla de red o cancelación.
        } finally {
          toggleBusy(false);
        }
      }

      // ---------- PUT XHR directo a S3 ----------
      function putChunkXHR(url, blob, onProgress) {
        return new Promise(function (resolve, reject) {
          const xhr = new XMLHttpRequest();
          xhr.open('PUT', url, true);

          xhr.upload.onprogress = function (evt) {
            if (evt.lengthComputable && typeof onProgress === 'function') {
              onProgress(evt.loaded);
            }
          };

          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
              let etag = xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag') || '';
              etag = String(etag).replace(/"/g, '').trim();
              if (!etag) {
                reject(new Error('No se recibió ETag. Revisa CORS del bucket (ExposeHeaders: ETag).'));
                return;
              }
              resolve(etag);
              return;
            }
            reject(new Error('PUT directo a S3 falló HTTP ' + xhr.status));
          };

          xhr.onerror = function () {
            reject(new Error('Error de red durante el PUT directo a S3'));
          };

          xhr.send(blob);
        });
      }

      function sleep(ms) {
        return new Promise(function (resolve) {
          setTimeout(resolve, ms);
        });
      }

      async function putChunkWithRetry(url, blob, onProgress, maxRetries) {
        let attempt = 0;
        let lastError = null;
        const retries = maxRetries || 5;

        while (attempt < retries) {
          if (cancelRequested) {
            throw new Error('Cancelado por el usuario.');
          }

          try {
            return await putChunkXHR(url, blob, onProgress);
          } catch (error) {
            lastError = error;
            attempt++;

            if (attempt >= retries) break;

            const wait = 800 * attempt;
            setStatus(
              'Fallo temporal de red. Reintentando (' + attempt + '/' + retries + ')…',
              'warning'
            );
            await sleep(wait);
          }
        }

        throw lastError || new Error('Falló PUT directo a S3 tras los reintentos');
      }

      // ---------- UI helpers ----------
      function toggleBusy(busy) {
        if (btnSubir) btnSubir.disabled = Boolean(busy);

        if (btnCancelar) {
          btnCancelar.classList.toggle('d-none', !busy);
          btnCancelar.classList.remove('disabled');
          btnCancelar.setAttribute('aria-disabled', 'false');
        }

        if (spinner) spinner.classList[busy ? 'remove' : 'add']('d-none');
        if (btnTxt) btnTxt.textContent = busy ? 'Subiendo…' : 'Subir';

        if (!busy) cancelRequested = false;
      }

      function showProgress(show) {
        if (!progressBar) return;
        progressBar.style.display = show ? 'block' : 'none';
      }

      function setProgress(pct) {
        const value = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
        if (progressInner) {
          progressInner.style.width = value + '%';
          progressInner.setAttribute('aria-valuenow', String(value));
        }
      }

      function setStatus(message, kind) {
        if (!result) return;
        const classes = {
          muted: 'text-muted small',
          primary: 'text-primary small',
          success: 'text-success small',
          danger: 'text-danger small',
          warning: 'text-warning small'
        };
        result.className = classes[kind] || classes.muted;
        result.textContent = message;
      }

      async function safeJson(resp) {
        try {
          return await resp.json();
        } catch (e) {
          return null;
        }
      }

      function formatFileSize(bytes) {
        const value = Number(bytes);
        if (!isFinite(value) || value <= 0) return '0 Bytes';

        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const index = Math.min(
          sizes.length - 1,
          Math.floor(Math.log(value) / Math.log(k))
        );
        const number = value / Math.pow(k, index);
        return (Math.round(number * 100) / 100) + ' ' + sizes[index];
      }
    });

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new SubirChunkedModule(win, doc).init();
    win.ArcadeCloudDrive.modules['subir-chunked'] = instance;
    return instance;
  }
}

SubirChunkedModule.boot();
