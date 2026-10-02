'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

async function exercise(script, trigger, expectedEndpoint, expectedType) {
  const handlers = new Map(); const elements = new Map(); const requests = [];
  const document = {
    getElementById: id => elements.get(id) || null,
    querySelector: () => null, querySelectorAll: () => [],
    addEventListener(type, callback) { handlers.set(type, [...(handlers.get(type) || []), callback]); },
    dispatchEvent() {}, documentElement: { dataset: {} },
  };
  const window = { location: { href: 'https://fixture.invalid/drive/s3.php' }, DRIVE_UPLOAD_CSRF: 'fixture-token',
    addEventListener() {}, setTimeout, clearTimeout };
  const fetch = async (url, options) => {
    requests.push({ url, options });
    // Stop after capturing the request; avoid unrelated UI refresh behavior.
    return { ok: false, status: 403, text: async () => JSON.stringify({ ok: false, error: 'fixture rejection' }) };
  };
  class FixtureFormData extends Map { constructor(form) { super(form?.fields || []); } append(key, value) { this.set(key, value); } }
  const context = { window, document, fetch, Headers, URL, URLSearchParams, FormData: FixtureFormData,
    console: { log() {}, error() {}, warn() {} }, alert() {}, confirm: () => true, setTimeout, clearTimeout,
    Event, CustomEvent: class extends Event {}, Element: class {}, HTMLElement: class {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/', script), 'utf8'), context);
  trigger(handlers, elements);
  await new Promise(resolve => setImmediate(resolve));
  const request = requests.find(r => new URL(r.url, window.location.href).pathname.endsWith('/' + expectedEndpoint));
  assert(request, `${script} did not issue ${expectedEndpoint}`);
  const headers = new Headers(request.options.headers);
  assert.equal(headers.get('X-Drive-CSRF'), 'fixture-token');
  assert.equal(headers.get('Content-Type'), expectedType);
  assert.equal(request.options.credentials, 'same-origin');
  console.log(`OK: ${script} real handler supplies CSRF and preserves body headers`);
}
(async () => {
  await exercise('carpetas.js', (handlers) => {
    const form = { id: 'formCrearCarpeta', fields: [['nueva','Informe'],['ruta','Data2/d_physical/']], querySelector: () => null };
    handlers.get('submit').forEach(callback => callback({ target: form, preventDefault() {} }));
  }, 'crear_carpeta.php', null);
  await exercise('archivos.js', (handlers) => {
    const button = { dataset: { archivo: 'Data2/f_physical' }, getAttribute: () => 'Data2/f_physical',
      closest: () => null, innerHTML: '', disabled: false };
    const target = { closest: selector => selector === '.js-delete-one' ? button : null };
    handlers.get('click').forEach(callback => callback({ target, preventDefault() {}, stopPropagation() {}, stopImmediatePropagation() {} }));
  }, 'eliminar_archivo.php', 'application/x-www-form-urlencoded; charset=UTF-8');
})().catch(error => { console.error(error); process.exitCode = 1; });
