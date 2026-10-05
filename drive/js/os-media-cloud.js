class ArcadeCloudMediaCloud {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.state = { type: 'audio', route: '', items: [], index: -1, pinned: true };
    this.audioContext = null;
    this.graphs = new WeakMap();
    this.raf = 0;
    this.drag = null;
    this.el = null;
    this.audio = null;
    this.video = null;
    this.current = null;
    this.eqMode = 'auto';
    this.eqValues = { low: 0, mid: 0, high: 0 };
  }

  init() {
    if (this.el) return this;
    this.build();
    this.bind();
    this.restorePreferences();
    this.drawIdleWave();
    return this;
  }

  build() {
    const root = this.document.createElement('section');
    root.id = 'arcadeCloudMediaCloud';
    root.className = 'ac-media-cloud is-pinned';
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', 'Reproductor multimedia ArcadeCloud');
    root.innerHTML = `
      <svg class="ac-cloud-frame" viewBox="0 0 1000 650" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="acCloudFill" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#0a3e93"/>
            <stop offset=".48" stop-color="#03143e"/>
            <stop offset="1" stop-color="#0a63bd"/>
          </linearGradient>
          <linearGradient id="acCloudStroke" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#83fbff"/>
            <stop offset=".34" stop-color="#2bdfff"/>
            <stop offset=".62" stop-color="#8aa1ff"/>
            <stop offset="1" stop-color="#64f2ff"/>
          </linearGradient>
          <radialGradient id="acCloudGlow" cx=".5" cy=".25" r=".8">
            <stop offset="0" stop-color="#69dfff" stop-opacity=".34"/>
            <stop offset=".55" stop-color="#1578ff" stop-opacity=".08"/>
            <stop offset="1" stop-color="#020b20" stop-opacity=".05"/>
          </radialGradient>
          <filter id="acCloudNeon" x="-30%" y="-30%" width="160%" height="160%">
            <feGaussianBlur stdDeviation="8" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
          <filter id="acCloudSoftGlow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="16"/>
          </filter>
          <path id="acCloudShape" d="M116 175
            C122 116 168 74 230 80
            C260 28 322 5 385 26
            C423 39 451 66 470 101
            C497 72 530 57 569 58
            C598 6 655 -14 716 7
            C761 22 794 57 808 101
            C858 91 910 118 930 162
            C972 173 998 210 992 252
            C990 274 981 295 966 313
            C997 347 1001 396 976 433
            C1000 474 992 525 955 553
            C922 578 882 582 847 568
            C820 615 765 636 713 619
            C674 640 621 637 585 615
            C536 640 473 641 426 615
            C380 640 320 640 277 615
            C224 632 169 611 143 568
            C102 580 58 562 35 526
            C12 490 17 445 45 414
            C15 378 16 326 49 291
            C28 255 40 210 74 188
            C86 180 100 176 116 175 Z"/>
        </defs>

        <use href="#acCloudShape" fill="#2ccfff" opacity=".18" filter="url(#acCloudSoftGlow)"/>
        <use href="#acCloudShape" fill="url(#acCloudFill)" stroke="url(#acCloudStroke)" stroke-width="7" filter="url(#acCloudNeon)"/>
        <use href="#acCloudShape" fill="url(#acCloudGlow)" stroke="rgba(255,255,255,.82)" stroke-width="2"/>
        <use href="#acCloudShape" fill="none" stroke="#47dcff" stroke-opacity=".34" stroke-width="13"/>

        <path d="M195 147 C235 95 299 82 354 105" fill="none" stroke="#d9ffff" stroke-opacity=".58" stroke-width="4" stroke-linecap="round"/>
        <path d="M615 91 C660 45 730 44 779 93" fill="none" stroke="#d9ffff" stroke-opacity=".5" stroke-width="4" stroke-linecap="round"/>
        <path d="M71 424 C94 469 124 500 166 518" fill="none" stroke="#5ceeff" stroke-opacity=".38" stroke-width="3"/>
        <path d="M835 529 C878 520 914 492 937 452" fill="none" stroke="#5ceeff" stroke-opacity=".38" stroke-width="3"/>

        <g class="ac-cloud-stars">
          <circle cx="127" cy="159" r="3"/><circle cx="190" cy="110" r="2"/>
          <circle cx="802" cy="94" r="3"/><circle cx="930" cy="242" r="2"/>
          <circle cx="81" cy="456" r="2"/><circle cx="865" cy="566" r="3"/>
        </g>
      </svg>
      <div class="ac-media-cloud-shell">
        <header class="ac-media-cloud-head" data-media-drag-handle>
          <div class="ac-media-brand">
            <span class="ac-media-brand-orb"><i class="fas fa-cloud"></i></span>
            <span><strong>ArcadeCloud</strong> Player</span>
          </div>
          <div class="ac-media-head-actions">
            <button type="button" data-media-pin title="Siempre visible" aria-pressed="true"><i class="fas fa-thumbtack"></i></button>
            <button type="button" data-media-collapse title="Compactar"><i class="fas fa-chevron-down"></i></button>
            <button type="button" data-media-close title="Cerrar"><i class="fas fa-xmark"></i></button>
          </div>
        </header>

        <div class="ac-media-main">
          <div class="ac-media-stage">
            <div class="ac-media-art" aria-hidden="true">
              <span class="ac-media-art-halo halo-a"></span>
              <span class="ac-media-art-halo halo-b"></span>
              <div class="ac-media-art-core"><i data-media-type-icon class="fas fa-music"></i></div>
            </div>

            <div class="ac-media-screen">
              <div class="ac-media-title-row">
                <div>
                  <strong data-media-title>Sin reproducción</strong>
                  <small><span data-media-counter>0 / 0</span><span class="ac-media-stream-badge"><i class="fas fa-bolt"></i> streaming</span></small>
                </div>
              </div>
              <video data-media-video playsinline preload="metadata" hidden></video>
              <audio data-media-audio preload="metadata" hidden></audio>
              <div class="ac-media-wave-frame">
                <canvas data-media-wave width="760" height="112" aria-label="Visualizador real de audio"></canvas>
                <span class="ac-wave-scanline" aria-hidden="true"></span>
              </div>
              <div class="ac-media-time-row">
                <span data-media-current>0:00</span>
                <input data-media-seek type="range" min="0" max="1000" value="0" aria-label="Posición de reproducción">
                <span data-media-duration>0:00</span>
              </div>
            </div>
          </div>

          <div class="ac-media-controls">
            <button type="button" data-media-prev title="Anterior"><i class="fas fa-backward-step"></i></button>
            <button type="button" data-media-rewind title="Retroceder 10 segundos; mantener para acelerar"><i class="fas fa-backward"></i><small>10</small></button>
            <button type="button" class="is-primary" data-media-play title="Reproducir / Pausar"><span class="ac-play-cloud"><i class="fas fa-play"></i></span></button>
            <button type="button" data-media-forward title="Adelantar 10 segundos; mantener para acelerar"><i class="fas fa-forward"></i><small>10</small></button>
            <button type="button" data-media-next title="Siguiente"><i class="fas fa-forward-step"></i></button>
          </div>

          <div class="ac-media-dock">
            <button type="button" class="ac-media-stop" data-media-stop title="Detener"><i class="fas fa-stop"></i><span>Stop</span></button>
            <label class="ac-media-volume"><i class="fas fa-volume-high"></i><input data-media-volume type="range" min="0" max="1" step="0.01" value="1" aria-label="Volumen"></label>
            <button type="button" data-media-playlist-toggle><i class="fas fa-list-ul"></i><span>Lista</span></button>
            <button type="button" data-media-eq-toggle><i class="fas fa-sliders"></i><span>EQ</span></button>
          </div>

          <div class="ac-media-eq" data-media-eq hidden>
            <div class="ac-media-eq-head">
              <strong><i class="fas fa-wave-square"></i> Ecualizador</strong>
              <div>
                <button type="button" data-eq-mode="auto" class="is-active">Automático</button>
                <button type="button" data-eq-mode="manual">Manual</button>
              </div>
            </div>
            <label>Graves <input data-eq-band="low" type="range" min="-12" max="12" step="1" value="0"><output data-eq-output="low">0 dB</output></label>
            <label>Medios <input data-eq-band="mid" type="range" min="-12" max="12" step="1" value="0"><output data-eq-output="mid">0 dB</output></label>
            <label>Agudos <input data-eq-band="high" type="range" min="-12" max="12" step="1" value="0"><output data-eq-output="high">0 dB</output></label>
          </div>

          <div class="ac-media-playlist" data-media-playlist hidden>
            <div class="ac-media-playlist-head"><strong><i class="fas fa-list-ul"></i> Lista de reproducción</strong><span data-media-playlist-count>0 elementos</span></div>
            <div data-media-playlist-items></div>
          </div>
        </div>
      </div>`;
    this.document.body.append(root);
    this.el = root;
    this.audio = root.querySelector('[data-media-audio]');
    this.video = root.querySelector('[data-media-video]');
    this.canvas = root.querySelector('[data-media-wave]');
    this.ctx2d = this.canvas.getContext('2d');
  }

  bind() {
    const q = selector => this.el.querySelector(selector);
    q('[data-media-close]').addEventListener('click', () => this.close());
    q('[data-media-collapse]').addEventListener('click', () => {
      const collapsed = this.el.classList.toggle('is-collapsed');
      const button = q('[data-media-collapse]');
      if (button) {
        button.title = collapsed ? 'Expandir reproductor' : 'Compactar';
        const icon = button.querySelector('i');
        if (icon) icon.className = collapsed ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
      }
    });
    q('[data-media-pin]').addEventListener('click', () => this.setPinned(!this.state.pinned));
    q('[data-media-play]').addEventListener('click', () => this.toggle());
    q('[data-media-stop]').addEventListener('click', () => this.stop());
    q('[data-media-prev]').addEventListener('click', () => this.loadIndex(this.state.index - 1, true));
    q('[data-media-next]').addEventListener('click', () => this.loadIndex(this.state.index + 1, true));
    this.bindHoldSeek(q('[data-media-rewind]'), -1);
    this.bindHoldSeek(q('[data-media-forward]'), 1);

    q('[data-media-volume]').addEventListener('input', event => {
      const value = Math.max(0, Math.min(1, Number(event.target.value || 1)));
      this.audio.volume = value; this.video.volume = value;
    });

    q('[data-media-seek]').addEventListener('input', event => {
      const player = this.currentPlayer();
      if (!player || !Number.isFinite(player.duration) || player.duration <= 0) return;
      player.currentTime = (Number(event.target.value || 0) / 1000) * player.duration;
    });

    q('[data-media-playlist-toggle]').addEventListener('click', () => {
      const panel = q('[data-media-playlist]');
      panel.hidden = !panel.hidden;
    });
    q('[data-media-eq-toggle]').addEventListener('click', async () => {
      const panel = q('[data-media-eq]');
      panel.hidden = !panel.hidden;
      if (!panel.hidden) await this.ensureAudioGraph(this.currentPlayer());
    });
    this.el.querySelectorAll('[data-eq-mode]').forEach(button => button.addEventListener('click', () => this.setEqMode(button.dataset.eqMode)));
    this.el.querySelectorAll('[data-eq-band]').forEach(input => input.addEventListener('input', () => {
      this.eqMode = 'manual';
      this.syncEqModeButtons();
      this.eqValues[input.dataset.eqBand] = Number(input.value || 0);
      this.applyEqValues();
      this.updateEqOutputs();
      this.persistPreferences();
    }));

    [this.audio, this.video].forEach(player => {
      player.addEventListener('play', async () => {
        this.current = player;
        await this.ensureAudioGraph(player);
        this.updatePlayButton();
        this.drawWave();
      });
      player.addEventListener('pause', () => this.updatePlayButton());
      player.addEventListener('timeupdate', () => this.updateTime());
      player.addEventListener('durationchange', () => this.updateTime());
      player.addEventListener('loadedmetadata', () => this.updateTime());
      player.addEventListener('ended', () => {
        if (this.state.index + 1 < this.state.items.length) this.loadIndex(this.state.index + 1, true);
        else this.updatePlayButton();
      });
      player.addEventListener('error', () => this.showStatus('No se pudo reproducir este archivo.'));
    });

    this.bindDrag();
  }

  async open(file, options = {}) {
    this.init();
    const type = options.type || (String(file?.mime || '').startsWith('video/') ? 'video' : 'audio');
    const route = String(options.route || file?.route || this.activeRoute() || '');
    let items = [];
    if (route) {
      try {
        const response = await this.window.fetch('media_playlist.php?ruta=' + encodeURIComponent(route), {
          credentials: 'same-origin', cache: 'no-store'
        });
        const data = await response.json();
        if (response.ok && data?.ok === true && Array.isArray(data[type])) items = data[type];
      } catch (_) {}
    }

    if (!items.length) {
      items = [{ key: String(file?.key || ''), nombre: String(file?.name || 'Multimedia'), src: String(file?.openUrl || '') }];
    }
    let index = items.findIndex(item => String(item.key || '') === String(file?.key || ''));
    if (index < 0) {
      items.unshift({ key: String(file?.key || ''), nombre: String(file?.name || 'Multimedia'), src: String(file?.openUrl || '') });
      index = 0;
    }

    this.state.type = type;
    this.state.route = route;
    this.state.items = items;
    this.state.index = index;
    this.el.hidden = false;
    this.el.classList.remove('is-collapsed');
    this.renderPlaylist();
    await this.loadIndex(index, true);
    return this;
  }

  async loadIndex(index, autoplay = true) {
    if (!this.state.items.length) return;
    if (index < 0) index = this.state.items.length - 1;
    if (index >= this.state.items.length) index = 0;
    this.state.index = index;
    const item = this.state.items[index];
    const player = this.currentPlayer();
    const other = this.state.type === 'video' ? this.audio : this.video;

    try { other.pause(); } catch (_) {}
    other.removeAttribute('src');
    other.hidden = true;

    player.hidden = false;
    player.preload = 'metadata';
    if (player.getAttribute('src') !== String(item.src || '')) {
      player.src = String(item.src || '');
      player.load();
    }

    this.current = player;
    this.updateInfo();
    this.renderPlaylist();
    this.applyAutoEq();
    if (autoplay) {
      try { await player.play(); } catch (_) { this.updatePlayButton(); }
    }
  }

  currentPlayer() {
    return this.state.type === 'video' ? this.video : this.audio;
  }

  activeRoute() {
    const active = this.document.querySelector('.os-explorer-window.is-active .os-explorer-live');
    return String(active?.dataset.explorerRoute || this.window.DRIVE_INITIAL_ROUTE || '');
  }

  async ensureAudioGraph(player) {
    if (!player) return null;
    try {
      if (!this.audioContext) this.audioContext = new (this.window.AudioContext || this.window.webkitAudioContext)();
      if (this.audioContext.state === 'suspended') await this.audioContext.resume();
      if (this.graphs.has(player)) return this.graphs.get(player);

      const source = this.audioContext.createMediaElementSource(player);
      const low = this.audioContext.createBiquadFilter(); low.type = 'lowshelf'; low.frequency.value = 180;
      const mid = this.audioContext.createBiquadFilter(); mid.type = 'peaking'; mid.frequency.value = 1200; mid.Q.value = 0.8;
      const high = this.audioContext.createBiquadFilter(); high.type = 'highshelf'; high.frequency.value = 4800;
      const analyser = this.audioContext.createAnalyser(); analyser.fftSize = 512; analyser.smoothingTimeConstant = 0.78;
      source.connect(low); low.connect(mid); mid.connect(high); high.connect(analyser); analyser.connect(this.audioContext.destination);
      const graph = { source, low, mid, high, analyser };
      this.graphs.set(player, graph);
      this.applyEqValues();
      return graph;
    } catch (error) {
      console.warn('[ArcadeCloud media] Web Audio no disponible:', error);
      return null;
    }
  }

  setEqMode(mode) {
    this.eqMode = mode === 'manual' ? 'manual' : 'auto';
    if (this.eqMode === 'auto') this.applyAutoEq();
    this.syncEqModeButtons();
    this.persistPreferences();
  }

  applyAutoEq() {
    if (this.eqMode !== 'auto') return;
    this.eqValues = this.state.type === 'video'
      ? { low: -1, mid: 3, high: 1 }
      : { low: 2, mid: 0, high: 2 };
    this.syncEqInputs();
    this.applyEqValues();
  }

  applyEqValues() {
    const graph = this.graphs.get(this.currentPlayer());
    if (!graph) return;
    const now = this.audioContext?.currentTime || 0;
    graph.low.gain.setTargetAtTime(Number(this.eqValues.low || 0), now, 0.02);
    graph.mid.gain.setTargetAtTime(Number(this.eqValues.mid || 0), now, 0.02);
    graph.high.gain.setTargetAtTime(Number(this.eqValues.high || 0), now, 0.02);
  }

  syncEqInputs() {
    this.el.querySelectorAll('[data-eq-band]').forEach(input => {
      input.value = String(this.eqValues[input.dataset.eqBand] ?? 0);
    });
    this.updateEqOutputs();
  }

  updateEqOutputs() {
    ['low','mid','high'].forEach(name => {
      const output = this.el.querySelector('[data-eq-output="' + name + '"]');
      if (output) output.textContent = String(this.eqValues[name] ?? 0) + ' dB';
    });
  }

  syncEqModeButtons() {
    this.el.querySelectorAll('[data-eq-mode]').forEach(button => button.classList.toggle('is-active', button.dataset.eqMode === this.eqMode));
  }

  toggle() {
    const player = this.currentPlayer();
    if (!player?.src) return;
    if (player.paused || player.ended) player.play().catch(() => {});
    else player.pause();
  }

  stop() {
    const player = this.currentPlayer();
    if (!player) return;
    player.pause();
    try { player.currentTime = 0; } catch (_) {}
    this.updateTime();
  }

  seekBy(seconds) {
    const player = this.currentPlayer();
    if (!player || !Number.isFinite(player.duration)) return;
    player.currentTime = Math.max(0, Math.min(player.duration, Number(player.currentTime || 0) + Number(seconds || 0)));
  }

  bindHoldSeek(button, direction) {
    let timer = 0;
    let interval = 0;
    let held = 0;
    const stop = () => {
      if (timer) this.window.clearTimeout(timer);
      if (interval) this.window.clearInterval(interval);
      timer = 0; interval = 0; held = 0;
      button.classList.remove('is-holding');
    };
    button.addEventListener('pointerdown', event => {
      if (event.button !== undefined && event.button !== 0) return;
      event.preventDefault();
      this.seekBy(direction * 10);
      button.classList.add('is-holding');
      timer = this.window.setTimeout(() => {
        interval = this.window.setInterval(() => {
          held += 1;
          const step = Math.min(60, 10 + held * 5);
          this.seekBy(direction * step);
          const small = button.querySelector('small');
          if (small) small.textContent = String(step);
        }, 300);
      }, 500);
      try { button.setPointerCapture(event.pointerId); } catch (_) {}
    });
    ['pointerup','pointercancel','lostpointercapture'].forEach(name => button.addEventListener(name, event => {
      stop();
      const small = button.querySelector('small');
      if (small) small.textContent = '10';
      try { button.releasePointerCapture(event.pointerId); } catch (_) {}
    }));
  }

  updateInfo() {
    const item = this.state.items[this.state.index] || {};
    const title = this.el.querySelector('[data-media-title]');
    const counter = this.el.querySelector('[data-media-counter]');
    const icon = this.el.querySelector('[data-media-type-icon]');
    if (title) { title.textContent = String(item.nombre || 'Multimedia'); title.title = String(item.nombre || ''); }
    if (counter) counter.textContent = (this.state.index + 1) + ' / ' + this.state.items.length;
    if (icon) icon.className = this.state.type === 'video' ? 'fas fa-video' : 'fas fa-music';
    this.el.classList.toggle('is-video', this.state.type === 'video');
    this.updatePlayButton();
  }

  updatePlayButton() {
    const button = this.el.querySelector('[data-media-play] i');
    const player = this.currentPlayer();
    if (button) button.className = player && !player.paused && !player.ended ? 'fas fa-pause' : 'fas fa-play';
  }

  updateTime() {
    const player = this.currentPlayer();
    const current = Number(player?.currentTime || 0);
    const duration = Number(player?.duration || 0);
    const q = selector => this.el.querySelector(selector);
    q('[data-media-current]').textContent = this.formatTime(current);
    q('[data-media-duration]').textContent = Number.isFinite(duration) && duration > 0 ? this.formatTime(duration) : '0:00';
    q('[data-media-seek]').value = Number.isFinite(duration) && duration > 0 ? String(Math.round((current / duration) * 1000)) : '0';
  }

  formatTime(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = String(seconds % 60).padStart(2, '0');
    return hours > 0 ? hours + ':' + String(minutes).padStart(2, '0') + ':' + secs : minutes + ':' + secs;
  }

  renderPlaylist() {
    const host = this.el.querySelector('[data-media-playlist-items]');
    const count = this.el.querySelector('[data-media-playlist-count]');
    if (!host) return;
    host.replaceChildren();
    this.state.items.forEach((item, index) => {
      const button = this.document.createElement('button');
      button.type = 'button';
      button.className = 'ac-media-playlist-item' + (index === this.state.index ? ' is-active' : '');
      button.innerHTML = '<i class="fas ' + (this.state.type === 'video' ? 'fa-film' : 'fa-music') + '"></i><span></span><small></small>';
      button.querySelector('span').textContent = String(item.nombre || 'Multimedia');
      button.querySelector('small').textContent = String(index + 1);
      button.addEventListener('click', () => this.loadIndex(index, true));
      host.append(button);
    });
    if (count) count.textContent = this.state.items.length + ' elemento' + (this.state.items.length === 1 ? '' : 's');
  }

  async drawWave() {
    this.window.cancelAnimationFrame(this.raf);
    const frame = async () => {
      if (this.el.hidden) return;
      const graph = this.graphs.get(this.currentPlayer()) || await this.ensureAudioGraph(this.currentPlayer());
      if (!graph) { this.drawIdleWave(); return; }

      const bins = new Uint8Array(graph.analyser.frequencyBinCount);
      graph.analyser.getByteFrequencyData(bins);
      const ctx = this.ctx2d;
      const width = this.canvas.width;
      const height = this.canvas.height;
      const center = height / 2;
      const bars = 74;
      const gap = 3;
      const barWidth = Math.max(2, (width - (bars - 1) * gap) / bars);

      ctx.clearRect(0, 0, width, height);
      const glow = ctx.createLinearGradient(0, 0, width, 0);
      glow.addColorStop(0, 'rgba(61,210,255,.42)');
      glow.addColorStop(.28, 'rgba(77,243,255,.95)');
      glow.addColorStop(.62, 'rgba(80,177,255,.98)');
      glow.addColorStop(1, 'rgba(151,118,255,.72)');
      ctx.fillStyle = glow;
      ctx.shadowBlur = 16;
      ctx.shadowColor = 'rgba(38,214,255,.78)';

      for (let i = 0; i < bars; i++) {
        const sampleIndex = Math.min(bins.length - 1, Math.floor((i / bars) * bins.length * .72));
        const energy = bins[sampleIndex] / 255;
        const eased = Math.pow(energy, .72);
        const amplitude = Math.max(2, eased * (center - 7));
        const x = i * (barWidth + gap);
        ctx.globalAlpha = .48 + eased * .52;
        ctx.fillRect(x, center - amplitude, barWidth, amplitude);
        ctx.fillRect(x, center + 2, barWidth, amplitude);
      }

      ctx.globalAlpha = 1;
      ctx.shadowBlur = 0;
      ctx.strokeStyle = 'rgba(135,245,255,.72)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(0, center + .5);
      ctx.lineTo(width, center + .5);
      ctx.stroke();

      this.raf = this.window.requestAnimationFrame(frame);
    };
    this.raf = this.window.requestAnimationFrame(frame);
  }

  drawIdleWave() {
    const ctx = this.ctx2d;
    if (!ctx) return;
    const width = this.canvas.width;
    const height = this.canvas.height;
    const center = height / 2;
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = 'rgba(70,221,255,.32)';
    ctx.shadowBlur = 9;
    ctx.shadowColor = 'rgba(50,205,255,.32)';
    for (let x = 0, i = 0; x < width; x += 10, i++) {
      const amp = 4 + (Math.sin(i * .43) + 1) * 6;
      ctx.fillRect(x, center - amp, 4, amp);
      ctx.fillRect(x, center + 2, 4, amp);
    }
    ctx.shadowBlur = 0;
  }

  setPinned(value) {
    this.state.pinned = Boolean(value);
    this.el.classList.toggle('is-pinned', this.state.pinned);
    const button = this.el.querySelector('[data-media-pin]');
    if (button) {
      button.setAttribute('aria-pressed', this.state.pinned ? 'true' : 'false');
      button.title = this.state.pinned ? 'Siempre visible: activado' : 'Siempre visible: desactivado';
    }
    this.persistPreferences();
  }

  showStatus(message) {
    const title = this.el.querySelector('[data-media-title]');
    if (title) title.textContent = String(message || 'Error de reproducción');
  }

  close() {
    try { this.audio.pause(); this.video.pause(); } catch (_) {}
    this.el.hidden = true;
    this.window.cancelAnimationFrame(this.raf);
  }

  bindDrag() {
    const handle = this.el.querySelector('[data-media-drag-handle]');
    handle.addEventListener('pointerdown', event => {
      if (event.target.closest('button')) return;
      if (this.window.matchMedia('(max-width: 700px)').matches) return;
      const rect = this.el.getBoundingClientRect();
      const offsetX = event.clientX - rect.left;
      const offsetY = event.clientY - rect.top;
      const move = moveEvent => {
        const left = Math.max(8, Math.min(this.window.innerWidth - rect.width - 8, moveEvent.clientX - offsetX));
        const top = Math.max(8, Math.min(this.window.innerHeight - rect.height - 54, moveEvent.clientY - offsetY));
        this.el.style.left = Math.round(left) + 'px';
        this.el.style.top = Math.round(top) + 'px';
        this.el.style.right = 'auto';
        this.el.style.bottom = 'auto';
      };
      const end = endEvent => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', end);
        handle.removeEventListener('pointercancel', end);
        try { handle.releasePointerCapture(endEvent.pointerId); } catch (_) {}
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', end);
      handle.addEventListener('pointercancel', end);
      try { handle.setPointerCapture(event.pointerId); } catch (_) {}
    });
  }

  persistPreferences() {
    try {
      this.window.localStorage.setItem('arcadecloud.mediaCloud.preferences', JSON.stringify({
        pinned: this.state.pinned,
        eqMode: this.eqMode,
        eqValues: this.eqValues
      }));
    } catch (_) {}
  }

  restorePreferences() {
    try {
      const data = JSON.parse(this.window.localStorage.getItem('arcadecloud.mediaCloud.preferences') || '{}');
      this.state.pinned = data.pinned !== false;
      this.eqMode = data.eqMode === 'manual' ? 'manual' : 'auto';
      if (data.eqValues && typeof data.eqValues === 'object') {
        ['low','mid','high'].forEach(name => {
          if (Number.isFinite(Number(data.eqValues[name]))) this.eqValues[name] = Number(data.eqValues[name]);
        });
      }
    } catch (_) {}
    this.setPinned(this.state.pinned);
    this.syncEqModeButtons();
    this.syncEqInputs();
  }

  static boot(win = window, doc = document) {
    if (win.ArcadeCloudMediaCloud instanceof ArcadeCloudMediaCloud) return win.ArcadeCloudMediaCloud;
    win.ArcadeCloudMediaCloud = new ArcadeCloudMediaCloud(win, doc).init();
    return win.ArcadeCloudMediaCloud;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudMediaCloud };
if (typeof window !== 'undefined' && typeof document !== 'undefined') ArcadeCloudMediaCloud.boot(window, document);
