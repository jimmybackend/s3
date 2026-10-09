/* Nuevo archivo de texto en la carpeta seleccionada de ArcadeCloud OS.
 * El backend es la autoridad sobre ruta, extensión, existencia y permisos. */
(function (win, doc) {
  'use strict';
  const form = doc.getElementById('formCrearArchivoVacio');
  if (!form) return;
  const field = id => doc.getElementById(id);
  const name = field('emptyTextFileName');
  const format = field('emptyTextFileFormat');
  const route = field('emptyTextFileRoute');
  const folderName = field('emptyTextFileFolderName');
  const preview = field('emptyTextFilePreview');
  const message = field('emptyTextFileMessage');
  const submit = field('emptyTextFileSave');
  let saving = false;

  function display(messageText, severity = 'danger') {
    message.className = 'alert ' + (messageText ? 'alert-' + severity : 'd-none');
    message.textContent = messageText;
  }

  function normalizedBase() {
    return String(name.value || '').trim().replace(/\.(?:html?|markdown|md|txt)$/i, '').trim();
  }

  function showPreview() {
    const basename = normalizedBase() || 'Mi documento';
    preview.textContent = basename + '.' + format.value;
  }

  function showModal() {
    const modal = field('modalCrearArchivoVacio');
    if (win.jQuery?.fn?.modal) {
      win.jQuery(modal).modal('show');
    } else if (win.bootstrap?.Modal) {
      (win.bootstrap.Modal.getInstance(modal) || new win.bootstrap.Modal(modal)).show();
    } else {
      display('No está disponible la ventana para crear archivos.');
      return false;
    }
    return true;
  }

  win.openEmptyTextFileCreator = function (folderRoute, label) {
    if (saving || !String(folderRoute || '').trim()) return false;
    form.reset();
    route.value = String(folderRoute || '');
    folderName.textContent = String(label || folderRoute || '');
    display('');
    showPreview();
    const opened = showModal();
    if (opened) {
      // Bootstrap handles focus-trapping and restoring keyboard focus.
      field('modalCrearArchivoVacio').addEventListener('shown.bs.modal', () => name.focus(), { once: true });
    }
    return opened;
  };

  name.addEventListener('input', showPreview);
  format.addEventListener('change', showPreview);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving) return;
    const value = String(name.value || '').trim();
    const destination = String(route.value || '').trim();
    if (!destination || !value || !['txt', 'md', 'html'].includes(format.value)) {
      display('Indica un nombre y elige un formato de archivo.');
      return;
    }
    if (/[/\\:*?"<>|\x00-\x1F\x7F]/.test(value) || /^\.{1,2}$/.test(value)) {
      display('El nombre contiene caracteres no permitidos.');
      return;
    }

    saving = true;
    submit.disabled = true;
    display('');
    try {
      const payload = new URLSearchParams({
        route: destination,
        name: value,
        format: format.value,
        create_empty: '1',
        plain_text: '',
        html_content: ''
      });
      const response = await win.fetch('create_folder_document.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Drive-CSRF': String(win.DRIVE_UPLOAD_CSRF || '')
        },
        body: payload.toString()
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok || json.ok !== true) {
        throw new Error(json.error || json.mensaje || 'No se pudo crear el archivo.');
      }
      const savedName = String(json.data?.nombre_original || preview.textContent);
      // Existing OS filesystem bus refreshes all Explorer instances in this
      // route; a different current folder must not be changed.
      doc.dispatchEvent(new CustomEvent('drive:folder-document-created', {
        detail: { route: destination, name: savedName, data: json.data || {} }
      }));
      doc.dispatchEvent(new Event('drive:storage-changed'));
      if (win.jQuery?.fn?.modal) win.jQuery(field('modalCrearArchivoVacio')).modal('hide');
      else win.bootstrap?.Modal?.getInstance?.(field('modalCrearArchivoVacio'))?.hide();
      win.ArcadeCloudOsShell?.notify?.('Archivo creado: ' + savedName, 'success');
    } catch (error) {
      display(error?.message || 'Error al crear el archivo.');
    } finally {
      saving = false;
      submit.disabled = false;
    }
  });
})(window, document);
