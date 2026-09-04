class SincronizarModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    const btn =
      document.getElementById(
        'btnSyncS3'
      );

    const host =
      document.getElementById(
        'syncStatus'
      );

    if (!btn) {
      return this;
    }

    function status(
      text,
      type = 'muted'
    ) {
      if (!host) return;

      host.className =
        'mb-2 small ' +
        (
          type === 'success'
            ? 'text-success'
            : type === 'danger'
              ? 'text-danger'
              : type === 'primary'
                ? 'text-primary'
                : 'text-muted'
        );

      host.textContent = text;
    }

    async function jsonFetch(
      url,
      options
    ) {
      const response =
        await fetch(
          url,
          options
        );

      const raw =
        await response.text();

      let data = null;

      try {
        data =
          raw
            ? JSON.parse(raw)
            : null;
      } catch (_) {}

      if (
        !response.ok ||
        !data ||
        data.ok !== true
      ) {
        throw new Error(
          (
            data &&
            (
              data.error ||
              data.message
            )
          ) ||
          raw ||
          `HTTP ${response.status}`
        );
      }

      return data;
    }

    function wait(ms) {
      return new Promise(
        resolve =>
          setTimeout(
            resolve,
            ms
          )
      );
    }

    async function refreshDrive() {
      if (
        typeof window
          .actualizarBloqueArchivos ===
        'function'
      ) {
        await window
          .actualizarBloqueArchivos({
            pagina: 1
          });
      }

      if (
        typeof window
          .actualizarBloqueCarpetas ===
        'function'
      ) {
        await window
          .actualizarBloqueCarpetas();
      }

      try {
        document.dispatchEvent(
          new Event(
            'drive:storage-changed'
          )
        );
      } catch (_) {}
    }

    async function triggerSync(
      event
    ) {
      if (event) {
        event.preventDefault();
      }

      if (btn.disabled) {
        return;
      }

      const oldHtml =
        btn.innerHTML;

      btn.disabled = true;

      btn.innerHTML =
        '<i class="fas fa-spinner fa-spin"></i> Sincronizando…';

      try {
        status(
          'Iniciando sincronización…',
          'primary'
        );

        const start =
          await jsonFetch(
            'sync_s3_to_db.php',
            {
              method: 'POST',
              credentials:
                'same-origin',
              cache:
                'no-store',
              headers: {
                'X-Requested-With':
                  'XMLHttpRequest'
              }
            }
          );

        const jobId =
          start.job_id;

        if (!jobId) {
          throw new Error(
            'El servidor no devolvió job_id.'
          );
        }

        while (true) {
          await wait(1000);

          const job =
            await jsonFetch(
              'sync_status.php?job_id=' +
              encodeURIComponent(jobId) +
              '&_=' +
              Date.now(),
              {
                credentials:
                  'same-origin',
                cache:
                  'no-store',
                headers: {
                  'X-Requested-With':
                    'XMLHttpRequest'
                }
              }
            );

          if (
            job.state ===
            'queued'
          ) {
            status(
              'Sincronización en cola…',
              'primary'
            );

            continue;
          }

          if (
            job.state ===
            'running'
          ) {
            status(
              `Lote ${job.batch || 0} · ` +
              `${job.files || 0} archivo(s) · ` +
              `${job.folders || 0} carpeta(s)`,
              'primary'
            );

            continue;
          }

          if (
            job.state ===
            'done'
          ) {
            status(
              `Sincronización completada · ` +
              `${job.files || 0} archivo(s) · ` +
              `${job.folders || 0} carpeta(s)`,
              'success'
            );

            await refreshDrive();

            break;
          }

          if (
            job.state ===
            'error'
          ) {
            throw new Error(
              job.message ||
              'El worker falló.'
            );
          }

          throw new Error(
            'Estado desconocido: ' +
            String(job.state)
          );
        }

      } catch (error) {
        console.error(error);

        status(
          'No se pudo sincronizar: ' +
          (
            error.message ||
            error
          ),
          'danger'
        );

      } finally {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
      }
    }

    btn.addEventListener(
      'click',
      triggerSync
    );

    window.triggerSyncS3 =
      triggerSync;

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
      new SincronizarModule(
        win,
        doc
      ).init();

    win.ArcadeCloudDrive.modules[
      'sincronizar'
    ] = instance;

    return instance;
  }
}

SincronizarModule.boot();
