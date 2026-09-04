class MediaFloatingModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
      const toggleBtn = document.getElementById('toggleMediaBtn');
      const mediaFloating = document.getElementById('mediaFloating');

      if (toggleBtn && mediaFloating) {
        toggleBtn.addEventListener('click', () => {
          if (mediaFloating.style.display === 'none') {
            mediaFloating.style.display = 'block';
            toggleBtn.textContent = 'Ocultar reproductor';
          } else {
            mediaFloating.style.display = 'none';
            toggleBtn.textContent = 'Mostrar reproductor';
          }
        });
      }

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new MediaFloatingModule(win, doc).init();
    win.ArcadeCloudDrive.modules['mediaFloating'] = instance;
    return instance;
  }
}

MediaFloatingModule.boot();
