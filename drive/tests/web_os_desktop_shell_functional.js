'use strict';
const { ArcadeCloudDesktopShell } = require('../js/desktop-shell.js');
function assert(value, message) { if (!value) throw new Error(message); process.stdout.write(`OK: ${message}\n`); }
const editable = selector => ({ closest: query => query.includes(selector) ? {} : null });
assert(ArcadeCloudDesktopShell.isEditable(editable('input')), 'input conserva shortcuts nativos');
assert(ArcadeCloudDesktopShell.isEditable(editable('textarea')), 'textarea conserva shortcuts nativos');
assert(ArcadeCloudDesktopShell.isEditable(editable('[contenteditable="true"]')), 'contenteditable conserva shortcuts nativos');
assert(ArcadeCloudDesktopShell.isEditable(editable('.terminal')), 'Terminal conserva shortcuts nativos');
assert(!ArcadeCloudDesktopShell.isEditable({ closest: () => null }), 'fondo y Explorer permiten shortcuts de shell');

const records = [
  { id: 'old', lastFocused: 10, status: 'open' },
  { id: 'minimized', lastFocused: 20, status: 'minimized' },
  { id: 'recent', lastFocused: 30, status: 'open' }
];
const instance = Object.create(ArcadeCloudDesktopShell.prototype);
instance.manager = { openRecords: () => records };
assert(instance.mruRecords().map(record => record.id).join(',') === 'recent,minimized,old', 'MRU incluye minimizadas y ordena por foco reciente');
records.splice(records.findIndex(record => record.id === 'old'), 1);
assert(!instance.mruRecords().some(record => record.id === 'old'), 'ventanas cerradas/retiradas no aparecen en switcher');

const registered = new Map(); let remoteLaunches = 0;
const declarative = {
  dataset: { launcherApp: 'guacamole' },
  getAttribute: name => ({ href: 'office-launch.php?target=guacamole', target: '_blank' }[name] || null),
  querySelector: selector => selector === 'strong' ? { textContent: 'Guacamole' } : { classList: ['fas', 'fa-headset'] }
};
const launcher = Object.create(ArcadeCloudDesktopShell.prototype);
launcher.window = { open: () => { remoteLaunches += 1; } };
launcher.document = { getElementById: () => null };
launcher.manager = { apps: registered, registerApp: (id, definition) => registered.set(id, definition) };
launcher.registerDeclarativeApplications({ querySelectorAll: () => [declarative] });
assert(remoteLaunches === 0, 'registrar y renderizar una aplicación remota no inicia su recurso');
assert(registered.get('guacamole').lifecycle === 'external' && registered.get('guacamole').launchable, 'aplicación remota usa el Application Registry como external lanzable');
const remoteApps = launcher.launchableApplications();
assert(remoteApps.filter(app => app.title.toLocaleLowerCase().includes('guac')).map(app => app.title).join(',') === 'Guacamole', 'búsqueda local encuentra Guacamole desde el registro');
registered.get('guacamole').launch();
assert(remoteLaunches === 1, 'recurso remoto se inicia sólo tras la acción explícita de launch');
process.stdout.write('Web OS desktop shell functional passed.\n');
