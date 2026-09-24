(function (window, document) {
  'use strict';
  if (window.__mediaProcessingBound) return;
  window.__mediaProcessingBound = true;

  const endpoint = 'media_processing.php';

  async function enqueue(button, operation, parts) {
    const key = (button.dataset.key || '').trim();
    const name = (button.dataset.nombre || key).trim();
    if (!key) return;

    const body = new URLSearchParams({
      archivo: key,
      operation: operation,
      parts: String(parts || 1),
      overlap_before: '10',
      overlap_after: '10'
    });

    button.disabled = true;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || data.mensaje || 'No se pudo crear la tarea multimedia.');
      }
      const action = operation === 'extract_mp3'
        ? 'extracción de MP3'
        : (operation === 'split_audio' ? 'división del audio' : 'división del video');
      alert(
        'Se envió la ' + action + ' al nodo de procesamiento.\n\n' +
        'Archivo: ' + name + '\n' +
        'El original NO se eliminará.'
      );
      document.dispatchEvent(new CustomEvent('background-tasks:refresh'));
    } catch (error) {
      alert(error.message || 'No se pudo crear la tarea multimedia.');
    } finally {
      button.disabled = false;
    }
  }

  document.addEventListener('click', function (event) {
    const splitVideo = event.target.closest('.js-media-split-video');
    if (splitVideo) {
      event.preventDefault();
      const raw = prompt('¿En cuántas partes deseas dividir el video? (2 a 50)', '2');
      if (raw === null) return;
      const parts = parseInt(raw, 10);
      if (!Number.isInteger(parts) || parts < 2 || parts > 50) {
        alert('Indica una cantidad entre 2 y 50.');
        return;
      }
      enqueue(splitVideo, 'split_video', parts);
      return;
    }

    const splitAudio = event.target.closest('.js-media-split-audio');
    if (splitAudio) {
      event.preventDefault();
      const raw = prompt('¿En cuántas partes deseas dividir el audio? (2 a 50)', '2');
      if (raw === null) return;
      const parts = parseInt(raw, 10);
      if (!Number.isInteger(parts) || parts < 2 || parts > 50) {
        alert('Indica una cantidad entre 2 y 50.');
        return;
      }
      enqueue(splitAudio, 'split_audio', parts);
      return;
    }

    const mp3 = event.target.closest('.js-media-extract-mp3');
    if (mp3) {
      event.preventDefault();
      enqueue(mp3, 'extract_mp3', 1);
    }
  });
})(window, document);
