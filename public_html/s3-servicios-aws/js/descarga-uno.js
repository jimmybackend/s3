(function () {
  // Lee nombre sugerido de headers (X-Filename o Content-Disposition)
  function getFileNameFromHeaders(res, fallback) {
    const xf = res.headers.get('X-Filename');
    if (xf) {
      try { return decodeURIComponent(xf); } catch { return xf; }
    }
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename\*?=(?:UTF-8''|")?([^\";]+)/i);
    if (m && m[1]) {
      try { return decodeURIComponent(m[1]); } catch { return m[1]; }
    }
    return fallback || 'archivo';
  }

  // Obtiene query param de un href
  function getParamFromHref(href, key) {
    try {
      const url = new URL(href, window.location.origin);
      return url.searchParams.get(key) || '';
    } catch { return ''; }
  }

  // Spinner temporal en el <a>
  function setLinkLoading(a, on) {
    if (!a) return;
    if (on) {
      a.dataset.oldHtml = a.innerHTML;
      a.classList.add('disabled');
      a.style.pointerEvents = 'none';
      a.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Descargando…';
    } else {
      if (a.dataset.oldHtml) a.innerHTML = a.dataset.oldHtml;
      a.classList.remove('disabled');
      a.style.pointerEvents = '';
    }
  }

  // Delegación: clicks en el botón de descarga individual dentro de #bloque-archivos
  document.addEventListener('click', async function (ev) {
    const a = ev.target.closest('a.dropdown-item[href*="descargar.php"]');
    if (!a || !a.closest('#bloque-archivos')) return;

    ev.preventDefault();

    const href    = a.getAttribute('href');
    const nombreQ = getParamFromHref(href, 'nombre');   // nombre visible sugerido
    const archivo = getParamFromHref(href, 'archivo');  // key S3 (por si hace falta fallback)
    const fallbackName = nombreQ
      ? decodeURIComponent(nombreQ)
      : (archivo ? archivo.split('/').pop() : 'archivo');

    try {
      setLinkLoading(a, true);

      const res = await fetch(href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);

      const blob = await res.blob();
      const fileName = getFileNameFromHeaders(res, fallbackName);

      // Disparar descarga sin navegar
      const url = URL.createObjectURL(blob);
      const tmp = document.createElement('a');
      tmp.href = url;
      tmp.download = fileName;
      document.body.appendChild(tmp);
      tmp.click();
      setTimeout(() => {
        URL.revokeObjectURL(url);
        tmp.remove();
      }, 100);

      // No refrescamos nada; UI queda igual
    } catch (err) {
      console.error(err);
      alert('❌ No se pudo descargar el archivo: ' + (err.message || err));
    } finally {
      setLinkLoading(a, false);
    }
  });
})();
