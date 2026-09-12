class SincronizarModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    const btn = document.getElementById('btnSyncS3');
    const host = document.getElementById('syncStatus');

    if (!btn) {
      return this;
    }

    let busy = false;

    function status(text, type = 'muted') {
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

    async function jsonFetch(url, options) {
      const response = await fetch(url, options);
      const raw = await response.text();
      let data = null;

      try {
        data = raw ? JSON.parse(raw) : null;
      } catch (_) {}

      if (!response.ok || !data || data.ok !== true) {
        throw new Error(
          (
            data &&
            (data.error || data.message)
          ) ||
          raw ||
          `HTTP ${response.status}`
        );
      }

      return data;
    }

    function wait(ms) {
      return new Promise(resolve => setTimeout(resolve, ms));
    }

    function setControlsDisabled(disabled) {
      btn.disabled = disabled;
      document
        .querySelectorAll('.js-sync-folder')
        .forEach((button) => {
          button.disabled = disabled;
        });
    }

    async function refreshDrive() {
      if (typeof window.actualizarBloqueArchivos === 'function') {
        await window.actualizarBloqueArchivos({ pagina: 1 });
      }

      if (typeof window.actualizarBloqueCarpetas === 'function') {
        await window.actualizarBloqueCarpetas();
      }

      try {
        document.dispatchEvent(new Event('drive:storage-changed'));
      } catch (_) {}
    }

    async function runSync(prefix = '', label = '', triggerButton = btn) {
      if (busy) {
        status('Ya existe una sincronización en curso para este usuario.', 'primary');
        return;
      }

      busy = true;
      setControlsDisabled(true);

      const oldHtml = triggerButton ? triggerButton.innerHTML : '';
      if (triggerButton) {
        triggerButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      }

      const scopeLabel = label ? ` · ${label}` : '';

      try {
        status(
          prefix
            ? `Iniciando sincronización de carpeta${scopeLabel}…`
            : 'Iniciando sincronización del usuario…',
          'primary'
        );

        const form = new FormData();
        if (prefix) {
          form.append('prefix', prefix);
        }

        const start = await jsonFetch(
          'sync_s3_to_db.php',
          {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          }
        );

        const jobId = start.job_id;

        if (!jobId) {
          throw new Error('El servidor no devolvió job_id.');
        }

        const scoped = start.scope === 'folder';
        const effectiveLabel = scoped
          ? (label || String(start.scope_prefix || 'carpeta'))
          : '';

        while (true) {
          await wait(1000);

          const job = await jsonFetch(
            'sync_status.php?job_id=' +
              encodeURIComponent(jobId) +
              '&_=' +
              Date.now(),
            {
              credentials: 'same-origin',
              cache: 'no-store',
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              }
            }
          );

          if (job.state === 'queued') {
            status(
              scoped
                ? `Carpeta ${effectiveLabel} · en cola…`
                : 'Sincronización en cola…',
              'primary'
            );
            continue;
          }

          if (job.state === 'running') {
            status(
              (scoped ? `Carpeta ${effectiveLabel} · ` : '') +
              `Lote ${job.batch || 0} · ` +
              `${job.files || 0} archivo(s) · ` +
              `${job.folders || 0} carpeta(s)`,
              'primary'
            );
            continue;
          }

          if (job.state === 'done') {
            status(
              (scoped ? `Carpeta ${effectiveLabel} · ` : '') +
              `Sincronización completada · ` +
              `${job.files || 0} archivo(s) · ` +
              `${job.folders || 0} carpeta(s)`,
              'success'
            );

            await refreshDrive();
            break;
          }

          if (job.state === 'error') {
            throw new Error(job.message || 'El worker falló.');
          }

          throw new Error('Estado desconocido: ' + String(job.state));
        }

      } catch (error) {
        console.error(error);

        status(
          'No se pudo sincronizar: ' +
          (error.message || error),
          'danger'
        );

      } finally {
        busy = false;
        setControlsDisabled(false);

        if (triggerButton && triggerButton.isConnected) {
          triggerButton.innerHTML = oldHtml;
        }
      }
    }

    btn.addEventListener('click', (event) => {
      event.preventDefault();
      runSync('', '', btn);
    });

    document.addEventListener('click', (event) => {
      const folderButton = event.target?.closest?.('.js-sync-folder');
      if (!folderButton) return;

      event.preventDefault();
      event.stopPropagation();

      runSync(
        String(folderButton.dataset.syncPrefix || ''),
        String(folderButton.dataset.syncName || ''),
        folderButton
      );
    });

    window.triggerSyncS3 = function triggerSyncS3(event) {
      if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
      }
      return runSync('', '', btn);
    };

    window.triggerSyncFolderS3 = function triggerSyncFolderS3(prefix, label) {
      return runSync(String(prefix || ''), String(label || ''), null);
    };

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};

    const instance = new SincronizarModule(win, doc).init();
    win.ArcadeCloudDrive.modules.sincronizar = instance;

    return instance;
  }
}

SincronizarModule.boot();
