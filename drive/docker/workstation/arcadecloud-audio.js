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
    audio.setAttribute('aria-hidden', 'true');
    audio.style.display = 'none';
    document.body.appendChild(audio);

    let playing = false;
    let closed = false;
    let lastError = '';
    let phase = 'starting';
    let attempt = 0;
    let lastRetryMs = 0;

    // Diagnóstico local en DevTools, sin tokens ni cabeceras de sesión.
    // Se consulta desde la consola del iframe como:
    // window.__arcadecloudAudioDiagnostics()
    window.__arcadecloudAudioDiagnostics = () => ({
      phase,
      paused: audio.paused,
      muted: audio.muted,
      volume: audio.volume,
      readyState: audio.readyState,
      networkState: audio.networkState,
      currentTime: Math.round(audio.currentTime || 0),
      errorCode: audio.error ? audio.error.code : 0,
      lastError
    });

    function tryStart(fromGesture) {
      if (closed || playing) return;
      const now = Date.now();
      if (fromGesture && now - lastRetryMs < 900) return;
      if (fromGesture) lastRetryMs = now;

      // IMPORTANTE: volver a llamar play() aunque la petición automática
      // anterior esté pendiente. Chrome puede mantener esa promesa esperando
      // datos y antes 'starting=true' impedía aprovechar el primer clic.
      const thisAttempt = ++attempt;
      if (!audio.hasAttribute('src') || audio.error) {
        audio.removeAttribute('src');
        audio.src = '/__office_audio?session=' + Date.now();
      }
      audio.muted = false;
      audio.volume = 1;
      phase = fromGesture ? 'gesture-request' : 'autoplay-request';
      let request;
      try {
        request = audio.play(); // síncrono dentro del gesto real
      } catch (error) {
        if (thisAttempt === attempt) {
          phase = 'play-error';
          lastError = error && error.name ? error.name : 'play-error';
        }
        return;
      }
      if (!request || typeof request.then !== 'function') return;
      request.then(() => {
        if (closed) return;
        playing = true;
        phase = 'playing';
        lastError = '';
      }).catch((error) => {
        // Una promesa antigua puede fallar DESPUÉS de un clic legítimo.
        // No detener el flujo que esté arrancando la petición más reciente.
        if (closed || thisAttempt !== attempt) return;
        playing = false;
        lastError = (error && error.name) || 'media-error';
        phase = lastError === 'NotAllowedError' ? 'awaiting-gesture' : 'failed';
        if (phase !== 'awaiting-gesture') {
          console.warn('[ArcadeCloud Office] Audio noVNC: ' + lastError);
        }
        // En un bloqueo de autoplay no abrir capturas MP3 innecesarias.
        // La siguiente interacción puede iniciar un src nuevo.
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
      });
    }

    audio.addEventListener('playing', () => {
      if (closed) return;
      playing = true;
      phase = 'playing';
      lastError = '';
    });
    audio.addEventListener('waiting', () => {
      if (!closed && !playing) phase = 'buffering';
    });
    audio.addEventListener('stalled', () => {
      if (!closed) phase = 'network-stalled';
    });
    audio.addEventListener('error', () => {
      if (closed) return;
      playing = false;
      phase = 'stream-error';
      lastError = 'MediaError-' + (audio.error ? audio.error.code : 0);
      console.warn('[ArcadeCloud Office] Falló el audio remoto: ' + lastError);
    });
    audio.addEventListener('ended', () => {
      playing = false;
      phase = 'ended';
      audio.removeAttribute('src');
    });
    audio.addEventListener('pause', () => {
      if (!closed && playing) {
        playing = false;
        phase = 'paused';
      }
    });

    function retryAfterGesture() {
      if (closed || playing) return;
      tryStart(true);
    }

    // El ratón/tap/teclado son acciones normales dentro de noVNC; no
    // muestran botones extra y respetan el bloqueo autoplay de Chrome.
    document.addEventListener('pointerdown', retryAfterGesture, {capture: true});
    document.addEventListener('keydown', retryAfterGesture, {capture: true});
    if (parent) {
      try {
        parent.document.addEventListener('pointerdown', retryAfterGesture, {capture: true});
        parent.document.addEventListener('keydown', retryAfterGesture, {capture: true});
      } catch (_) { /* noVNC abierto desde otro origen */ }
    }

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && !playing && !closed) tryStart(false);
    });
    window.addEventListener('pagehide', () => {
      closed = true;
      phase = 'closed';
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
    }, {once: true});

    // En sitios autorizados comienza automáticamente. En Chrome con
    // bloqueo autoplay comienza al primer clic/tecla, incluso si el
    // intento inicial de play() quedó esperando indefinidamente.
    tryStart(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, {once: true});
  } else {
    boot();
  }
})();
