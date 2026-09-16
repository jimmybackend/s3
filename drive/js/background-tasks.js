class BackgroundTaskCenter {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.endpoint = 'background_tasks.php';
    this.pollMs = 5000;
    this.tasks = [];
    this.summary = { active: 0, queued: 0, running: 0, failed: 0, completed_recent: 0 };
    this.open = false;
    this.timer = null;
    this.lastStatuses = new Map();
  }

  init() {
    this.window.ARCADECLOUD_UNIFIED_TASK_CENTER = true;
    this.removeLegacyTaskCenter();
    this.ensureUi();
    this.refresh();
    this.timer = this.window.setInterval(() => this.refresh(), this.pollMs);

    [
      'drive:move-task-completed',
      'drive:move-task-failed',
      'drive:transcribe-completed',
      'drive:transcribe-failed',
      'drive:polly-task-completed',
      'drive:polly-task-failed',
      'drive:storage-changed'
    ].forEach((name) => {
      this.document.addEventListener(name, () => this.window.setTimeout(() => this.refresh(), 350));
    });

    return this;
  }

  removeLegacyTaskCenter() {
    ['transcribeTaskCenterButton', 'transcribeTaskCenterPanel', 'transcribeTaskCenterStyle'].forEach((id) => {
      const node = this.document.getElementById(id);
      if (node) node.remove();
    });
  }

  ensureUi() {
    if (!this.document.body) {
      this.document.addEventListener('DOMContentLoaded', () => this.ensureUi(), { once: true });
      return;
    }

    if (!this.document.getElementById('backgroundTaskCenterStyle')) {
      const style = this.document.createElement('style');
      style.id = 'backgroundTaskCenterStyle';
      style.textContent = `
        #backgroundTaskButton {
          position: fixed; right: 1rem; bottom: 1rem; z-index: 2075;
          border: 0; border-radius: 999px; padding: .72rem 1rem;
          background: #111827; color: #fff; font-weight: 700;
          box-shadow: 0 8px 28px rgba(0,0,0,.28);
        }
        #backgroundTaskButton .bg-task-count {
          display:inline-flex; align-items:center; justify-content:center;
          min-width:1.55rem; height:1.55rem; margin-left:.45rem;
          padding:0 .35rem; border-radius:999px; background:#0ea5e9; color:#fff;
          font-size:.82rem;
        }
        #backgroundTaskPanel {
          position: fixed; right: 1rem; bottom: 4.8rem; z-index: 2074;
          width: min(460px, calc(100vw - 2rem)); max-height: min(76vh, 720px);
          overflow: hidden; display:none; border-radius:.9rem;
          background:#111827; color:#f9fafb; box-shadow:0 16px 42px rgba(0,0,0,.42);
          border:1px solid rgba(255,255,255,.12);
        }
        #backgroundTaskPanel.bg-task-open { display:flex; flex-direction:column; }
        .bg-task-head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.9rem 1rem; border-bottom:1px solid rgba(255,255,255,.12); }
        .bg-task-head strong { font-size:1.05rem; }
        .bg-task-head small { display:block; color:#9ca3af; margin-top:.15rem; }
        .bg-task-head button { border:1px solid rgba(255,255,255,.2); background:transparent; color:#fff; border-radius:.45rem; padding:.3rem .55rem; }
        .bg-task-summary { display:flex; flex-wrap:wrap; gap:.4rem; padding:.65rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
        .bg-task-summary span { background:#1f2937; border-radius:999px; padding:.25rem .55rem; font-size:.75rem; color:#d1d5db; }
        .bg-task-list { overflow:auto; padding:.7rem; }
        .bg-task-empty { padding:1.2rem; text-align:center; color:#cbd5e1; }
        .bg-task-item { background:#1f2937; border:1px solid rgba(255,255,255,.08); border-radius:.7rem; padding:.8rem; margin-bottom:.65rem; }
        .bg-task-row { display:flex; justify-content:space-between; align-items:flex-start; gap:.7rem; }
        .bg-task-kind { color:#93c5fd; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.035em; }
        .bg-task-title { font-weight:700; overflow-wrap:anywhere; line-height:1.25; margin-top:.1rem; }
        .bg-task-service { color:#9ca3af; font-size:.75rem; margin-top:.15rem; }
        .bg-task-badge { flex:0 0 auto; border-radius:999px; padding:.22rem .55rem; font-size:.72rem; font-weight:700; }
        .bg-task-queued, .bg-task-pending { background:#334155; color:#e2e8f0; }
        .bg-task-running { background:#075985; color:#e0f2fe; }
        .bg-task-completed { background:#14532d; color:#dcfce7; }
        .bg-task-failed { background:#7f1d1d; color:#fee2e2; }
        .bg-task-detail { color:#d1d5db; font-size:.8rem; margin-top:.55rem; overflow-wrap:anywhere; }
        .bg-task-meta { display:flex; flex-wrap:wrap; gap:.35rem .8rem; color:#9ca3af; font-size:.75rem; margin-top:.5rem; }
        .bg-task-progress { overflow:hidden; height:.42rem; margin-top:.65rem; background:#374151; border-radius:999px; }
        .bg-task-progress > span { display:block; height:100%; border-radius:999px; }
        .bg-task-progress.indeterminate > span { width:42%; background:#0ea5e9; animation:bgTaskMove 1.35s ease-in-out infinite alternate; }
        .bg-task-progress.completed > span { width:100%; background:#22c55e; }
        .bg-task-progress.failed > span { width:100%; background:#ef4444; }
        .bg-task-progress.queued > span { width:16%; background:#64748b; }
        .bg-task-warning { padding:.55rem 1rem; color:#fcd34d; font-size:.74rem; border-top:1px solid rgba(255,255,255,.08); }
        @keyframes bgTaskMove { from { transform:translateX(-55%); } to { transform:translateX(145%); } }
        @media (max-width:575.98px) {
          #backgroundTaskButton { right:.65rem; bottom:.65rem; }
          #backgroundTaskPanel { right:.65rem; bottom:4.35rem; width:calc(100vw - 1.3rem); max-height:76vh; }
        }
      `;
      this.document.head.appendChild(style);
    }

    if (!this.document.getElementById('backgroundTaskButton')) {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.id = 'backgroundTaskButton';
      button.setAttribute('aria-controls', 'backgroundTaskPanel');
      button.setAttribute('aria-expanded', 'false');
      button.innerHTML = 'Tareas <span class="bg-task-count">0</span>';
      button.addEventListener('click', () => {
        this.open = !this.open;
        this.render();
        if (this.open) this.refresh();
      });
      this.document.body.appendChild(button);
    }

    if (!this.document.getElementById('backgroundTaskPanel')) {
      const panel = this.document.createElement('aside');
      panel.id = 'backgroundTaskPanel';
      panel.setAttribute('aria-label', 'Tareas en segundo plano');
      panel.innerHTML = `
        <div class="bg-task-head">
          <div>
            <strong>Tareas en segundo plano</strong>
            <small>Drive, nubes y servicios conectados</small>
          </div>
          <button type="button" data-bg-task-close aria-label="Cerrar">×</button>
        </div>
        <div class="bg-task-summary"></div>
        <div class="bg-task-list"></div>
        <div class="bg-task-warning" style="display:none"></div>
      `;
      panel.querySelector('[data-bg-task-close]').addEventListener('click', () => {
        this.open = false;
        this.render();
      });
      this.document.body.appendChild(panel);
    }
  }

  async refresh() {
    try {
      const url = new URL(this.endpoint, this.window.location.href);
      url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const text = await response.text();
      let json = null;
      try { json = JSON.parse(text); } catch (_) {}
      if (!response.ok || !json || json.ok !== true) {
        throw new Error((json && json.error) || text || `HTTP ${response.status}`);
      }

      const previous = new Map(this.tasks.map((task) => [String(task.id), String(task.status)]));
      this.tasks = Array.isArray(json.tasks) ? json.tasks : [];
      this.summary = json.summary || this.summary;
      this.sourceErrors = json.source_errors || {};
      this.render();
      this.notifyTransitions(previous);
    } catch (error) {
      console.warn('[background-tasks] no se pudo actualizar:', error);
      this.sourceErrors = { endpoint: error && error.message ? String(error.message) : 'Estado no disponible' };
      this.render();
    }
  }

  notifyTransitions(previous) {
    this.tasks.forEach((task) => {
      const id = String(task.id || '');
      const before = previous.get(id);
      const now = String(task.status || '');
      if (!before || before === now) return;
      if (now === 'completed') {
        this.notify(`Tarea terminada: ${task.title || task.category || 'Tarea'}.`, 'success', 6500);
      } else if (now === 'failed') {
        this.notify(`Tarea con error: ${task.title || task.category || 'Tarea'}.`, 'danger', 8500);
      }
    });
  }

  render() {
    this.ensureUi();
    const button = this.document.getElementById('backgroundTaskButton');
    const panel = this.document.getElementById('backgroundTaskPanel');
    if (!button || !panel) return;

    const active = Number(this.summary && this.summary.active || 0);
    const count = button.querySelector('.bg-task-count');
    if (count) count.textContent = String(active);
    button.setAttribute('aria-expanded', this.open ? 'true' : 'false');
    panel.classList.toggle('bg-task-open', this.open);

    const summary = panel.querySelector('.bg-task-summary');
    if (summary) {
      summary.innerHTML = [
        `Activas: ${active}`,
        `En cola: ${Number(this.summary.queued || 0)}`,
        `Procesando: ${Number(this.summary.running || 0)}`,
        `Terminadas: ${Number(this.summary.completed_recent || 0)}`,
        `Fallidas: ${Number(this.summary.failed || 0)}`
      ].map((text) => `<span>${this.escapeHtml(text)}</span>`).join('');
    }

    const list = panel.querySelector('.bg-task-list');
    if (list) {
      list.innerHTML = this.tasks.length
        ? this.tasks.map((task) => this.taskHtml(task)).join('')
        : '<div class="bg-task-empty">No hay tareas en segundo plano ni tareas recientes.</div>';
    }

    const warning = panel.querySelector('.bg-task-warning');
    const errors = this.sourceErrors && typeof this.sourceErrors === 'object'
      ? Object.entries(this.sourceErrors)
      : [];
    if (warning) {
      if (errors.length) {
        warning.style.display = 'block';
        warning.textContent = 'Alguna fuente de tareas no pudo consultarse; el resto del panel sigue disponible.';
      } else {
        warning.style.display = 'none';
        warning.textContent = '';
      }
    }
  }

  taskHtml(task) {
    const status = this.normalizeStatus(task.status);
    const label = this.statusLabel(status);
    const meta = task.metadata && typeof task.metadata === 'object' ? task.metadata : {};
    const details = [];

    const created = this.formatDate(task.created_at);
    const updated = this.formatDate(task.updated_at);
    if (created) details.push(`Inicio: ${created}`);
    if (updated && updated !== created) details.push(`Actualizado: ${updated}`);

    if (task.kind === 'sync') {
      if (Number(meta.batch || 0) > 0) details.push(`Lote: ${Number(meta.batch)}`);
      if (Number(meta.files || 0) > 0) details.push(`Archivos: ${Number(meta.files)}`);
      if (Number(meta.folders || 0) > 0) details.push(`Carpetas: ${Number(meta.folders)}`);
    }
    if (task.kind === 'move' && Number(meta.items || 0) > 0) details.push(`Elementos: ${Number(meta.items)}`);
    if (task.kind === 'polly') {
      if (meta.engine) details.push(`Motor: ${String(meta.engine)}`);
      if (Number(meta.characters || 0) > 0) details.push(`Caracteres: ${Number(meta.characters)}`);
    }
    if (task.kind === 'transcribe' && Number(meta.billable_seconds || 0) > 0) {
      details.push(`Facturable: ${Number(meta.billable_seconds)} s`);
    }

    if (task.estimated_cost !== null && task.estimated_cost !== undefined) {
      details.push(`Costo: ${this.money(task.estimated_cost, task.currency || 'USD')}`);
    } else if (['queued', 'running', 'pending'].includes(status) && ['polly', 'transcribe'].includes(String(task.kind))) {
      details.push('Costo: pendiente');
    }

    const progressClass = status === 'completed'
      ? 'completed'
      : (status === 'failed' ? 'failed' : (status === 'queued' ? 'queued' : 'indeterminate'));

    return `
      <article class="bg-task-item">
        <div class="bg-task-row">
          <div>
            <div class="bg-task-kind">${this.escapeHtml(task.category || task.kind || 'Tarea')}</div>
            <div class="bg-task-title">${this.escapeHtml(task.title || 'Tarea')}</div>
            <div class="bg-task-service">${this.escapeHtml(task.service || '')}${task.provider ? ' · ' + this.escapeHtml(task.provider) : ''}</div>
          </div>
          <span class="bg-task-badge bg-task-${status}">${this.escapeHtml(label)}</span>
        </div>
        <div class="bg-task-detail">${this.escapeHtml(task.detail || '')}</div>
        <div class="bg-task-meta">${details.map((item) => `<span>${this.escapeHtml(item)}</span>`).join('')}</div>
        <div class="bg-task-progress ${progressClass}"><span></span></div>
      </article>
    `;
  }

  normalizeStatus(value) {
    const status = String(value || '').toLowerCase();
    return ['queued', 'running', 'completed', 'failed', 'pending'].includes(status) ? status : 'pending';
  }

  statusLabel(status) {
    return {
      queued: 'EN COLA',
      running: 'PROCESANDO',
      completed: 'TERMINADA',
      failed: 'FALLÓ',
      pending: 'PENDIENTE'
    }[status] || 'PENDIENTE';
  }

  formatDate(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('T') ? '' : 'Z'));
    if (Number.isNaN(date.getTime())) return String(value);
    try {
      return date.toLocaleString([], { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    } catch (_) {
      return String(value);
    }
  }

  money(value, currency) {
    const amount = Number(value || 0);
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency', currency: String(currency || 'USD'), minimumFractionDigits: 2, maximumFractionDigits: 4
      }).format(amount);
    } catch (_) {
      return `${String(currency || 'USD')} ${amount.toFixed(4)}`;
    }
  }

  notify(message, type = 'info', timeout = 5000) {
    if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.notify === 'function') {
      this.window.DriveMoveTasks.notify(message, type, timeout);
    }
  }

  escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  static boot(win = window, doc = document) {
    if (win.BackgroundTaskCenter instanceof BackgroundTaskCenter) return win.BackgroundTaskCenter;
    const instance = new BackgroundTaskCenter(win, doc).init();
    win.BackgroundTaskCenter = instance;
    return instance;
  }
}

BackgroundTaskCenter.boot();
