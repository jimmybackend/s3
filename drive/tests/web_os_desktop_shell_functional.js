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
process.stdout.write('Web OS desktop shell functional passed.\n');
