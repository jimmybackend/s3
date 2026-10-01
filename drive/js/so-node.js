class ArcadeCloudOsNodeMonitor {
  constructor(win, doc) {
    this.window = win; this.document = doc; this.config = win.ARCADECLOUD_OS_NODE || {};
    this.endpoint = String(this.config.endpoint || 'node-status.php'); this.busy = false;
    this.nodes = {}; this.selected = 'local'; this.pollTimer = null; this.countdownTimer = null; this.zeroConfirmed = false;
  }

  init() {
    this.document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null; if (!target) return;
      if (target.closest('[data-window-open="nodeWindow"]')) this.window.setTimeout(() => this.startPolling(), 30);
      if (target.closest('#nodeWindow [data-window-close]')) this.stopPolling();
      const tab = target.closest('[data-node-tab]'); if (tab) { this.selected = String(tab.dataset.nodeTab || 'local'); this.renderSelected(); }
      const broom = target.closest('[data-node-memory-clear]'); if (broom) { event.preventDefault(); this.openMaintenanceModal(false); }
      const disk = target.closest('[data-node-disk-clean]'); if (disk) { event.preventDefault(); this.openMaintenanceModal(true); }
      const memorySubmit = target.closest('[data-node-memory-submit]'); if (memorySubmit) { event.preventDefault(); this.queueMaintenance('memory-clear'); }
      const diskSubmit = target.closest('[data-node-disk-submit]'); if (diskSubmit) { event.preventDefault(); this.queueMaintenance('disk-clean'); }
      const refresh = target.closest('[data-node-refresh]'); if (refresh) { event.preventDefault(); this.refresh(); }
    });
    this.document.addEventListener('background-tasks:refresh', () => { if (this.isOpen()) this.refresh(); });
    return this;
  }

  isOpen() { return Boolean(this.document.getElementById('nodeWindow')?.classList.contains('is-open')); }
  startPolling() { this.stopPolling(); this.refresh(); this.pollTimer = this.window.setInterval(() => { if (this.isOpen()) this.refresh(); else this.stopPolling(); }, 30000); }
  stopPolling() { if (this.pollTimer) this.window.clearInterval(this.pollTimer); this.pollTimer = null; if (this.countdownTimer) this.window.clearInterval(this.countdownTimer); this.countdownTimer = null; }

  async refresh() {
    if (this.busy) return; this.busy = true; const windowEl = this.document.getElementById('nodeWindow'); windowEl?.classList.add('is-node-loading');
    try {
      const url = new URL(this.endpoint, this.window.location.href); url.searchParams.set('_', String(Date.now()));
      const response = await this.window.fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await response.json(); if (!response.ok || data?.ok !== true) throw new Error(data?.error || 'No se pudo actualizar Mi nodo.');
      this.nodes = { local: data.local || data.node || {}, fastdrive: data.fastdrive || {} }; this.zeroConfirmed = false;
      this.renderLegacy(this.nodes.local); this.renderSelected();
    } catch (error) { this.notify(error?.message || 'No se pudo actualizar Mi nodo.', 'warning'); }
    finally { this.busy = false; windowEl?.classList.remove('is-node-loading'); }
  }

  renderLegacy(node) {
    const values = { instance_type: node.instance_type || 'no identificada', vcpu: String(node.vcpu ?? 0), memory_total: node.memory_total || '0 B', memory_available: node.memory_available || '0 B', disk_total: node.disk_total || '0 B', disk_used: (node.disk_used || '0 B') + ' · ' + String(node.disk_used_percent ?? 0) + '%', disk_free: node.disk_free || '0 B', swap: node.swap_total || '0 B', load: Array.isArray(node.load_average) ? node.load_average.join(' · ') : '0 · 0 · 0', role: node.role || 'web', instance_id: node.instance_id || '—' };
    Object.entries(values).forEach(([key, value]) => this.document.querySelectorAll('[data-node-field="' + key + '"]').forEach((el) => { el.textContent = String(value); }));
    this.renderCapability('ffmpeg', node.ffmpeg_available, 'Disponible', 'No disponible'); this.renderCapability('ffprobe', node.ffprobe_available, 'Disponible', 'No disponible'); this.renderCapability('docker', node.docker_installed, 'Instalado', 'No instalado'); this.renderCapability('gpu', node.gpu_present, 'Detectada', 'No detectada');
    const updated = this.document.querySelector('[data-node-updated]'); if (updated) updated.textContent = 'Actualizado ' + new Date(node.generated_at || Date.now()).toLocaleTimeString('es-MX');
  }

  renderSelected() {
    this.document.querySelectorAll('[data-node-tab]').forEach((button) => { const active = button.dataset.nodeTab === this.selected; button.classList.toggle('is-active', active); button.setAttribute('aria-selected', String(active)); });
    const root = this.document.querySelector('[data-node-dashboard]'); if (!root) return; root.replaceChildren();
    const node = this.nodes[this.selected]; if (!node || !Object.keys(node).length) { root.append(this.el('p', 'os-node-placeholder', 'Datos no disponibles.')); return; }
    if (this.selected === 'fastdrive') this.renderFastDrive(root, node); else this.renderLocal(root, node);
  }

  renderLocal(root, node) {
    root.append(this.healthCard(node), this.resourceCard(node.resources || {}), this.servicesCard(node.services || []),
      this.phpNginxCard(node.php || {}, node.nginx || {}), this.databaseCard(node.database || {}), this.usersCard(node.users || {}),
      this.federationCard(node.federation || {}), this.activityCard(node.activity || {}), this.programsCard(node.programs || []), this.networkCard(node.network || {}, node));
  }

  renderFastDrive(root, node) {
    root.append(this.healthCard(node));
    root.append(this.card('AWS / EC2', [['Estado', node.state || 'No disponible'], ['Instancia', node.instance_type || '—'], ['Zona', node.availability_zone || '—'], ['IP privada', node.private_ip || '—'], ['IP pública', node.public_ip || '—']]));
    root.append(this.card('Servicios internos', [['Consulta', node.internal_message || 'No disponible']]));
    if (node.autoshutdown) root.append(this.autoShutdownCard(node.autoshutdown));
  }

  healthCard(node) {
    const health = node.health || {}; const card = this.el('section', 'os-node-card os-node-card-wide os-node-state-' + (health.state || 'neutral'));
    card.append(this.el('h3', '', this.selected === 'fastdrive' ? 'NODO FASTDRIVE' : 'NODO PRINCIPAL'));
    const line = this.el('div', 'os-node-health'); line.append(this.el('span', 'os-node-dot'), this.el('span', '', health.label || 'No disponible')); card.append(line);
    card.append(this.el('div', 'os-node-list-row', '', [this.el('span', '', 'Nodo'), this.el('span', '', node.hostname || (this.selected === 'fastdrive' ? 'fastdrive.esforzados.com' : '—'))]));
    if (Array.isArray(health.reasons) && health.reasons.length) { const ul = this.el('ul', 'os-node-reasons'); health.reasons.forEach((reason) => ul.append(this.el('li', '', String(reason)))); card.append(ul); } return card;
  }

  resourceCard(r) {
    const card = this.el('section', 'os-node-card'); card.append(this.el('h3', '', 'Recursos'));
    const load = Array.isArray(r.load_average) ? r.load_average.join(' · ') : '—';
    card.append(this.metrics([['vCPU', r.vcpu ?? '—'], ['Carga 1m · 5m · 15m', load], ['Carga relativa / CPU', r.load_per_vcpu ?? '—'], ['Uptime', this.duration(r.uptime_seconds)]]));
    [['RAM', r.memory], ['Swap', r.swap], ['Disco', r.disk]].forEach(([name, value]) => { if (!value) return; card.append(this.el('strong', '', String(name) + ' · ' + String(value.used_percent ?? 0) + '%')); const progress = this.el('div', 'os-node-progress'); const bar = this.el('span'); bar.style.width = Math.min(100, Number(value.used_percent || 0)) + '%'; progress.append(bar); card.append(progress, this.metrics([['Total', value.total], ['Usada', value.used], ['Disponible', value.available]])); }); return card;
  }

  servicesCard(services) { const card = this.el('section', 'os-node-card'); card.append(this.el('h3', '', 'Servicios ArcadeCloud')); if (!services.length) card.append(this.el('p', 'os-node-placeholder', 'No hay unidades de la whitelist instaladas o systemd no está disponible.')); services.forEach((s) => card.append(this.row(s.name || s.unit, (s.active || 'unknown') + ' · ' + (s.enabled || 'unknown'), s.active === 'active'))); return card; }
  phpNginxCard(php, nginx) { const fpm = php.service || {}; const ng = nginx.service || {}; const card = this.card('PHP / Nginx', [['PHP', php.version || '—'], ['PHP-FPM', fpm.active || 'No instalado'], ['Nginx', ng.active || 'No instalado'], ['Configuración Nginx', nginx.config_ok ? 'OK' : 'Error / no disponible']]); const details = this.el('details', 'os-node-details'); details.append(this.el('summary', '', 'Detalles'), this.metrics([['FPM unit', fpm.unit || '—'], ['FPM PID', fpm.pid || '—'], ['memory_limit', php.settings?.memory_limit || '—'], ['upload_max_filesize', php.settings?.upload_max_filesize || '—'], ['post_max_size', php.settings?.post_max_size || '—'], ['max_execution_time', php.settings?.max_execution_time || '—'], ['Pool FPM', php.pool_message || '—'], ['nginx -t', nginx.config_message || '—']])); card.append(details); return card; }
  databaseCard(db) { return this.card('MySQL / Base de datos', [['Tipo', db.type === 'local' ? 'Local' : 'Remota'], ['Host', db.host || '—'], ['Estado', db.available ? 'Disponible' : 'No disponible'], ['Respuesta', db.latency_ms == null ? '—' : db.latency_ms + ' ms'], ['Conexiones', db.advanced_available ? db.threads_connected + ' / ' + db.max_connections : 'Estado avanzado no disponible con los permisos actuales.'], ['Threads running', db.advanced_available ? db.threads_running : '—'], ['Uptime MySQL', db.advanced_available ? this.duration(db.uptime_seconds) : '—']]); }
  usersCard(users) { return this.card('Usuarios / Sesiones', [['Usuarios registrados', users.registered ?? 'No disponible'], ['Sesiones abiertas', users.online_available ? users.sessions : 'No disponible'], ['Definición', users.message || '—']]); }
  federationCard(f) { return this.card('FederationCloud', [['Estado', f.enabled ? 'Activo' : 'Desactivado / no disponible'], ['Nodo', f.node_name || '—'], ['Node ID', f.node_id || '—'], ['Rol', f.role || '—'], ['Seed configurado', f.seed_configured ? 'Sí' : 'No'], ['Nodos conocidos', f.known ?? '—'], ['Vistos recientemente', f.recently_seen ?? '—'], ['Disponibles', f.available ?? '—'], ['No disponibles', f.unavailable ?? '—'], ['Última sincronización observada', f.last_sync_at || '—']]); }
  activityCard(a) { const entries = [['Trabajos activos', a.active ?? 'No disponible']]; Object.entries(a.sources || {}).forEach(([key, value]) => entries.push([key, value ? 'Activo' : '0'])); return this.card('Actividad', entries); }
  programsCard(programs) { const card = this.el('section', 'os-node-card'); card.append(this.el('h3', '', 'Programas / Capacidades')); programs.forEach((p) => card.append(this.row(p.name, p.installed ? (p.version || 'Disponible') : 'No instalado', p.installed))); return card; }
  networkCard(n, node) { return this.card('Red / EC2', [['Hostname', n.hostname || node.hostname || '—'], ['IP privada', n.private_ip || '—'], ['IP pública', n.public_ip || 'No disponible'], ['S3', n.s3 === 'not_probed' ? 'No probado (evita sondeos costosos)' : n.s3], ['MySQL', n.mysql || 'No disponible'], ['Instance ID', node.instance_id || '—'], ['Tipo', node.instance_type || '—'], ['Zona', node.availability_zone || '—']]); }

  autoShutdownCard(idle) {
    const card = this.card('Autoapagado', [['Activo', idle.enabled ? 'Sí' : 'No'], ['Timeout', this.duration(idle.idle_timeout_seconds)], ['Última actividad', idle.last_activity_at ? new Date(idle.last_activity_at).toLocaleTimeString('es-MX') : 'No disponible'], ['Inactividad', this.duration(idle.idle_seconds)], ['Apagado en', this.duration(idle.remaining_seconds)], ['Estado', idle.shutdown_blockers?.length ? 'Pausado' : (idle.state || 'Cuenta regresiva')], ['Bloqueos', idle.shutdown_blockers?.length ? idle.shutdown_blockers.join(', ') : 'Sin tareas que bloqueen apagado']]);
    if (this.countdownTimer) this.window.clearInterval(this.countdownTimer);
    let remaining = Number(idle.remaining_seconds); const dd = card.querySelectorAll('dd')[4];
    if (idle.enabled && Number.isFinite(remaining) && remaining > 0 && !idle.shutdown_blockers?.length) this.countdownTimer = this.window.setInterval(() => { remaining = Math.max(0, remaining - 1); if (dd) dd.textContent = this.duration(remaining); if (remaining === 0) { this.window.clearInterval(this.countdownTimer); this.countdownTimer = null; if (!this.zeroConfirmed && this.isOpen()) { this.zeroConfirmed = true; this.refresh(); } } }, 1000);
    return card;
  }

  card(title, entries) { const card = this.el('section', 'os-node-card'); card.append(this.el('h3', '', title), this.metrics(entries)); return card; }
  metrics(entries) { const dl = this.el('dl'); entries.forEach(([key, value]) => dl.append(this.el('dt', '', String(key)), this.el('dd', '', value == null || value === '' ? '—' : String(value)))); return dl; }
  row(key, value, ready) { const row = this.el('div', 'os-node-list-row' + (ready ? ' os-node-status-active' : '')); row.append(this.el('span', '', String(key)), this.el('span', '', String(value))); return row; }
  el(tag, className = '', text = '', children = []) { const el = this.document.createElement(tag); if (className) el.className = className; if (text) el.textContent = text; children.forEach((child) => el.append(child)); return el; }
  duration(seconds) { const n = Number(seconds); if (!Number.isFinite(n) || n < 0) return '—'; const d = Math.floor(n / 86400), h = Math.floor((n % 86400) / 3600), m = Math.floor((n % 3600) / 60), s = Math.floor(n % 60); return (d ? d + 'd ' : '') + (h ? h + 'h ' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
  renderCapability(name, ready, yes, no) { const el = this.document.querySelector('[data-node-capability="' + name + '"]'); if (!el) return; el.textContent = ready ? yes : no; el.classList.toggle('is-ready', Boolean(ready)); el.classList.toggle('is-missing', !ready); }

  openMaintenanceModal(isDisk) { const modal = this.document.getElementById(isDisk ? 'nodeDiskCleanModal' : 'nodeMemoryClearModal'); if (!modal) return; const input = modal.querySelector(isDisk ? '[data-node-disk-password]' : '[data-node-memory-password]'); const status = modal.querySelector(isDisk ? '[data-node-disk-status]' : '[data-node-memory-status]'); if (input) input.value = ''; if (status) { status.textContent = isDisk ? 'La limpieza se ejecutará sólo cuando no haya tareas activas.' : 'Si hay procesos activos, la limpieza quedará en cola hasta que terminen.'; status.className = 'small text-muted'; } const jq = this.window.jQuery || this.window.$; if (jq && typeof jq(modal).modal === 'function') jq(modal).modal('show'); }
  async queueMaintenance(action) { if (this.busy) return; const isDisk = action === 'disk-clean'; const modal = this.document.getElementById(isDisk ? 'nodeDiskCleanModal' : 'nodeMemoryClearModal'); const input = modal?.querySelector(isDisk ? '[data-node-disk-password]' : '[data-node-memory-password]'); const status = modal?.querySelector(isDisk ? '[data-node-disk-status]' : '[data-node-memory-status]'); const button = modal?.querySelector(isDisk ? '[data-node-disk-submit]' : '[data-node-memory-submit]'); const password = String(input?.value || ''); if (!password) { if (status) { status.textContent = 'Escribe la contraseña privada.'; status.className = 'small text-danger'; } return; } this.busy = true; if (button) button.disabled = true; try { const response = await this.window.fetch(this.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Server-Admin-CSRF': String(this.config.csrf || ''), 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ action, access_password: password }).toString() }); const data = await response.json(); if (!response.ok || data?.ok !== true) throw new Error(data?.error || 'No se pudo programar el mantenimiento.'); if (status) { status.textContent = String(data.message || 'Mantenimiento programado.'); status.className = 'small text-success'; } this.renderLegacy(data.node || {}); this.document.dispatchEvent(new Event('background-tasks:refresh')); this.notify(String(data.message || 'Mantenimiento programado.'), 'success'); } catch (error) { if (status) { status.textContent = error?.message || 'No se pudo programar el mantenimiento.'; status.className = 'small text-danger'; } } finally { this.busy = false; if (button) button.disabled = false; } }
  notify(message, type) { if (this.window.ArcadeCloudOsShell?.notify) this.window.ArcadeCloudOsShell.notify(message, type || 'info'); else console.log('[Mi nodo]', message); }
  static boot(win = window, doc = document) { if (win.ArcadeCloudOsNodeMonitor instanceof ArcadeCloudOsNodeMonitor) return win.ArcadeCloudOsNodeMonitor; const instance = new ArcadeCloudOsNodeMonitor(win, doc).init(); win.ArcadeCloudOsNodeMonitor = instance; return instance; }
}
ArcadeCloudOsNodeMonitor.boot();
