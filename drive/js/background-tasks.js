class BackgroundTaskCenter {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.endpoint = 'background_tasks.php';
    this.pollMs = 5000;
    this.tasks = [];
    this.serverTasks = [];
    this.serverSummary = null;
    this.summary = {
      active: 0,
      queued: 0,
      running: 0,
      stopping: 0,
      failed: 0,
      completed_recent: 0,
      cancelled_recent: 0
    };
    this.sourceErrors = {};
    this.open = false;
    this.timer = null;
    this.filter = 'all';
    this.busy = new Set();
    this.selected = new Set();
    this.bulkBusy = false;
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
      'drive:storage-changed',
      'background-tasks:refresh'
    ].forEach((name) => {
      this.document.addEventListener(name, () => this.window.setTimeout(() => this.refresh(), 350));
    });

    this.document.addEventListener('drive:client-upload-task', () => {
      const previous = new Map(this.tasks.map((task) => [String(task.id), String(task.status)]));
      this.mergeClientTasks();
      this.render();
      this.notifyTransitions(previous);
    });

    return this;
  }

  removeLegacyTaskCenter() {
    [
      'transcribeTaskCenterButton',
      'transcribeTaskCenterPanel',
      'transcribeTaskCenterStyle'
    ].forEach((id) => {
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
        #backgroundTaskPanel {
          position:fixed; right:1rem; bottom:3.5rem; z-index:2074;
          width:min(500px, calc(100vw - 2rem));
          max-height:min(80vh, 760px);
          overflow:hidden; display:none;
          border-radius:16px;
          background:var(--panel-solid, #fff) !important;
          color:var(--text, #222) !important;
          border:1px solid rgba(var(--accent-rgb, 14,165,233), .28) !important;
          box-shadow:0 18px 55px rgba(0,0,0,.34), 0 0 22px rgba(var(--accent-rgb, 14,165,233), .10) !important;
        }
        #backgroundTaskPanel.bg-task-open { display:flex; flex-direction:column; }
        #backgroundTaskPanel * { box-sizing:border-box; }
        .bg-task-head {
          display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem;
          padding:.9rem 1rem;
          background:linear-gradient(180deg, var(--panel-bg, transparent), var(--panel-bg2, transparent)) !important;
          border-bottom:1px solid var(--border, rgba(0,0,0,.12));
        }
        .bg-task-head strong {
          display:block; color:var(--text-strong, #111) !important;
          font-size:1.05rem; line-height:1.25;
        }
        .bg-task-head small {
          display:block; color:var(--text-soft, #666) !important;
          margin-top:.18rem; line-height:1.35;
        }
        .bg-task-close {
          flex:0 0 auto; min-width:40px; min-height:40px;
          border-radius:10px !important;
          background:var(--panel-bg2, transparent) !important;
          color:var(--text, #222) !important;
          border:1px solid var(--border, rgba(0,0,0,.15)) !important;
        }
        .bg-task-toolbar {
          display:flex; gap:.45rem; align-items:center; padding:.62rem .75rem;
          border-bottom:1px solid var(--border-soft, rgba(0,0,0,.08));
          background:var(--panel-bg2, transparent) !important;
        }
        .bg-task-filter {
          width:100%; min-height:40px; padding:.35rem .55rem;
          border-radius:9px;
          background:var(--bg3, #fff) !important;
          color:var(--text, #222) !important;
          border:1px solid var(--border, rgba(0,0,0,.15)) !important;
        }
        .bg-task-refresh {
          flex:0 0 auto; min-width:42px; min-height:40px;
          border-radius:9px !important;
          background:var(--bg3, #fff) !important;
          color:var(--text, #222) !important;
          border:1px solid var(--border, rgba(0,0,0,.15)) !important;
        }
        .bg-task-bulk {
          display:flex; flex-wrap:wrap; gap:.45rem; align-items:center; padding:.55rem .75rem;
          border-bottom:1px solid var(--border-soft, rgba(0,0,0,.08));
          background:var(--panel-bg, transparent) !important;
        }
        .bg-task-select-all {
          display:inline-flex; align-items:center; gap:.4rem; margin:0;
          color:var(--text-soft, #666) !important; font-size:.76rem; font-weight:700;
        }
        .bg-task-select-all input, .bg-task-item-select input { width:16px; height:16px; }
        .bg-task-bulk-action {
          min-height:34px; padding:.3rem .58rem; border-radius:8px !important;
          border:1px solid var(--border, rgba(0,0,0,.15)) !important;
          background:var(--bg3, #fff) !important; color:var(--text, #222) !important;
          font-size:.75rem; font-weight:700;
        }
        .bg-task-bulk-action.danger { border-color:rgba(220,38,38,.42) !important; color:#b91c1c !important; }
        .bg-task-bulk-action:disabled { opacity:.48; cursor:not-allowed; }
        .bg-task-summary {
          display:flex; flex-wrap:wrap; gap:.4rem; padding:.62rem .75rem;
          border-bottom:1px solid var(--border-soft, rgba(0,0,0,.08));
        }
        .bg-task-summary span {
          display:inline-flex; align-items:center;
          border-radius:999px; padding:.28rem .55rem;
          background:var(--panel-bg2, rgba(0,0,0,.04)) !important;
          color:var(--text-soft, #555) !important;
          border:1px solid var(--border-soft, rgba(0,0,0,.08));
          font-size:.75rem;
        }
        .bg-task-list { overflow:auto; padding:.7rem; overscroll-behavior:contain; }
        .bg-task-empty { padding:1.2rem; text-align:center; color:var(--text-soft, #666) !important; }
        .bg-task-item {
          background:linear-gradient(180deg, var(--panel-bg, transparent), var(--panel-bg2, transparent)) !important;
          color:var(--text, #222) !important;
          border:1px solid var(--border, rgba(0,0,0,.12));
          border-radius:12px; padding:.82rem; margin-bottom:.68rem;
          box-shadow:0 6px 18px rgba(0,0,0,.08);
        }
        .bg-task-item:last-child { margin-bottom:0; }
        .bg-task-row { display:flex; justify-content:space-between; align-items:flex-start; gap:.7rem; }
        .bg-task-title-wrap { display:flex; align-items:flex-start; gap:.55rem; min-width:0; }
        .bg-task-item-select { flex:0 0 auto; display:flex; align-items:center; padding-top:.15rem; }
        .bg-task-kind {
          color:var(--accent, #0ea5e9) !important;
          font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.035em;
        }
        .bg-task-title {
          color:var(--text-strong, #111) !important;
          font-weight:700; overflow-wrap:anywhere; line-height:1.28; margin-top:.12rem;
        }
        .bg-task-service { color:var(--text-soft, #666) !important; font-size:.75rem; margin-top:.18rem; }
        .bg-task-badge {
          flex:0 0 auto; border-radius:999px; padding:.24rem .55rem;
          font-size:.7rem; font-weight:800; border:1px solid currentColor;
        }
        .bg-task-queued, .bg-task-pending { background:rgba(100,116,139,.15); color:#64748b !important; }
        .bg-task-running { background:rgba(14,165,233,.13); color:#0284c7 !important; }
        .bg-task-stopping { background:rgba(245,158,11,.14); color:#b45309 !important; }
        .bg-task-completed { background:rgba(34,197,94,.14); color:#15803d !important; }
        .bg-task-failed { background:rgba(239,68,68,.13); color:#b91c1c !important; }
        .bg-task-cancelled { background:rgba(107,114,128,.14); color:#6b7280 !important; }
        .bg-task-detail {
          color:var(--text, #222) !important; font-size:.82rem; margin-top:.58rem;
          line-height:1.42; overflow-wrap:anywhere;
        }
        .bg-task-meta {
          display:flex; flex-wrap:wrap; gap:.35rem .8rem;
          color:var(--text-soft, #666) !important; font-size:.75rem; margin-top:.5rem;
        }
        .bg-task-progress {
          overflow:hidden; height:.44rem; margin-top:.68rem;
          background:color-mix(in srgb, var(--text, #222) 14%, transparent) !important;
          border-radius:999px;
        }
        .bg-task-progress > span { display:block; height:100%; border-radius:999px; }
        .bg-task-progress.indeterminate > span {
          width:42%; background:var(--accent, #0ea5e9) !important;
          animation:bgTaskMove 1.35s ease-in-out infinite alternate;
        }
        .bg-task-progress.completed > span { width:100%; background:#22c55e !important; }
        .bg-task-progress.failed > span { width:100%; background:#ef4444 !important; }
        .bg-task-progress.cancelled > span { width:100%; background:#6b7280 !important; }
        .bg-task-progress.queued > span { width:16%; background:#64748b !important; }
        .bg-task-progress.stopping > span { width:58%; background:#f59e0b !important; animation:bgTaskMove 1.25s ease-in-out infinite alternate; }
        .bg-task-progress.determinate > span { background:var(--accent, #0ea5e9) !important; }
        .bg-task-actions { display:flex; flex-wrap:wrap; gap:.45rem; margin-top:.72rem; }
        .bg-task-action {
          min-height:36px; padding:.38rem .62rem !important;
          border-radius:8px !important; font-size:.78rem !important; font-weight:700 !important;
          border:1px solid var(--border, rgba(0,0,0,.15)) !important;
          background:var(--bg3, #fff) !important; color:var(--text, #222) !important;
        }
        .bg-task-action.primary {
          border-color:rgba(var(--accent-rgb, 14,165,233), .48) !important;
          color:var(--accent, #0ea5e9) !important;
        }
        .bg-task-action.danger { border-color:rgba(220,38,38,.45) !important; color:#b91c1c !important; }
        .bg-task-action.muted { color:var(--text-soft, #666) !important; }
        .bg-task-action:disabled { opacity:.55; cursor:wait; }
        .bg-task-warning {
          padding:.58rem .8rem; color:#b45309 !important; font-size:.76rem;
          border-top:1px solid var(--border-soft, rgba(0,0,0,.08));
          background:rgba(245,158,11,.08) !important;
        }
        @keyframes bgTaskMove { from { transform:translateX(-55%); } to { transform:translateX(145%); } }
        @media (max-width:575.98px) {
          #backgroundTaskPanel {
            right:.55rem; left:.55rem; bottom:3.5rem; width:auto;
            max-height:78vh; border-radius:14px;
          }
          .bg-task-head { padding:.8rem .82rem; }
          .bg-task-list { padding:.58rem; }
          .bg-task-item { padding:.72rem; }
          .bg-task-action { flex:1 1 auto; }
        }
      `;
      this.document.head.appendChild(style);
    }

    let button = this.document.getElementById('backgroundTaskButton');
    if (!button) {
      button = this.document.createElement('button');
      button.type = 'button';
      button.id = 'backgroundTaskButton';
      button.className = 'os-task-center-button';
      button.setAttribute('aria-controls', 'backgroundTaskPanel');
      button.setAttribute('aria-expanded', 'false');
      button.setAttribute('title', 'Tareas');
      button.setAttribute('aria-label', 'Abrir tareas');
      button.innerHTML = '<i class="fas fa-list-check"></i><span class="bg-task-count">0</span>';
      const taskbar = this.document.querySelector('.os-taskbar');
      const clock = this.document.getElementById('osClock');
      if (taskbar) taskbar.insertBefore(button, clock || null);
      else this.document.body.appendChild(button);
    }
    if (!button.dataset.taskCenterBound) {
      button.dataset.taskCenterBound = '1';
      button.addEventListener('click', () => {
        this.open = !this.open;
        this.render();
        if (this.open) this.refresh();
      });
    }

    if (!this.document.getElementById('backgroundTaskPanel')) {
      const panel = this.document.createElement('aside');
      panel.id = 'backgroundTaskPanel';
      panel.setAttribute('aria-label', 'Tareas en segundo plano');
      panel.innerHTML = `
        <div class="bg-task-head">
          <div>
            <strong>Tareas en segundo plano</strong>
            <small>Las tareas del servidor continúan aunque navegues; las subidas del navegador requieren mantener abierta la pestaña.</small>
          </div>
          <button class="bg-task-close" type="button" data-bg-task-close aria-label="Cerrar">×</button>
        </div>
        <div class="bg-task-toolbar">
          <select class="bg-task-filter" aria-label="Filtrar tareas">
            <option value="all">Todas las tareas</option>
            <option value="active">Activas</option>
            <option value="queued">En cola / pendientes</option>
            <option value="running">Procesando</option>
            <option value="completed">Terminadas</option>
            <option value="failed">Fallidas / canceladas</option>
          </select>
          <button class="bg-task-refresh" type="button" data-bg-task-refresh title="Actualizar">↻</button>
        </div>
        <div class="bg-task-bulk">
          <label class="bg-task-select-all">
            <input type="checkbox" data-bg-task-select-visible>
            <span>Seleccionar visibles</span>
          </label>
          <button type="button" class="bg-task-bulk-action" data-bg-task-remove-selected disabled>
            Eliminar seleccionadas
          </button>
          <button type="button" class="bg-task-bulk-action danger" data-bg-task-clean-terminal>
            Limpiar terminadas/fallidas
          </button>
        </div>
        <div class="bg-task-summary"></div>
        <div class="bg-task-list"></div>
        <div class="bg-task-warning" style="display:none"></div>
      `;

      panel.querySelector('[data-bg-task-close]').addEventListener('click', () => {
        this.open = false;
        this.render();
      });
      panel.querySelector('[data-bg-task-refresh]').addEventListener('click', () => this.refresh());
      panel.querySelector('.bg-task-filter').addEventListener('change', (event) => {
        this.filter = String(event.target.value || 'all');
        this.render();
      });
      panel.addEventListener('click', (event) => {
        const clean = event.target.closest('[data-bg-task-clean-terminal]');
        if (clean) {
          this.cleanupTerminalTasks();
          return;
        }

        const removeSelected = event.target.closest('[data-bg-task-remove-selected]');
        if (removeSelected) {
          this.removeSelectedTasks();
          return;
        }

        const button = event.target.closest('[data-bg-task-action]');
        if (!button) return;
        this.performAction(button);
      });

      panel.addEventListener('change', (event) => {
        const selectVisible = event.target.closest('[data-bg-task-select-visible]');
        if (selectVisible) {
          this.selectVisibleTerminalTasks(Boolean(selectVisible.checked));
          return;
        }

        const checkbox = event.target.closest('[data-bg-task-select]');
        if (!checkbox) return;
        const id = String(checkbox.dataset.bgTaskSelect || '');
        if (!id) return;
        if (checkbox.checked) this.selected.add(id);
        else this.selected.delete(id);
        this.renderBulkControls();
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
      this.serverTasks = Array.isArray(json.tasks) ? json.tasks : [];
      this.serverSummary = json.summary || null;
      this.sourceErrors = json.source_errors || {};
      this.mergeClientTasks();
      this.render();
      this.notifyTransitions(previous);
    } catch (error) {
      console.warn('[background-tasks] no se pudo actualizar:', error);
      this.sourceErrors = { endpoint: error && error.message ? String(error.message) : 'Estado no disponible' };
      this.render();
    }
  }

  mergeClientTasks() {
    const client = this.window.ArcadeCloudUploadManager &&
      typeof this.window.ArcadeCloudUploadManager.taskSnapshots === 'function'
      ? this.window.ArcadeCloudUploadManager.taskSnapshots()
      : [];

    this.tasks = [
      ...client,
      ...this.serverTasks
    ];
    this.pruneSelection();

    const base = this.serverSummary || {
      active: 0,
      queued: 0,
      running: 0,
      stopping: 0,
      failed: 0,
      completed_recent: 0,
      cancelled_recent: 0
    };

    this.summary = { ...base };

    client.forEach((task) => {
      const status = this.normalizeStatus(task.status);
      if (['queued', 'pending', 'running', 'stopping'].includes(status)) {
        this.summary.active = Number(this.summary.active || 0) + 1;
      }
      if (status === 'queued' || status === 'pending') {
        this.summary.queued = Number(this.summary.queued || 0) + 1;
      } else if (status === 'running') {
        this.summary.running = Number(this.summary.running || 0) + 1;
      } else if (status === 'stopping') {
        this.summary.stopping = Number(this.summary.stopping || 0) + 1;
      } else if (status === 'failed') {
        this.summary.failed = Number(this.summary.failed || 0) + 1;
      } else if (status === 'completed') {
        this.summary.completed_recent = Number(this.summary.completed_recent || 0) + 1;
      } else if (status === 'cancelled') {
        this.summary.cancelled_recent = Number(this.summary.cancelled_recent || 0) + 1;
      }
    });
  }

  async performAction(button) {
    const taskId = String(button.dataset.taskId || '');
    const action = String(button.dataset.bgTaskAction || '');
    const label = String(button.dataset.actionLabel || button.textContent || 'acción');
    const key = `${taskId}:${action}`;
    if (!taskId || !action || this.busy.has(key)) return;

    const needsConfirm = button.dataset.confirm === '1';
    if (needsConfirm) {
      const question = action === 'cancel'
        ? '¿Detener esta tarea? El servidor la detendrá en un punto seguro para no dejar una operación a medias.'
        : '¿Quitar esta tarea de la lista? Cuando exista historial de costos se conservará.';
      if (!this.window.confirm(question)) return;
    }

    this.busy.add(key);
    button.disabled = true;
    const original = button.textContent;
    button.textContent = 'Procesando…';

    try {
      const task = this.tasks.find((item) => String(item.control_id || item.id || '') === taskId || String(item.id || '') === taskId);
      if (task && String(task.kind || '') === 'upload' && action === 'dismiss') {
        this.dismissClientUpload(task);
        this.mergeClientTasks();
        this.render();
      } else {
        const json = await this.requestTaskAction(taskId, action);
        this.notify(json.message || `${label} solicitada.`, 'success', 6000);
        await this.refresh();
      }
    } catch (error) {
      this.notify(error && error.message ? error.message : 'No se pudo ejecutar la acción.', 'danger', 8500);
    } finally {
      this.busy.delete(key);
      if (button.isConnected) {
        button.disabled = false;
        button.textContent = original;
      }
    }
  }

  async requestTaskAction(taskId, action) {
    const body = new URLSearchParams();
    body.set('task_id', String(taskId || ''));
    body.set('task_action', String(action || ''));

    const response = await this.window.fetch(this.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body.toString()
    });

    const text = await response.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    if (!response.ok || !json || json.ok !== true) {
      throw new Error((json && json.error) || text || `HTTP ${response.status}`);
    }
    return json;
  }

  isTerminalTask(task) {
    return ['completed', 'failed', 'cancelled'].includes(this.normalizeStatus(task && task.status));
  }

  removalAction(task) {
    if (!task || !this.isTerminalTask(task)) return null;

    if (String(task.kind || '') === 'upload') {
      return {
        controlId: String(task.id || ''),
        action: 'dismiss'
      };
    }

    const actions = Array.isArray(task.actions) ? task.actions : [];
    const action = actions.find((item) => ['delete', 'dismiss'].includes(String(item && item.id || '')));
    if (!action) return null;

    return {
      controlId: String(task.control_id || task.id || ''),
      action: String(action.id || '')
    };
  }

  pruneSelection() {
    const removable = new Set(
      this.tasks
        .filter((task) => this.removalAction(task))
        .map((task) => String(task.id || ''))
    );
    Array.from(this.selected).forEach((id) => {
      if (!removable.has(id)) this.selected.delete(id);
    });
  }

  selectedTasks() {
    return this.tasks.filter((task) => this.selected.has(String(task.id || '')) && this.removalAction(task));
  }

  visibleRemovableTasks() {
    return this.tasks.filter((task) => this.matchesFilter(task) && this.removalAction(task));
  }

  selectVisibleTerminalTasks(selected) {
    this.visibleRemovableTasks().forEach((task) => {
      const id = String(task.id || '');
      if (!id) return;
      if (selected) this.selected.add(id);
      else this.selected.delete(id);
    });
    this.render();
  }

  renderBulkControls() {
    const panel = this.document.getElementById('backgroundTaskPanel');
    if (!panel) return;

    const selectedTasks = this.selectedTasks();
    const removeButton = panel.querySelector('[data-bg-task-remove-selected]');
    if (removeButton) {
      removeButton.disabled = this.bulkBusy || selectedTasks.length === 0;
      removeButton.textContent = selectedTasks.length
        ? `Eliminar seleccionadas (${selectedTasks.length})`
        : 'Eliminar seleccionadas';
    }

    const cleanButton = panel.querySelector('[data-bg-task-clean-terminal]');
    if (cleanButton) {
      cleanButton.disabled = this.bulkBusy || !this.tasks.some((task) => this.removalAction(task));
      cleanButton.textContent = this.bulkBusy ? 'Limpiando…' : 'Limpiar terminadas/fallidas';
    }

    const visible = this.visibleRemovableTasks();
    const selectVisible = panel.querySelector('[data-bg-task-select-visible]');
    if (selectVisible) {
      const selectedVisible = visible.filter((task) => this.selected.has(String(task.id || ''))).length;
      selectVisible.checked = visible.length > 0 && selectedVisible === visible.length;
      selectVisible.indeterminate = selectedVisible > 0 && selectedVisible < visible.length;
      selectVisible.disabled = visible.length === 0 || this.bulkBusy;
    }
  }

  async removeSelectedTasks() {
    const tasks = this.selectedTasks();
    if (!tasks.length || this.bulkBusy) return;
    if (!this.window.confirm(`¿Eliminar ${tasks.length} tarea(s) seleccionada(s) de la lista?`)) return;
    await this.bulkRemoveTasks(tasks);
  }

  async cleanupTerminalTasks() {
    if (this.bulkBusy) return;
    const tasks = this.tasks.filter((task) => this.removalAction(task));
    if (!tasks.length) {
      this.notify('No hay tareas terminadas o fallidas para limpiar.', 'info', 4500);
      return;
    }
    if (!this.window.confirm(`¿Limpiar ${tasks.length} tarea(s) terminada(s), fallida(s) o cancelada(s)?`)) return;
    await this.bulkRemoveTasks(tasks);
  }

  async bulkRemoveTasks(tasks) {
    this.bulkBusy = true;
    this.renderBulkControls();

    let removed = 0;
    let failed = 0;
    const pending = Array.from(tasks);

    for (let offset = 0; offset < pending.length; offset += 5) {
      const batch = pending.slice(offset, offset + 5);
      const results = await Promise.allSettled(batch.map(async (task) => {
        const removal = this.removalAction(task);
        if (!removal) throw new Error('La tarea ya no se puede eliminar.');

        if (String(task.kind || '') === 'upload') {
          if (!this.dismissClientUpload(task)) throw new Error('No se pudo quitar la subida del centro.');
          return;
        }

        await this.requestTaskAction(removal.controlId, removal.action);
      }));

      results.forEach((result) => {
        if (result.status === 'fulfilled') removed += 1;
        else failed += 1;
      });
    }

    tasks.forEach((task) => this.selected.delete(String(task.id || '')));
    this.bulkBusy = false;

    try {
      await this.refresh();
    } catch (_) {
      this.mergeClientTasks();
      this.render();
    }

    if (failed > 0) {
      this.notify(`Se eliminaron ${removed} tarea(s); ${failed} no pudieron eliminarse.`, 'warning', 8500);
    } else {
      this.notify(`Se limpiaron ${removed} tarea(s).`, 'success', 6000);
    }
  }

  dismissClientUpload(task) {
    const manager = this.window.ArcadeCloudUploadManager;
    if (!manager || typeof manager.dismissTask !== 'function') return false;
    return manager.dismissTask(String(task && task.id || ''));
  }

  notifyTransitions(previous) {
    this.tasks.forEach((task) => {
      const id = String(task.id || '');
      const before = previous.get(id);
      const now = String(task.status || '');
      if (!before || before === now) return;
      if (now === 'completed') {
        this.notify(`Tarea terminada: ${task.title || task.category || 'Tarea'}.`, 'success', 6500);
        this.refreshDriveIfRelevant(task);
      } else if (now === 'failed') {
        this.notify(`Tarea con error: ${task.title || task.category || 'Tarea'}.`, 'danger', 8500);
      } else if (now === 'cancelled') {
        this.notify(`Tarea detenida: ${task.title || task.category || 'Tarea'}.`, 'info', 6000);
      }
    });
  }

  async refreshDriveIfRelevant(task) {
    const kind = String(task && task.kind || '');
    if (!['media', 'transcribe'].includes(kind)) return;

    this.document.dispatchEvent(new CustomEvent('drive:storage-changed', {
      detail: { source: 'background-task', task: task }
    }));

    if (this.document.visibilityState !== 'visible') return;
    if (typeof this.window.rutaActual === 'undefined' && typeof this.window.DRIVE_INITIAL_ROUTE === 'undefined') {
      return;
    }

    const meta = task && task.metadata && typeof task.metadata === 'object' ? task.metadata : {};
    const expectedRoute = this.normalizeRoute(meta.output_route || '');
    const rawCurrentRoute = typeof this.window.rutaActual !== 'undefined'
      ? this.window.rutaActual
      : (this.window.DRIVE_INITIAL_ROUTE || '');
    const currentRoute = this.normalizeRoute(rawCurrentRoute);
    if (expectedRoute !== currentRoute) return;

    // No recargar la página completa al terminar trabajos multimedia.
    // El Drive ya dispone de un refresco AJAX del bloque de archivos que
    // conserva ruta, página, filtros y el estado del Centro de Tareas.
    if (typeof this.window.actualizarBloqueArchivos !== 'function') {
      if (this.window.console) {
        this.window.console.warn(
          '[background-tasks] actualizarBloqueArchivos no está disponible; se conserva la página sin recargar.'
        );
      }
      return;
    }

    const refreshBlock = async () => {
      try {
        let context = {};
        if (typeof this.window.getBloqueArchivosContext === 'function') {
          const current = this.window.getBloqueArchivosContext();
          if (current && typeof current === 'object') {
            context = Object.assign({}, current);
          }
        }

        if (!context.ruta && rawCurrentRoute) context.ruta = String(rawCurrentRoute);
        if (!context.pagina) context.pagina = 1;

        await this.window.actualizarBloqueArchivos(context);
      } catch (error) {
        if (this.window.console) {
          this.window.console.warn(
            '[background-tasks] no se pudo refrescar el bloque de archivos:',
            error
          );
        }
      }
    };

    const openModal = this.document.querySelector('.modal.show');
    if (openModal && this.window.jQuery) {
      this.window.jQuery(openModal).one('hidden.bs.modal', () => {
        this.window.setTimeout(() => { void refreshBlock(); }, 150);
      });
      return;
    }

    await refreshBlock();
  }

  normalizeRoute(value) {
    return String(value || '')
      .replace(/\\/g, '/')
      .replace(/^\/+/, '')
      .replace(/\/{2,}/g, '/')
      .replace(/\/$/, '');
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

    const filter = panel.querySelector('.bg-task-filter');
    if (filter && filter.value !== this.filter) filter.value = this.filter;

    const summary = panel.querySelector('.bg-task-summary');
    if (summary) {
      summary.innerHTML = [
        `Activas: ${active}`,
        `En cola: ${Number(this.summary.queued || 0)}`,
        `Procesando: ${Number(this.summary.running || 0)}`,
        `Deteniendo: ${Number(this.summary.stopping || 0)}`,
        `Terminadas: ${Number(this.summary.completed_recent || 0)}`,
        `Fallidas: ${Number(this.summary.failed || 0)}`,
        `Canceladas: ${Number(this.summary.cancelled_recent || 0)}`
      ].map((text) => `<span>${this.escapeHtml(text)}</span>`).join('');
    }

    const visibleTasks = this.tasks.filter((task) => this.matchesFilter(task));
    const list = panel.querySelector('.bg-task-list');
    if (list) {
      list.innerHTML = visibleTasks.length
        ? visibleTasks.map((task) => this.taskHtml(task)).join('')
        : '<div class="bg-task-empty">No hay tareas para este filtro.</div>';
    }

    const warning = panel.querySelector('.bg-task-warning');
    const errors = this.sourceErrors && typeof this.sourceErrors === 'object'
      ? Object.entries(this.sourceErrors)
      : [];
    if (warning) {
      if (errors.length) {
        warning.style.display = 'block';
        warning.textContent = 'Alguna fuente de tareas no pudo consultarse; las demás siguen disponibles.';
      } else {
        warning.style.display = 'none';
        warning.textContent = '';
      }
    }

    this.renderBulkControls();
  }

  matchesFilter(task) {
    const status = this.normalizeStatus(task.status);
    if (this.filter === 'all') return true;
    if (this.filter === 'active') return ['queued', 'pending', 'running', 'stopping'].includes(status);
    if (this.filter === 'queued') return ['queued', 'pending'].includes(status);
    if (this.filter === 'running') return ['running', 'stopping'].includes(status);
    if (this.filter === 'completed') return status === 'completed';
    if (this.filter === 'failed') return ['failed', 'cancelled'].includes(status);
    return true;
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
    if (task.kind === 'move') {
      if (Number(meta.items || 0) > 0) details.push(`Elementos: ${Number(meta.items)}`);
      if (Number(meta.processed_items || 0) > 0) details.push(`Procesados: ${Number(meta.processed_items)}`);
      if (meta.destination) details.push(`Destino: ${String(meta.destination)}`);
    }
    if (task.kind === 'upload') {
      if (meta.destination) details.push(`Destino: ${String(meta.destination)}`);
      if (Number(meta.bytes_total || 0) > 0) details.push(`Tamaño: ${this.formatBytes(Number(meta.bytes_total))}`);
      if (meta.source) details.push(`Origen: ${String(meta.source)}`);
    }
    if (task.kind === 'polly') {
      if (meta.engine) details.push(`Motor: ${String(meta.engine)}`);
      if (Number(meta.characters || 0) > 0) details.push(`Caracteres: ${Number(meta.characters)}`);
    }
    if (task.kind === 'transcribe' && Number(meta.billable_seconds || 0) > 0) {
      details.push(`Facturable: ${Number(meta.billable_seconds)} s`);
    }

    if (task.estimated_cost !== null && task.estimated_cost !== undefined) {
      details.push(`Costo: ${this.money(task.estimated_cost, task.currency || 'USD')}`);
    } else if (['queued', 'running', 'pending', 'stopping'].includes(status) && ['polly', 'transcribe'].includes(String(task.kind))) {
      details.push('Costo: pendiente');
    }

    const progress = this.progressInfo(task, status, meta);
    const actions = Array.isArray(task.actions) ? task.actions : [];
    const actionHtml = actions.length
      ? `<div class="bg-task-actions">${actions.map((action) => this.actionHtml(task, action)).join('')}</div>`
      : '';
    const selectable = Boolean(this.removalAction(task));
    const taskId = String(task.id || '');
    const checked = selectable && this.selected.has(taskId) ? ' checked' : '';
    const selectorHtml = selectable
      ? `<label class="bg-task-item-select" title="Seleccionar para limpiar"><input type="checkbox" data-bg-task-select="${this.escapeHtml(taskId)}"${checked}></label>`
      : '';

    return `
      <article class="bg-task-item">
        <div class="bg-task-row">
          <div class="bg-task-title-wrap">
            ${selectorHtml}
            <div>
              <div class="bg-task-kind">${this.escapeHtml(task.category || task.kind || 'Tarea')}</div>
              <div class="bg-task-title">${this.escapeHtml(task.title || 'Tarea')}</div>
              <div class="bg-task-service">${this.escapeHtml(task.service || '')}${task.provider ? ' · ' + this.escapeHtml(task.provider) : ''}</div>
            </div>
          </div>
          <span class="bg-task-badge bg-task-${status}">${this.escapeHtml(label)}</span>
        </div>
        <div class="bg-task-detail">${this.escapeHtml(task.detail || '')}</div>
        <div class="bg-task-meta">${details.map((item) => `<span>${this.escapeHtml(item)}</span>`).join('')}</div>
        <div class="bg-task-progress ${progress.cssClass}"><span style="${progress.widthStyle}"></span></div>
        ${actionHtml}
      </article>
    `;
  }

  actionHtml(task, action) {
    const controlId = String(task.control_id || task.id || '');
    const id = String(action && action.id || '');
    const label = String(action && action.label || id || 'Acción');
    const tone = ['primary', 'danger', 'muted'].includes(String(action && action.tone))
      ? String(action.tone)
      : 'muted';
    const confirm = action && action.confirm ? '1' : '0';
    const key = `${controlId}:${id}`;
    const disabled = this.busy.has(key) ? ' disabled' : '';

    return `<button type="button" class="bg-task-action ${tone}" data-bg-task-action="${this.escapeHtml(id)}" data-task-id="${this.escapeHtml(controlId)}" data-action-label="${this.escapeHtml(label)}" data-confirm="${confirm}"${disabled}>${this.escapeHtml(label)}</button>`;
  }

  progressInfo(task, status, meta) {
    if (status === 'completed') return { cssClass: 'completed', widthStyle: 'width:100%' };
    if (status === 'failed') return { cssClass: 'failed', widthStyle: 'width:100%' };
    if (status === 'cancelled') return { cssClass: 'cancelled', widthStyle: 'width:100%' };
    if (status === 'stopping') return { cssClass: 'stopping', widthStyle: '' };
    if (status === 'queued' || status === 'pending') return { cssClass: 'queued', widthStyle: '' };

    if (task.kind === 'move') {
      const total = Number(meta.items || 0);
      const processed = Number(meta.processed_items || 0);
      if (total > 0 && processed >= 0) {
        const pct = Math.max(1, Math.min(99, Math.round((processed / total) * 100)));
        return { cssClass: 'determinate', widthStyle: `width:${pct}%` };
      }
    }

    if (task.kind === 'upload' && Number.isFinite(Number(task.progress))) {
      const pct = Math.max(1, Math.min(99, Math.round(Number(task.progress))));
      return { cssClass: 'determinate', widthStyle: `width:${pct}%` };
    }

    return { cssClass: 'indeterminate', widthStyle: '' };
  }

  normalizeStatus(value) {
    const status = String(value || '').toLowerCase();
    return ['queued', 'running', 'completed', 'failed', 'pending', 'stopping', 'cancelled'].includes(status)
      ? status
      : 'pending';
  }

  statusLabel(status) {
    return {
      queued: 'EN COLA',
      running: 'PROCESANDO',
      completed: 'TERMINADA',
      failed: 'FALLÓ',
      pending: 'PENDIENTE',
      stopping: 'DETENIENDO',
      cancelled: 'CANCELADA'
    }[status] || 'PENDIENTE';
  }

  formatDate(value) {
    if (!value) return '';
    const raw = String(value);
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z';
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return raw;
    try {
      return new Intl.DateTimeFormat(undefined, {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
      }).format(date);
    } catch (_) {
      return date.toLocaleString();
    }
  }

  formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
    return (value / Math.pow(1024, index)).toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
  }

  money(value, currency) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '';
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency', currency: String(currency || 'USD'), maximumFractionDigits: 4
      }).format(amount);
    } catch (_) {
      return `${String(currency || 'USD')} ${amount.toFixed(4)}`;
    }
  }

  notify(message, type = 'info', timeout = 5500) {
    if (this.window.DriveMoveTasks && typeof this.window.DriveMoveTasks.notify === 'function') {
      this.window.DriveMoveTasks.notify(message, type, timeout);
      return;
    }
    if (this.window.ArcadeCloudOsShell && typeof this.window.ArcadeCloudOsShell.notify === 'function') {
      this.window.ArcadeCloudOsShell.notify(message, type, timeout);
      return;
    }
    if (this.window.console) this.window.console.log(`[Tareas/${type}] ${message}`);
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
