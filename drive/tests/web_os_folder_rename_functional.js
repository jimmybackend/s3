'use strict';
const assert = require('node:assert/strict');
global.CustomEvent = class extends Event { constructor(type, options = {}) { super(type); this.detail = options.detail; } };
global.MutationObserver = class { observe() {} };
const { ArcadeCloudDesktopRuntime } = require('../js/os-window-manager.js');
const listeners = new Map();
const document = {
  getElementById() { return null; }, querySelectorAll() { return []; },
  addEventListener(type, callback) { listeners.set(type, callback); }, body: {},
};
const window = { ARCADECLOUD_OS_ROOT_ROUTE: 'Data2/', innerWidth: 1200, innerHeight: 800,
  location: { origin: 'https://arcadecloud.test' },
  addEventListener() {},
};
const desktop = new ArcadeCloudDesktopRuntime(window, document).init();
const navigations = [];
for (const [id, route, page] of [
  ['parent', 'Data2/', 2], ['renamed', 'Data2/d_opaque/', 7],
  ['child', 'Data2/d_opaque/d_child/', 3], ['other', 'Data2/d_opaque_other/', 4],
]) {
  desktop.explorers.set(id, { route, page, normalize: value => String(value).replace(/\/*$/, '/'),
    navigate(target, options) { navigations.push({ id, target, options }); },
  });
}
listeners.get('drive:folder-mutated')({ detail: { kind: 'rename', route: 'Data2/d_opaque/', name: 'Contabilidad <2026>' } });
assert.deepEqual(navigations.map(n => [n.id, n.target, n.options.page]).sort(), [
  ['child', 'Data2/d_opaque/d_child/', 3], ['parent', 'Data2/', 2], ['renamed', 'Data2/d_opaque/', 7],
]);
assert(navigations.every(n => n.options.replace === true && !n.target.includes('Contabilidad')));
console.log('OK: rename refreshes parent, folder and descendant breadcrumbs at unchanged physical routes/pages; sibling unaffected');
navigations.length = 0;
listeners.get('drive:folder-mutated')({ detail: { kind: 'delete', route: 'Data2/d_opaque/' } });
assert.deepEqual(navigations.filter(n => n.id !== 'parent').map(n => [n.id, n.target, n.options.page]).sort(), [
  ['child', 'Data2/', 1], ['renamed', 'Data2/', 1],
]);
console.log('OK: deleted subtree windows leave the removed physical prefix');
