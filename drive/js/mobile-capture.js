class ArcadeCloudMobileCapture {
  static boot() {
  'use strict';
  const element = id => document.getElementById(id);
  const result = message => { element('result').textContent = message; };
  let microphone = null, camera = null, recorder = null;
  let pieces = [], audioFile = null, photoFile = null, isSaving = false;
  let audioUrl = null, photoUrl = null;

  const closeStream = stream => {
    if (stream) stream.getTracks().forEach(track => track.stop());
  };
  const closeMicrophone = () => {
    closeStream(microphone);
    microphone = null;
    element('record').disabled = false;
    element('stop-record').disabled = true;
  };
  const closeCamera = () => {
    closeStream(camera);
    camera = null;
    element('camera').srcObject = null;
    element('camera').hidden = true;
    element('snap').disabled = true;
    element('close-camera').disabled = true;
    element('open-camera').disabled = false;
  };

  element('record').addEventListener('click', async () => {
    if (isSaving || !navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      result('La grabación no está disponible en este dispositivo.'); return;
    }
    try {
      microphone = await navigator.mediaDevices.getUserMedia({audio: true});
      let mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
        .find(type => MediaRecorder.isTypeSupported(type));
      recorder = new MediaRecorder(microphone, mime ? {mimeType: mime} : {});
      pieces = [];
      audioFile = null;
      element('save-audio').disabled = true;
      recorder.addEventListener('dataavailable', event => {
        if (event.data?.size) pieces.push(event.data);
      });
      recorder.addEventListener('stop', () => {
        audioFile = new Blob(pieces, {type: recorder.mimeType || 'audio/webm'});
        if (audioUrl) URL.revokeObjectURL(audioUrl);
        audioUrl = URL.createObjectURL(audioFile);
        element('audio-preview').src = audioUrl;
        element('audio-preview').hidden = false;
        element('save-audio').disabled = audioFile.size === 0 || audioFile.size > 25 * 1024 * 1024;
        closeMicrophone();
        result(audioFile.size > 25 * 1024 * 1024 ? 'Audio demasiado grande (máximo 25 MB).' : 'Grabación lista para guardar.');
      }, {once: true});
      recorder.start(1000);
      element('record').disabled = true;
      element('stop-record').disabled = false;
      result('Grabando nota... Pulsa Detener para terminar.');
    } catch (error) {
      closeMicrophone();
      result('Para grabar debes autorizar el micrófono.');
    }
  });
  element('stop-record').addEventListener('click', () => {
    if (recorder?.state === 'recording') recorder.stop();
  });

  element('open-camera').addEventListener('click', async () => {
    if (isSaving || !navigator.mediaDevices?.getUserMedia) return;
    try {
      camera = await navigator.mediaDevices.getUserMedia({
        video: {facingMode: {ideal: 'environment'}}, audio: false
      });
      const video = element('camera');
      video.srcObject = camera;
      video.hidden = false;
      await video.play();
      element('open-camera').disabled = true;
      element('snap').disabled = false;
      element('close-camera').disabled = false;
      result('La cámara está encendida. Pulsa Tomar foto.');
    } catch (error) {
      closeCamera();
      result('Para tomar fotos debes autorizar la cámara.');
    }
  });
  element('close-camera').addEventListener('click', closeCamera);
  element('snap').addEventListener('click', () => {
    const video = element('camera');
    if (!video.videoWidth) { result('Espera a que la cámara esté lista.'); return; }
    const canvas = element('frame');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(blob => {
      if (!blob) { result('No se pudo tomar la fotografía.'); return; }
      photoFile = blob;
      if (photoUrl) URL.revokeObjectURL(photoUrl);
      photoUrl = URL.createObjectURL(blob);
      element('image-preview').src = photoUrl;
      element('image-preview').hidden = false;
      element('save-photo').disabled = false;
      closeCamera();
      result('Foto tomada. Pulsa Guardar foto para subirla.');
    }, 'image/jpeg', .87);
  });

  element('select-photo').addEventListener('change', () => {
    const file = element('select-photo').files?.[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 25 * 1024 * 1024) {
      result('Usa una foto JPG, PNG o WebP de hasta 25 MB.'); return;
    }
    photoFile = file;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = URL.createObjectURL(file);
    element('image-preview').src = photoUrl;
    element('image-preview').hidden = false;
    element('save-photo').disabled = false;
    result('Foto seleccionada. Pulsa Guardar foto.');
  });

  async function save(file, type) {
    if (isSaving || !file) return;
    isSaving = true;
    element('save-photo').disabled = true;
    element('save-audio').disabled = true;
    const route = element('folder').value;
    const form = new FormData();
    form.append('ruta_objetivo', route);
    form.append('tipo', type);
    const ext = type === 'audio' ? (file.type.includes('mp4') ? 'm4a' : 'webm')
      : (file.type === 'image/png' ? 'png' : file.type === 'image/webp' ? 'webp' : 'jpg');
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    form.append('archivo', file, (type === 'audio' ? 'Nota_voz_' : 'Foto_') + stamp + '.' + ext);
    result('Guardando en la carpeta seleccionada...');
    try {
      const response = await fetch('mobile-capture-upload.php', {
        method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'X-DRIVE-CSRF':element('upload-token').value}, body:form
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Subida no confirmada');
      result('Guardado en ' + route + '. Vuelve al escritorio para verlo.');
      if (type === 'audio') audioFile = null;
      if (type === 'photo') photoFile = null;
    } catch (error) {
      result('No se guardó: ' + error.message);
    } finally {
      isSaving = false;
      element('save-audio').disabled = !audioFile;
      element('save-photo').disabled = !photoFile;
    }
  }
  element('save-audio').addEventListener('click', () => save(audioFile, 'audio'));
  element('save-photo').addEventListener('click', () => save(photoFile, 'photo'));
  window.addEventListener('pagehide', () => {
    if (recorder?.state === 'recording') recorder.stop();
    closeMicrophone();
    closeCamera();
    if (audioUrl) URL.revokeObjectURL(audioUrl);
    if (photoUrl) URL.revokeObjectURL(photoUrl);
  });
  }
}
ArcadeCloudMobileCapture.boot();
