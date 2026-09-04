
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
