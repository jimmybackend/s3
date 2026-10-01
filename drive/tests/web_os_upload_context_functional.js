'use strict';

const { ArcadeCloudUploadCenter } = require('../js/upload-center.js');
function assert(value, message) { if (!value) throw new Error(message); process.stdout.write(`OK: ${message}\n`); }

const routeLabel = { textContent: '' };
const doc = { body: { classList: { add() {}, remove() {} } }, getElementById: () => null, dispatchEvent() {} };
const center = new ArcadeCloudUploadCenter({ DRIVE_UPLOAD_CSRF: 'test', setTimeout, clearTimeout }, doc);
center.modal = { hidden: true, querySelector: selector => selector === '[data-upload-route]' ? routeLabel : null };
center.ensureUi = () => center.modal;
center.inspectClipboard = async () => {};
center.setPanelStatus = () => {};

function button(windowId, route) {
  const live = { dataset: { explorerRoute: route } };
  const owner = { dataset: { windowId }, querySelector: () => live };
  return { closest(selector) {
    if (selector === '.os-explorer-live') return live;
    if (selector === '.os-explorer-window' || selector === '.os-explorer-window, .os-window') return owner;
    return null;
  } };
}

(async () => {
  await center.open(center.contextForButton(button('explorer-b', 'Data/Documentos')));
  const taskB = center.createTask('prueba.txt', center.openRoute, 'Archivo', 4, 'local_put');
  assert(center.openContext.sourceWindowId === 'explorer-b', 'Subir conserva la identidad de Explorer B');
  assert(taskB.metadata.destination === 'Data/Documentos/', 'archivo local fija Data/Documentos al crear su tarea');

  await center.open(center.contextForButton(button('explorer-a', 'Data/Fotos/')));
  const taskA = center.createTask('foto.jpg', center.openRoute, 'Dropzone', 8, 'local_put');
  assert(taskA.metadata.destination === 'Data/Fotos/', 'Dropzone abierto desde A usa Data/Fotos');
  assert(taskB.metadata.destination === 'Data/Documentos/', 'abrir otro Explorer no cambia una tarea iniciada');

  const tasks = [
    center.createTask('informe.pdf', 'Data/Trabajo/', 'Multipart', 16, 'chunked'),
    center.createTask('url.bin', 'Data/Trabajo/', 'Enlace', 0, 'remote_url'),
    center.createTask('clipboard.txt', 'Data/Trabajo/', 'Portapapeles', 3, 'local_put')
  ];
  assert(tasks.every(task => task.metadata.destination === 'Data/Trabajo/'), 'multipart, URL y portapapeles conservan el destino por tarea');
  assert(routeLabel.textContent === 'Destino fijado: Data/Fotos/', 'el centro muestra el destino capturado');
  process.stdout.write('Web OS upload context functional test passed.\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
