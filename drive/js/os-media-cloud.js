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
    this.videoMode = 'cloud';
    this.videoAspect = 16 / 9;
    this.videoScreen = null;
    this.videoInlineHost = null;
    this.videoScreenBody = null;
    this.layoutMode = 'desktop';
    this.geometry = {};
    this.remoteSaveTimer = 0;
    this.layoutResizeHandler = () => this.updateResponsiveLayout();
    this.eqMode = 'auto';
    this.eqValues = { low: 0, mid: 0, high: 0 };
  }

  init() {
    if (this.el) return this;
    this.build();
    this.bind();
    this.restorePreferences();
    this.updateResponsiveLayout();
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
          <filter id="acMistBlur" x="-30%" y="-30%" width="160%" height="160%">
            <feGaussianBlur stdDeviation="14"/>
          </filter>
          <filter id="acCloudPuff" x="-35%" y="-35%" width="170%" height="170%">
            <feTurbulence type="fractalNoise" baseFrequency=".013 .028" numOctaves="4" seed="27" result="cloudNoise"/>
            <feDisplacementMap in="SourceGraphic" in2="cloudNoise" scale="24" xChannelSelector="R" yChannelSelector="G" result="cloudDisplaced"/>
            <feGaussianBlur in="cloudDisplaced" stdDeviation="3.8" result="cloudSoft"/>
            <feSpecularLighting in="cloudNoise" surfaceScale="4" specularConstant=".42" specularExponent="18" lighting-color="#e9ffff" result="cloudLight">
              <feDistantLight azimuth="225" elevation="48"/>
            </feSpecularLighting>
            <feComposite in="cloudLight" in2="cloudSoft" operator="in" result="cloudLit"/>
            <feMerge><feMergeNode in="cloudSoft"/><feMergeNode in="cloudLit"/></feMerge>
          </filter>
          <filter id="acSparkGlow" x="-100%" y="-100%" width="300%" height="300%">
            <feGaussianBlur stdDeviation="3" result="sparkBlur"/>
            <feMerge><feMergeNode in="sparkBlur"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
          <radialGradient id="acMistWhite" cx=".48" cy=".35" r=".7">
            <stop offset="0" stop-color="#ffffff" stop-opacity=".92"/>
            <stop offset=".38" stop-color="#d9fbff" stop-opacity=".76"/>
            <stop offset=".72" stop-color="#8ddfff" stop-opacity=".34"/>
            <stop offset="1" stop-color="#4198ff" stop-opacity="0"/>
          </radialGradient>
          <radialGradient id="acPuffFace" cx=".38" cy=".26" r=".78">
            <stop offset="0" stop-color="#ffffff" stop-opacity=".98"/>
            <stop offset=".24" stop-color="#eaffff" stop-opacity=".96"/>
            <stop offset=".55" stop-color="#bcefff" stop-opacity=".84"/>
            <stop offset=".82" stop-color="#65bfff" stop-opacity=".48"/>
            <stop offset="1" stop-color="#245ec8" stop-opacity=".10"/>
          </radialGradient>
          <radialGradient id="acPuffShade" cx=".45" cy=".2" r=".9">
            <stop offset="0" stop-color="#c9f7ff" stop-opacity=".80"/>
            <stop offset=".55" stop-color="#559de8" stop-opacity=".42"/>
            <stop offset="1" stop-color="#072a79" stop-opacity=".08"/>
          </radialGradient>
          <linearGradient id="acCloudPearl" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#f4ffff" stop-opacity=".98"/>
            <stop offset=".18" stop-color="#8cf5ff" stop-opacity=".98"/>
            <stop offset=".52" stop-color="#25cfff" stop-opacity=".98"/>
            <stop offset="1" stop-color="#2373ff" stop-opacity=".86"/>
          </linearGradient>
          <path id="acCloudShape" d="M122 206
            C126 150 169 118 224 124
            C244 68 296 31 352 34
            C402 37 437 72 463 116
            C493 91 529 82 564 93
            C596 31 651 4 711 10
            C770 16 812 58 827 113
            C872 109 914 131 934 168
            C969 179 991 211 988 248
            C1005 277 1000 315 974 342
            C998 382 986 430 949 454
            C934 500 890 529 846 520
            C817 574 757 603 700 587
            C651 618 592 617 550 594
            C503 615 451 616 408 593
            C363 616 309 614 270 587
            C218 606 166 584 136 543
            C89 552 47 526 34 483
            C2 455 2 411 27 378
            C3 342 13 296 48 270
            C54 235 81 211 122 206 Z" pathLength="100"/>
          <clipPath id="acCloudClip"><use href="#acCloudShape"/></clipPath>
        </defs>

        <use href="#acCloudShape" fill="#2ccfff" opacity=".18" filter="url(#acCloudSoftGlow)"/>
        <use href="#acCloudShape" fill="url(#acCloudFill)" stroke="url(#acCloudStroke)" stroke-width="7" filter="url(#acCloudNeon)"/>
        <use href="#acCloudShape" fill="url(#acCloudGlow)" stroke="rgba(255,255,255,.82)" stroke-width="2"/>
        <use href="#acCloudShape" fill="none" stroke="#47dcff" stroke-opacity=".34" stroke-width="13"/>

        <g class="ac-cloud-scenery" clip-path="url(#acCloudClip)">
          <g class="scenery-top">
            <path d="M568 153 C579 132 596 120 617 121 C626 97 649 82 674 88 C690 59 724 51 749 67 C770 61 794 73 800 95 C824 100 838 119 834 140 C823 160 802 168 780 164 C759 180 729 178 710 165 C687 178 654 174 642 158 C615 168 586 164 568 153 Z" fill="url(#acPuffShade)"/>
            <path d="M590 151 C600 134 615 128 632 130 C638 111 656 100 674 105 C686 81 712 76 731 88 C750 83 769 95 773 113 C795 115 808 130 804 145 C791 159 773 162 756 156 C739 169 717 168 703 156 C683 168 659 165 649 152 C629 160 607 158 590 151 Z" fill="url(#acPuffFace)"/>
          </g>
          <g class="scenery-left">
            <path d="M48 510 C57 486 77 476 98 481 C103 454 126 438 151 446 C164 421 194 418 210 438 C234 437 251 455 246 477 C263 493 255 519 234 527 C216 547 187 549 168 536 C144 552 110 548 98 530 C79 536 57 527 48 510 Z" fill="url(#acPuffShade)"/>
            <path d="M68 507 C76 491 90 486 105 490 C109 470 126 458 144 465 C155 446 177 444 190 459 C208 457 222 471 219 487 C233 498 226 516 211 521 C197 534 177 535 163 525 C145 537 122 533 113 521 C98 525 80 519 68 507 Z" fill="url(#acPuffFace)"/>
          </g>
          <g class="scenery-right">
            <path d="M754 510 C764 484 785 473 808 479 C817 449 842 433 867 442 C882 419 910 421 925 441 C949 441 966 459 960 482 C978 500 968 526 945 533 C927 551 899 552 881 539 C858 554 827 550 814 532 C791 538 768 529 754 510 Z" fill="url(#acPuffShade)"/>
            <path d="M778 506 C786 489 801 483 816 488 C822 468 839 456 857 463 C869 444 891 446 903 461 C921 461 934 474 931 491 C944 502 937 519 922 524 C908 536 888 537 874 527 C856 538 834 535 824 522 C807 527 790 519 778 506 Z" fill="url(#acPuffFace)"/>
          </g>
        </g>

        <g class="ac-cloud-aura" filter="url(#acMistBlur)" opacity=".30">
          <path d="M38 500 C71 447 137 431 202 456 C239 471 253 509 226 538 C177 567 88 561 38 500 Z" fill="url(#acMistWhite)"/>
          <path d="M760 491 C797 441 870 428 930 459 C967 478 972 516 942 540 C888 565 806 554 760 491 Z" fill="url(#acMistWhite)"/>
          <path d="M574 147 C608 82 696 48 775 75 C816 90 837 128 816 158 C753 190 633 184 574 147 Z" fill="url(#acMistWhite)"/>
        </g>

        <g class="ac-cloud-mist" clip-path="url(#acCloudClip)" filter="url(#acCloudPuff)">
          <path class="mist-cluster mist-top-right" d="M548 162 C563 131 588 121 614 127 C626 96 656 82 684 93 C707 60 754 58 776 88 C809 82 837 106 832 137 C854 159 840 184 808 188 C777 204 731 196 711 180 C678 198 635 190 620 174 C592 182 563 176 548 162 Z" fill="url(#acMistWhite)"/>
          <path class="mist-cluster mist-bottom-left" d="M25 522 C39 489 66 477 91 485 C99 457 124 443 149 451 C171 426 207 433 218 459 C245 463 256 489 243 509 C257 532 235 553 207 552 C182 569 143 562 126 545 C93 557 54 548 25 522 Z" fill="url(#acMistWhite)"/>
          <path class="mist-cluster mist-bottom-right" d="M748 521 C763 488 790 475 816 483 C826 454 852 440 878 449 C899 426 936 433 947 461 C972 469 981 495 966 514 C979 537 956 557 928 554 C901 570 865 562 847 545 C816 558 777 548 748 521 Z" fill="url(#acMistWhite)"/>
        </g>

        <g class="ac-cloud-arcs" fill="none" stroke-linecap="round">
          <path class="arc-one" d="M120 438 C210 590 420 634 590 600 C760 567 861 485 920 370"/>
          <path class="arc-two" d="M178 225 C275 142 402 116 516 139 C650 165 741 236 819 305"/>
        </g>

        <g class="ac-cloud-sparkles" filter="url(#acSparkGlow)">
          <circle cx="84" cy="300" r="3"/><circle cx="122" cy="425" r="2.2"/><circle cx="176" cy="204" r="2.4"/>
          <circle cx="258" cy="108" r="2.6"/><circle cx="344" cy="72" r="2.1"/><circle cx="462" cy="130" r="2.2"/>
          <circle cx="579" cy="78" r="2.8"/><circle cx="690" cy="52" r="2.2"/><circle cx="816" cy="122" r="2.5"/>
          <circle cx="917" cy="198" r="2.6"/><circle cx="948" cy="342" r="2.2"/><circle cx="891" cy="510" r="2.8"/>
          <circle cx="728" cy="578" r="2.1"/><circle cx="542" cy="610" r="2.5"/><circle cx="350" cy="586" r="2.1"/>
          <circle cx="210" cy="548" r="2.5"/>
          <path d="M316 86 h18 M325 77 v18"/><path d="M858 166 h18 M867 157 v18"/>
          <path d="M126 396 h15 M133 389 v15"/><path d="M802 548 h15 M809 541 v15"/>
        </g>

        <path d="M195 147 C235 95 299 82 354 105" fill="none" stroke="#d9ffff" stroke-opacity=".58" stroke-width="4" stroke-linecap="round"/>
        <path d="M615 91 C660 45 730 44 779 93" fill="none" stroke="#d9ffff" stroke-opacity=".5" stroke-width="4" stroke-linecap="round"/>
        <path d="M71 424 C94 469 124 500 166 518" fill="none" stroke="#5ceeff" stroke-opacity=".38" stroke-width="3"/>
        <path d="M835 529 C878 520 914 492 937 452" fill="none" stroke="#5ceeff" stroke-opacity=".38" stroke-width="3"/>

        <g class="ac-cloud-stars">
          <circle cx="127" cy="159" r="3"/><circle cx="190" cy="110" r="2"/>
          <circle cx="802" cy="94" r="3"/><circle cx="930" cy="242" r="2"/>
          <circle cx="81" cy="456" r="2"/><circle cx="865" cy="566" r="3"/>
        </g>
        <use href="#acCloudShape" fill="none" stroke="#eaffff" stroke-opacity=".76" stroke-width="2.6"/>
        <use href="#acCloudShape" fill="none" stroke="url(#acCloudStroke)" stroke-opacity=".92" stroke-width="6.5" filter="url(#acCloudNeon)"/>
        <use href="#acCloudShape" class="ac-cloud-running-light ac-cloud-running-light-glow" fill="none"/>
        <use href="#acCloudShape" class="ac-cloud-running-light ac-cloud-running-light-core" fill="none"/>
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
                <button type="button" class="ac-video-mode-toggle" data-video-mode-toggle hidden title="Sacar video a pantalla flotante">
                  <i class="fas fa-display"></i><span>Pantalla</span>
                </button>
              </div>
              <div class="ac-video-inline-host" data-video-inline-host>
                <video data-media-video playsinline preload="metadata" hidden></video>
              </div>
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
      </div>
      <button type="button" class="ac-media-resize-handle" data-media-resize-handle
              title="Cambiar tamaño del reproductor" aria-label="Cambiar tamaño del reproductor">
        <i class="fas fa-up-right-and-down-left-from-center" aria-hidden="true"></i>
      </button>

      <section class="ac-video-screen" data-video-screen hidden aria-label="Pantalla de video separada">
        <header class="ac-video-screen-head" data-video-screen-drag>
          <div class="ac-video-screen-title">
            <i class="fas fa-display"></i>
            <span data-video-screen-title>Video</span>
          </div>
          <div class="ac-video-screen-actions">
            <button type="button" data-video-return title="Volver a reproducir dentro de la nube"><i class="fas fa-cloud"></i></button>
            <button type="button" data-video-screen-fullscreen title="Pantalla completa"><i class="fas fa-expand"></i></button>
            <button type="button" data-video-screen-close title="Cerrar pantalla externa"><i class="fas fa-xmark"></i></button>
          </div>
        </header>
        <div class="ac-video-screen-body" data-video-screen-body></div>
        <footer class="ac-video-screen-foot">
          <span data-video-orientation>Horizontal</span>
          <span>ArcadeCloud Video</span>
        </footer>
      </section>`;
    this.document.body.append(root);
    this.el = root;
    this.audio = root.querySelector('[data-media-audio]');
    this.video = root.querySelector('[data-media-video]');
    this.videoInlineHost = root.querySelector('[data-video-inline-host]');
    this.videoScreen = root.querySelector('[data-video-screen]');
    this.videoScreenBody = root.querySelector('[data-video-screen-body]');
    if (this.videoScreen) this.document.body.append(this.videoScreen);
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
    q('[data-video-mode-toggle]').addEventListener('click', () => this.toggleVideoMode());
    this.videoScreen?.querySelector('[data-video-return]')?.addEventListener('click', () => this.setVideoMode('cloud'));
    this.videoScreen?.querySelector('[data-video-screen-close]')?.addEventListener('click', () => this.setVideoMode('cloud'));
    this.videoScreen?.querySelector('[data-video-screen-fullscreen]')?.addEventListener('click', () => this.requestVideoFullscreen());
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
      player.addEventListener('loadedmetadata', () => {
        this.updateTime();
        if (player === this.video) this.updateVideoPresentation();
      });
      player.addEventListener('ended', () => {
        if (this.state.index + 1 < this.state.items.length) this.loadIndex(this.state.index + 1, true);
        else this.updatePlayButton();
      });
      player.addEventListener('error', () => this.showStatus('No se pudo reproducir este archivo.'));
    });

    this.bindDrag();
    this.bindResize();
    this.bindVideoScreenDrag();
    this.window.addEventListener('resize', this.layoutResizeHandler, { passive: true });
  }

  async open(file, options = {}) {
    this.init();
    try { this.audio.pause(); this.video.pause(); } catch (_) {}
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
    this.updateResponsiveLayout();
    this.applySavedGeometry();
    if (type !== 'video') this.setVideoMode('cloud', false);
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
    const isVideo = this.state.type === 'video';
    this.el.classList.toggle('is-video', isVideo);
    const modeButton = this.el.querySelector('[data-video-mode-toggle]');
    if (modeButton) modeButton.hidden = !isVideo;
    if (isVideo) {
      this.setVideoMode(this.videoMode, false);
      this.updateVideoPresentation();
    } else {
      this.setVideoMode('cloud', false);
    }
    this.updatePlayButton();
  }

  updatePlayButton() {
    const button = this.el.querySelector('[data-media-play] i');
    const player = this.currentPlayer();
    const playing = Boolean(player && !player.paused && !player.ended);
    if (button) button.className = playing ? 'fas fa-pause' : 'fas fa-play';
    this.el?.classList.toggle('is-playing', playing);
    this.videoScreen?.classList.toggle('is-playing', playing);
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
      const bars = 68;
      const gap = 3;
      const barWidth = Math.max(2, (width - (bars - 1) * gap) / bars);

      let peak = 1;
      for (let i = 0; i < Math.floor(bins.length * .72); i++) peak = Math.max(peak, bins[i]);
      const normalize = Math.max(72, peak);

      ctx.clearRect(0, 0, width, height);
      const glow = ctx.createLinearGradient(0, 0, width, 0);
      glow.addColorStop(0, 'rgba(45,190,255,.70)');
      glow.addColorStop(.22, 'rgba(78,247,255,1)');
      glow.addColorStop(.54, 'rgba(45,214,255,1)');
      glow.addColorStop(.82, 'rgba(70,150,255,.98)');
      glow.addColorStop(1, 'rgba(151,118,255,.78)');
      ctx.fillStyle = glow;
      ctx.shadowBlur = 18;
      ctx.shadowColor = 'rgba(48,225,255,.92)';

      for (let i = 0; i < bars; i++) {
        const ratio = i / Math.max(1, bars - 1);
        const sampleIndex = Math.min(bins.length - 1, Math.floor(ratio * bins.length * .72));
        const raw = bins[sampleIndex] / normalize;
        const spectralShape = .82 + Math.sin(ratio * Math.PI * 3.2) * .10 + Math.sin(ratio * Math.PI * 7.4) * .05;
        const energy = Math.max(.08, Math.min(1, raw * 1.18 * spectralShape));
        const eased = Math.pow(energy, .63);
        const amplitude = Math.max(3, eased * (center - 6));
        const x = i * (barWidth + gap);

        ctx.globalAlpha = .56 + eased * .44;
        ctx.fillRect(x, center - amplitude, barWidth, amplitude - 1);
        ctx.fillRect(x, center + 2, barWidth, amplitude - 1);
      }

      ctx.globalAlpha = 1;
      ctx.shadowBlur = 0;
      ctx.strokeStyle = 'rgba(183,252,255,.88)';
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

  toggleVideoMode() {
    if (this.state.type !== 'video') return;
    this.setVideoMode(this.videoMode === 'screen' ? 'cloud' : 'screen');
  }

  setVideoMode(mode, persist = true) {
    const next = mode === 'screen' && this.state.type === 'video' ? 'screen' : 'cloud';
    this.videoMode = next;
    this.el.classList.toggle('is-video-detached', next === 'screen');

    const button = this.el.querySelector('[data-video-mode-toggle]');
    if (button) {
      const icon = button.querySelector('i');
      const label = button.querySelector('span');
      button.hidden = this.state.type !== 'video';
      button.title = next === 'screen' ? 'Volver a reproducir dentro de la nube' : 'Sacar video a pantalla flotante';
      if (icon) icon.className = next === 'screen' ? 'fas fa-cloud' : 'fas fa-display';
      if (label) label.textContent = next === 'screen' ? 'En nube' : 'Pantalla';
    }

    if (!this.video || !this.videoInlineHost || !this.videoScreenBody || !this.videoScreen) return;

    if (next === 'screen') {
      if (this.video.parentElement !== this.videoScreenBody) this.videoScreenBody.append(this.video);
      this.videoScreen.hidden = false;
      this.video.hidden = false;
      this.updateVideoPresentation();
    } else {
      if (this.video.parentElement !== this.videoInlineHost) this.videoInlineHost.append(this.video);
      this.videoScreen.hidden = true;
      this.videoScreen.classList.remove('is-portrait', 'is-landscape');
      this.video.hidden = this.state.type !== 'video';
    }

    if (persist) this.persistPreferences();
  }

  updateVideoPresentation() {
    if (!this.video) return;
    const width = Number(this.video.videoWidth || 0);
    const height = Number(this.video.videoHeight || 0);
    if (width > 0 && height > 0) this.videoAspect = width / height;
    const portrait = this.videoAspect < 1;
    const screen = this.videoScreen;
    if (screen) {
      screen.style.setProperty('--video-aspect', String(Math.max(.35, Math.min(3.2, this.videoAspect || (16 / 9)))));
      screen.classList.toggle('is-portrait', portrait);
      screen.classList.toggle('is-landscape', !portrait);
      const orientation = screen.querySelector('[data-video-orientation]');
      if (orientation) orientation.textContent = portrait ? 'Vertical' : 'Horizontal';
      const title = screen.querySelector('[data-video-screen-title]');
      const item = this.state.items[this.state.index] || {};
      if (title) title.textContent = String(item.nombre || 'Video');
    }
  }

  async requestVideoFullscreen() {
    const target = this.videoScreen && !this.videoScreen.hidden ? this.videoScreen : this.video;
    if (!target) return;
    try {
      if (this.document.fullscreenElement) await this.document.exitFullscreen?.();
      else await target.requestFullscreen?.();
    } catch (_) {}
  }

  bindVideoScreenDrag() {
    const screen = this.videoScreen;
    const handle = screen?.querySelector('[data-video-screen-drag]');
    if (!screen || !handle) return;

    handle.addEventListener('pointerdown', event => {
      if (event.target.closest('button')) return;
      if (this.window.matchMedia('(max-width: 700px)').matches) return;
      const rect = screen.getBoundingClientRect();
      const offsetX = event.clientX - rect.left;
      const offsetY = event.clientY - rect.top;

      const move = moveEvent => {
        const nextLeft = Math.max(8, Math.min(this.window.innerWidth - rect.width - 8, moveEvent.clientX - offsetX));
        const nextTop = Math.max(8, Math.min(this.window.innerHeight - rect.height - 54, moveEvent.clientY - offsetY));
        screen.style.left = Math.round(nextLeft) + 'px';
        screen.style.top = Math.round(nextTop) + 'px';
        screen.style.right = 'auto';
        screen.style.bottom = 'auto';
        screen.style.transform = 'none';
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

  updateResponsiveLayout() {
    const width = Math.max(0, Number(this.window.innerWidth || this.document.documentElement?.clientWidth || 0));
    const mode = width <= 600 ? 'mobile' : (width <= 1024 ? 'tablet' : 'desktop');
    const changed = this.layoutMode !== mode;
    this.layoutMode = mode;
    ['mobile', 'tablet', 'desktop'].forEach(name => {
      this.el?.classList.toggle('is-' + name + '-ui', name === mode);
      this.videoScreen?.classList.toggle('is-' + name + '-ui', name === mode);
    });
    if (this.el) this.el.dataset.mediaLayout = mode;
    if (this.videoScreen) this.videoScreen.dataset.mediaLayout = mode;
    if (changed || !this.el?.hidden) this.applySavedGeometry();
    this.updateVideoPresentation();
  }

  geometryBounds() {
    const mode = this.layoutMode || 'desktop';
    const minWidth = mode === 'mobile' ? 260 : (mode === 'tablet' ? 300 : 320);
    const preferredMax = mode === 'mobile' ? 420 : (mode === 'tablet' ? 650 : 920);
    return {
      minWidth,
      maxWidth: Math.max(minWidth, Math.min(preferredMax, Math.max(minWidth, this.window.innerWidth - 16))),
      aspect: 1000 / 650
    };
  }

  clearGeometryStyles() {
    if (!this.el) return;
    ['left','top','right','bottom','width','height'].forEach(name => this.el.style.removeProperty(name));
  }

  applySavedGeometry() {
    if (!this.el) return;
    const saved = this.geometry && this.geometry[this.layoutMode];
    if (!saved || !Number.isFinite(Number(saved.width))) {
      this.clearGeometryStyles();
      return;
    }

    const bounds = this.geometryBounds();
    const width = Math.max(bounds.minWidth, Math.min(bounds.maxWidth, Number(saved.width)));
    const height = width / bounds.aspect;
    const maxLeft = Math.max(4, this.window.innerWidth - width - 4);
    const maxTop = Math.max(4, this.window.innerHeight - height - 50);
    const left = Math.max(4, Math.min(maxLeft, Number(saved.left || 4)));
    const top = Math.max(4, Math.min(maxTop, Number(saved.top || 4)));

    this.el.style.setProperty('width', Math.round(width) + 'px', 'important');
    this.el.style.setProperty('height', 'auto', 'important');
    this.el.style.setProperty('left', Math.round(left) + 'px', 'important');
    this.el.style.setProperty('top', Math.round(top) + 'px', 'important');
    this.el.style.setProperty('right', 'auto', 'important');
    this.el.style.setProperty('bottom', 'auto', 'important');
  }

  captureGeometry() {
    if (!this.el || this.el.hidden || this.el.classList.contains('is-collapsed')) return;
    const rect = this.el.getBoundingClientRect();
    if (!(rect.width > 0 && rect.height > 0)) return;
    this.geometry[this.layoutMode || 'desktop'] = {
      left: Math.round(rect.left),
      top: Math.round(rect.top),
      width: Math.round(rect.width)
    };
  }

  setPinned(value) {
    this.state.pinned = Boolean(value);
    this.el.classList.toggle('is-pinned', this.state.pinned);
    if (this.videoScreen) this.videoScreen.classList.toggle('is-pinned', this.state.pinned);
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
    this.setVideoMode('cloud', false);
    this.el.hidden = true;
    this.window.cancelAnimationFrame(this.raf);
  }

  bindDrag() {
    const handle = this.el.querySelector('[data-media-drag-handle]');
    if (!handle) return;

    handle.addEventListener('pointerdown', event => {
      if (event.target.closest('button')) return;
      if (event.button !== undefined && event.button !== 0) return;
      const rect = this.el.getBoundingClientRect();
      if (!(rect.width > 0 && rect.height > 0)) return;
      const offsetX = event.clientX - rect.left;
      const offsetY = event.clientY - rect.top;

      const move = moveEvent => {
        const current = this.el.getBoundingClientRect();
        const left = Math.max(4, Math.min(this.window.innerWidth - current.width - 4, moveEvent.clientX - offsetX));
        const top = Math.max(4, Math.min(this.window.innerHeight - current.height - 50, moveEvent.clientY - offsetY));
        this.el.style.setProperty('left', Math.round(left) + 'px', 'important');
        this.el.style.setProperty('top', Math.round(top) + 'px', 'important');
        this.el.style.setProperty('right', 'auto', 'important');
        this.el.style.setProperty('bottom', 'auto', 'important');
      };

      const end = endEvent => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', end);
        handle.removeEventListener('pointercancel', end);
        this.captureGeometry();
        this.persistPreferences();
        try { handle.releasePointerCapture(endEvent.pointerId); } catch (_) {}
      };

      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', end);
      handle.addEventListener('pointercancel', end);
      try { handle.setPointerCapture(event.pointerId); } catch (_) {}
    });
  }

  bindResize() {
    const handle = this.el.querySelector('[data-media-resize-handle]');
    if (!handle) return;

    handle.addEventListener('pointerdown', event => {
      if (event.button !== undefined && event.button !== 0) return;
      event.preventDefault();
      event.stopPropagation();
      if (this.el.classList.contains('is-collapsed')) return;

      const rect = this.el.getBoundingClientRect();
      const bounds = this.geometryBounds();
      const startX = event.clientX;
      const startY = event.clientY;
      const startWidth = rect.width;
      const left = rect.left;
      const top = rect.top;

      const move = moveEvent => {
        const deltaX = moveEvent.clientX - startX;
        const deltaY = (moveEvent.clientY - startY) * bounds.aspect;
        const requested = startWidth + (Math.abs(deltaX) > Math.abs(deltaY) ? deltaX : deltaY);
        const byViewportWidth = Math.max(bounds.minWidth, this.window.innerWidth - left - 4);
        const byViewportHeight = Math.max(bounds.minWidth, (this.window.innerHeight - top - 50) * bounds.aspect);
        const maxWidth = Math.max(bounds.minWidth, Math.min(bounds.maxWidth, byViewportWidth, byViewportHeight));
        const width = Math.max(bounds.minWidth, Math.min(maxWidth, requested));
        this.el.style.setProperty('width', Math.round(width) + 'px', 'important');
        this.el.style.setProperty('height', 'auto', 'important');
        this.el.style.setProperty('left', Math.round(left) + 'px', 'important');
        this.el.style.setProperty('top', Math.round(top) + 'px', 'important');
        this.el.style.setProperty('right', 'auto', 'important');
        this.el.style.setProperty('bottom', 'auto', 'important');
      };

      const end = endEvent => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', end);
        handle.removeEventListener('pointercancel', end);
        this.captureGeometry();
        this.persistPreferences();
        try { handle.releasePointerCapture(endEvent.pointerId); } catch (_) {}
      };

      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', end);
      handle.addEventListener('pointercancel', end);
      try { handle.setPointerCapture(event.pointerId); } catch (_) {}
    });
  }

  persistPreferences() {
    const state = {
      pinned: this.state.pinned,
      videoMode: this.videoMode,
      geometry: this.geometry,
      eqMode: this.eqMode,
      eqValues: this.eqValues
    };
    try {
      this.window.localStorage.setItem('arcadecloud.mediaCloud.preferences', JSON.stringify(state));
    } catch (_) {}
    this.persistRemotePreferences(state);
  }

  persistRemotePreferences(state) {
    const remote = this.window.ARCADECLOUD_OS_APPEARANCE || {};
    if (!remote.endpoint || !remote.csrf) return;
    this.window.clearTimeout(this.remoteSaveTimer);
    this.remoteSaveTimer = this.window.setTimeout(() => {
      this.window.fetch(String(remote.endpoint), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(remote.csrf)
        },
        body: JSON.stringify({
          mediaPlayerPreference: {
            pinned: state.pinned !== false,
            videoMode: state.videoMode === 'screen' ? 'screen' : 'cloud',
            geometry: state.geometry && typeof state.geometry === 'object' ? state.geometry : {}
          }
        })
      }).catch(() => {});
    }, 250);
  }

  restorePreferences() {
    let local = {};
    try {
      const parsed = JSON.parse(this.window.localStorage.getItem('arcadecloud.mediaCloud.preferences') || '{}');
      if (parsed && typeof parsed === 'object') local = parsed;
    } catch (_) {}

    const remoteRoot = this.window.ARCADECLOUD_OS_APPEARANCE?.preferences;
    const remote = remoteRoot?.mediaPlayerPreferences && typeof remoteRoot.mediaPlayerPreferences === 'object'
      ? remoteRoot.mediaPlayerPreferences
      : {};
    const data = { ...local, ...remote };
    if (local.geometry || remote.geometry) {
      data.geometry = {
        ...(local.geometry && typeof local.geometry === 'object' ? local.geometry : {}),
        ...(remote.geometry && typeof remote.geometry === 'object' ? remote.geometry : {})
      };
    }

    this.state.pinned = data.pinned !== false;
    this.videoMode = data.videoMode === 'screen' ? 'screen' : 'cloud';
    this.geometry = data.geometry && typeof data.geometry === 'object' ? data.geometry : {};
    this.eqMode = data.eqMode === 'manual' ? 'manual' : 'auto';
    if (data.eqValues && typeof data.eqValues === 'object') {
      ['low','mid','high'].forEach(name => {
        if (Number.isFinite(Number(data.eqValues[name]))) this.eqValues[name] = Number(data.eqValues[name]);
      });
    }

    this.setPinned(this.state.pinned);
    this.syncEqModeButtons();
    this.syncEqInputs();
  }

  static boot(win = window, doc = document) {
    const existing = win.ArcadeCloudMediaCloud;
    if (existing && typeof existing.open === 'function' && doc.getElementById('arcadeCloudMediaCloud')) {
      return existing;
    }
    win.ArcadeCloudMediaCloud = new ArcadeCloudMediaCloud(win, doc).init();
    return win.ArcadeCloudMediaCloud;
  }
}

if (typeof module !== 'undefined') module.exports = { ArcadeCloudMediaCloud };
if (typeof window !== 'undefined' && typeof document !== 'undefined') ArcadeCloudMediaCloud.boot(window, document);
