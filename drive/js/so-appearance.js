class ArcadeCloudOsAppearance {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.storageKey = 'arcadecloud-os-appearance-v1';
    this.defaults = { wallpaper: '', wallpaperName: '', windowOpacity: 94, menuOpacity: 98 };
    this.remote = win.ARCADECLOUD_OS_APPEARANCE || {};
    this.state = this.load();
  }

  init() {
    this.apply();
    this.bindRange('osWindowOpacity', 'osWindowOpacityValue', 'windowOpacity');
    this.bindRange('osMenuOpacity', 'osMenuOpacityValue', 'menuOpacity');
    this.document.querySelector('[data-os-reset-wallpaper]')?.addEventListener('click', () => {
      this.state.wallpaper = '';
      this.state.wallpaperName = '';
      this.save();
      this.applyWallpaper();
      this.notify('Se restauró el fondo original.');
    });
    return this;
  }

  load() {
    try {
      const saved = JSON.parse(this.window.localStorage.getItem(this.storageKey) || '{}');
      const local = saved && typeof saved === 'object' ? saved : {};
      const remote = this.remote.preferences && typeof this.remote.preferences === 'object'
        ? this.remote.preferences : {};
      return Object.assign({}, this.defaults, local, remote);
    } catch (_) {
      return Object.assign({}, this.defaults);
    }
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
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.remote.csrf },
        body: JSON.stringify(this.state)
      }).catch(() => {});
    }, 250);
  }

  clamp(value) {
    return Math.max(35, Math.min(100, Number(value) || 100));
  }

  apply() {
    this.state.windowOpacity = this.clamp(this.state.windowOpacity);
    this.state.menuOpacity = this.clamp(this.state.menuOpacity);
    this.document.documentElement.style.setProperty('--os-window-opacity', String(this.state.windowOpacity / 100));
    this.document.documentElement.style.setProperty('--os-menu-opacity', String(this.state.menuOpacity / 100));
    this.applyWallpaper();
  }

  applyWallpaper() {
    const url = String(this.state.wallpaper || '');
    this.document.body.classList.toggle('has-custom-wallpaper', Boolean(url));
    this.document.body.style.backgroundImage = url
      ? 'linear-gradient(rgba(2,8,15,.2),rgba(2,8,15,.2)),url("' + url.replace(/["\\\n\r]/g, encodeURIComponent) + '")'
      : '';
  }

  bindRange(inputId, outputId, property) {
    const input = this.document.getElementById(inputId);
    const output = this.document.getElementById(outputId);
    if (!input) return;
    input.value = String(this.state[property]);
    if (output) output.value = this.state[property] + '%';
    input.addEventListener('input', () => {
      this.state[property] = this.clamp(input.value);
      if (output) output.value = this.state[property] + '%';
      this.apply();
      this.save();
    });
  }

  setWallpaper(url, name) {
    if (!url) return;
    this.state.wallpaper = String(url);
    this.state.wallpaperName = String(name || 'Imagen');
    this.save();
    this.applyWallpaper();
    this.notify('Fondo cambiado a "' + this.state.wallpaperName + '".');
  }

  notify(message) {
    const shell = this.window.ArcadeCloudOsShell;
    if (shell?.notify) shell.notify(message, 'success');
  }
}

window.ArcadeCloudOsAppearance = new ArcadeCloudOsAppearance(window, document).init();
