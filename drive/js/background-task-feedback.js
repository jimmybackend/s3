(function installBackgroundTaskFeedback(win, doc) {
  let attempts = 0;
  let pollyGuardAttempts = 0;

  function ensureStyle() {
    if (doc.getElementById('backgroundTaskFeedbackStyle')) return;
    const style = doc.createElement('style');
    style.id = 'backgroundTaskFeedbackStyle';
    style.textContent = `
      #backgroundTaskPanel .bg-task-action-feedback {
        display:none;
        padding:.62rem .8rem;
        border-bottom:1px solid var(--border-soft, rgba(0,0,0,.08));
        font-size:.78rem;
        line-height:1.4;
        font-weight:700;
        overflow-wrap:anywhere;
        background:var(--panel-bg2, transparent) !important;
        color:var(--text, #222) !important;
      }
      #backgroundTaskPanel .bg-task-action-feedback.is-success {
        display:block;
        color:#15803d !important;
        background:rgba(34,197,94,.10) !important;
      }
      #backgroundTaskPanel .bg-task-action-feedback.is-info {
        display:block;
        color:var(--accent, #0284c7) !important;
        background:rgba(var(--accent-rgb, 14,165,233), .10) !important;
      }
      #backgroundTaskPanel .bg-task-action-feedback.is-warning {
        display:block;
        color:#b45309 !important;
        background:rgba(245,158,11,.10) !important;
      }
      #backgroundTaskPanel .bg-task-action-feedback.is-danger {
        display:block;
        color:#b91c1c !important;
        background:rgba(239,68,68,.10) !important;
      }
    `;
    doc.head.appendChild(style);
  }

  function ensureBanner(panel) {
    let banner = panel.querySelector('.bg-task-action-feedback');
    if (banner) return banner;

    banner = doc.createElement('div');
    banner.className = 'bg-task-action-feedback';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');

    const head = panel.querySelector('.bg-task-head');
    if (head) {
      head.insertAdjacentElement('afterend', banner);
    } else {
      panel.prepend(banner);
    }
    return banner;
  }

  function apply() {
    attempts += 1;
    const center = win.BackgroundTaskCenter;
    const panel = doc.getElementById('backgroundTaskPanel');

    if (!center || !panel) {
      if (attempts < 40) win.setTimeout(apply, 100);
      return;
    }
    if (center.__actionFeedbackInstalled) return;
    center.__actionFeedbackInstalled = true;

    ensureStyle();
    const banner = ensureBanner(panel);
    let clearTimer = null;

    center.notify = function notifyInsideTaskPanel(message, type = 'info', timeout = 5500) {
      const safeType = ['success', 'info', 'warning', 'danger'].includes(String(type))
        ? String(type)
        : 'info';

      banner.className = `bg-task-action-feedback is-${safeType}`;
      banner.textContent = String(message || '');
      banner.style.display = 'block';

      if (clearTimer) {
        win.clearTimeout(clearTimer);
        clearTimer = null;
      }
      if (timeout > 0) {
        clearTimer = win.setTimeout(() => {
          if (banner.textContent === String(message || '')) {
            banner.style.display = 'none';
            banner.textContent = '';
            banner.className = 'bg-task-action-feedback';
          }
        }, timeout);
      }
    };

    const originalPerformAction = center.performAction.bind(center);
    center.performAction = async function performActionAndRefresh(button) {
      await originalPerformAction(button);

      // El POST ya hace un refresh inmediato. Estos dos repasos capturan los
      // cambios que dependen de un worker/reconciliador y actualizan botones y
      // estado sin esperar al poll de 5 segundos.
      win.setTimeout(() => this.refresh(), 650);
      win.setTimeout(() => this.refresh(), 1600);
    };
  }

  /**
   * El Drive todavía carga polly.js para otras herramientas AWS. Ese módulo
   * heredado conserva un handler de "Generar audio" que espera audioBase64.
   * El módulo nuevo polly-background.js usa el mismo botón para crear un job
   * asíncrono. Dependiendo del orden de DOMContentLoaded, ambos handlers podían
   * quedar activos y mandar dos solicitudes a Polly; el heredado además
   * mostraba el falso error "El servidor no devolvió audio válido".
   *
   * Este listener en fase capture se vuelve el único dueño del submit Polly.
   * Detiene handlers heredados y protege también contra doble toque en móvil.
   */
  function installPollySingleSubmitGuard() {
    pollyGuardAttempts += 1;
    const button = doc.getElementById('btnPollyGenerar');
    const module = win.PollyBackground;

    if (!button || !module || typeof module.generate !== 'function') {
      if (pollyGuardAttempts < 100) win.setTimeout(installPollySingleSubmitGuard, 100);
      return;
    }
    if (button.__arcadecloudPollySingleSubmitGuard) return;
    button.__arcadecloudPollySingleSubmitGuard = true;

    // Retira los handlers jQuery conocidos. El listener capture que instalamos
    // abajo seguirá protegiendo aunque polly.js vuelva a enlazarse después.
    if (win.jQuery) {
      win.jQuery(button)
        .off('click.polly')
        .off('click.pollyBackground');
    }

    let busy = false;
    button.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      if (busy) return;
      busy = true;
      try {
        await module.generate();
      } finally {
        busy = false;
      }
    }, true);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', apply, { once: true });
    doc.addEventListener('DOMContentLoaded', installPollySingleSubmitGuard, { once: true });
  } else {
    apply();
    installPollySingleSubmitGuard();
  }
})(window, document);
