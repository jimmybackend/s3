(function (window, document) {
  'use strict';

  if (window.__arcadeFolderDocumentBound) return;
  window.__arcadeFolderDocumentBound = true;

  const ENDPOINT = 'create_folder_document.php';

  function byId(id) {
    return document.getElementById(id);
  }

  function normalizeRoute(value) {
    return String(value || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/^\/+/, '').replace(/\/+$/, '') + '/';
  }

  function sameRoute(a, b) {
    return normalizeRoute(a) === normalizeRoute(b);
  }

  function setMessage(message, type) {
    const box = byId('folderDocumentMessage');
    if (!box) return;
    box.className = 'alert alert-' + (type || 'info');
    box.textContent = String(message || '');
    box.classList.toggle('d-none', !message);
  }

  function setSaving(saving) {
    const button = byId('folderDocumentSave');
    if (!button) return;
    if (!button.dataset.normalHtml) button.dataset.normalHtml = button.innerHTML;
    button.disabled = !!saving;
    button.innerHTML = saving
      ? '<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...'
      : button.dataset.normalHtml;
  }

  function escapeText(text) {
    const div = document.createElement('div');
    div.textContent = String(text || '');
    return div.innerHTML;
  }

  function safeHref(href) {
    href = String(href || '').trim();
    if (!href || href.charAt(0) === '#') return href;
    try {
      const parsed = new URL(href, window.location.href);
      const protocol = parsed.protocol.toLowerCase();
      return ['http:', 'https:', 'mailto:'].includes(protocol) ? href : '';
    } catch (_) {
      return '';
    }
  }

  function sanitizeHtml(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString('<div id="arcade-paste-root">' + String(html || '') + '</div>', 'text/html');
    const root = doc.getElementById('arcade-paste-root');
    if (!root) return '';

    const allowed = new Set([
      'P', 'BR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
      'STRONG', 'B', 'EM', 'I', 'U', 'S', 'DEL',
      'UL', 'OL', 'LI', 'BLOCKQUOTE', 'PRE', 'CODE',
      'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD',
      'A', 'HR', 'SPAN'
    ]);
    const drop = new Set([
      'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH',
      'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'OPTION',
      'META', 'LINK', 'BASE', 'IMG', 'VIDEO', 'AUDIO', 'CANVAS'
    ]);

    function clean(node) {
      Array.from(node.childNodes).forEach(function (child) {
        if (child.nodeType !== Node.ELEMENT_NODE) return;

        if (drop.has(child.tagName)) {
          child.remove();
          return;
        }

        if (!allowed.has(child.tagName)) {
          const parent = child.parentNode;
          while (child.firstChild) parent.insertBefore(child.firstChild, child);
          child.remove();
          return;
        }

        const tag = child.tagName;
        const allowedAttrs = tag === 'A'
          ? new Set(['href', 'title'])
          : ((tag === 'TD' || tag === 'TH') ? new Set(['colspan', 'rowspan']) : (tag === 'OL' ? new Set(['start']) : new Set()));

        Array.from(child.attributes).forEach(function (attr) {
          if (!allowedAttrs.has(attr.name.toLowerCase())) child.removeAttribute(attr.name);
        });

        if (tag === 'A' && child.hasAttribute('href')) {
          const href = safeHref(child.getAttribute('href'));
          if (!href) child.removeAttribute('href');
          else {
            child.setAttribute('href', href);
            child.setAttribute('rel', 'noopener noreferrer');
          }
        }

        ['colspan', 'rowspan', 'start'].forEach(function (name) {
          if (!child.hasAttribute(name)) return;
          const value = parseInt(child.getAttribute(name), 10);
          if (!Number.isFinite(value) || value < 1 || value > 100) child.removeAttribute(name);
          else child.setAttribute(name, String(value));
        });

        clean(child);
      });
    }

    clean(root);
    return root.innerHTML;
  }

  function insertHtmlAtCursor(editor, html) {
    editor.focus();
    const selection = window.getSelection ? window.getSelection() : null;
    if (!selection || selection.rangeCount === 0 || !editor.contains(selection.anchorNode)) {
      editor.insertAdjacentHTML('beforeend', html);
      return;
    }

    const range = selection.getRangeAt(0);
    range.deleteContents();
    const template = document.createElement('template');
    template.innerHTML = html;
    const fragment = template.content;
    const last = fragment.lastChild;
    range.insertNode(fragment);
    if (last) {
      range.setStartAfter(last);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    }
  }

  function editorPlainText(editor) {
    return String(editor.innerText || editor.textContent || '').replace(/\u00a0/g, ' ').trimEnd();
  }

  function openForFolder(button) {
    const route = String(button.getAttribute('data-route') || '').trim();
    const name = String(button.getAttribute('data-name') || route).trim();
    const routeInput = byId('folderDocumentRoute');
    const folderName = byId('folderDocumentFolderName');
    const filename = byId('folderDocumentName');
    const format = byId('folderDocumentFormat');
    const editor = byId('folderDocumentEditor');

    if (routeInput) routeInput.value = route;
    if (folderName) folderName.textContent = name;
    if (filename) filename.value = '';
    if (format) format.value = 'html';
    if (editor) {
      editor.innerHTML = '';
      delete editor.dataset.plainSource;
    }
    setMessage('', 'info');
    updateFormatHelp();
  }

  function updateFormatHelp() {
    const format = byId('folderDocumentFormat');
    const help = byId('folderDocumentFormatHelp');
    if (!format || !help) return;

    if (format.value === 'html') {
      help.textContent = 'HTML conserva títulos, negritas, cursivas, listas, tablas, enlaces y bloques de código. Los estilos propios de ChatGPT no se guardan.';
    } else if (format.value === 'md') {
      help.textContent = 'Markdown guarda texto editable. Si el portapapeles trae sintaxis Markdown, se conserva en el archivo .md.';
    } else {
      help.textContent = 'TXT guarda únicamente texto plano, sin formato visual.';
    }
  }

  async function pasteFromClipboard() {
    const editor = byId('folderDocumentEditor');
    if (!editor) return;

    try {
      if (navigator.clipboard && typeof navigator.clipboard.read === 'function') {
        const items = await navigator.clipboard.read();
        for (const item of items) {
          let html = '';
          let text = '';
          if (item.types.includes('text/html')) html = await (await item.getType('text/html')).text();
          if (item.types.includes('text/plain')) text = await (await item.getType('text/plain')).text();
          if (html || text) {
            editor.innerHTML = html ? sanitizeHtml(html) : escapeText(text).replace(/\n/g, '<br>');
            if (text) editor.dataset.plainSource = text;
            setMessage('Contenido pegado. Revisa el nombre y el formato antes de guardar.', 'info');
            return;
          }
        }
      }

      if (navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
        const text = await navigator.clipboard.readText();
        editor.textContent = text;
        editor.dataset.plainSource = text;
        setMessage('Texto pegado desde el portapapeles.', 'info');
        return;
      }

      throw new Error('clipboard_unavailable');
    } catch (_) {
      setMessage('El navegador no permitió leer el portapapeles. Mantén pulsado dentro del área de contenido y elige Pegar.', 'warning');
      editor.focus();
    }
  }

  async function saveDocument(form) {
    const route = String(byId('folderDocumentRoute')?.value || '').trim();
    const name = String(byId('folderDocumentName')?.value || '').trim();
    const format = String(byId('folderDocumentFormat')?.value || 'html').trim();
    const editor = byId('folderDocumentEditor');

    if (!route || !name || !editor) {
      setMessage('Completa la carpeta, el nombre y el contenido.', 'danger');
      return;
    }

    const richHtml = sanitizeHtml(editor.innerHTML || '');
    const livePlain = editorPlainText(editor);
    const plainText = editor.dataset.plainSource && !editor.dataset.editedAfterPaste
      ? editor.dataset.plainSource
      : livePlain;

    if (!livePlain && !plainText) {
      setMessage('Pega o escribe contenido antes de guardar.', 'warning');
      editor.focus();
      return;
    }

    const body = new URLSearchParams();
    body.set('route', route);
    body.set('name', name);
    body.set('format', format);
    body.set('plain_text', plainText || livePlain);
    body.set('html_content', richHtml);

    setSaving(true);
    setMessage('', 'info');

    try {
      const response = await fetch(ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });

      const data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) {
        throw new Error(data.error || data.mensaje || 'No se pudo guardar el documento.');
      }

      const savedName = data.data && data.data.nombre_original ? data.data.nombre_original : name;
      setMessage('Guardado: ' + savedName, 'success');

      if (sameRoute(route, window.rutaActual || '')) {
        try {
          if (typeof window.actualizarBloqueArchivos === 'function') {
            await window.actualizarBloqueArchivos({ pagina: 1, ruta: route });
          }
        } catch (_) {}
      }

      window.setTimeout(function () {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery('#modalCrearDocumentoCarpeta').modal('hide');
        }
      }, 650);
    } catch (error) {
      setMessage(error && error.message ? error.message : 'No se pudo guardar el documento.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  document.addEventListener('click', function (event) {
    const action = event.target.closest ? event.target.closest('.js-folder-document') : null;
    if (action) openForFolder(action);

    const pasteButton = event.target.closest ? event.target.closest('#folderDocumentPaste') : null;
    if (pasteButton) {
      event.preventDefault();
      pasteFromClipboard();
    }
  });

  document.addEventListener('change', function (event) {
    if (event.target && event.target.id === 'folderDocumentFormat') updateFormatHelp();
  });

  document.addEventListener('input', function (event) {
    if (event.target && event.target.id === 'folderDocumentEditor') {
      event.target.dataset.editedAfterPaste = '1';
    }
  });

  document.addEventListener('paste', function (event) {
    const editor = event.target && event.target.id === 'folderDocumentEditor' ? event.target : null;
    if (!editor || !event.clipboardData) return;

    const html = event.clipboardData.getData('text/html');
    const text = event.clipboardData.getData('text/plain');
    if (!html && !text) return;

    event.preventDefault();
    const safe = html ? sanitizeHtml(html) : escapeText(text).replace(/\n/g, '<br>');
    insertHtmlAtCursor(editor, safe);

    if (!editor.dataset.plainSource && text) editor.dataset.plainSource = text;
    else delete editor.dataset.plainSource;
    delete editor.dataset.editedAfterPaste;
  });

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form || form.id !== 'formCrearDocumentoCarpeta') return;
    event.preventDefault();
    saveDocument(form);
  });
})(window, document);
