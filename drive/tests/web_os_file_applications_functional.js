'use strict';
global.CustomEvent = class CustomEvent extends Event { constructor(type, init = {}) { super(type); this.detail = init.detail; } };
const { ArcadeCloudFileApplicationService } = require('../js/file-applications.js');
function assert(value, message) { if (!value) throw new Error(message); process.stdout.write(`OK: ${message}\n`); }
class Bus extends EventTarget { on(type, fn) { this.addEventListener(type, fn); return () => this.removeEventListener(type, fn); } emit(type, detail) { this.dispatchEvent(new CustomEvent(type, { detail })); } }
const apps = new Map(); const records = new Map(); let counter = 0; let focused = '';
const manager = {
  apps,
  registerApp(id, definition) { apps.set(id, { ...definition }); },
  record(id) { return records.get(id); },
  open(id) { focused = id; return records.get(id); },
  setTitle(id, title) { records.get(id).title = title; },
  addCleanup(id, fn) { records.get(id).cleanup.push(fn); }
};
const runtime = { manager, bus: new Bus(), bindWindowChrome() {} };
const service = new ArcadeCloudFileApplicationService({ open() {} }, {}, runtime);
service.registerApplications();
service.createInstance = function (application, file, identity) {
  const id = `${application.appId}-${++counter}`;
  const record = { id, app: application.appId, state: { file: { ...file }, application: application.appId }, title: file.name, cleanup: [], element: { querySelector() { return { textContent: '' }; } } };
  records.set(id, record); this.instances.set(identity, id);
  const unsubscribe = runtime.bus.on('filesystem:changed', event => this.onFilesystemChanged(id, event.detail));
  record.cleanup.push(() => { unsubscribe(); this.instances.delete(identity); records.delete(id); });
  focused = id; return record;
};
service.showError = (record, message) => { record.error = message; };

const image1 = { fileId: '17', name: 'foto1.jpg', key: 'Data/foto1.jpg', mime: 'image/jpeg', extension: 'jpg', openUrl: '/view/1', downloadUrl: '/download/1' };
const image2 = { fileId: '18', name: 'foto2.jpg', key: 'Data/foto2.jpg', mime: 'image/jpeg', extension: 'jpg', openUrl: '/view/2' };
const pdf1 = { fileId: '21', name: 'contrato.pdf', key: 'Data/contrato.pdf', mime: 'application/pdf', extension: 'pdf', openUrl: '/view/21' };
const pdf2 = { fileId: '22', name: 'factura.pdf', key: 'Data/factura.pdf', mime: 'application/pdf', extension: 'pdf', openUrl: '/view/22' };
assert(apps.get('image').multiInstance && apps.get('video').supportedTypes.includes('video/*'), 'registro declara aplicaciones reales, asociaciones y multiinstancia');
assert(service.defaultApplication({ ...image1, extension: 'misleading.txt' }).appId === 'image', 'MIME confiable tiene prioridad sobre extensión');
assert(service.applicationsFor({ ...image1, mime: 'application/pdf' }).every(app => app.appId === 'pdf'), 'MIME confiable contradictorio no habilita la extensión');
assert(service.defaultApplication({ ...pdf1, mime: '' }).appId === 'pdf', 'extensión resuelve como fallback cuando MIME no existe');
assert(service.applicationsFor({ ...image1, locked: true }).length === 0, 'archivo bloqueado no se ofrece a aplicaciones');
const i1 = service.openFile(image1), i2 = service.openFile(image2), p1 = service.openFile(pdf1), p2 = service.openFile(pdf2);
assert(new Set([i1.id, i2.id, p1.id, p2.id]).size === 4, 'imágenes y PDF diferentes crean ventanas independientes');
assert(service.openFile(image1).id === i1.id && focused === i1.id, 'reabrir mismo FileId y aplicación enfoca la instancia existente');
assert(service.openFile(image1, { forceNew: true }).id !== i1.id, 'forceNew conserva la posibilidad explícita de otra instancia');
const audio = service.openFile({ fileId: '31', name: 'audio.mp3', key: 'Data/audio.mp3', mime: 'audio/mpeg', extension: 'mp3', openUrl: '/a' });
const video = service.openFile({ fileId: '32', name: 'video.mp4', key: 'Data/video.mp4', mime: 'video/mp4', extension: 'mp4', openUrl: '/v' });
const text = service.openFile({ fileId: '33', name: 'notas.txt', key: 'Data/notas.txt', mime: 'text/plain', extension: 'txt', openUrl: '/t', editUrl: '/e' });
assert(new Set([audio.app, video.app, text.app]).size === 3, 'mezcla audio, video y texto mantiene aplicaciones independientes');
assert(service.applicationsFor(text.state.file).map(app => app.appId).join(',') === 'text,text-preview', 'Abrir con sólo resuelve editor y visor de texto reales compatibles');
runtime.bus.emit('filesystem:changed', { operation: 'rename', fileId: '18', name: 'vacaciones.jpg', items: [] });
assert(i2.state.file.name === 'vacaciones.jpg' && i2.title === 'vacaciones.jpg', 'rename por FileId actualiza metadata y título');
runtime.bus.emit('filesystem:changed', { operation: 'delete', items: ['Data/contrato.pdf'] });
assert(p1.error === 'Este archivo ya no está disponible.', 'delete por key muestra indisponibilidad dentro de la ventana');
const oldName = i1.state.file.name; i1.cleanup.forEach(fn => fn());
runtime.bus.emit('filesystem:changed', { operation: 'rename', fileId: '17', name: 'no-debe-cambiar.jpg' });
assert(i1.state.file.name === oldName && !service.instances.has('image:id:17'), 'cleanup elimina suscripción e identidad de instancia');
process.stdout.write('Web OS file applications functional test passed.\n');
