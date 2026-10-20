'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { ArcadeCloudFolderRecorder } = require('../js/so-folder-recorder.js');

const so = fs.readFileSync(path.join(__dirname, '..', 'so.php'), 'utf8');
const folders = fs.readFileSync(path.join(__dirname, '..', 'js', 'so-folders.js'), 'utf8');
const native = fs.readFileSync(path.join(__dirname, '..', '..', 'android-app', 'app', 'src', 'main', 'java', 'com', 'arcadecloud', 'drive', 'MainActivity.java'), 'utf8');
const upload = fs.readFileSync(path.join(__dirname, '..', 'js', 'upload-center.js'), 'utf8');

test('capture is available from real folder entries and active folder toolbar', () => {
  for (const name of ['record-audio', 'record-video']) {
    assert.match(so, new RegExp('data-folder-action="' + name + '"'));
    assert.match(so, new RegExp('data-current-folder-action="' + name + '"'));
    assert.match(folders, new RegExp("action\\('" + name + "'\\)"));
  }
  assert.match(so, /so-folder-recorder\.js/);
  assert.match(so, /os-folder-recorder\.css/);
});

test('Android native floating overlay and old broken URL are gone', () => {
  assert.doesNotMatch(native, /Button tools = new Button/);
  assert.doesNotMatch(native, /mobile_capture\.php/);
  assert.match(native, /onPermissionRequest\(PermissionRequest request\)/);
  assert.match(native, /android\.Manifest/);
});

test('recorded media uses existing presigned direct S3 upload paths', () => {
  assert.match(upload, /enqueueLocal\(blob, route, source/);
  assert.match(upload, /startMultipart\(file, requestedRoute\)/);
  assert.match(upload, /runLocal\(task, blob, name, route\)/);
  assert.match(upload, /putBlob\(String\(initJson\.url\), blob/);
  const body = fs.readFileSync(path.join(__dirname, '..', 'js', 'so-folder-recorder.js'), 'utf8');
  assert.match(body, /upload\.enqueueLocal\(file, route/);
  assert.match(body, /upload\.startMultipart\(file, route\)/);
  assert.doesNotMatch(body, /mobile-capture-upload\.php/);
  assert.doesNotMatch(body, /new URLSearchParams\(\{.*ruta_actual/);
});

test('save dispatches audio to the original folder and does not claim completion', () => {
  let received = null;
  const messages = [];
  const win = {
    ArcadeCloudUploadManager: {
      enqueueLocal(file, route, source) {
        received = { file, route, source };
        return 'task-one';
      },
      startMultipart() { throw new Error('not expected'); }
    },
    ArcadeCloudOsShell: { notify: (text) => messages.push(text) }
  };
  const recorder = new ArcadeCloudFolderRecorder(win, {});
  recorder.route = 'Data2/2026/';
  recorder.folderName = '2026';
  recorder.mode = 'audio';
  recorder.mediaBlob = new Blob(['voice'], { type: 'audio/webm' });
  recorder.close = () => {};
  recorder.save();
  assert.equal(received.route, 'Data2/2026/');
  assert.equal(received.source, 'Nota de voz');
  assert.match(received.file.name, /^Nota_voz_.*\.webm$/);
  assert.ok(messages.some(msg => msg.includes('enviado a Tareas')));
  assert.ok(messages.every(msg => !msg.includes('Guardado correctamente')));
});

test('save dispatches large video to multipart in the frozen folder', async () => {
  let selected = '';
  let fileType = '';
  const win = {
    ArcadeCloudUploadManager: {
      enqueueLocal() { throw new Error('wrong upload channel'); },
      startMultipart(file, route) {
        selected = route;
        fileType = file.type;
        return Promise.resolve();
      }
    },
    ArcadeCloudOsShell: { notify: () => {} }
  };
  const recorder = new ArcadeCloudFolderRecorder(win, {});
  recorder.route = 'Data3/subfolder/';
  recorder.folderName = 'subfolder';
  recorder.mode = 'video';
  recorder.mediaBlob = new Blob([Buffer.alloc(8 * 1024 * 1024 + 10)], { type: 'video/webm' });
  recorder.close = () => {};
  recorder.save();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(selected, 'Data3/subfolder/');
  assert.equal(fileType, 'video/webm');
});
