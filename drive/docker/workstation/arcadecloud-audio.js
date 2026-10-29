/* ArcadeCloud noVNC: escuchar sonido remoto sin Guacamole.
   No hay reproduccion automática: el usuario activa el sonido. */
(() => {
  'use strict';
  if (window.__arcadecloudAudioInstalled) return;
  window.__arcadecloudAudioInstalled = true;

  function setup() {
    // Office ofrece el mismo control fuera del iframe. Evitar abrir
    // streams duplicados cuando la página principal ya tiene controles.
    try {
      if (window.parent !== window) {
        const parentToggle = window.parent.document.getElementById('officeAudioToggle');
        const parentTest = window.parent.document.getElementById('officeAudioTest');
        if (parentToggle && parentTest) return;
        // El gateway antiguo todavía no incluye diagnóstico ni volumen.
        // Preferir los nuevos controles internos hasta actualizarlo,
        // sin dejar dos transmisiones activables.
        if (parentToggle) parentToggle.style.display = 'none';
      }
    } catch (_) {
      // noVNC autónomo: mostrar los controles dentro del visor.
    }

    const button = document.createElement('button');
    button.id = 'arcadecloud-audio-toggle';
    button.type = 'button';
    button.textContent = '🔊 Activar sonido';
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Escuchar el audio de KDE en este navegador';
    Object.assign(button.style, {
      position: 'fixed', left: '12px', bottom: '68px', zIndex: '10000',
      fontFamily: 'system-ui,sans-serif', fontSize: '13px',
      padding: '8px 12px', borderRadius: '10px', cursor: 'pointer',
      border: '1px solid #476f85', background: 'rgba(7,23,37,.94)',
      color: '#eaf7ff', boxShadow: '0 4px 16px #0008'
    });

    const test = document.createElement('button');
    test.id = 'arcadecloud-audio-test';
    test.type = 'button';
    test.textContent = '🔔 Probar audífonos';
    test.title = 'Emite un tono corto en este navegador sin comunicarse con el servidor';
    Object.assign(test.style, {
      position: 'fixed', left: '12px', bottom: '28px', zIndex: '10000',
      fontFamily: 'system-ui,sans-serif', fontSize: '12px',
      padding: '6px 10px', borderRadius: '9px', cursor: 'pointer',
      border: '1px solid #476f85', background: 'rgba(7,23,37,.94)',
      color: '#eaf7ff'
    });

    const audio = new Audio();
    audio.preload = 'none';
    audio.controls = true;
    audio.muted = false;
    audio.volume = 1;
    audio.style.display = 'none';
    Object.assign(audio.style, {
      position: 'fixed', left: '190px', bottom: '67px', width: '240px',
      maxWidth: 'calc(100vw - 205px)', height: '32px', zIndex: '10000'
    });
    audio.setAttribute('aria-label', 'Audio remoto de KDE');
    document.body.appendChild(audio);

    let enabled = false;
    function stop() {
      enabled = false;
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
      audio.style.display = 'none';
      button.textContent = '🔊 Activar sonido';
      button.setAttribute('aria-pressed', 'false');
    }
    button.addEventListener('click', async () => {
      if (enabled) {
        stop();
        return;
      }
      enabled = true;
      audio.muted = false;
      audio.volume = 1;
      audio.style.display = 'block';
      audio.src = '/__office_audio?session=' + Date.now();
      button.textContent = 'Conectando sonido…';
      try {
        await audio.play();
      } catch (error) {
        stop();
        button.textContent = 'Error de audio · reintentar';
        console.warn('[ArcadeCloud audio]', error);
      }
    });
    audio.addEventListener('playing', () => {
      if (!enabled) return;
      button.textContent = '🔇 Detener sonido';
      button.setAttribute('aria-pressed', 'true');
    });
    audio.addEventListener('waiting', () => {
      if (enabled) button.textContent = 'Audio en espera de datos…';
    });
    audio.addEventListener('stalled', () => {
      if (enabled) button.textContent = 'Audio detenido por la red…';
    });
    audio.addEventListener('error', () => {
      if (!enabled) return;
      const code = audio.error?.code ?? 0;
      stop();
      button.textContent = 'Error de transmisión (' + code + ') · reintentar';
      console.warn('[ArcadeCloud audio] Error multimedia', code);
    });

    test.addEventListener('click', async () => {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        test.textContent = 'Prueba no compatible';
        return;
      }
      const ctx = new AudioCtx();
      try {
        await ctx.resume();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.frequency.value = 523.25;
        gain.gain.value = 0.035;
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start();
        oscillator.stop(ctx.currentTime + 0.18);
        oscillator.onended = () => { ctx.close().catch(() => {}); };
        test.textContent = '🔔 Tono enviado';
      } catch (error) {
        test.textContent = 'Error en salida local';
        console.warn('[ArcadeCloud audio] Prueba de audífonos', error);
        ctx.close().catch(() => {});
      }
    });

    // Una pestaña cerrada no debe dejar un flujo FFmpeg activo.
    window.addEventListener('pagehide', stop, {once: true});
    document.body.appendChild(button);
    document.body.appendChild(test);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup, {once: true});
  } else {
    setup();
  }
})();
