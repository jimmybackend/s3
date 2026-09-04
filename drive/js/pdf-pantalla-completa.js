class PdfPantallaCompletaModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    function pantallaCompletaPDF() {
      const iframe = document.getElementById('visorPdf');
      // Primero, nos aseguramos de que el iframe esté visible
      if (iframe.style.display === 'none') {
        alert('Abre antes el PDF en el visor.');
        return;
      }
      // Pide fullscreen sobre el iframe
      if (iframe.requestFullscreen) {
        iframe.requestFullscreen();
      } else if (iframe.webkitRequestFullscreen) {
        iframe.webkitRequestFullscreen();
      } else if (iframe.mozRequestFullScreen) {
        iframe.mozRequestFullScreen();
      } else if (iframe.msRequestFullscreen) {
        iframe.msRequestFullscreen();
      } else {
        alert("Tu navegador no soporta pantalla completa.");
      }
    }
    if (typeof pantallaCompletaPDF === 'function' && typeof window.pantallaCompletaPDF !== 'function') window.pantallaCompletaPDF = pantallaCompletaPDF;
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new PdfPantallaCompletaModule(win, doc).init();
    win.ArcadeCloudDrive.modules['pdf-pantalla-completa'] = instance;
    return instance;
  }
}

PdfPantallaCompletaModule.boot();
