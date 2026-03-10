
(function(){
  // ------------------------------
  // Mini player por fila (.ap-row)
  // ------------------------------
  const mimeMap = { mp3:'mpeg', wav:'wav', ogg:'ogg', opus:'ogg', m4a:'mp4' };
  let currentRow = null;

  // apMode global (compartido con tu switch)
  let apMode = localStorage.getItem('apMode') || 'next'; // 'next' | 'repeat'
  const repeatSwitch = document.getElementById('apModeRepeat');
  if (repeatSwitch) {
    repeatSwitch.checked = (apMode === 'repeat');
    repeatSwitch.addEventListener('change', () => {
      apMode = repeatSwitch.checked ? 'repeat' : 'next';
      localStorage.setItem('apMode', apMode);
    });
  }

  // Candado global: si el usuario pausó, NO auto-reproducir (repeat/next)
  if (typeof window.audioStoppedByUser === 'undefined') {
    window.audioStoppedByUser = false;
  }

  // Util para saber si el índice viene bien desde PHP; si no, reindexa por DOM
  function rows() {
    const list = Array.from(document.querySelectorAll('.ap-row[data-type="audio"]'));
    const allHaveIndex = list.every(r => r.dataset.audioIndex !== undefined);
    if (allHaveIndex) {
      return list.sort((a,b) => (+a.dataset.audioIndex) - (+b.dataset.audioIndex));
    }
    // fallback: orden natural del DOM
    return list;
  }

  function formatTime(s) {
    s = Math.max(0, Math.floor(s||0));
    const m = Math.floor(s/60);
    const r = String(s%60).padStart(2,'0');
    return m + ':' + r;
  }

  function updateIcon(row, playing) {
    const ic = row.querySelector('.btnPlay i');
    if (!ic) return;
    ic.className = playing ? 'fas fa-pause' : 'fas fa-play';
  }

  function pauseAllExcept(row) {
    rows().forEach(r => {
      if (r !== row) {
        const a = r.querySelector('.ap-audio');
        if (a) a.pause();
        r.classList.remove('ap-playing');
        updateIcon(r, false);
      }
    });
  }

  async function getTokenUrl(key) {
    const res = await fetch('token_audio.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ archivo: key })
    });
    const data = await res.json();
    if (!data || data.estado !== 'ok') {
      throw new Error(data && data.mensaje ? data.mensaje : 'No se pudo firmar el audio');
    }
    return data.url;
  }

  // Carga la fuente SOLO la primera vez
  async function ensureSource(row) {
    const audio = row.querySelector('.ap-audio');
    if (!audio) return null;
    if (!audio.src && !audio.querySelector('source')) {
      const key = row.dataset.key;
      const ext = (key.split('.').pop() || '').toLowerCase();
      const url = await getTokenUrl(key);

      const src = document.createElement('source');
      src.src  = url;
      src.type = 'audio/' + (mimeMap[ext] || ext);
      audio.appendChild(src);
      audio.load(); // ← NO reproduce
    }
    return audio;
  }

  function attachRowEvents(row) {
    const audio = row.querySelector('.ap-audio');
    if (!audio || audio._apAttached) return;

    const seek = row.querySelector('.ap-seek');
    const cur  = row.querySelector('.ap-cur');
    const dur  = row.querySelector('.ap-dur');

    // Marca de intención (solo cuando el usuario pulsa Play)
    row._userIntent = false;

    audio.addEventListener('loadedmetadata', () => {
      if (dur) dur.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('timeupdate', () => {
      if (seek && audio.duration) {
        seek.value = (audio.currentTime / audio.duration) * 100;
      }
      if (cur) cur.textContent = formatTime(audio.currentTime);
      if (dur && isFinite(audio.duration)) dur.textContent = formatTime(audio.duration);
    });

    // Si alguien dispara play() programático y el usuario había pausado, deténlo
    audio.addEventListener('play', () => {
      if (window.audioStoppedByUser && !row._userIntent) {
        // Rechaza cualquier reproducción automática post-pausa
        audio.pause();
      }
    }, true);

    // Si el usuario pausa (desde nuestro botón), activamos el candado
    audio.addEventListener('pause', () => {
      if (row._userIntent) {
        window.audioStoppedByUser = true;
      }
    }, true);

    // Al terminar: solo repetir/ir a siguiente si el usuario NO pausó
    audio.addEventListener('ended', () => {
      row.classList.remove('ap-playing');
      updateIcon(row, false);

      if (window.audioStoppedByUser) return;

      if (apMode === 'repeat') {
        audio.currentTime = 0;
        // Solo repite si no hubo pausa previa del usuario
        audio.play().catch(()=>{});
        row.classList.add('ap-playing');
        updateIcon(row, true);
        return;
      }
      playNextFrom(row);
    });

    if (seek) {
      seek.addEventListener('input', () => {
        if (audio.duration) {
          audio.currentTime = (seek.value / 100) * audio.duration;
        }
      });
    }

    audio._apAttached = true;
  }

  /**
   * Toggle robusto: si está sonando → pausa y SAL (sin tocar src/load).
   * fromUser=true cuando viene de un click del usuario (desbloquea candado).
   */
  async function playPauseRow(row, fromUser = false) {
    const audio = await ensureSource(row);
    if (!audio) return;

    attachRowEvents(row);

    // Si ya está reproduciendo, pausar y activar candado
    if (!audio.paused && !audio.ended) {
      row._userIntent = fromUser;      // ← viene del usuario
      audio.pause();
      window.audioStoppedByUser = true; // ← candado
      row.classList.remove('ap-playing');
      updateIcon(row, false);
      if (currentRow === row) currentRow = null;
      row._userIntent = false;
      return; // ← evita reiniciar
    }

    // Si terminó, al volver a dar play empieza desde 0
    if (audio.ended) audio.currentTime = 0;

    // Si se llamó de forma no-usuario y el candado está activo, NO reproducir
    if (!fromUser && window.audioStoppedByUser) {
      row.classList.remove('ap-playing');
      updateIcon(row, false);
      return;
    }

    // Reproducir
    pauseAllExcept(row);
    try {
      row._userIntent = fromUser;      // ← intención del usuario (si la hay)
      if (fromUser) window.audioStoppedByUser = false; // desbloquea si viene del usuario
      await audio.play();
      row.classList.add('ap-playing');
      updateIcon(row, true);
      currentRow = row;
    } catch(e) {
      console.warn('No se pudo reproducir:', e);
      row.classList.remove('ap-playing');
      updateIcon(row, false);
    } finally {
      // Borra la intención unos ms después para filtrar eventos inmediatos
      setTimeout(() => { row._userIntent = false; }, 300);
    }
  }

  function playNextFrom(row) {
    // Si el usuario pausó, NO auto-avanzar
    if (window.audioStoppedByUser) return;

    const list = rows();
    const idx  = list.indexOf(row);
    if (idx === -1) return;
    const next = list[idx + 1];
    if (next && next !== row) {
      // Llamada programática → fromUser=false (respeta candado)
      playPauseRow(next, false).catch(console.error);
    } else {
      updateIcon(row, false);
    }
  }

  // Evita doble disparo al clickar el ícono dentro del botón
  const style = document.createElement('style');
  style.textContent = '.btnPlay i{pointer-events:none;}';
  document.head.appendChild(style);

  // Click play/pause por fila (delegado) — intención del usuario
  $(document).on('click', '.ap-row .btnPlay', function(e){
    e.preventDefault();
    e.stopPropagation();
    const row = this.closest('.ap-row');
    playPauseRow(row, true).catch(err => alert('No se pudo reproducir: ' + err.message));
  });

  // API para reproducir por clave (si lo usas en algún menú). Consideramos intención del usuario.
  window.playRowByKey = function(key) {
    const sel = `.ap-row[data-type="audio"][data-key="${CSS.escape(key)}"]`;
    const row = document.querySelector(sel);
    if (row) playPauseRow(row, true).catch(console.error);
  };
})();
