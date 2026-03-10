
function verMetadatos(nombre, datos) {
  const contenedor = document.getElementById('cuerpoMetadatos');
  const titulo = document.getElementById('metaTitulo');

  if (!contenedor || !titulo) return;

  titulo.textContent = "Metadatos de: " + nombre;

  let html = '<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Clave</th><th>Valor</th></tr></thead><tbody>';

  for (const [k, v] of Object.entries(datos)) {
    const valor = typeof v === 'object' ? JSON.stringify(v, null, 2) : v;
    html += `<tr><td>${escapeHtml(k)}</td><td>${escapeHtml(valor)}</td></tr>`;
  }

  html += '</tbody></table></div>';
  contenedor.innerHTML = html;

  $('#modalMetadatos').modal('show');
}

function escapeHtml(text) {
  if (typeof text !== 'string') return text;
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
