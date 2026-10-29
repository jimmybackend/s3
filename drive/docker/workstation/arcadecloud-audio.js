/* ArcadeCloud Office / noVNC:
 * Recibir sonido de KDE sin botones flotantes ni tonos de prueba.
 * Autoplay audible solo cuando el navegador lo autorice; si Chrome
 * requiere un gesto, el primer clic/tecla dentro del escritorio inicia
 * el canal automáticamente, sin pedir al usuario pulsar "Activar sonido".
 * No alterar la política del navegador ni iniciar varios streams.
 */
(() => {
  'use strict';
  if (window.__arcadecloudAudioInstalled) return;
  window.__arcadecloudAudioInstalled = true;

  function boot() {
    const parent = (() => {
      try { return window.parent !== window ? window.parent : null; }
      catch (_) { return null; }
    })();

    // Compatibilidad con el gateway PHP anterior, que todavía ofrece
    // controles diagnósticos. Ocultarlos cuando noVNC tome el audio.
    if (parent) {
      try {
        const host = parent.document;
        const idle = host.getElementById('officeIdleStatus');
        if (idle) {
          idle.style.right = '12px';
          idle.style.bottom = '12px';
        }
        const prior = host.getElementById('officeAudioToggle');
        if (prior) {
          if (prior.getAttribute('aria-pressed') === 'true') prior.click();
          prior.style.display = 'none';
        }
        const test = host.getElementById('officeAudioTest');
        if (test) test.style.display = 'none';
        const priorPlayer = host.querySelector('.office-audio-player');
        if (priorPlayer) priorPlayer.style.display = 'none';
      } catch (_) {
        // Fallback independiente si se usa noVNC en otro origen.
      }
    }

    const audio = new Audio();
    audio.preload = 'none';
    audio.autoplay = false;
    audio.muted = false;
    audio.volume = 1;

    let playing = false;
    let starting = false;
    let closed = false;
    let needsGesture = false;

    function tryStart() {
      if (closed || playing || starting) return;
      starting = true;
      if (!audio.hasAttribute('src')) audio.src = '/__office_audio?session=' + Date.now();
      // El método play() se invoca de forma síncrona desde pointerdown/keydown
      // para preservar la activación de usuario del navegador.
      const request = audio.play();
      if (!request || typeof request.then !== 'function') {
        starting = false;
        return;
      }
      request.then(() => {
        starting = false;
        playing = true;
        needsGesture = false;
      }).catch((error) => {
        starting = false;
        if (error && error.name === 'NotAllowedError') {
          needsGesture = true;
        } else {
          needsGesture = true;
          console.warn('[ArcadeCloud noVNC audio] Reproducción no disponible', error);
        }
        // No dejar una captura FFmpeg en curso si el navegador impide sonar.
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
      });
    }

    audio.addEventListener('playing', () => {
      playing = true;
      starting = false;
      needsGesture = false;
    });
    audio.addEventListener('error', () => {
      if (closed) return;
      playing = false;
      starting = false;
      needsGesture = true;
      console.warn('[ArcadeCloud noVNC audio] El flujo MP3 no llegó al navegador');
    });
    audio.addEventListener('ended', () => {
      playing = false;
      starting = false;
      needsGesture = true;
      audio.removeAttribute('src');
    });

    function retryAfterGesture() {
      if (!playing && !starting && needsGesture) tryStart();
    }
    // Solo cuando el usuario interactúa. No hay botón ni tono flotante.
    document.addEventListener('pointerdown', retryAfterGesture, {capture: true});
    document.addEventListener('keydown', retryAfterGesture, {capture: true});
    if (parent) {
      try {
        parent.document.addEventListener('pointerdown', retryAfterGesture, {capture: true});
        parent.document.addEventListener('keydown', retryAfterGesture, {capture: true});
      } catch (_) { /* noVNC de otro origen */ }
    }

    window.addEventListener('pagehide', () => {
      closed = true;
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
    }, {once: true});

    // Intentar el audio sin interacción cuando el navegador ya autoriza
    // autoplay para office.esforzados.com. Si lo bloquea, basta hacer clic
    // dentro del escritorio, sin modificar políticas de Chrome.
    tryStart();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, {once: true});
  } else {
    boot();
  }
})();
