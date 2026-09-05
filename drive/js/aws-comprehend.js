class AwsComprehendModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  pct(value) {
    const n = Number(value);
    return Number.isFinite(n) ? (n * 100).toFixed(1) + '%' : '—';
  }

  showModal() {
    const el = this.document.getElementById('modalComprehend');
    if (!el) return false;

    if (
      this.window.jQuery &&
      this.window.jQuery.fn &&
      typeof this.window.jQuery.fn.modal === 'function'
    ) {
      this.window.jQuery(el).modal('show');
      return true;
    }

    if (this.window.bootstrap && this.window.bootstrap.Modal) {
      const Modal = this.window.bootstrap.Modal;
      if (typeof Modal.getOrCreateInstance === 'function') {
        Modal.getOrCreateInstance(el).show();
      } else {
        new Modal(el).show();
      }
      return true;
    }

    return false;
  }

  render(data) {
    const body = this.document.getElementById('comprehendBody');
    if (!body) return;

    const phrases = Array.isArray(data.key_phrases) ? data.key_phrases : [];
    const entities = Array.isArray(data.entities) ? data.entities : [];
    const pii = Array.isArray(data.pii) ? data.pii : [];
    const warnings = Array.isArray(data.warnings) ? data.warnings : [];

    let html = '';
    html += '<div class="mb-3"><strong>Idioma:</strong> ' + this.esc(data.language || 'No determinado') +
      ' &nbsp; <strong>Sentimiento:</strong> ' + this.esc(data.sentiment || 'No disponible') + '</div>';

    if (data.truncated) {
      html += '<div class="alert alert-warning py-2">El archivo supera el límite del análisis inmediato; se analizaron los primeros ' +
        this.esc(data.bytes_analyzed || 0) + ' bytes.</div>';
    }

    if (warnings.length) {
      html += '<div class="alert alert-secondary py-2"><strong>Avisos:</strong><ul class="mb-0">' +
        warnings.map((warning) => '<li>' + this.esc(warning) + '</li>').join('') + '</ul></div>';
    }

    html += '<h6>Frases clave</h6>';
    html += phrases.length
      ? '<div class="mb-3">' + phrases.map((phrase) =>
          '<span class="badge badge-info mr-1 mb-1">' +
          this.esc(phrase.text) + ' · ' + this.pct(phrase.score) + '</span>'
        ).join('') + '</div>'
      : '<p class="text-muted">No se detectaron frases clave.</p>';

    html += '<h6>Entidades</h6>';
    if (entities.length) {
      html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Texto</th><th>Tipo</th><th>Conf.</th></tr></thead><tbody>';
      entities.forEach((entity) => {
        html += '<tr><td>' + this.esc(entity.text) + '</td><td>' + this.esc(entity.type) + '</td><td>' + this.pct(entity.score) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<p class="text-muted">No se detectaron entidades.</p>';
    }

    html += '<h6>PII detectada</h6>';
    if (pii.length) {
      html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Tipo</th><th>Conf.</th><th>Rango</th></tr></thead><tbody>';
      pii.forEach((entity) => {
        html += '<tr><td>' + this.esc(entity.type) + '</td><td>' + this.pct(entity.score) + '</td><td>' + this.esc(entity.begin) + '–' + this.esc(entity.end) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    } else {
      html += '<p class="text-muted mb-0">No se detectó PII.</p>';
    }

    body.innerHTML = html;
  }

  analyze(key, name) {
    const fileKey = String(key || '').trim();
    if (!fileKey) {
      this.window.alert('Archivo inválido.');
      return;
    }

    const title = this.document.getElementById('comprehendTitle');
    const body = this.document.getElementById('comprehendBody');

    if (title) {
      title.textContent = 'Amazon Comprehend · ' + (name || fileKey);
    }

    if (body) {
      body.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin"></i> Analizando texto…</div>';
    }

    this.showModal();

    this.window.fetch('comprehend_archivo.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new URLSearchParams({ key: fileKey })
    })
      .then((response) => response.text().then((text) => {
        let json = null;
        try { json = JSON.parse(text); } catch (_) {}

        if (!response.ok || !json || !json.ok) {
          throw new Error((json && json.error) || text || ('HTTP ' + response.status));
        }

        return json.analysis || {};
      }))
      .then((data) => this.render(data))
      .catch((error) => {
        if (body) {
          body.innerHTML = '<div class="alert alert-danger mb-0">' + this.esc(error.message || error) + '</div>';
        }
      });
  }

  init() {
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};

    const instance = new AwsComprehendModule(win, doc).init();
    win.ArcadeCloudDrive.modules['aws-comprehend'] = instance;
    return instance;
  }
}

class AwsFileActionRouter {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.bound = false;
    this.onClick = this.onClick.bind(this);
  }

  invoke(functionName, args) {
    const fn = this.window[functionName];
    if (typeof fn !== 'function') {
      this.window.alert('La acción AWS todavía no está disponible: ' + functionName);
      return false;
    }

    fn.apply(this.window, args);
    return true;
  }

  onClick(event) {
    const button = event.target && event.target.closest
      ? event.target.closest('.aws-file-action')
      : null;

    if (!button) return;

    const key = button.getAttribute('data-key') || '';
    const name = button.getAttribute('data-nombre') || key;
    let handled = false;

    if (button.matches('.js-textract')) {
      handled = this.invoke('extraerTexto', [key]);
    } else if (button.matches('.js-rekognition')) {
      handled = this.invoke('abrirModalRekognition', [key]);
    } else if (button.matches('.js-traducir')) {
      handled = this.invoke('abrirModalTraducir', [key, name]);
    } else if (button.matches('.js-polly')) {
      handled = this.invoke('abrirModalPolly', [key, name]);
    } else if (button.matches('.js-transcribir')) {
      handled = this.invoke('abrirModalTranscribir', [key, name]);
    } else if (button.matches('.js-comprehend')) {
      const module = this.window.ArcadeCloudDrive &&
        this.window.ArcadeCloudDrive.modules &&
        this.window.ArcadeCloudDrive.modules['aws-comprehend'];

      if (module && typeof module.analyze === 'function') {
        module.analyze(key, name);
        handled = true;
      } else {
        this.window.alert('Amazon Comprehend todavía no está disponible.');
      }
    }

    if (!handled) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }
  }

  init() {
    if (!this.bound) {
      this.document.addEventListener('click', this.onClick, true);
      this.bound = true;
    }
    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};

    const previous = win.ArcadeCloudDrive.modules['aws-action-router'];
    if (previous instanceof AwsFileActionRouter) {
      return previous;
    }

    const instance = new AwsFileActionRouter(win, doc).init();
    win.ArcadeCloudDrive.modules['aws-action-router'] = instance;
    return instance;
  }
}

AwsComprehendModule.boot();
AwsFileActionRouter.boot();
