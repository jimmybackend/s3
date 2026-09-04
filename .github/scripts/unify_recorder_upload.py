from pathlib import Path
import re

p = Path('drive/js/soportesMediaTypes.js')
text = p.read_text(encoding='utf-8').replace('\r\n','\n').replace('\r','\n')

pattern = r'''  /\*\* Subida directa a S3 por URL presignada \(PUT\) \*/\n  function subirBlob\(blob, filename, mime\)\{.*?\n  \}\n\}\)\(\);\s*$'''
replacement = r'''  /**
   * Subida de la grabación mediante el mismo flujo unificado del Drive.
   * La ruta queda congelada al pulsar Guardar y FileS3 se registra solo
   * después de que el PUT a S3 terminó correctamente.
   */
  async function subirBlob(blob, filename, mime){
    let rutaObjetivo = '';
    try {
      rutaObjetivo = window.DriveUploadDestination.capture();
    } catch (err) {
      estado(err.message || 'No se pudo determinar la carpeta destino');
      return;
    }

    estado('Preparando subida a ' + rutaObjetivo + '…');
    if (bar) {
      bar.parentElement && bar.parentElement.classList.remove('d-none');
      bar.style.width = '0%';
    }
    habilitar(btnGuardar, false);

    try {
      const API = window.UPLOAD_API || 'api/upload.php';
      const initParams = new URLSearchParams({
        mode: 'local_put',
        action: 'init',
        nombre: filename,
        ruta_objetivo: rutaObjetivo
      });

      const initResponse = await fetch(API + '?' + initParams.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const initJson = await initResponse.json().catch(() => null);
      if (!initResponse.ok || !initJson || !initJson.ok || !initJson.url || !initJson.upload_token) {
        throw new Error((initJson && initJson.error) || ('No se pudo iniciar la subida (HTTP ' + initResponse.status + ')'));
      }

      estado('Subiendo a S3…');
      await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', initJson.url, true);
        xhr.setRequestHeader('Content-Type', mime || 'application/octet-stream');
        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable && bar) {
            bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
          }
        };
        xhr.onload = () => {
          if (xhr.status >= 200 && xhr.status < 300) resolve();
          else reject(new Error('Fallo PUT S3 (' + xhr.status + ')'));
        };
        xhr.onerror = () => reject(new Error('Error de red durante la subida'));
        xhr.send(blob);
      });

      estado('Registrando archivo…');
      const completeBody = new URLSearchParams({
        upload_token: initJson.upload_token,
        tamano: String(blob.size || 0)
      });
      const completeResponse = await fetch(API + '?mode=local_put&action=complete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: completeBody.toString()
      });
      const completeJson = await completeResponse.json().catch(() => null);
      if (!completeResponse.ok || !completeJson || !completeJson.ok) {
        throw new Error((completeJson && completeJson.error) || ('No se pudo registrar en FileS3 (HTTP ' + completeResponse.status + ')'));
      }

      if (bar) bar.style.width = '100%';
      estado('Guardado en ' + rutaObjetivo);
      await window.DriveUploadDestination.afterSuccess(rutaObjetivo);

      setTimeout(() => {
        try {
          if (typeof jQuery !== 'undefined' && jQuery(modalEl).modal) {
            jQuery(modalEl).modal('hide');
          } else if (window.bootstrap?.Modal) {
            (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
          }
        } catch(e){}
      }, 600);
    } catch (err) {
      console.error(err);
      estado(err.message || 'Error subiendo la grabación');
      habilitar(btnGuardar, true);
    }
  }
})();
'''

out, n = re.subn(pattern, replacement, text, count=1, flags=re.S)
if n != 1:
    raise SystemExit(f'No se encontró bloque de subida de grabadora: {n}')
p.write_text(out, encoding='utf-8')

# El endpoint antiguo queda sin consumidores activos y violaba la regla de ruta inmutable.
old = Path('drive/firma_grabadora.php')
if old.exists():
    old.unlink()
