'use strict';

global.CustomEvent = class CustomEvent extends Event { constructor(type, init = {}) { super(type); this.detail = init.detail; } };
const { ArcadeCloudFilesystemOperations } = require('../js/filesystem-operations.js');

function assert(value, message) {
  if (!value) throw new Error(message);
  process.stdout.write(`OK: ${message}\n`);
}

const events = [];
const busEvents = [];
const doc = new EventTarget();
const win = {
  ArcadeCloudDesktop: { emitFilesystemChanged: detail => busEvents.push(detail) },
  DriveMoveTasks: { start: async payload => ({ ok: true, job_id: 'job-1', payload }) }
};
doc.addEventListener('filesystem:operation', event => events.push(event.detail));
const service = new ArcadeCloudFilesystemOperations(win, doc);

(async () => {
  let calls = 0;
  let release;
  const executor = () => { calls++; return new Promise(resolve => { release = resolve; }); };
  const spec = { type: 'delete', items: ['a', 'b'], sourceRoute: '/Fotos', sourceWindowId: 'a' };
  const first = service.run(spec, executor);
  const second = service.run(spec, executor);
  assert(calls === 1, 'la protección de doble submit ejecuta una sola petición lógica');
  release({ ok: true, total: 2 });
  await Promise.all([first, second]);
  assert(busEvents.length === 1 && busEvents[0].sourceRoute === '/Fotos', 'el EventBus se publica una vez y sólo después del éxito');
  assert(events.some(item => item.status === 'completed'), 'la operación rápida recorre el ciclo running/completed');

  const beforeFailure = busEvents.length;
  try {
    await service.run({ type: 'rename', items: ['a'], sourceRoute: '/Fotos' }, async () => { throw new Error('conflicto'); });
  } catch (_) {}
  assert(busEvents.length === beforeFailure, 'un fallo backend no publica filesystem:changed');
  assert(Array.from(service.operations.values()).some(item => item.status === 'failed' && item.error === 'conflicto'), 'el diagnóstico seguro queda asociado a la operación fallida');

  const transfer = await service.startTransfer({
    type: 'copy', items: ['a'], sourceRoute: '/Fotos', destinationRoute: '/Documentos', progress: null
  }, { operation: 'copy' });
  assert(transfer.operation.status === 'running' && transfer.operation.progress === null, 'una copia larga usa progreso indeterminado sin inventar porcentaje');
  doc.dispatchEvent(new CustomEvent('drive:move-task-progress', { detail: { job_id: 'job-1', progress: 50 } }));
  assert(transfer.operation.progress === 50, 'la tarea muestra porcentaje solamente cuando el backend lo entrega');
  doc.dispatchEvent(new CustomEvent('drive:move-task-completed', { detail: { job_id: 'job-1', progress: 100 } }));
  assert(transfer.operation.status === 'completed', 'la tarea termina con estado normalizado completed');

  win.DRIVE_UPLOAD_CSRF = 'fixture-token';
  win.fetch = async (endpoint, options) => {
    assert(endpoint === 'delete_multiple.php' && options.headers['X-Drive-CSRF'] === 'fixture-token', 'operación OS envía CSRF existente');
    assert(options.headers['Content-Type'].includes('application/x-www-form-urlencoded') && options.credentials === 'same-origin', 'CSRF conserva Content-Type y credenciales');
    return { ok: true, text: async () => JSON.stringify({ ok: true }) };
  };
  await service.request('delete_multiple.php', { archivos_json: '["Data2/f_physical"]' });
  process.stdout.write('Web OS filesystem operation functional test passed.\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
