/* Touchable selection and clipboard controls for the existing Monaco text editor.
 * Mobile users can put the caret at one boundary, mark it, move to the other
 * boundary and mark it without relying on tiny OS drag handles. */
class ArcadeCloudMobileEditorSelection {
  constructor(win, doc, editor) {
    this.win = win;
    this.doc = doc;
    this.editor = editor;
    this.start = null;
    this.end = null;
    this.bar = doc.getElementById('mobileSelectionBar');
    this.status = doc.getElementById('mobileSelectionStatus');
    this.fallback = doc.getElementById('mobilePastePanel');
    this.fallbackText = doc.getElementById('mobilePasteText');
  }

  init() {
    if (!this.bar || !this.editor) return this;
    const actions = {
      start: () => this.mark('start'),
      end: () => this.mark('end'),
      all: () => this.selectAll(),
      copy: () => this.copy(false),
      cut: () => this.copy(true),
      paste: () => this.paste(),
      insert: () => this.insertFallback(),
      cancel: () => this.hideFallback()
    };
    this.bar.addEventListener('click', event => {
      const button = event.target.closest('[data-mobile-edit]');
      if (!button || button.disabled) return;
      event.preventDefault();
      const action = actions[button.dataset.mobileEdit];
      if (action) void action();
    });
    this.editor.onDidChangeModel?.(() => this.clearMarks());
    this.describe('Toca el texto, marca Inicio; mueve el cursor y marca Fin.');
    return this;
  }

  describe(message, severity = 'info') {
    if (!this.status) return;
    this.status.textContent = message;
    this.status.dataset.severity = severity;
  }

  point() {
    const pos = this.editor.getPosition();
    return pos ? { lineNumber: pos.lineNumber, column: pos.column } : null;
  }

  static ordered(start, end) {
    const early = start.lineNumber < end.lineNumber ||
      (start.lineNumber === end.lineNumber && start.column <= end.column);
    return early ? [start, end] : [end, start];
  }

  clearMarks() {
    this.start = null;
    this.end = null;
  }

  mark(which) {
    const pos = this.point();
    if (!pos) return;
    if (which === 'start') {
      this.start = pos;
      this.end = null;
    } else {
      if (!this.start) {
        this.describe('Marca primero el inicio de la selección.', 'warn');
        return;
      }
      this.end = pos;
    }
    if (this.start && this.end) {
      const range = this.markedRange();
      this.editor.setSelection(range);
      this.editor.revealRangeInCenterIfOutsideViewport?.(range);
      this.describe('Texto seleccionado. Ya puedes copiar, cortar o pegar.');
    } else {
      this.describe('Inicio en línea ' + pos.lineNumber + ', columna ' + pos.column +
        '. Coloca el cursor al final y pulsa Fin.');
    }
  }

  markedRange() {
    if (!this.start || !this.end) return null;
    const [a, b] = ArcadeCloudMobileEditorSelection.ordered(this.start, this.end);
    return new this.win.monaco.Range(a.lineNumber, a.column, b.lineNumber, b.column);
  }

  activeRange() {
    return this.markedRange() || this.editor.getSelection();
  }

  selectedText() {
    const range = this.activeRange();
    if (!range || range.isEmpty()) return '';
    return this.editor.getModel().getValueInRange(range);
  }

  selectAll() {
    const model = this.editor.getModel();
    if (!model) return;
    const fullRange = model.getFullModelRange();
    this.clearMarks();
    this.editor.setSelection(fullRange);
    this.editor.revealRangeInCenterIfOutsideViewport?.(fullRange);
    this.describe('Se seleccionó todo el documento.');
  }

  async writeClipboard(text) {
    try {
      if (this.win.navigator?.clipboard?.writeText && this.win.isSecureContext !== false) {
        await this.win.navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) { /* Try the legacy browser command next. */ }

    if (!this.doc.execCommand) return false;
    const scratch = this.doc.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.top = '-1000px';
    this.doc.body.appendChild(scratch);
    scratch.select();
    let result = false;
    try { result = this.doc.execCommand('copy') === true; } catch (_) {}
    scratch.remove();
    return result;
  }

  async copy(cut) {
    const range = this.activeRange();
    const selected = this.selectedText();
    if (!selected || !range) {
      this.describe('Marca Inicio y Fin o selecciona texto antes de copiar.', 'warn');
      return;
    }
    const success = await this.writeClipboard(selected);
    if (!success) {
      this.describe('El navegador bloqueó la copia. Mantén pulsado el texto y usa Copiar.', 'warn');
      return;
    }
    if (cut) {
      this.replaceRange(range, '');
      this.describe('Texto cortado y copiado al portapapeles.', 'ok');
    } else {
      this.describe('Texto copiado al portapapeles.', 'ok');
    }
  }

  replaceRange(range, text) {
    this.editor.pushUndoStop();
    this.editor.executeEdits('mobile-toolbar', [{ range, text, forceMoveMarkers: true }]);
    this.editor.pushUndoStop();
    this.clearMarks();
    this.editor.focus();
  }

  async paste() {
    try {
      if (!this.win.navigator?.clipboard?.readText || this.win.isSecureContext === false) {
        throw new Error('clipboard_unavailable');
      }
      const text = await this.win.navigator.clipboard.readText();
      if (!text) {
        this.describe('El portapapeles está vacío.', 'warn');
        return;
      }
      const range = this.activeRange();
      if (!range) return;
      this.replaceRange(range, text);
      this.hideFallback();
      this.describe('Texto pegado en el editor.', 'ok');
    } catch (_) {
      if (this.fallback) this.fallback.hidden = false;
      this.fallbackText?.focus();
      this.describe('Usa Pegar dentro del recuadro y pulsa Insertar texto.', 'warn');
    }
  }

  insertFallback() {
    const value = this.fallbackText?.value || '';
    if (!value) {
      this.describe('Primero pega o escribe el texto en el recuadro.', 'warn');
      return;
    }
    const range = this.activeRange();
    if (!range) return;
    this.replaceRange(range, value);
    this.hideFallback();
    this.describe('Texto insertado correctamente.', 'ok');
  }

  hideFallback() {
    if (this.fallback) this.fallback.hidden = true;
    if (this.fallbackText) this.fallbackText.value = '';
  }
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { ArcadeCloudMobileEditorSelection };
}
if (typeof window !== 'undefined') {
  window.ArcadeCloudMobileEditorSelection = ArcadeCloudMobileEditorSelection;
}
