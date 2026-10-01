class ArcadeCloudPageTaskManager {
  constructor(win, doc) {
    this.window = win; this.document = doc; this.manager = win.ArcadeCloudWindowManager;
    this.startedAt = win.performance?.timeOrigin || Date.now(); this.timer = null;
  }
  init() {
    const root = this.document.querySelector('[data-page-monitor]');
    if (!root || !this.manager) return this;
    this.root = root; this.update();
    this.timer = this.window.setInterval(() => this.update(), 2000);
    this.document.addEventListener('visibilitychange', () => { if (!this.document.hidden) this.update(); });
    this.window.ArcadeCloudPageTaskManager = this; return this;
  }
  update() {
    if (!this.root) return;
    const records = this.manager.openRecords ? this.manager.openRecords() : [];
    const open = records.filter(record => record.status === 'open').length;
    const minimized = records.filter(record => record.status === 'minimized').length;
    const taskCenter = this.window.ArcadeCloudBackgroundTaskCenter;
    this.set('[data-page-dom-nodes]', this.document.getElementsByTagName('*').length.toLocaleString());
    this.set('[data-page-windows]', String(records.length));
    this.set('[data-page-window-detail]', `${open} abiertas · ${minimized} minimizadas`);
    this.set('[data-page-tasks]', String(Number(taskCenter?.summary?.active || 0)));
    this.set('[data-page-resources]', String(this.window.performance?.getEntriesByType?.('resource')?.length || 0));
    this.set('[data-page-uptime]', this.duration(Math.max(0, Date.now() - this.startedAt)));
    this.updateMemory();
  }
  updateMemory() {
    const memory = this.window.performance?.memory;
    if (!memory || !Number.isFinite(Number(memory.usedJSHeapSize))) {
      this.set('[data-page-memory-used]', 'No disponible');
      this.set('[data-page-memory-detail]', 'Este navegador no expone performance.memory');
      this.set('[data-page-memory-note]', 'El resto de métricas sigue siendo real. La memoria JS exacta sólo se muestra cuando el navegador permite medirla.');
      return;
    }
    const used = Number(memory.usedJSHeapSize || 0), total = Number(memory.totalJSHeapSize || 0), limit = Number(memory.jsHeapSizeLimit || 0);
    this.set('[data-page-memory-used]', this.bytes(used));
    this.set('[data-page-memory-detail]', total > 0 ? `${this.bytes(used)} de ${this.bytes(total)} reservados` : this.bytes(used));
    this.set('[data-page-memory-note]', limit > 0 ? `Límite de heap informado por el navegador: ${this.bytes(limit)}.` : 'Memoria JavaScript informada por el navegador.');
  }
  set(selector, value) { const node = this.root.querySelector(selector); if (node) node.textContent = String(value); }
  bytes(value) {
    let size = Math.max(0, Number(value) || 0); const units = ['B', 'KB', 'MB', 'GB']; let index = 0;
    while (size >= 1024 && index < units.length - 1) { size /= 1024; index += 1; }
    return `${size.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
  }
  duration(ms) {
    const seconds = Math.floor(ms / 1000), hours = Math.floor(seconds / 3600), minutes = Math.floor((seconds % 3600) / 60), rest = seconds % 60;
    return hours > 0 ? `${hours}h ${minutes}m` : minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
  }
}
if (typeof module !== 'undefined') module.exports = { ArcadeCloudPageTaskManager };
if (typeof window !== 'undefined' && typeof document !== 'undefined') window.addEventListener('DOMContentLoaded', () => new ArcadeCloudPageTaskManager(window, document).init());
