class RecargarPaginaModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    function recargarPagina() {
      location.reload();
    }
    if (typeof recargarPagina === 'function' && typeof window.recargarPagina !== 'function') window.recargarPagina = recargarPagina;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new RecargarPaginaModule(win, doc).init();
    win.ArcadeCloudDrive.modules['recargarPagina'] = instance;
    return instance;
  }
}

RecargarPaginaModule.boot();
