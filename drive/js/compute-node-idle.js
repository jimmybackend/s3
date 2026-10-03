class ArcadeCloudComputeIdleGuard {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.endpoint = 'media_processing.php';
    this.csrf = String(win.DRIVE_UPLOAD_CSRF || '');
    this.lastTouchSentAt = 0;
    this.touchThrottleMs = 20000;
    this.pollMs = 5000;
    this.pollTimer = null;
    this.countdownTimer = null;
    this.warningBox = null;
    this.statusBadge = null;
    this.warningSeconds = 30;
    this.currentRemaining = null;
    this.busy = false;
  }

  init() {
    if (!this.csrf) return this;
    this.ensureWarningUi();
    this.ensureStatusUi();
    this.bindActivity();
    if (this.document.visibilityState === 'visible') {
      this.touch(true);
    }
    this.poll();
    this.pollTimer = this.window.setInterval(() => this.poll(), this.pollMs);
    return this;
  }

  bindActivity() {
    const touch = () => {
      if (this.document.visibilityState !== 'visible') return;
      this.touch(false);
    };

    ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'wheel'].forEach((name) => {
      this.document.addEventListener(name, touch, { passive: true, capture: true });
    });

    this.document.addEventListener('visibilitychange', () => {
      if (this.document.visibilityState === 'visible') this.touch(true);
    });

    this.document.addEventListener('arcadecloud:compute-activity', () => this.touch(true));
  }

  async touch(force) {
    const now = Date.now();
    if (!force && now - this.lastTouchSentAt < this.touchThrottleMs) return;
    this.lastTouchSentAt = now;

    try {
      const body = new URLSearchParams({ node_activity: '1' });
      const response = await this.window.fetch(this.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Drive-CSRF': this.csrf,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });
      const data = await response.json();
      if (response.ok && data?.ok === true) {
        this.applyStatus(data.idle || {});
      }
    } catch (_) {
      // El guard nunca debe bloquear el uso normal del Drive por un fallo de red.
    }
  }

  async poll() {
    if (this.busy) return;
    try {
      const url = new URL(this.endpoint, this.window.location.href);
      url.searchParams.set('idle_status', '1');
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) return;
      this.applyStatus(data.idle || {});
    } catch (_) {}
  }

  applyStatus(status) {
    const state = String(status?.state || '');
    const blockers = Array.isArray(status?.blockers) ? status.blockers.map(String) : [];
    const elapsed = Math.max(0, Number(status?.idle_elapsed_seconds || 0));
    const grace = Math.max(0, Number(status?.idle_grace_seconds || 0));
    const sessionActive = status?.session_active === true;

    this.renderStatus(state, sessionActive, elapsed, grace, blockers);

    const warning = status?.warning === true && state === 'running';
    if (!warning) {
      this.hideWarning();
      return;
    }

    const remaining = Math.max(0, Number(status.shutdown_in_seconds ?? this.warningSeconds));
    this.showWarning(remaining);
  }

  ensureStatusUi() {
    if (this.statusBadge) return this.statusBadge;
    const badge = this.document.createElement('div');
    badge.id = 'computeIdleStatus';
    badge.className = 'compute-idle-status';
    badge.hidden = true;
    badge.setAttribute('aria-live', 'polite');
    this.document.body.appendChild(badge);
    this.statusBadge = badge;
    return badge;
  }

  renderStatus(state, sessionActive, elapsed, grace, blockers) {
    const badge = this.ensureStatusUi();
    if (state !== 'running' || !sessionActive) {
      badge.hidden = true;
      return;
    }
    badge.hidden = false;
    badge.classList.toggle('is-paused', blockers.length > 0);
    if (blockers.length > 0) {
      const labels = blockers.map((item) => item === 'office_activo'
        ? 'Office activo'
        : (item === 'multimedia_activa' ? 'multimedia activa' : item));
      badge.textContent = 'Autoapagado pausado: ' + labels.join(', ');
      return;
    }
    if (elapsed <= 0) {
      badge.textContent = 'Autoapagado listo · esperando inactividad';
      return;
    }
    badge.textContent = 'Inactivo ' + this.formatDuration(elapsed)
      + ' / ' + this.formatDuration(grace);
  }

  formatDuration(seconds) {
    const value = Math.max(0, Math.floor(Number(seconds || 0)));
    const minutes = Math.floor(value / 60);
    const rest = value % 60;
    return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
  }

  ensureWarningUi() {
    if (this.warningBox) return this.warningBox;

    const box = this.document.createElement('section');
    box.id = 'computeIdleWarning';
    box.className = 'compute-idle-warning';
    box.hidden = true;
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'computeIdleWarningTitle');
    box.innerHTML = `
      <div class="compute-idle-backdrop"></div>
      <div class="compute-idle-card">
        <i class="fas fa-power-off compute-idle-icon"></i>
        <h3 id="computeIdleWarningTitle">Nodo de alto rendimiento inactivo</h3>
        <p>Han pasado 20 minutos sin actividad. Para ahorrar recursos, la EC2 grande se apagará en:</p>
        <strong class="compute-idle-count" data-compute-idle-count>30</strong>
        <span class="compute-idle-seconds">segundos</span>
        <div class="compute-idle-actions">
          <button type="button" data-compute-idle-continue>Seguir usando</button>
          <button type="button" class="is-danger" data-compute-idle-stop>Apagar ahora</button>
        </div>
        <small>Una tarea multimedia activa impide el apagado automático.</small>
      </div>
    `;

    box.querySelector('[data-compute-idle-continue]')?.addEventListener('click', async () => {
      await this.touch(true);
      this.hideWarning();
    });

    box.querySelector('[data-compute-idle-stop]')?.addEventListener('click', () => this.stopNow());

    this.document.body.appendChild(box);
    this.warningBox = box;
    return box;
  }

  showWarning(seconds) {
    const box = this.ensureWarningUi();
    const next = Math.max(0, Math.ceil(Number(seconds || 0)));
    this.currentRemaining = next;
    box.hidden = false;
    this.renderCount();

    if (this.countdownTimer) this.window.clearInterval(this.countdownTimer);
    this.countdownTimer = this.window.setInterval(() => {
      if (this.currentRemaining === null) return;
      this.currentRemaining = Math.max(0, this.currentRemaining - 1);
      this.renderCount();
      if (this.currentRemaining <= 0) {
        this.window.clearInterval(this.countdownTimer);
        this.countdownTimer = null;
        this.window.setTimeout(() => this.poll(), 700);
      }
    }, 1000);
  }

  renderCount() {
    const node = this.warningBox?.querySelector('[data-compute-idle-count]');
    if (node) node.textContent = String(Math.max(0, this.currentRemaining ?? 0));
  }

  hideWarning() {
    if (this.countdownTimer) {
      this.window.clearInterval(this.countdownTimer);
      this.countdownTimer = null;
    }
    this.currentRemaining = null;
    if (this.warningBox) this.warningBox.hidden = true;
  }

  async stopNow() {
    if (this.busy) return;
    this.busy = true;
    const button = this.warningBox?.querySelector('[data-compute-idle-stop]');
    if (button) {
      button.disabled = true;
      button.textContent = 'Apagando…';
    }

    try {
      const body = new URLSearchParams({ node_shutdown_now: '1' });
      const response = await this.window.fetch(this.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Drive-CSRF': this.csrf,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || data?.ok !== true) {
        throw new Error(data?.error || 'No se pudo solicitar el apagado.');
      }
      this.hideWarning();
      this.notify('Se solicitó apagar la EC2 de alto rendimiento.', 'success');
    } catch (error) {
      this.notify(error?.message || 'No se pudo apagar el nodo.', 'warning');
      await this.poll();
    } finally {
      this.busy = false;
      if (button) {
        button.disabled = false;
        button.textContent = 'Apagar ahora';
      }
    }
  }

  notify(message, type) {
    if (this.window.ArcadeCloudOsShell?.notify) {
      this.window.ArcadeCloudOsShell.notify(message, type || 'info');
      return;
    }
    if (this.window.DriveMoveTasks?.notify) {
      this.window.DriveMoveTasks.notify(message, type || 'info', 7000);
      return;
    }
    console.log('[ArcadeCloud compute]', message);
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudComputeIdleGuard instanceof ArcadeCloudComputeIdleGuard) {
      return win.ArcadeCloudComputeIdleGuard;
    }
    const guard = new ArcadeCloudComputeIdleGuard(win, doc).init();
    win.ArcadeCloudComputeIdleGuard = guard;
    return guard;
  }
}

ArcadeCloudComputeIdleGuard.boot();
