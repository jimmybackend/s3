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
  for (const name of ['record-audio', 'record-video', 'record-photo']) {
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

test('camera photo stays in the requested folder and uses the regular direct S3 upload', () => {
  let saved = null;
  const recorder = new ArcadeCloudFolderRecorder({
    ArcadeCloudUploadManager: {
      enqueueLocal(file, route, source) {
        saved = { file, route, source };
      },
      startMultipart() { throw new Error('Photo unexpectedly used multipart'); }
    },
    ArcadeCloudOsShell: { notify() {} }
  }, {});
  recorder.route = 'Data4/imagenes/';
  recorder.folderName = 'imagenes';
  recorder.mode = 'photo';
  recorder.mediaBlob = new Blob(['JPEG test fixture'], { type: 'image/jpeg' });
  recorder.close = () => {};
  recorder.save();
  assert.equal(saved.route, 'Data4/imagenes/');
  assert.equal(saved.source, 'Foto');
  assert.match(saved.file.name, /^Foto_.*\.jpg$/);
});


test('Android WebView selects default MediaRecorder when none of the named codecs is supported', () => {
  const attempted = [];
  class MockRecorder {
    static isTypeSupported() { return false; }
    constructor(stream, opts) {
      attempted.push(opts?.mimeType || 'default');
      this.mimeType = opts?.mimeType || 'audio/webm';
    }
  }
  const recorder = new ArcadeCloudFolderRecorder({ MediaRecorder: MockRecorder }, {});
  recorder.mode = 'audio';
  const instance = recorder.createRecorder({ id: 'mic' });
  assert.equal(instance.mimeType, 'audio/webm');
  assert.deepEqual(attempted, ['default']);
});

test('Android WebView retries codecs and finally its automatic media encoder', () => {
  const attempts = [];
  class MockRecorder {
    static isTypeSupported() { return true; }
    constructor(stream, opts) {
      attempts.push(opts?.mimeType || 'default');
      if (opts?.mimeType) throw Object.assign(new Error('encoder mismatch'), { name: 'NotSupportedError' });
      this.mimeType = 'video/webm';
    }
  }
  const recorder = new ArcadeCloudFolderRecorder({ MediaRecorder: MockRecorder }, {});
  recorder.mode = 'video';
  assert.equal(recorder.createRecorder({}).mimeType, 'video/webm');
  assert.ok(attempts.includes('video/webm;codecs=vp8,opus'));
  assert.equal(attempts.at(-1), 'default');
});

test('differentiate device permission, encoder and busy-device failures', () => {
  const recorder = new ArcadeCloudFolderRecorder({}, {});
  assert.match(recorder.describeCaptureError({ name: 'NotAllowedError' }), /bloqueó/);
  assert.match(recorder.describeCaptureError({ name: 'NotReadableError' }), /ocupado/);
  assert.match(recorder.describeCaptureError({ name: 'NotSupportedError' }), /WebView/);
  assert.match(recorder.describeCaptureError({ name: 'NotFoundError' }), /No se encontró/);
});

test('native Android recorder selection saves original recording in frozen S3 folder', () => {
  let saved = null;
  const win = {
    ArcadeCloudUploadManager: {
      enqueueLocal(file, route, source) { saved = { file, route, source }; },
      startMultipart() { throw new Error('not expected for small file'); }
    },
    ArcadeCloudOsShell: { notify() {} }
  };
  const selectors = new Map();
  const node = () => ({ disabled: false, hidden: true, src: '', srcObject: null,
    controls: false, muted: true, textContent: '' });
  for (const selector of ['#osFolderRecorderAudio', '[data-recorder-save]',
    '#osFolderRecorderMessage', '#osFolderRecorderVideo', '#osFolderRecorderPhoto']) {
    selectors.set(selector, node());
  }
  const dialog = {
    hidden: false,
    querySelector: selector => selectors.get(selector) || node(),
    querySelectorAll: () => []
  };
  const blobUrl = URL.createObjectURL;
  const revoke = URL.revokeObjectURL;
  URL.createObjectURL = () => 'blob:native-test';
  URL.revokeObjectURL = () => {};
  try {
    const recorder = new ArcadeCloudFolderRecorder(win, {});
    recorder.dialog = dialog;
    recorder.route = 'Data2/Temporal/';
    recorder.folderName = 'Temporal';
    recorder.mode = 'audio';
    recorder.close = () => {};
    const inputFile = new File(['recorded native audio'], 'voice-recorded.m4a', { type: 'audio/mp4' });
    recorder.acceptNativeRecording(inputFile);
    assert.equal(recorder.mediaBlob, inputFile);
    assert.equal(selectors.get('[data-recorder-save]').disabled, false);
    recorder.save();
    assert.equal(saved.route, 'Data2/Temporal/');
    assert.equal(saved.file.name, 'voice-recorded.m4a');
    assert.equal(saved.source, 'Nota de voz');
  } finally {
    URL.createObjectURL = blobUrl;
    URL.revokeObjectURL = revoke;
  }
});

test('Android capture fallback is wired without broad file or camera access', () => {
  const body = fs.readFileSync(path.join(__dirname, '..', 'js', 'so-folder-recorder.js'), 'utf8');
  assert.match(body, /data-recorder-native-input/);
  assert.match(body, /setAttribute\('capture'/);
  assert.match(native, /params\.isCaptureEnabled\(\)/);
  assert.match(native, /MediaStore\.ACTION_VIDEO_CAPTURE/);
  assert.match(native, /MediaStore\.Audio\.Media\.RECORD_SOUND_ACTION/);
  assert.match(native, /FLAG_GRANT_READ_URI_PERMISSION/);
  assert.match(native, /"content"\.equalsIgnoreCase\(uri\.getScheme\(\)\)/);
  assert.doesNotMatch(native, /addJavascriptInterface/);
  assert.doesNotMatch(native, /mobile_capture\.php/);
});
