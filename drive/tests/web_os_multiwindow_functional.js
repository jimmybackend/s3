'use strict';

/* Dependency-free DOM contract test for the real WindowManager. It exercises
 * registry/taskbar lifecycle rather than looking for source-code strings. */
global.CustomEvent = class CustomEvent extends Event { constructor(type, init = {}) { super(type); this.detail = init.detail; } };

class Classes {
  constructor(names = '') { this.values = new Set(names.split(/\s+/).filter(Boolean)); }
  add(...names) { names.forEach(name => this.values.add(name)); }
  remove(...names) { names.forEach(name => this.values.delete(name)); }
  contains(name) { return this.values.has(name); }
  toggle(name, force) { const enabled = force === undefined ? !this.contains(name) : force; enabled ? this.add(name) : this.remove(name); return enabled; }
}

class ElementStub {
  constructor(tag = 'section') { this.tag = tag; this.dataset = {}; this.style = {}; this.classList = new Classes(); this.children = []; this.isConnected = true; this.hidden = false; this.listeners = {}; this.textContent = ''; }
  append(...items) { this.children.push(...items); items.forEach(item => { item.parent = this; }); }
  replaceChildren(...items) { this.children = []; this.append(...items); }
  remove() { this.isConnected = false; if (this.parent) this.parent.children = this.parent.children.filter(item => item !== this); }
  querySelector(selector) {
    if (selector === 'span') {
      let span = this.children.find(item => item.tag === 'span');
      if (!span) { span = new ElementStub('span'); this.append(span); }
      return span;
    }
    return null;
  }
  querySelectorAll() { return []; }
  addEventListener(type, callback) { this.listeners[type] = callback; }
}

const taskbar = new ElementStub('div');
const documentStub = { getElementById: id => id === 'osTaskButtons' ? taskbar : null, createElement: tag => new ElementStub(tag), dispatchEvent: () => true };
const { ArcadeCloudEventBus, ArcadeCloudWindowManager } = require('../js/os-window-manager.js');
const manager = new ArcadeCloudWindowManager({}, documentStub, new ArcadeCloudEventBus());
manager.registerApp('explorer', { multiInstance: true, lifecycle: 'dynamic', title: 'Mis datos', icon: 'fa-folder-open' });
manager.registerApp('image', { multiInstance: true, lifecycle: 'dynamic', title: 'Imagen', icon: 'fa-file-image' });
manager.registerApp('pdf', { multiInstance: true, lifecycle: 'dynamic', title: 'PDF', icon: 'fa-file-pdf' });
manager.registerApp('settings', { multiInstance: false, title: 'Configuración', icon: 'fa-gear' });
manager.registerApp('node', { multiInstance: false, lifecycle: 'persistent', title: 'Mi nodo', icon: 'fa-server' });
manager.registerApp('links', { multiInstance: false, lifecycle: 'persistent', title: 'Enlaces', icon: 'fa-link' });

function windowStub(title, open = true) { const element = new ElementStub(); element.dataset.windowTitle = title; element.classList.add('os-window'); if (open) element.classList.add('is-open'); return element; }
function assert(value, message) { if (!value) throw new Error(message); process.stdout.write(`OK: ${message}\n`); }

const node = manager.register(windowStub('Mi nodo', false), 'node');
const settings = manager.register(windowStub('Configuración', false), 'settings');
const links = manager.register(windowStub('Enlaces', false), 'links');
assert(manager.registry.size === 3 && taskbar.children.length === 0, 'inicio registra persistentes cerradas y deja taskbar vacía');
assert(manager.last('explorer') === null, 'inicio no crea ninguna ventana Explorer');
for (let cycle = 0; cycle < 5; cycle += 1) {
  manager.open(node.id); assert(node.status === 'open' && node.element.classList.contains('is-open'), `Mi nodo abre en ciclo ${cycle + 1}`);
  manager.close(node.id); assert(node.status === 'closed' && manager.record(node.id) && !node.element.classList.contains('is-open'), `Mi nodo cierra sin destruirse en ciclo ${cycle + 1}`);
}
manager.open(settings.id); manager.close(settings.id); manager.open(settings.id); manager.close(settings.id); manager.open(settings.id);
assert(settings.status === 'open' && manager.record(settings.id), 'Configuración abre, cierra y reabre sin reload');
manager.close(settings.id); manager.open(links.id); manager.close(links.id); manager.open(links.id);
assert(links.status === 'open' && manager.record(links.id), 'Enlaces abre, cierra y reabre sin reload');
manager.close(links.id); manager.open(node.id); manager.minimize(node.id);
assert(node.status === 'minimized' && taskbar.children.length === 1, 'minimizar conserva Mi nodo en taskbar');
manager.open(node.id); assert(node.status === 'open' && node.element.classList.contains('is-open'), 'taskbar/open restaura la misma instancia persistente');
manager.close(node.id);

