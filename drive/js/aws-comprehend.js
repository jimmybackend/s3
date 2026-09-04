class AwsComprehendModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;
    (function (window, document) {
      'use strict';

      function esc(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function pct(value) {
        var n = Number(value);
        return Number.isFinite(n) ? (n * 100).toFixed(1) + '%' : '—';
      }

      function showModal() {
        var el = document.getElementById('modalComprehend');
        if (!el) return;
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function') {
          window.jQuery(el).modal('show');
          return;
        }
        if (window.bootstrap && window.bootstrap.Modal) {
          window.bootstrap.Modal.getOrCreateInstance(el).show();
        }
      }

      function render(data) {
        var body = document.getElementById('comprehendBody');
        if (!body) return;

        var phrases = Array.isArray(data.key_phrases) ? data.key_phrases : [];
        var entities = Array.isArray(data.entities) ? data.entities : [];
        var pii = Array.isArray(data.pii) ? data.pii : [];
        var warnings = Array.isArray(data.warnings) ? data.warnings : [];

        var html = '';
        html += '<div class="mb-3"><strong>Idioma:</strong> ' + esc(data.language || 'No determinado') +
          ' &nbsp; <strong>Sentimiento:</strong> ' + esc(data.sentiment || 'No disponible') + '</div>';

        if (data.truncated) {
          html += '<div class="alert alert-warning py-2">El archivo supera el límite del análisis inmediato; se analizaron los primeros ' +
            esc(data.bytes_analyzed || 0) + ' bytes.</div>';
        }

        if (warnings.length) {
          html += '<div class="alert alert-secondary py-2"><strong>Avisos:</strong><ul class="mb-0">' +
            warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>';
        }

        html += '<h6>Frases clave</h6>';
        html += phrases.length
          ? '<div class="mb-3">' + phrases.map(function (p) {
              return '<span class="badge badge-info mr-1 mb-1">' + esc(p.text) + ' · ' + pct(p.score) + '</span>';
            }).join('') + '</div>'
          : '<p class="text-muted">No se detectaron frases clave.</p>';

        html += '<h6>Entidades</h6>';
        if (entities.length) {
          html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Texto</th><th>Tipo</th><th>Conf.</th></tr></thead><tbody>';
          entities.forEach(function (e) {
            html += '<tr><td>' + esc(e.text) + '</td><td>' + esc(e.type) + '</td><td>' + pct(e.score) + '</td></tr>';
          });
          html += '</tbody></table></div>';
        } else {
          html += '<p class="text-muted">No se detectaron entidades.</p>';
        }

        html += '<h6>PII detectada</h6>';
        if (pii.length) {
          html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Tipo</th><th>Conf.</th><th>Rango</th></tr></thead><tbody>';
          pii.forEach(function (e) {
            html += '<tr><td>' + esc(e.type) + '</td><td>' + pct(e.score) + '</td><td>' + esc(e.begin) + '–' + esc(e.end) + '</td></tr>';
          });
          html += '</tbody></table></div>';
        } else {
          html += '<p class="text-muted mb-0">No se detectó PII.</p>';
        }

        body.innerHTML = html;
      }

      document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-comprehend');
        if (!button) return;

        event.preventDefault();
        var key = button.getAttribute('data-key') || '';
        var name = button.getAttribute('data-nombre') || key;
        var title = document.getElementById('comprehendTitle');
        var body = document.getElementById('comprehendBody');
        if (title) title.textContent = 'Amazon Comprehend · ' + name;
        if (body) body.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-spinner fa-spin"></i> Analizando texto…</div>';
        showModal();

        fetch('comprehend_archivo.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({ key: key })
        })
          .then(function (response) {
            return response.text().then(function (text) {
              var json = null;
              try { json = JSON.parse(text); } catch (_) {}
              if (!response.ok || !json || !json.ok) {
                throw new Error((json && json.error) || text || ('HTTP ' + response.status));
              }
              return json.analysis || {};
            });
          })
          .then(render)
          .catch(function (error) {
            if (body) body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(error.message || error) + '</div>';
          });
      });
    })(window, document);

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

AwsComprehendModule.boot();
