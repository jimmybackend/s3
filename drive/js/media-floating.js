(() => {
  'use strict';

  if (window.__floatingMediaBound) {
    return;
  }

  window.__floatingMediaBound = true;

  const dock =
    document.getElementById('floatingMediaPlayer');

  const handle =
    document.getElementById('floatingMediaHandle');

  const audio =
    document.getElementById('floatingAudio');

  const video =
    document.getElementById('floatingVideo');

  const title =
    document.getElementById('floatingMediaTitle');

  const icon =
    document.getElementById('floatingMediaTypeIcon');

  const counter =
    document.getElementById('floatingMediaCounter');

  const btnPrev =
    document.getElementById('floatingPrev');

  const btnPlay =
    document.getElementById('floatingPlayPause');

  const btnNext =
    document.getElementById('floatingNext');

  const btnClose =
    document.getElementById('floatingMediaClose');

  if (
    !dock ||
    !audio ||
    !video
  ) {
    return;
  }

  const state = {
    type: null,
    route: '',
    items: [],
    index: -1
  };


  /* =========================================================
     HELPERS
     ========================================================= */

  function currentRoute() {
    const ctx =
      document.getElementById('archivosContexto');

    return String(
      ctx?.dataset?.rutaActual || ''
    );
  }


  function activePlayer() {
    return state.type === 'video'
      ? video
      : audio;
  }


  function otherPlayer() {
    return state.type === 'video'
      ? audio
      : video;
  }


  function currentItem() {
    if (
      state.index < 0 ||
      state.index >= state.items.length
    ) {
      return null;
    }

    return state.items[state.index];
  }


  function updatePlayIcon() {
    const player = activePlayer();

    const i =
      btnPlay.querySelector('i');

    if (!i) {
      return;
    }

    i.className =
      player &&
      !player.paused &&
      !player.ended
        ? 'fas fa-pause'
        : 'fas fa-play';
  }


  function updateUi() {
    const item = currentItem();

    if (!item) {
      return;
    }

    title.textContent =
      item.nombre || 'Multimedia';

    title.title =
      item.nombre || '';

    counter.textContent =
      `${state.index + 1} / ${state.items.length}`;

    icon.className =
      state.type === 'video'
        ? 'fas fa-video'
        : 'fas fa-music';

    updatePlayIcon();
  }


  async function getPlaylist(
    route,
    type
  ) {
    const url =
      'media_playlist.php?ruta=' +
      encodeURIComponent(route);

    const response =
      await fetch(url, {
        credentials: 'same-origin'
      });

    if (!response.ok) {
      throw new Error(
        `Playlist HTTP ${response.status}`
      );
    }

    const data =
      await response.json();

    if (!data || data.ok !== true) {
      throw new Error(
        data?.error ||
        'No se pudo obtener la playlist.'
      );
    }

    return Array.isArray(data[type])
      ? data[type]
      : [];
  }


  /* =========================================================
     REPRODUCCION
     ========================================================= */

  async function loadIndex(
    index,
    autoplay = true
  ) {
    if (!state.items.length) {
      return;
    }

    if (index < 0) {
      index =
        state.items.length - 1;
    }

    if (index >= state.items.length) {
      index = 0;
    }

    state.index = index;

    const item =
      currentItem();

    if (!item) {
      return;
    }

    const player =
      activePlayer();

    const other =
      otherPlayer();

    try {
      other.pause();
    } catch (_) {}

    other.removeAttribute('src');
    other.load();

    if (state.type === 'video') {
      video.hidden = false;
      audio.hidden = true;
    } else {
      audio.hidden = false;
      video.hidden = true;
    }

    if (
      player.getAttribute('src') !==
      item.src
    ) {
      player.src = item.src;
      player.load();
    }

    dock.hidden = false;

    updateUi();

    if (autoplay) {
      try {
        await player.play();
      } catch (error) {
        console.warn(
          'El navegador bloqueó autoplay:',
          error
        );
      }
    }

    updatePlayIcon();
  }


  async function start(
    type,
    key,
    route
  ) {
    try {
      const list =
        await getPlaylist(
          route,
          type
        );

      if (!list.length) {
        alert(
          type === 'audio'
            ? 'No hay audios en esta carpeta.'
            : 'No hay videos en esta carpeta.'
        );

        return;
      }

      const index =
        list.findIndex(
          item =>
            String(item.key) ===
            String(key)
        );

      if (index < 0) {
        alert(
          'No se encontró el archivo en la playlist.'
        );

        return;
      }

      state.type = type;
      state.route = route;

      /*
       * Snapshot de la carpeta:
       * aunque el usuario navegue a otra,
       * esta lista se conserva.
       */
      state.items = list;
      state.index = index;

      await loadIndex(
        index,
        true
      );

    } catch (error) {
      console.error(
        'Floating media:',
        error
      );

      alert(
        'No se pudo iniciar el reproductor.'
      );
    }
  }


  function togglePlay() {
    const player =
      activePlayer();

    if (!player) {
      return;
    }

    if (
      player.paused ||
      player.ended
    ) {
      player.play()
        .catch(() => {});
    } else {
      player.pause();
    }
  }


  function closePlayer() {
    try {
      audio.pause();
      video.pause();
    } catch (_) {}

    dock.hidden = true;

    updatePlayIcon();
  }


  /* =========================================================
     BOTONES
     ========================================================= */

  btnPrev.addEventListener(
    'click',
    () => {
      loadIndex(
        state.index - 1,
        true
      );
    }
  );


  btnNext.addEventListener(
    'click',
    () => {
      loadIndex(
        state.index + 1,
        true
      );
    }
  );


  btnPlay.addEventListener(
    'click',
    togglePlay
  );


  btnClose.addEventListener(
    'click',
    closePlayer
  );


  [audio, video].forEach(
    player => {

      player.addEventListener(
        'play',
        updatePlayIcon
      );

      player.addEventListener(
        'pause',
        updatePlayIcon
      );

      player.addEventListener(
        'ended',
        () => {

          /*
           * Avanza automáticamente
           * por todos los multimedia
           * de la carpeta original.
           */
          if (
            state.index + 1 <
            state.items.length
          ) {
            loadIndex(
              state.index + 1,
              true
            );
          } else {
            updatePlayIcon();
          }
        }
      );
    }
  );


  /* =========================================================
     INTERCEPTAR AUDIO / VIDEO DEL LISTADO
     Capture=true evita que se active el reproductor viejo.
     ========================================================= */

  document.addEventListener(
    'click',
    event => {

      const audioButton =
        event.target.closest(
          '.btn-play'
        );

      if (audioButton) {

        const rid =
          String(
            audioButton.id || ''
          ).replace(
            /^btn-/,
            ''
          );

        const source =
          document.getElementById(
            'audio-' + rid
          );

        if (!source) {
          return;
        }

        const key =
          source.dataset.key || '';

        if (!key) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        start(
          'audio',
          key,
          currentRoute()
        );

        return;
      }


      const videoButton =
        event.target.closest(
          '.js-inline-video-open'
        );

      if (videoButton) {

        const key =
          videoButton.dataset.key || '';

        if (!key) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        start(
          'video',
          key,
          currentRoute()
        );
      }

    },
    true
  );


  /* =========================================================
     ARRASTRAR REPRODUCTOR
     ========================================================= */

  let drag = null;


  function savePosition(
    left,
    top
  ) {
    try {
      localStorage.setItem(
        'driveFloatingMediaPosition',
        JSON.stringify({
          left,
          top
        })
      );
    } catch (_) {}
  }


  function restorePosition() {
    try {
      const raw =
        localStorage.getItem(
          'driveFloatingMediaPosition'
        );

      if (!raw) {
        return;
      }

      const pos =
        JSON.parse(raw);

      if (
        Number.isFinite(pos.left) &&
        Number.isFinite(pos.top)
      ) {
        dock.style.left =
          `${Math.max(4, pos.left)}px`;

        dock.style.top =
          `${Math.max(4, pos.top)}px`;

        dock.style.right = 'auto';
        dock.style.bottom = 'auto';
      }

    } catch (_) {}
  }


  handle.addEventListener(
    'pointerdown',
    event => {

      if (
        event.target.closest(
          'button'
        )
      ) {
        return;
      }

      const rect =
        dock.getBoundingClientRect();

      drag = {
        x:
          event.clientX -
          rect.left,

        y:
          event.clientY -
          rect.top
      };

      handle.setPointerCapture(
        event.pointerId
      );
    }
  );


  handle.addEventListener(
    'pointermove',
    event => {

      if (!drag) {
        return;
      }

      const width =
        dock.offsetWidth;

      const height =
        dock.offsetHeight;

      let left =
        event.clientX -
        drag.x;

      let top =
        event.clientY -
        drag.y;

      left =
        Math.max(
          4,
          Math.min(
            window.innerWidth -
            width -
            4,
            left
          )
        );

      top =
        Math.max(
          4,
          Math.min(
            window.innerHeight -
            height -
            4,
            top
          )
        );

      dock.style.left =
        `${left}px`;

      dock.style.top =
        `${top}px`;

      dock.style.right =
        'auto';

      dock.style.bottom =
        'auto';
    }
  );


  handle.addEventListener(
    'pointerup',
    () => {

      if (!drag) {
        return;
      }

      const rect =
        dock.getBoundingClientRect();

      savePosition(
        rect.left,
        rect.top
      );

      drag = null;
    }
  );


  restorePosition();

})();
