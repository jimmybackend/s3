/* ArcadeCloud Office: sonido del KDE remoto para noVNC.
   Solo carga en la página noVNC autenticada; activación siempre manual. */
(() => {
  'use strict';
  class ArcadeCloudOfficeAudio {
    static boot() {
  if (window.__arcadecloudAudioInstalled) return;
  window.__arcadecloudAudioInstalled = true;
  function setup() {
    // El gateway Office actualizado ofrece un botón de audio estable fuera
    // del iframe. Si existe, no mostrar dos controles ni abrir dos flujos.
    try {
      if (window.parent !== window &&
          window.parent.document.getElementById('officeAudioToggle')) return;
    } catch (_) {
      // noVNC autónomo o frame de otro origen: conservar botón local.
    }
    const button = document.createElement('button');
    button.id = 'arcadecloud-audio-toggle';
    button.type = 'button';
    button.textContent = '🔊 Activar sonido';
    button.title = 'Escuchar el audio de KDE en este navegador';
    button.setAttribute('aria-pressed', 'false');
    Object.assign(button.style, {
      position: 'fixed', left: '12px', bottom: '68px', zIndex: '10000',
      fontFamily: 'system-ui,sans-serif', fontSize: '13px',
      padding: '8px 12px', borderRadius: '10px', cursor: 'pointer',
      border: '1px solid #476f85', background: 'rgba(7,23,37,.94)',
      color: '#eaf7ff', boxShadow: '0 4px 16px #0008'
    });
    const audio = new Audio();
    audio.preload = 'none';
    audio.autoplay = false;

    let enabled = false;
    function stop() {
      enabled = false;
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
      button.textContent = '🔊 Activar sonido';
      button.setAttribute('aria-pressed', 'false');
    }
    button.addEventListener('click', async () => {
      if (enabled) {
        stop();
        return;
      }
      enabled = true;
      audio.src = '/__office_audio?session=' + Date.now();
      button.textContent = 'Conectando sonido…';
      try {
        await audio.play();
        if (enabled) {
          button.textContent = '🔇 Silenciar sonido';
          button.setAttribute('aria-pressed', 'true');
        }
      } catch (error) {
        stop();
        button.textContent = 'Sonido no disponible · reintentar';
        console.warn('[ArcadeCloud audio]', error);
      }
    });
    audio.addEventListener('error', () => {
      if (!enabled) return;
      stop();
      button.textContent = 'Audio desconectado · reintentar';
    });
    // No mantener una captura FFmpeg en segundo plano al cerrar la sesión.
    window.addEventListener('pagehide', stop, {once: true});
    document.body.appendChild(button);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup, {once: true});
  } else {
    setup();
  }
    }
  }
  ArcadeCloudOfficeAudio.boot();
})();