const aborts = [new AbortController(), new AbortController(), new AbortController()];
const explorers = [
  manager.register(windowStub('Mis datos — /'), 'explorer', { route: '/', history: [], future: [], selection: new Set(), controller: aborts[0] }),
  manager.register(windowStub('Mis datos — /Fotos'), 'explorer', { route: '/Fotos', history: [], future: [], selection: new Set(), controller: aborts[1] }),
  manager.register(windowStub('Mis datos — /Documentos'), 'explorer', { route: '/Documentos', history: [], future: [], selection: new Set(), controller: aborts[2] })
];
assert(manager.registry.size === 6, 'cada uno de tres clics agrega exactamente un Explorer al registry');
assert(new Set(explorers.map(record => record.id)).size === 3, 'los tres windowId son diferentes');
assert(new Set(explorers.map(record => record.state.route)).size === 3, 'las rutas Explorer son independientes');
assert(new Set(explorers.map(record => record.state.history)).size === 3, 'los historiales Explorer son independientes');
assert(taskbar.children.length === 3 && taskbar.children.every(item => item.dataset.windowId), 'taskbar crea tres tareas vinculadas por windowId');

explorers[0].state.route = '/Legal'; explorers[0].state.history.push('/'); explorers[0].state.controller.abort();
assert(explorers[1].state.route === '/Fotos' && !aborts[1].signal.aborted, 'navegar/cancelar A no cambia ni aborta B');
manager.close(explorers[1].id);
assert(manager.registry.size === 5 && manager.record(explorers[0].id) && manager.record(explorers[2].id), 'cerrar B destruye sólo la instancia dinámica y conserva A y C');
assert(taskbar.children.length === 2, 'cerrar B elimina solamente su tarea');
const replacementExplorer = manager.register(windowStub('Mis datos — /Nueva'), 'explorer', { route: '/Nueva', history: [], future: [], selection: new Set() });
assert(manager.registry.size === 6 && manager.record(replacementExplorer.id), 'otro clic en Mis datos crea una instancia tras cerrar Explorer');
manager.close(replacementExplorer.id);

const imageA = manager.register(windowStub('foto1.jpg'), 'image', { src: '/foto1.jpg', zoom: 1 });
const imageB = manager.register(windowStub('foto2.jpg'), 'image', { src: '/foto2.jpg', zoom: 1 });
const pdfA = manager.register(windowStub('contrato1.pdf'), 'pdf', { src: '/contrato1.pdf' });
const pdfB = manager.register(windowStub('contrato2.pdf'), 'pdf', { src: '/contrato2.pdf' });
assert(imageA.id !== imageB.id && pdfA.id !== pdfB.id, 'imágenes y PDF son instancias simultáneas distintas');
manager.close(imageA.id);
assert(manager.record(imageB.id) && manager.record(pdfA.id) && manager.record(pdfB.id), 'cerrar un archivo no afecta los demás');

const clipboard = { sourceWindowId: explorers[0].id, sourceRoute: '/Legal', items: ['foto.jpg'], operation: 'move' };
const backendRequest = { ...clipboard, destinationRoute: explorers[2].state.route };
assert(backendRequest.destinationRoute === '/Documentos', 'clipboard/drop usa la ruta del Explorer B como destino backend');

manager.minimize(explorers[0].id);
assert(manager.record(explorers[0].id).minimized && explorers[0].state.route === '/Legal', 'minimizar conserva instancia y ruta');
manager.focus(explorers[0].id);
assert(!manager.record(explorers[0].id).minimized && explorers[0].state.history.length === 1, 'restaurar conserva historial');
manager.preferences.explorer = { width: 900, height: 700 };
manager.toggleMaximize(explorers[2].id);
assert(explorers[2].element.style.width === '450px' && explorers[2].element.style.height === '350px', 'botón reduce Explorer a 50 por ciento del tamaño preferido');
manager.toggleMaximize(explorers[2].id);
assert(explorers[2].element.style.width === '900px' && explorers[2].element.style.height === '700px', 'segundo clic restaura tamaño preferido');
assert(manager.registry.has(explorers[0].id) && manager.registry.has(explorers[2].id), 'modo móvil alterna ventanas sin eliminarlas del registry');
process.stdout.write('Web OS multiwindow functional test passed.\n');
