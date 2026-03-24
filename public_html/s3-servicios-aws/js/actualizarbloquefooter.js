/*
 * Recarga el footer sin recargar toda la página
 */
function actualizarBloqueFooter() {
  fetch('bloque_footer.php', { credentials: 'same-origin' })
    .then(resp => {
      if (!resp.ok) throw new Error(`Footer HTTP ${resp.status}`);
      return resp.text();
    })
    .then(html => {
      const footer = document.getElementById('bloque-footer');
      if (footer) footer.innerHTML = html;
    })
    .catch(err => console.error('Error al recargar footer:', err));
}
