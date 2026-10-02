'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor() { this.children = []; this.dataset = {}; this.textContent = ''; this.classes = new Set(); this.classList = { add: x => this.classes.add(x), remove: x => this.classes.delete(x) }; }
  appendChild(child) { this.children.push(child); }
  set innerHTML(value) { this.children = []; }
  setAttribute() {}
  scrollIntoView() {}
}
const document = { getElementById() { return null; }, createElement() { return new Element(); } };
const window = { setTimeout() {} };
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../js/so-search.js'), 'utf8'), { window, document, Element, URLSearchParams, console });
const search = window.ArcadeCloudOsSearch;
(async () => {
  for (const mode of ['normal','ai']) {
    const entry = new Element(); entry.dataset.name = 'Informe.docx';
    const staleSelection = new Element();
    const calls = []; let ready = false; let focused = '';
    window.fetch = async (url, options) => {
      assert.equal(url, 'buscar_archivo.php');
      const body = new URLSearchParams(options.body);
      if (body.has('localizar_id')) {
        assert.equal(body.get('localizar_id'), '42');
        return { ok: true, json: async () => ({ estado: 'ok', archivo: { ruta: 'Data2/d_current/', pagina: 7 } }) };
      }
      assert.equal(body.get('modo'), mode);
      return { ok: true, text: async () => JSON.stringify({ estado: 'ok', modo: mode, resultados: [{ id: 42, nombre_real: 'Informe.docx', ruta: 'Data2/d_old/', ruta_visible: 'Mis datos/Contabilidad/', key: 'Data2/d_old/f_physical.docx' }] }) };
    };
    window.ArcadeCloudOsShell = { setFileSelected(item, selected) { calls.push([item, selected]); }, notify(message) { throw new Error(message); }, refreshExplorer() { throw new Error('Must not reuse the previous Explorer'); } };
    window.ArcadeCloudDesktop = {
      openExplorer(route, options) {
        assert.equal(route, 'Data2/d_current/');
        assert.equal(options.forceNew, true);
        assert.equal(options.page, 7);
        return { id: 'new-window', ready: Promise.resolve().then(() => { ready = true; }), win: {
          querySelector(selector) { assert(ready); assert.match(selector, /data-file-id="42"/); return entry; },
          querySelectorAll() { return [staleSelection]; }
        } };
      }, manager: { focus(id) { focused = id; } }
    };
    search.mode = mode; search.input = { value: 'Informe' }; search.results = new Element();
    await search.search();
    const button = search.results.children.find(x => x.dataset.osSearchOpen === '1');
    assert(button);
    assert.equal(button.children[1].children[1].textContent, 'Mis datos/Contabilidad/');
    assert.equal(button.dataset.route, 'Data2/d_old/');
    await search.openResult(button);
    assert.equal(focused, 'new-window');
    assert(calls.some(([item, selected]) => item === entry && selected));
    assert(calls.some(([item, selected]) => item === staleSelection && !selected));
    assert(!button.classes.has('is-opening'));
    console.log(`OK: ${mode} visible catalog path, physical relocation, page 7, new window, readiness and selection`);
  }
  search.renderResults({ resultados: [{ id: 42, nombre_real: 'Informe.docx', ruta: 'Data2/d_secret/', key: 'Data2/d_secret/f_key' }] });
  const fallback = search.results.children.find(x => x.dataset.osSearchOpen === '1');
  assert.equal(fallback.children[1].children[1].textContent, 'Ubicación no disponible');
  assert.equal(fallback.dataset.route, 'Data2/d_secret/');
  console.log('OK: missing visible catalog path never falls back to a physical prefix');
})().catch(error => { console.error(error); process.exitCode = 1; });
