class ArcadeCloudPageResourceManager {
  constructor(win, doc) {
    this.window = win; this.document = doc;
    this.root = doc.querySelector('[data-page-resource-manager]');
    this.startedAt = win.performance?.timeOrigin || Date.now();
    this.timer = null;
  }
  init() {
    if (!this.root) return this;
    this.refresh();
    this.timer = this.window.setInterval(() => this.refresh(), 2000);
    this.document.addEventListener('visibilitychange', () => { if (!this.document.hidden) this.refresh(); });
    return this;
  }
  metric(name, value) { const node = this.root.querySelector(`[data-page-metric="${name}"]`); if (node) node.textContent = value; }
  bytes(value) {
    const bytes = Number(value || 0); if (!Number.isFinite(bytes) || bytes < 0) return 'No disponible';
    const units = ['B', 'KB', 'MB', 'GB']; let amount = bytes; let unit = 0;
    while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit += 1; }
    return `${amount.toFixed(unit > 1 ? 1 : 0)} ${units[unit]}`;
  }
  browserMemory() {
    const memory = this.window.performance?.memory; if (!memory) return null;
    const used = Number(memory.usedJSHeapSize || 0), total = Number(memory.totalJSHeapSize || 0), limit = Number(memory.jsHeapSizeLimit || 0);
    return used || total || limit ? { used, total, limit } : null;
  }
  taskCount() {
    const count = Number.parseInt(String(this.document.querySelector('#backgroundTaskButton .bg-task-count')?.textContent || '0'), 10);
    return Number.isFinite(count) ? count : 0;
  }
  refresh() {
    if (!this.root) return;
    const memory = this.browserMemory();
    this.metric('heap-used', memory ? this.bytes(memory.used) : 'No disponible');
    this.metric('heap-limit', memory?.limit ? this.bytes(memory.limit) : 'No disponible');
    this.metric('dom-nodes', `${this.document.getElementsByTagName('*').length} nodos`);
    this.metric('windows', String(this.document.querySelectorAll('.os-window.is-open').length));
    this.metric('tasks', String(this.taskCount()));
    this.metric('uptime', this.duration((Date.now() - this.startedAt) / 1000));
    this.renderResources();
  }
  duration(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0)); if (total < 60) return `${total} s`;
    const minutes = Math.floor(total / 60); return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
  }
  renderResources() {
    const host = this.root.querySelector('[data-page-resource-list]'); if (!host) return;
    const entries = this.window.performance?.getEntriesByType?.('resource') || [], groups = new Map();
    entries.forEach((entry) => {
      let kind = String(entry.initiatorType || 'otro').toLowerCase(); if (kind === 'link') kind = 'css'; if (kind === 'script') kind = 'js';
      const row = groups.get(kind) || { count: 0, bytes: 0 }; row.count += 1; row.bytes += Number(entry.transferSize || 0); groups.set(kind, row);
    });
    host.replaceChildren();
    if (!groups.size) { const empty = this.document.createElement('p'); empty.textContent = 'El navegador no reportó recursos todavía.'; host.append(empty); return; }
    [...groups.entries()].sort((a, b) => b[1].bytes - a[1].bytes).forEach(([kind, row]) => {
      const item = this.document.createElement('div'), label = this.document.createElement('span'), value = this.document.createElement('strong');
      label.textContent = kind.toUpperCase(); value.textContent = `${row.count} · ${row.bytes > 0 ? this.bytes(row.bytes) : 'tamaño no expuesto'}`;
      item.append(label, value); host.append(item);
    });
  }
}
if (typeof module !== 'undefined') module.exports = { ArcadeCloudPageResourceManager };
if (typeof window !== 'undefined' && typeof document !== 'undefined') window.addEventListener('DOMContentLoaded', () => { window.ArcadeCloudPageResourceManager = new ArcadeCloudPageResourceManager(window, document).init(); });
