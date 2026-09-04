
(function(){
  const multiForm = document.getElementById('multiDeleteForm');
  const btnDel    = document.getElementById('btnEliminarSeleccionados');

  if (!multiForm || !btnDel) return;

  function setLoading(on){
    if (on) {
      btnDel.dataset.old = btnDel.innerHTML;
      btnDel.disabled = true;
      btnDel.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Eliminando…';
    } else {
      btnDel.disabled = false;
      if (btnDel.dataset.old) btnDel.innerHTML = btnDel.dataset.old;
    }
  }

  function seleccionActual(){
    return Array.from(multiForm.querySelectorAll('input[name="archivos[]"]:checked')).map(cb => cb.value);
  }

  async function refreshBloqueArchivos(){
    if (typeof window.actualizarBloqueArchivos === 'function') {
      await window.actualizarBloqueArchivos({ pagina: 1 });
    } else {
      const res  = await fetch('bloque_archivos.php', { credentials: 'same-origin' });
      const html = await res.text();
      const cont = document.getElementById('bloque-archivos');
      if (cont) cont.innerHTML = html;
    }
  }

  btnDel.addEventListener('click', async () => {
    const seleccionados = seleccionActual();
    if (!seleccionados.length) {
      alert('Selecciona al menos un archivo.');
      return;
    }

    if (!confirm('¿Eliminar los archivos seleccionados?')) {
      return;
    }

    try {
      setLoading(true);
      const body = new URLSearchParams({
        archivos_json: JSON.stringify(seleccionados)
      });

      const res = await fetch('delete_multiple.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body
      });

      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json().catch(() => ({}));
      if (!j.ok) throw new Error(j.error || 'No se pudieron eliminar los archivos.');

      // Limpia checks y "seleccionar todos"
      multiForm.querySelectorAll('input[name="archivos[]"]:checked').forEach(cb => cb.checked = false);
      const selectAll = multiForm.querySelector('#selectAll');
      if (selectAll) selectAll.checked = false;

      // Refresca solo el bloque de archivos
      await refreshBloqueArchivos();

      // (Opcional) toast suave
      // alert('✔ ' + (j.deleted || 0) + ' archivo(s) eliminado(s).');

    } catch (err) {
      console.error(err);
      alert('❌ ' + (err.message || err));
    } finally {
      setLoading(false);
    }
  });
})();
