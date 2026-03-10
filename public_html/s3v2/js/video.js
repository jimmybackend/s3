
/* =====================================================================
   VIDEO — Dock izquierdo (#videoDock)
   - Respeta filtros/paginación: arma playlist desde el DOM visible
   - Comparte apMode con audio: localStorage.apMode = 'repeat' | 'next'
   - NO autoavanza si el usuario pausó manualmente (candado)
   ===================================================================== */
(function(){
  // ——— Estado compartido ———
  window.playlistVideo   = Array.isArray(window.playlistVideo) ? window.playlistVideo : [];
  window.videoIndex      = Number.isInteger(window.videoIndex) ? window.videoIndex : 0;
  window.currentVideoKey = window.currentVideoKey || null;

  // Candado: si el usuario pausó, no auto-reproducir ni repetir
  if (typeof window.videoStoppedByUser === 'undefined') {
    window.videoStoppedByUser = false;
  }

  // MIME de “mejores esfuerzos”
  const VIDEO_MIME = {
    mp4:'video/mp4', m4v:'video/mp4', mov:'video/mp4',
    webm:'video/webm', ogv:'video/ogg', ogg:'video/ogg', mkv:'video/webm'
  };

  // Preferencia global compartida con audio
  function getApMode(){ return (localStorage.getItem('apMode') || 'next'); }
  function setApMode(mode){ localStorage.setItem('apMode', mode); }

  // Mostrar / ocultar dock
  function showVideoDock(show){
    const dock = document.getElementById('videoDock');
    if (dock) dock.style.display = show ? 'block' : 'none';
  }

  // Normaliza objetos de diversas fuentes
  function normalizeItem(el){
    if (!el) return null;
    // Soporta {Key, Nombre} ó dataset de elementos del DOM
    const Key = el.Key || el.key || el.dataset?.key || el.getAttribute?.('data-key');
    if (!Key) return null;
    const NombreRaw = el.Nombre || el.name || el.dataset?.nombre || el.getAttribute?.('data-nombre');
    const Nombre = NombreRaw && String(NombreRaw).trim()
      ? String(NombreRaw).trim()
      : (Key.split('/').pop() || Key);

    let idx = el.dataset?.videoIndex;
    idx = (idx !== undefined && idx !== null) ? parseInt(idx, 10) : null;

    return { Key, Nombre, _idx: Number.isFinite(idx) ? idx : null, _el: el };
  }

  // Lee la lista de videos VISIBLES (respeta filtros/paginación)
  function readVisibleVideoItems(){
    const items = [];

    // 1) Botones "Ver" si existen
    document.querySelectorAll('#bloque-archivos .js-inline-video-open').forEach(btn=>{
      const it = normalizeItem(btn);
      if (it) items.push(it);
    });

    // 2) Si no hay botones, filas tipo video
    if (!items.length) {
      document.querySelectorAll('#bloque-archivos .ap-row[data-type="video"]').forEach(row=>{
        const it = normalizeItem(row);
        if (it) items.push(it);
      });
    }

    // Ordenar por data-video-index si existe; si no, por orden DOM
    const haveIndex = items.every(i => i._idx !== null);
    if (haveIndex) items.sort((a,b) => a._idx - b._idx);

    // Devuelve objetos limpios
    return items.map(({Key,Nombre}) => ({Key,Nombre}));
  }

  // Reconstruye playlist desde DOM y preserva el video actual si es posible
  function syncPlaylistFromDOM(){
    const visible = readVisibleVideoItems();
    if (!visible.length) return false;

    const currentKey = window.currentVideoKey;
    window.playlistVideo = visible;

    // Reposicionar index si el actual sigue visible
    if (currentKey) {
      const i = visible.findIndex(v => v.Key === currentKey);
      window.videoIndex = (i >= 0 ? i : 0);
    } else {
      window.videoIndex = 0;
    }
    updateVideoButtons();
    return true;
  }

  // Si existe fetchPlaylists() (tuya), úsala para llenar playlistVideo
  async function ensureVideoPlaylist(){
    // 1) Intento DOM (respeta filtros/paginación)
    if (syncPlaylistFromDOM()) return;

    // 2) API propia del proyecto
    if (typeof window.fetchPlaylists === 'function') {
      await window.fetchPlaylists(); // debe llenar playlistVideo/playlistAudio
      if (!Array.isArray(window.playlistVideo)) window.playlistVideo = [];
      return;
    }

    // 3) Fallback a get_playlist.php (puede NO reflejar filtros de UI)
    try {
      const ruta   = window.rutaActual || '';
      const params = new URLSearchParams({ ruta });
      const res    = await fetch('get_playlist.php?' + params.toString(), { credentials: 'same-origin' });
      const json   = await res.json();
      const raw    = Array.isArray(json.videos) ? json.videos : [];
      window.playlistVideo = raw.map(normalizeItem).filter(Boolean);
    } catch (e) {
      console.error('No se pudo cargar playlist de video:', e);
      window.playlistVideo = [];
    }
  }

  // ——— Util: marca intención del usuario por unos ms ———
  function setUserIntent(player, on){
    try {
      player._userIntent = !!on;
      if (on) setTimeout(()=>{ player._userIntent = false; }, 300);
    } catch(_e){}
  }

  // ——— Adjunta listeners al <video> del dock una sola vez ———
  function attachPlayerEvents(player){
    if (!player || player._apAttached) return;
    player._apAttached = true;

    // Si alguien intenta play() y el usuario había pausado, deténlo
    player.addEventListener('play', () => {
      if (window.videoStoppedByUser && !player._userIntent) {
        // Bloquea reproducción automática post-pausa
        player.pause();
      }
    }, true);

    // Si el usuario pausa manualmente, activamos el candado
    player.addEventListener('pause', () => {
      if (player._userIntent) {
        window.videoStoppedByUser = true;
      }
    }, true);

    // Al terminar: repeat / siguiente (siempre respetando el candado)
    player.addEventListener('ended', () => {
      // Fin de pista actual
      if (window.videoStoppedByUser) {
        updateVideoButtons();
        return;
      }

      const mode = getApMode();
      if (mode === 'repeat') {
        // Repite la MISMA pista
        player.currentTime = 0;
        player.play().catch(()=>{});
        return;
      }

      // Avanza al siguiente si existe
      if (window.videoIndex + 1 < window.playlistVideo.length) {
        loadVideo(window.videoIndex + 1, true);
      } else {
        // Fin de lista: detener (sin loop de lista)
        updateVideoButtons();
      }
    });

    // Si hay error, intenta siguiente (respetando candado)
    player.addEventListener('error', () => {
      console.warn('Error reproduciendo, intento con el siguiente (si existe).');
      if (!window.videoStoppedByUser && window.videoIndex + 1 < window.playlistVideo.length) {
        loadVideo(window.videoIndex + 1, true);
      }
    });
  }

  // Firma y carga un video por índice
  async function loadVideo(idx, autoplay = false){
    const list = window.playlistVideo;
    if (!list.length || idx < 0 || idx >= list.length) return;

    window.videoIndex      = idx;
    window.currentVideoKey = list[idx].Key;
    const { Key, Nombre }  = list[idx];

    const player = document.getElementById('videoDockPlayer');
    const source = document.getElementById('videoDockSource');
    const nameEl = document.getElementById('videoDockName');

    // Etiqueta
    if (nameEl) {
      nameEl.textContent = Nombre;
      nameEl.title       = Nombre;
    }

    try {
      // URL firmada
      const res = await fetch('token_video.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ archivo: Key })
      });
      const data = await res.json();
      if (!data || data.estado !== 'ok' || !data.url) {
        throw new Error(data && data.mensaje ? data.mensaje : 'No se pudo firmar el video');
      }

      // MIME
      const ext  = (Key.split('.').pop() || '').toLowerCase();
      const mime = VIDEO_MIME[ext] || ('video/' + ext);

      if (source) {
        source.type = mime;
        source.src  = data.url;
      }
      if (player) {
        attachPlayerEvents(player);      // asegura listeners 1 sola vez
        if (!source) player.src = data.url; // fallback si no hay <source>
        player.load();

        // Reproducción automática solo si NO hay candado
        if (autoplay && !window.videoStoppedByUser) {
          setUserIntent(player, false);
          await player.play().catch(()=>{});
        }
      }

      showVideoDock(true);
      updateVideoButtons();
    } catch (e) {
      console.error('Error al cargar video:', e);
      alert('No se pudo cargar el video: ' + e.message);
    }
  }

  // API pública: abrir desde botón de la lista
  window.reproducirVideoDesde = async (key, nombre) => {
    await ensureVideoPlaylist();
    const list = window.playlistVideo;
    if (!list.length) return alert('No hay videos en esta carpeta.');

    // Asegura que la playlist refleje lo visible (si el video viene del DOM)
    syncPlaylistFromDOM();

    // Encontrar el índice por Key
    const idx = list.findIndex(v => v.Key === key);
    if (idx < 0) return alert('Video no encontrado en la carpeta actual.');

    if (nombre) list[idx].Nombre = nombre; // etiqueta opcional

    // Al venir de la UI, desbloquea candado y reproduce
    window.videoStoppedByUser = false;
    await loadVideo(idx, true);
    return false;
  };

  // Delegación: botón "Ver" (.js-inline-video-open)
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.js-inline-video-open');
    if (!btn) return;
    ev.preventDefault();
    const it = normalizeItem(btn);
    if (!it) return;
    reproducirVideoDesde(it.Key, it.Nombre);
  });

  // Controles globales
  window.videoPlayPause = () => {
    const player = document.getElementById('videoDockPlayer');
    if (!player) return;

    // Si no hay nada cargado pero ya hay playlist, arranca el actual
    if (!player.currentSrc && window.playlistVideo.length) {
      window.videoStoppedByUser = false;       // intención de usuario
      loadVideo(window.videoIndex, true);
      return;
    }

    if (player.paused || player.ended) {
      // Intención del usuario: desbloquea candado
      window.videoStoppedByUser = false;
      setUserIntent(player, true);
      player.play().catch(()=>{});
    } else {
      // Pausa del usuario: activa candado
      setUserIntent(player, true);
      player.pause();
      window.videoStoppedByUser = true;
    }
  };

  window.videoNext = () => {
    const list = window.playlistVideo;
    if (!list.length) return;
    const next = window.videoIndex + 1;
    if (next < list.length) {
      window.videoStoppedByUser = false; // intención de usuario
      loadVideo(next, true);
    }
  };

  window.videoPrev = () => {
    const list = window.playlistVideo;
    if (!list.length) return;
    const prev = window.videoIndex - 1;
    if (prev >= 0) {
      window.videoStoppedByUser = false; // intención de usuario
      loadVideo(prev, true);
    }
  };

  function updateVideoButtons(){
    const prev = document.getElementById('btnVideoPrev');
    const next = document.getElementById('btnVideoNext');
    if (prev) prev.disabled = !(window.videoIndex > 0);
    if (next) next.disabled = !(window.videoIndex + 1 < window.playlistVideo.length);
  }

  // Cableado de botones físicos si existen
  document.addEventListener('click', (ev)=>{
    const id = ev.target.id || (ev.target.closest && ev.target.closest('button')?.id);
    if (id === 'btnVideoPrev')      { ev.preventDefault(); window.videoPrev(); }
    if (id === 'btnVideoNext')      { ev.preventDefault(); window.videoNext(); }
    if (id === 'btnVideoPlayPause') { ev.preventDefault(); window.videoPlayPause(); }
  });

  // Sincroniza apMode con switch #apModeRepeat (si existe)
  document.addEventListener('DOMContentLoaded', () => {
    const sw = document.getElementById('apModeRepeat');
    if (sw) {
      sw.checked = (getApMode() === 'repeat');
      sw.addEventListener('change', e => setApMode(e.target.checked ? 'repeat' : 'next'));
    }
  });

  // Observa recargas AJAX del bloque: actualiza playlist respetando filtros
  const target = document.getElementById('bloque-archivos');
  if (target && 'MutationObserver' in window) {
    const mo = new MutationObserver(() => {
      const was = window.currentVideoKey;
      syncPlaylistFromDOM();
      // Si se está reproduciendo y el actual sigue visible, no interrumpimos
      if (was && was === window.currentVideoKey) {
        updateVideoButtons();
      }
    });
    mo.observe(target, { childList:true, subtree:true });
  }

  // Init
  document.addEventListener('DOMContentLoaded', () => {
    showVideoDock(false);
    ensureVideoPlaylist().then(()=>updateVideoButtons()).catch(()=>{});
  });
})();
