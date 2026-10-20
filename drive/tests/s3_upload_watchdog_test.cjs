'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/upload-center.js'), 'utf8');
const from = source.indexOf('  putToS3(url, blob, onProgress, multipart = false, taskId = '') {');
const to = source.indexOf('  chooseChunkSize(file)', from);
assert.ok(from !== -1 && to > from, 'S3 upload methods must exist');

function harness() {
  let now = 0;
  let interval;
  let request;
  class XHR {
    constructor() { request = this; this.upload = {}; this.status = 200; this.aborted = false; }
    open() {}
    setRequestHeader() {}
    send() {}
    abort() { this.aborted = true; if (this.onabort) this.onabort(); }
    getResponseHeader(name) { return name.toLowerCase() === 'etag' ? '"etag-ok"' : null; }
    progress(bytes, total = this.size || 2 * 1024 * 1024) {
      if (this.upload.onprogress) this.upload.onprogress({loaded: bytes, total, lengthComputable: true});
    }
  }
  const ctx = vm.createContext({
    XMLHttpRequest: XHR,
    Date: { now: () => now },
    setInterval: (callback) => { interval = callback; return 1; },
    clearInterval: () => { interval = null; }
  });
  const UploadMethods = vm.runInContext('(class UploadMethods {' + source.slice(from, to) + '})', ctx);
  const obj = new UploadMethods();
  obj.activeTransfers = new Map();
  obj.window = { location: { origin: 'https://drive.example' } };
  return { obj, request: () => request, tick: (ms) => { now += ms; if (interval) interval(); } };
}

(async () => {
  {
    const h = harness();
    const result = h.obj.putBlob('https://s3.example', { size: 2 * 1024 * 1024 }, () => {});
    h.request().progress(0);
    h.tick(45000);
    h.request().progress(0);
    h.tick(46000);
    await assert.rejects(result, /90 segundos sin avance/);
    assert.equal(h.request().aborted, true);
  }
  {
    const h = harness();
    const result = h.obj.putBlob('https://s3.example', { size: 2 * 1024 * 1024 }, () => {});
    h.tick(45000);
    h.request().progress(100 * 1024);
    h.tick(46000);
    await assert.rejects(result, /demasiado lenta/);
    assert.equal(h.request().aborted, true);
  }
  {
    const h = harness();
    const result = h.obj.putPart('https://s3.example', { size: 8 * 1024 * 1024 }, () => {});
    h.tick(45000);
    h.request().progress(4 * 1024 * 1024);
    h.tick(46000);
    h.request().status = 200;
    h.request().onload();
    assert.equal(await result, 'etag-ok');
    assert.equal(h.request().aborted, false);
  }
  {
    const h = harness();
    const result = h.obj.putPart('https://s3.example', { size: 8 * 1024 * 1024 }, () => {});
    h.tick(91000);
    await assert.rejects(result, /90 segundos sin avance/);
    assert.equal(h.request().aborted, true);
  }
  {
    const h = harness();
    const result = h.obj.putBlob('https://s3.example', { size: 2 * 1024 * 1024 }, () => {}, 'upload:test');
    assert.equal(h.obj.activeTransfers.has('upload:test'), true);
    h.obj.activeTransfers.get('upload:test').abort();
    await assert.rejects(result, /interrumpió/);
    assert.equal(h.obj.activeTransfers.size, 0);
  }
  process.stdout.write('S3 watchdog: 5 tests passed\\n');
})().catch(e => { console.error(e); process.exitCode = 1; });
