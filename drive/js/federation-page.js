class FederationPageModule {
  constructor(doc) {
    this.document = doc;
    this.form = null;
    this.input = null;
    this.dropzone = null;
    this.busy = false;
  }

  init() {
    this.form = this.document.getElementById('inspectForm');
    this.input = this.document.getElementById('arcadeFile');
    this.dropzone = this.document.getElementById('dropZone');
    if (!this.form || !this.input || !this.dropzone) return this;

    ['dragenter', 'dragover'].forEach((name) => {
      this.dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        if (!this.busy) this.dropzone.classList.add('is-dragging');
      });
    });

    ['dragleave', 'drop'].forEach((name) => {
      this.dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        this.dropzone.classList.remove('is-dragging');
      });
    });

    this.dropzone.addEventListener('drop', (event) => {
      const files = event.dataTransfer && event.dataTransfer.files;
      if (!files || !files.length || this.busy) return;
      this.input.files = files;
      this.submitSelected();
    });

    this.dropzone.addEventListener('click', () => {
      if (!this.busy) this.input.click();
    });

    this.dropzone.addEventListener('keydown', (event) => {
      if ((event.key === 'Enter' || event.key === ' ') && !this.busy) {
        event.preventDefault();
        this.input.click();
      }
    });

    this.input.addEventListener('change', () => this.submitSelected());
    this.form.addEventListener('submit', () => this.setBusy(true));
    return this;
  }

  submitSelected() {
    const file = this.input.files && this.input.files[0];
    if (!file) return;

    if (!String(file.name || '').toLowerCase().endsWith('.arcadelink')) {
      this.showError('Selecciona un archivo .arcadelink válido.');
      this.input.value = '';
      return;
    }

    this.setBusy(true, file.name);
    if (typeof this.form.requestSubmit === 'function') this.form.requestSubmit();
    else this.form.submit();
  }

  setBusy(value, fileName = '') {
    this.busy = value;
    this.dropzone.classList.toggle('is-busy', value);
    const icon = this.document.getElementById('dropZoneIcon');
    const title = this.document.getElementById('dropZoneTitle');
    const text = this.document.getElementById('dropZoneText');

    if (value) {
      if (icon) icon.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
      if (title) title.textContent = 'Validando ArcadeLink…';
      if (text) text.textContent = fileName || 'Comprobando firma y origen del recurso';
    }
  }

  showError(message) {
    const box = this.document.getElementById('federationClientError');
    if (!box) return;
    box.textContent = message;
    box.classList.remove('d-none');
  }

  static boot() {
    new FederationPageModule(document).init();
  }
}

document.addEventListener('DOMContentLoaded', () => FederationPageModule.boot());
