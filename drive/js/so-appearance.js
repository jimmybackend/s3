class ArcadeCloudOsAppearance {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.remote = win.ARCADECLOUD_OS_APPEARANCE || {};
    this.nodeKey = String(this.remote.nodeKey || 'default');
    this.storageKey = 'arcadecloud-os-appearance-v2:' + this.nodeKey;
    this.defaults = { theme: 'dark', wallpaper: '', wallpaperName: '', wallpaperEnabled: true, windowOpacity: 94, menuOpacity: 98, chromeOpacity: 96 };
    this.state = this.load();
  }

  init() {
    this.apply();
    this.bindRange('osWindowOpacity', 'osWindowOpacityValue', 'windowOpacity');
    this.bindRange('osMenuOpacity', 'osMenuOpacityValue', 'menuOpacity');
    this.bindRange('osChromeOpacity', 'osChromeOpacityValue', 'chromeOpacity');
    this.document.querySelectorAll('[data-os-theme]').forEach((button) => {
      button.addEventListener('click', () => {
        this.state.theme = button.dataset.osTheme === 'light' ? 'light' : 'dark';
        this.commit('Tema ' + (this.state.theme === 'light' ? 'claro' : 'oscuro') + ' activado.');
      });
    });
    this.document.querySelector('[data-os-wallpaper-choice="original"]')?.addEventListener('click', () => {
      this.state.wallpaper = '';
      this.state.wallpaperName = '';
      this.state.wallpaperEnabled = true;
      this.commit('Se restauró el fondo original.');
    });
    this.document.querySelector('[data-os-wallpaper-choice="none"]')?.addEventListener('click', () => {
      this.state.wallpaperEnabled = false;
      this.commit('Fondo de escritorio desactivado.');
    });
    this.document.querySelector('[data-os-wallpaper-choice="current"]')?.addEventListener('click', () => {
      if (!this.state.wallpaper) return;
      this.state.wallpaperEnabled = true;
      this.commit('Fondo de escritorio activado.');
    });
    this.document.querySelector('[data-os-reset-appearance]')?.addEventListener('click', () => {
      this.state = Object.assign({}, this.state, this.defaults);
      this.syncRange('osWindowOpacity', 'osWindowOpacityValue', 'windowOpacity');
      this.syncRange('osMenuOpacity', 'osMenuOpacityValue', 'menuOpacity');
      this.syncRange('osChromeOpacity', 'osChromeOpacityValue', 'chromeOpacity');
      this.commit('Se restauró la configuración de apariencia.');
    });
    return this;
  }

  load() {
    let local = {};
    try {
      const saved = JSON.parse(this.window.localStorage.getItem(this.storageKey) || '{}');
      local = saved && typeof saved === 'object' ? saved : {};
    } catch (_) {}
    const remote = this.remote.preferences && typeof this.remote.preferences === 'object' ? this.remote.preferences : {};
    const state = Object.assign({}, this.defaults, local, remote);
    // Los datos anteriores no tenían wallpaperEnabled: una URL guardada continúa activa.
    if (!Object.prototype.hasOwnProperty.call(local, 'wallpaperEnabled') && !Object.prototype.hasOwnProperty.call(remote, 'wallpaperEnabled')) {
      state.wallpaperEnabled = true;
    }
    return state;
  }

  save() {
    try { this.window.localStorage.setItem(this.storageKey, JSON.stringify(this.state)); } catch (_) {}
    this.saveRemote();
  }

  saveRemote() {
    if (!this.remote.endpoint || !this.remote.csrf) return;
    this.window.clearTimeout(this.saveTimer);
    this.saveTimer = this.window.setTimeout(() => {
      this.window.fetch(this.remote.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.remote.csrf },
        body: JSON.stringify(this.state)
      }).catch(() => {});
    }, 250);
  }

  clamp(value) {
    const number = Number(value);
    return Math.max(0, Math.min(100, Number.isFinite(number) ? number : 100));
  }

  apply() {
    this.state.theme = this.state.theme === 'light' ? 'light' : 'dark';
    this.state.windowOpacity = this.clamp(this.state.windowOpacity);
    this.state.menuOpacity = this.clamp(this.state.menuOpacity);
    this.state.chromeOpacity = this.clamp(this.state.chromeOpacity);
    this.document.documentElement.style.setProperty('--os-window-opacity', String(this.state.windowOpacity / 100));
    this.document.documentElement.style.setProperty('--os-menu-opacity', String(this.state.menuOpacity / 100));
    this.document.documentElement.style.setProperty('--os-chrome-opacity', String(this.state.chromeOpacity / 100));
    this.document.body.classList.toggle('os-theme-light', this.state.theme === 'light');
    this.document.body.classList.toggle('os-theme-dark', this.state.theme === 'dark');
    this.applyWallpaper();
    this.syncControls();
  }

  applyWallpaper() {
    const desktop = this.document.getElementById('osDesktop');
    const enabled = this.state.wallpaperEnabled !== false;
    const url = enabled ? String(this.state.wallpaper || '') : '';
    this.document.body.classList.toggle('os-wallpaper-none', !enabled);
    desktop?.classList.toggle('has-custom-wallpaper', Boolean(url));
    if (desktop) desktop.style.backgroundImage = url ? this.wallpaperCss(url) : '';
    const preview = this.document.getElementById('osAppearancePreview');
    if (preview) preview.style.backgroundImage = url ? this.wallpaperCss(url) : '';
  }

  wallpaperCss(url) {
    return 'linear-gradient(rgba(2,8,15,.2),rgba(2,8,15,.2)),url("' + url.replace(/["\\\n\r]/g, encodeURIComponent) + '")';
  }

  syncControls() {
    this.document.querySelectorAll('[data-os-theme]').forEach((button) => {
      const selected = button.dataset.osTheme === this.state.theme;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-checked', String(selected));
    });
    const current = this.document.querySelector('[data-os-wallpaper-choice="current"]');
    if (current) {
      current.hidden = !this.state.wallpaper;
      current.querySelector('span').style.backgroundImage = this.state.wallpaper ? this.wallpaperCss(String(this.state.wallpaper)) : '';
      const label = current.querySelector('[data-os-current-wallpaper]');
      if (label) label.textContent = String(this.state.wallpaperName || 'Imagen elegida');
    }
    const choice = this.state.wallpaperEnabled === false ? 'none' : (this.state.wallpaper ? 'current' : 'original');
    this.document.querySelectorAll('[data-os-wallpaper-choice]').forEach((button) => button.classList.toggle('is-selected', button.dataset.osWallpaperChoice === choice));
  }

  bindRange(inputId, outputId, property) {
    const input = this.document.getElementById(inputId);
    if (!input) return;
    this.syncRange(inputId, outputId, property);
    input.addEventListener('input', () => {
      this.state[property] = this.clamp(input.value);
      this.syncRange(inputId, outputId, property);
      this.apply(); this.save();
    });
  }

  syncRange(inputId, outputId, property) {
    const input = this.document.getElementById(inputId);
    const output = this.document.getElementById(outputId);
    if (input) input.value = String(this.state[property]);
    if (output) output.value = this.state[property] + '%';
  }

  commit(message) { this.apply(); this.save(); this.notify(message); }

  setWallpaper(url, name) {
    if (!url) return;
    this.state.wallpaper = String(url);
    this.state.wallpaperName = String(name || 'Imagen');
    this.state.wallpaperEnabled = true;
    this.commit('Fondo cambiado a "' + this.state.wallpaperName + '".');
  }

  notify(message) { this.window.ArcadeCloudOsShell?.notify?.(message, 'success'); }
}

window.ArcadeCloudOsAppearance = new ArcadeCloudOsAppearance(window, document).init();
