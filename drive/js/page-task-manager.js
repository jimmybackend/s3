class ArcadeCloudPageTaskManager {
  constructor(win, doc) {
    this.window = win; this.document = doc; this.manager = win.ArcadeCloudWindowManager;
    this.startedAt = win.performance?.timeOrigin || Date.now(); this.timer = null;
  }
  init() {
    const root = this.document.querySelector('[data-page-monitor]');
    if (!root || !this.manager) return this;
    this.root = root;
    this.root.querySelector('[data-page-window-refresh]')?.addEventListener('click', () => this.update());
    this.root.querySelector('[data-page-window-list]')?.addEventListener('click', event => {
      const button = event.target.closest?.('[data-page-window-close]');
      if (!button) return;
      const id = String(button.dataset.pageWindowClose || '');
      if (!id) return;
      this.manager.requestClose(id).then(() => this.update());
    });
    this.update();
    this.timer = this.window.setInterval(() => this.update(), 2000);
    this.document.addEventListener('visibilitychange', () => { if (!this.document.hidden) this.update(); });
    this.document.addEventListener('arcadeos:window-opened', () => this.update());
    this.document.addEventListener('arcadeos:window-closed', () => this.update());
    this.window.ArcadeCloudPageTaskManager = this; return this;
  }
  update() {
    if (!this.root) return;
    const records = this.manager.openRecords ? this.manager.openRecords() : [];
    const open = records.filter(record => record.status === 'open').length;
    const minimized = records.filter(record => record.status === 'minimized').length;
    const taskCenter = this.window.BackgroundTaskCenter;
    this.set('[data-page-dom-nodes]', this.document.getElementsByTagName('*').length.toLocaleString());
    this.set('[data-page-windows]', String(records.length));
    this.set('[data-page-window-detail]', `${open} abiertas · ${minimized} minimizadas`);
    this.set('[data-page-tasks]', String(Number(taskCenter?.summary?.active || 0)));
    this.set('[data-page-resources]', String(this.window.performance?.getEntriesByType?.('resource')?.length || 0));
    this.set('[data-page-uptime]', this.duration(Math.max(0, Date.now() - this.startedAt)));
    this.updateWindowMonitor(records);
    this.updateMemory();
  }
  estimateWindowBytes(record) {
    const element = record?.element;
    if (!element) return 0;
    const nodes = element.getElementsByTagName('*').length + 1;
    const markupBytes = String(element.outerHTML || '').length * 2;
    const iframeBytes = element.querySelectorAll('iframe').length * 512 * 1024;
    const canvasBytes = [...element.querySelectorAll('canvas')].reduce((sum, canvas) => {
      const width = Math.max(0, Number(canvas.width) || 0);
      const height = Math.max(0, Number(canvas.height) || 0);
      return sum + Math.min(width * height * 4, 32 * 1024 * 1024);
    }, 0);
    return markupBytes + nodes * 384 + iframeBytes + canvasBytes;
  }
  updateWindowMonitor(records) {
    const chart = this.root.querySelector('[data-page-memory-chart]');
    const list = this.root.querySelector('[data-page-window-list]');
    if (!chart || !list) return;
    const rows = records.map(record => ({
      record,
      title: String(record.element?.dataset?.windowTitle || record.app || 'Ventana'),
      bytes: this.estimateWindowBytes(record)
    })).sort((a, b) => b.bytes - a.bytes);
    chart.replaceChildren();
    list.replaceChildren();
    if (!rows.length) {
      const empty = this.document.createElement('div');
      empty.className = 'os-page-monitor-note';
      empty.textContent = 'No hay ventanas abiertas.';
      list.append(empty);
      return;
    }
    const max = Math.max(1, ...rows.map(row => row.bytes));
    rows.forEach(row => {
      const bar = this.document.createElement('div');
      bar.className = 'os-page-memory-bar';
      const name = this.document.createElement('span'); name.className = 'os-page-memory-bar-name'; name.textContent = row.title;
      const track = this.document.createElement('span'); track.className = 'os-page-memory-track';
      const fill = this.document.createElement('span'); fill.className = 'os-page-memory-fill'; fill.style.width = Math.max(2, row.bytes * 100 / max) + '%'; track.append(fill);
      const value = this.document.createElement('span'); value.className = 'os-page-memory-value'; value.textContent = '≈ ' + this.bytes(row.bytes);
      bar.append(name, track, value); chart.append(bar);

      const item = this.document.createElement('div'); item.className = 'os-page-window-row';
      const info = this.document.createElement('div');
      const strong = this.document.createElement('strong'); strong.textContent = row.title;
      const small = this.document.createElement('small');
      small.textContent = (row.record.status === 'minimized' ? 'Minimizada' : 'Abierta') + ' · huella UI ≈ ' + this.bytes(row.bytes);
      info.append(strong, small);
      const state = this.document.createElement('span'); state.className = 'os-page-memory-value'; state.textContent = row.record.app;
      const close = this.document.createElement('button'); close.type = 'button'; close.dataset.pageWindowClose = row.record.id; close.innerHTML = '<i class="fas fa-xmark"></i> Cerrar';
      item.append(info, state, close); list.append(item);
    });
  }

  updateMemory() {
    const memory = this.window.performance?.memory;
    if (!memory || !Number.isFinite(Number(memory.usedJSHeapSize))) {
      this.set('[data-page-memory-used]', 'No disponible');
      this.set('[data-page-memory-detail]', 'Este navegador no expone performance.memory');
      this.set('[data-page-memory-note]', 'La gráfica por ventana es una estimación de huella de interfaz; el navegador no atribuye memoria exacta por ventana. El resto de métricas sigue siendo real.');
      return;
    }
    const used = Number(memory.usedJSHeapSize || 0), total = Number(memory.totalJSHeapSize || 0), limit = Number(memory.jsHeapSizeLimit || 0);
    this.set('[data-page-memory-used]', this.bytes(used));
    this.set('[data-page-memory-detail]', total > 0 ? `${this.bytes(used)} de ${this.bytes(total)} reservados` : this.bytes(used));
    this.set('[data-page-memory-note]', (limit > 0 ? `Límite de heap informado por el navegador: ${this.bytes(limit)}. ` : '') + 'La memoria JS es global para esta pestaña; la gráfica por ventana es una estimación de huella UI.');
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
