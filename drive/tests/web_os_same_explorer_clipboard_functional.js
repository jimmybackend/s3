'use strict';

const { ArcadeCloudOsClipboard } = require('../js/so-clipboard.js');
const { ArcadeCloudFilesystemOperations } = require('../js/filesystem-operations.js');

function assert(value, message) {
  if (!value) throw new Error(message);
  process.stdout.write(`OK: ${message}\n`);
}

function storage() {
  const values = new Map();
  return {
    getItem: key => values.get(key) || null,
    setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key)
  };
}

const requests = [];
const refreshed = [];
const filesystemEvents = [];
const explorer = { route: '/Documentos', page: 1, navigate: async (...args) => refreshed.push(args) };
const win = {
  sessionStorage: storage(),
  DriveMoveTasks: { start: async payload => { requests.push(payload); return { job_id: String(requests.length) }; } },
  ArcadeCloudDesktop: {
    explorers: new Map([['explorer-a', explorer], ['explorer-b', explorer]]),
    emitFilesystemChanged: detail => filesystemEvents.push(detail)
  },
  setTimeout
};
const doc = { querySelectorAll: () => [] };
win.ArcadeCloudFilesystemOperations = new ArcadeCloudFilesystemOperations(win, doc);
const clipboard = new ArcadeCloudOsClipboard(win, doc);
clipboard.notify = () => {};
clipboard.showTransfer = () => {};
clipboard.hideTransferSoon = () => {};
clipboard.currentRoute = () => explorer.route;

const entries = ['foto1.jpg', 'foto2.jpg', 'foto3.jpg'].map(name => ({
  dataset: { key: `/Fotos/${name}`, name, locked: '0' },
  classList: { contains: value => value === 'is-selected' },
  closest: selector => selector === '.os-window' ? { dataset: { windowId: 'explorer-a' } } : { dataset: { explorerRoute: '/Fotos' } }
}));

clipboard.captureFiles(entries[0], 'copy', {
  sourceWindowId: 'explorer-a', sourceRoute: '/Fotos', entries
});
assert(clipboard.clipboard.sourceRoute === '/Fotos' && clipboard.clipboard.items.length === 3, 'copy guarda ruta de origen y multiselección');

explorer.route = '/Documentos';
assert(clipboard.clipboard.sourceRoute === '/Fotos', 'navegar la misma Explorer no modifica el clipboard');
clipboard.paste('/Documentos', { destinationWindowId: 'explorer-a' }).then(async () => {
  assert(requests[0].ruta_actual === '/Fotos' && requests[0].nueva_ruta === '/Documentos', 'copy A:/Fotos -> A:/Documentos usa rutas distintas');
  assert(JSON.parse(requests[0].archivos_json).length === 3, 'copy envía los tres archivos seleccionados');
  await clipboard.onTransferCompleted({ job_id: '1', operation: 'copy' });
  assert(clipboard.clipboard?.mode === 'copy' && refreshed.length === 0, 'copy conserva clipboard sin refresco imperativo por windowId');
  assert(filesystemEvents[0].sourceRoute === '/Fotos' && filesystemEvents[0].destinationRoute === '/Documentos', 'copy publica filesystem:changed con ambas rutas');

  clipboard.captureFiles(entries[0], 'move', { sourceWindowId: 'explorer-a', sourceRoute: '/Fotos', entries });
  await clipboard.paste('/Legal', { destinationWindowId: 'explorer-a' });
  assert(requests[1].operation === 'move' && requests[1].ruta_actual === '/Fotos', 'cut A:/Fotos -> A:/Legal conserva origen');
  await clipboard.onTransferCompleted({ job_id: '2', operation: 'move' });
  assert(clipboard.clipboard === null, 'cut limpia clipboard después del movimiento exitoso');
  assert(filesystemEvents[1].operation === 'move' && filesystemEvents[1].items.length === 3, 'move publica operación e items para todas las Explorers afectadas');

  clipboard.captureFiles(entries[0], 'copy', { sourceWindowId: 'explorer-a', sourceRoute: '/Fotos', entries });
  await clipboard.paste('/Temporal', { destinationWindowId: 'explorer-b' });
  assert(requests[2].nueva_ruta === '/Temporal', 'copy A -> B continúa funcionando');
  process.stdout.write('Web OS same Explorer clipboard functional test passed.\n');
}).catch(error => { console.error(error); process.exitCode = 1; });
