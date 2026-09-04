class AudiovideoModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (function (window, document) {
      'use strict';

      if (window.__audioVideoJSBound) return;
      window.__audioVideoJSBound = true;

      const wavePlayers = {};
      let currentAudioRid = null;

      window.playlistVideo = Array.isArray(window.playlistVideo) ? window.playlistVideo : [];
      window.videoIndex = Number.isInteger(window.videoIndex) ? window.videoIndex : 0;
      window.currentVideoKey = window.currentVideoKey || null;

      if (typeof window.videoStoppedByUser === 'undefined') {
        window.videoStoppedByUser = false;
      }

      const VIDEO_MIME = {
        mp4: 'video/mp4',
        m4v: 'video/mp4',
        mov: 'video/mp4',
        webm: 'video/webm',
        ogv: 'video/ogg',
        ogg: 'video/ogg',
        mkv: 'video/webm'
      };

      function getApMode() {
        return localStorage.getItem('apMode') || 'next';
      }

      function setApMode(mode) {
        localStorage.setItem('apMode', mode);
      }

      function formatTime(time) {
        time = Math.floor(Number(time) || 0);
        const min = Math.floor(time / 60);
        const sec = String(time % 60).padStart(2, '0');
        return min + ':' + sec;
      }

      function audioButtons() {
        return Array.from(document.querySelectorAll('[id^="btn-"]'));
      }

      function getAudioRids() {
        return Array.from(document.querySelectorAll('audio[id^="audio-"]'))
          .map(function (el) { return el.id.replace('audio-', ''); });
      }

      function getAudioRidIndex(rid) {
        const rids = getAudioRids();
        return rids.indexOf(String(rid));
      }

      function getNextAudioRid(rid) {
        const rids = getAudioRids();
        const idx = rids.indexOf(String(rid));
        if (idx >= 0 && idx + 1 < rids.length) return rids[idx + 1];
        return null;
      }

      function getPrevAudioRid(rid) {
        const rids = getAudioRids();
        const idx = rids.indexOf(String(rid));
        if (idx > 0) return rids[idx - 1];
        return null;
      }

      function setAudioButtonState(rid, isPlaying) {
        const btn = document.getElementById('btn-' + rid);
        if (!btn) return;
        btn.textContent = isPlaying ? '⏸' : '▶';
      }

      function setAudioTime(rid, time, total) {
        const el = document.getElementById('time-' + rid);
        if (!el) return;

        if (typeof total === 'number' && isFinite(total) && total > 0) {
          el.textContent = formatTime(time) + ' / ' + formatTime(total);
        } else {
          el.textContent = formatTime(time);
        }
      }

      function pauseOtherAudio(currentRid) {
        Object.keys(wavePlayers).forEach(function (rid) {
          if (rid !== String(currentRid) && wavePlayers[rid]) {
            try { wavePlayers[rid].pause(); } catch (e) {}
            setAudioButtonState(rid, false);
          }
        });
      }

      function ensureAudioSource(audio) {
        if (!audio) return false;

        if (!audio.getAttribute('src')) {
          const dataSrc = audio.getAttribute('data-src');
          if (!dataSrc) return false;
          audio.setAttribute('src', dataSrc);
        }

        return true;
      }

      function playAudioRid(rid, fromUser) {
        const ws = initWaveByRid(rid);
        if (!ws) return;

        pauseOtherAudio(rid);

        try {
          if (fromUser) {
            window.audioStoppedByUser = false;
          }
          ws.play();
          currentAudioRid = String(rid);
        } catch (err) {
          console.error('No se pudo reproducir el audio:', err);
        }
      }

      function toggleAudioRid(rid) {
        const ws = initWaveByRid(rid);
        if (!ws) return;

        pauseOtherAudio(rid);

        try {
          if (ws.isPlaying && ws.isPlaying()) {
            ws.pause();
            window.audioStoppedByUser = true;
            setAudioButtonState(rid, false);
            return;
          }

          window.audioStoppedByUser = false;
          ws.play();
          currentAudioRid = String(rid);
        } catch (err) {
          console.error('No se pudo reproducir el audio:', err);
        }
      }

      function playNextAudioFrom(rid) {
        if (window.audioStoppedByUser) return;
        const nextRid = getNextAudioRid(rid);
        if (nextRid) {
          playAudioRid(nextRid, false);
        }
      }

      function playPrevAudioFrom(rid) {
        const prevRid = getPrevAudioRid(rid);
        if (prevRid) {
          playAudioRid(prevRid, true);
        }
      }

      function initWaveByRid(rid) {
        rid = String(rid);

        if (wavePlayers[rid]) {
          return wavePlayers[rid];
        }

        const waveEl = document.getElementById('wave-' + rid);
        const audio = document.getElementById('audio-' + rid);

        if (!waveEl || !audio) {
          return null;
        }

        if (!ensureAudioSource(audio)) {
          console.error('No se encontró data-src para el audio:', rid);
          return null;
        }

        const src = audio.getAttribute('src');
        if (!src) {
          return null;
        }

        const ws = WaveSurfer.create({
          container: waveEl,
          waveColor: '#cbd5e1',
          progressColor: '#0d6efd',
          height: 38,
          barWidth: 2,
          barGap: 2,
          barRadius: 2,
          cursorWidth: 1,
          responsive: true,
          normalize: true,
          url: src
        });

        wavePlayers[rid] = ws;

        setAudioButtonState(rid, false);
        setAudioTime(rid, 0, 0);

        ws.on('ready', function () {
          let total = 0;
          try { total = ws.getDuration ? ws.getDuration() : 0; } catch (e) {}
          setAudioTime(rid, 0, total);
        });

        ws.on('play', function () {
          pauseOtherAudio(rid);
          setAudioButtonState(rid, true);
          currentAudioRid = rid;
        });

        ws.on('pause', function () {
          setAudioButtonState(rid, false);
        });

        ws.on('finish', function () {
          setAudioButtonState(rid, false);

          let total = 0;
          try { total = ws.getDuration ? ws.getDuration() : 0; } catch (e) {}
          setAudioTime(rid, total, total);

          const mode = getApMode();
          if (window.audioStoppedByUser) return;

          if (mode === 'repeat') {
            try {
              ws.setTime(0);
              ws.play();
            } catch (e) {}
            return;
          }

          playNextAudioFrom(rid);
        });

        ws.on('timeupdate', function (time) {
          let total = 0;
          try { total = ws.getDuration ? ws.getDuration() : 0; } catch (e) {}
          setAudioTime(rid, time, total);
        });

        ws.on('interaction', function () {
          pauseOtherAudio(rid);
        });

        ws.on('error', function (err) {
          console.error('WaveSurfer error en audio ' + rid + ':', err);
          setAudioButtonState(rid, false);
        });

        return ws;
      }

      window.wavePlayPause = function (rid) {
        toggleAudioRid(rid);
      };

      window.audioNext = function () {
        if (!currentAudioRid) return;
        window.audioStoppedByUser = false;
        playNextAudioFrom(currentAudioRid);
      };

      window.audioPrev = function () {
        if (!currentAudioRid) return;
        playPrevAudioFrom(currentAudioRid);
      };

      function showVideoDock(show) {
        const dock = document.getElementById('videoDock');
        if (dock) dock.style.display = show ? 'block' : 'none';
      }

      function normalizeVideoItem(el) {
        if (!el) return null;

        const Key = el.getAttribute('data-key') || el.dataset?.key || '';
        const Src = el.getAttribute('data-src') || el.dataset?.src || '';
        if (!Key && !Src) return null;

        const NombreRaw = el.getAttribute('data-nombre') || el.dataset?.nombre || '';
        const Nombre = NombreRaw && String(NombreRaw).trim()
          ? String(NombreRaw).trim()
          : ((Key || Src).split('/').pop() || 'Video');

        let idx = el.getAttribute('data-video-index');
        idx = (idx !== undefined && idx !== null && idx !== '') ? parseInt(idx, 10) : null;

        return {
          Key: Key,
          Src: Src,
          Nombre: Nombre,
          _idx: Number.isFinite(idx) ? idx : null,
          _el: el
        };
      }

      function readVisibleVideoItems() {
        const items = [];

        document.querySelectorAll('#bloque-archivos .js-inline-video-open').forEach(function (btn) {
          const it = normalizeVideoItem(btn);
          if (it) items.push(it);
        });

        const haveIndex = items.every(function (i) { return i._idx !== null; });
        if (haveIndex) {
          items.sort(function (a, b) { return a._idx - b._idx; });
        }

        return items.map(function (it) {
          return { Key: it.Key, Src: it.Src, Nombre: it.Nombre };
        });
      }

      function syncPlaylistFromDOM() {
        const visible = readVisibleVideoItems();
        if (!visible.length) return false;

        const currentKey = window.currentVideoKey;
        window.playlistVideo = visible;

        if (currentKey) {
          const i = visible.findIndex(function (v) { return v.Key === currentKey; });
          window.videoIndex = (i >= 0 ? i : 0);
        } else {
          window.videoIndex = 0;
        }

        updateVideoButtons();
        return true;
      }

      function setUserIntent(player, on) {
        try {
          player._userIntent = !!on;
          if (on) {
            setTimeout(function () {
              player._userIntent = false;
            }, 300);
          }
        } catch (_e) {}
      }

      function attachPlayerEvents(player) {
        if (!player || player._apAttached) return;
        player._apAttached = true;

        player.addEventListener('play', function () {
          if (window.videoStoppedByUser && !player._userIntent) {
            player.pause();
          }
        }, true);

        player.addEventListener('pause', function () {
          if (player._userIntent) {
            window.videoStoppedByUser = true;
          }
        }, true);

        player.addEventListener('ended', function () {
          if (window.videoStoppedByUser) {
            updateVideoButtons();
            return;
          }

          const mode = getApMode();
          if (mode === 'repeat') {
            player.currentTime = 0;
            player.play().catch(function () {});
            return;
          }

          if (window.videoIndex + 1 < window.playlistVideo.length) {
            loadVideo(window.videoIndex + 1, true);
          } else {
            updateVideoButtons();
          }
        });

        player.addEventListener('error', function () {
          console.warn('Error reproduciendo video; intento con el siguiente.');
          if (!window.videoStoppedByUser && window.videoIndex + 1 < window.playlistVideo.length) {
            loadVideo(window.videoIndex + 1, true);
          }
        });
      }

      async function loadVideo(idx, autoplay) {
        const list = window.playlistVideo;
        if (!list.length || idx < 0 || idx >= list.length) return;

        window.videoIndex = idx;
        window.currentVideoKey = list[idx].Key || null;

        const item = list[idx];
        const player = document.getElementById('videoDockPlayer');
        const source = document.getElementById('videoDockSource');
        const nameEl = document.getElementById('videoDockName');

        if (nameEl) {
          nameEl.textContent = item.Nombre || 'Video';
          nameEl.title = item.Nombre || 'Video';
        }

        try {
          let finalUrl = item.Src || '';

          if (!finalUrl && item.Key) {
            const res = await fetch('token_video.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({ archivo: item.Key })
            });

            const data = await res.json();
            if (!data || data.estado !== 'ok' || !data.url) {
              throw new Error(data && data.mensaje ? data.mensaje : 'No se pudo firmar el video');
            }
            finalUrl = data.url;
          }

          if (!finalUrl) {
            throw new Error('No existe URL para reproducir el video');
          }

          const ext = ((item.Key || finalUrl).split('.').pop() || '').toLowerCase();
          const mime = VIDEO_MIME[ext] || ('video/' + ext);

          if (source) {
            source.type = mime;
            source.src = finalUrl;
          }

          if (player) {
            attachPlayerEvents(player);
            if (!source) player.src = finalUrl;
            player.load();

            if (autoplay && !window.videoStoppedByUser) {
              setUserIntent(player, false);
              await player.play().catch(function () {});
            }
          }

          showVideoDock(true);
          updateVideoButtons();
        } catch (e) {
          console.error('Error al cargar video:', e);
          alert('No se pudo cargar el video: ' + e.message);
        }
      }

      window.reproducirVideoDesde = async function (key, nombre) {
        syncPlaylistFromDOM();

        const list = window.playlistVideo;
        if (!list.length) {
          alert('No hay videos en esta carpeta.');
          return false;
        }

        const idx = list.findIndex(function (v) { return v.Key === key; });
        if (idx < 0) {
          alert('Video no encontrado en la carpeta actual.');
          return false;
        }

        if (nombre) {
          list[idx].Nombre = nombre;
        }

        window.videoStoppedByUser = false;
        await loadVideo(idx, true);
        return false;
      };

      window.videoPlayPause = function () {
        const player = document.getElementById('videoDockPlayer');
        if (!player) return;

        if (!player.currentSrc && window.playlistVideo.length) {
          window.videoStoppedByUser = false;
          loadVideo(window.videoIndex, true);
          return;
        }

        if (player.paused || player.ended) {
          window.videoStoppedByUser = false;
          setUserIntent(player, true);
          player.play().catch(function () {});
        } else {
          setUserIntent(player, true);
          player.pause();
          window.videoStoppedByUser = true;
        }
      };

      window.videoNext = function () {
        const list = window.playlistVideo;
        if (!list.length) return;
        const next = window.videoIndex + 1;
        if (next < list.length) {
          window.videoStoppedByUser = false;
          loadVideo(next, true);
        }
      };

      window.videoPrev = function () {
        const list = window.playlistVideo;
        if (!list.length) return;
        const prev = window.videoIndex - 1;
        if (prev >= 0) {
          window.videoStoppedByUser = false;
          loadVideo(prev, true);
        }
      };

      function updateVideoButtons() {
        const prev = document.getElementById('btnVideoPrev');
        const next = document.getElementById('btnVideoNext');
        if (prev) prev.disabled = !(window.videoIndex > 0);
        if (next) next.disabled = !(window.videoIndex + 1 < window.playlistVideo.length);
      }

      function bindUI() {
        document.addEventListener('click', function (ev) {
          const btn = ev.target.closest('.js-inline-video-open');
          if (!btn) return;

          ev.preventDefault();

          const it = normalizeVideoItem(btn);
          if (!it) return;

          if (it.Key) {
            window.reproducirVideoDesde(it.Key, it.Nombre);
            return;
          }

          syncPlaylistFromDOM();
          const idx = window.playlistVideo.findIndex(function (v) { return v.Src === it.Src; });
          if (idx >= 0) {
            window.videoStoppedByUser = false;
            loadVideo(idx, true);
          }
        });

        document.addEventListener('click', function (ev) {
          const btn = ev.target.closest('button');
          if (!btn) return;

          if (btn.id === 'btnVideoPrev') {
            ev.preventDefault();
            window.videoPrev();
          } else if (btn.id === 'btnVideoNext') {
            ev.preventDefault();
            window.videoNext();
          } else if (btn.id === 'btnVideoPlayPause') {
            ev.preventDefault();
            window.videoPlayPause();
          }
        });
      }

      function initMedia() {
        syncPlaylistFromDOM();
        updateVideoButtons();

        const sw = document.getElementById('apModeRepeat');
        if (sw && !sw.dataset.apModeBound) {
          sw.checked = (getApMode() === 'repeat');
          sw.addEventListener('change', function (e) {
            setApMode(e.target.checked ? 'repeat' : 'next');
          });
          sw.dataset.apModeBound = '1';
        }

        showVideoDock(false);
      }

      bindUI();

      document.addEventListener('DOMContentLoaded', initMedia);
      document.addEventListener('bloque-archivos:actualizado', initMedia);
      document.addEventListener('bloque-archivos:updated', initMedia);

    })(window, document);

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new AudiovideoModule(win, doc).init();
    win.ArcadeCloudDrive.modules['audiovideo'] = instance;
    return instance;
  }
}

AudiovideoModule.boot();
