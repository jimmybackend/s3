class ActualizarHoraModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    function actualizarHoraFooter() {
      const ahora = new Date();
      const fecha = ahora.toLocaleDateString('es-MX');
      const hora = ahora.toLocaleTimeString('es-MX');
      const reloj = document.getElementById('relojFooter');
      if (reloj) {
        reloj.innerHTML = `<strong>${fecha} ${hora}</strong>`;
      }
    }
    setInterval(actualizarHoraFooter, 1000);
    actualizarHoraFooter();
    if (typeof actualizarHoraFooter === 'function' && typeof window.actualizarHoraFooter !== 'function') window.actualizarHoraFooter = actualizarHoraFooter;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new ActualizarHoraModule(win, doc).init();
    win.ArcadeCloudDrive.modules['actualizar-hora'] = instance;
    return instance;
  }
}

ActualizarHoraModule.boot();
