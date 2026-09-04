from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'
SRC = DRIVE / 'src'
JS = DRIVE / 'js'


def write(path: Path, content: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')

# -----------------------------------------------------------------------------
# 1) ApplicationKernel: elimina el global drive_app().
# -----------------------------------------------------------------------------
write(SRC / 'Core/ApplicationKernel.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use mysqli;
use RuntimeException;

final class ApplicationKernel
{
    private static ?DriveApplication $application = null;

    private function __construct()
    {
    }

    public static function boot(mysqli $db): DriveApplication
    {
        if (self::$application === null) {
            self::$application = DriveApplication::boot($db);
        }
        return self::$application;
    }

    public static function app(): DriveApplication
    {
        if (self::$application === null) {
            throw new RuntimeException('DriveApplication no fue inicializada.');
        }
        return self::$application;
    }

    public static function resetForTests(): void
    {
        self::$application = null;
    }
}
''')

bootstrap_path = DRIVE / 'app_bootstrap.php'
bootstrap = bootstrap_path.read_text(encoding='utf-8')
old_tail = r'''$driveApplication = \ArcadeCloud\Drive\Core\DriveApplication::boot($db_connection);

function drive_app(): \ArcadeCloud\Drive\Core\DriveApplication
{
    global $driveApplication;
    if (!$driveApplication instanceof \ArcadeCloud\Drive\Core\DriveApplication) {
        throw new RuntimeException('DriveApplication no fue inicializada.');
    }
    return $driveApplication;
}

define('APP_BOOTSTRAP_LOADED', true);
'''
new_tail = r'''\ArcadeCloud\Drive\Core\ApplicationKernel::boot($db_connection);

define('APP_BOOTSTRAP_LOADED', true);
'''
if old_tail not in bootstrap:
    raise SystemExit('No se encontró el bootstrap global esperado.')
bootstrap_path.write_text(bootstrap.replace(old_tail, new_tail, 1), encoding='utf-8')

for p in DRIVE.rglob('*.php'):
    if p == bootstrap_path:
        continue
    text = p.read_text(encoding='utf-8', errors='ignore')
    if 'drive_app()' in text:
        text = text.replace('drive_app()', r'\ArcadeCloud\Drive\Core\ApplicationKernel::app()')
        p.write_text(text, encoding='utf-8')

# -----------------------------------------------------------------------------
# 2) FileBlockApp: extrae TODO el JS inline de bloque_archivos.php.
# -----------------------------------------------------------------------------
block_path = DRIVE / 'bloque_archivos.php'
block = block_path.read_text(encoding='utf-8')
start_marker = '<script>\n/* =========================================================\n   BLOQUE ARCHIVOS - UI'
start = block.find(start_marker)
if start < 0:
    raise SystemExit('No se encontró el script inline de bloque_archivos.php')
end = block.find('</script>', start)
if end < 0:
    raise SystemExit('No se encontró cierre script de bloque_archivos.php')
end += len('</script>')
block = block[:start] + block[end:]
block = block.replace('onclick="deleteSelected()"', 'data-file-bulk-action="delete"')
block = block.replace('onclick="downloadSelected()"', 'data-file-bulk-action="download"')
block = block.replace('onclick="moveSelected()"', 'data-file-bulk-action="move"')
block_path.write_text(block, encoding='utf-8')

write(JS / 'file-block.js', r'''class FileBlockApp {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
    this.paginationHandler = this.onPaginationClick.bind(this);
    this.changeHandler = this.onChange.bind(this);
    this.bulkHandler = this.onBulkClick.bind(this);
    this.refreshHandler = this.refresh.bind(this);
    this.bound = false;
  }

  init() {
    if (!this.bound) {
      this.document.addEventListener('click', this.paginationHandler);
      this.document.addEventListener('click', this.bulkHandler);
      this.document.addEventListener('change', this.changeHandler);
      this.document.addEventListener('bloque-archivos:actualizado', this.refreshHandler);
      this.document.addEventListener('DOMContentLoaded', this.refreshHandler, { once: true });
      this.bound = true;
    }
    this.refresh();
    return this;
  }

  refresh() {
    this.initTooltips();
    this.syncContext();
    this.finishLoader();
    this.updateSelectionCount();
  }

  initTooltips() {
    try {
      if (this.window.jQuery && typeof this.window.jQuery.fn.tooltip === 'function') {
        this.window.jQuery('[data-toggle="tooltip"]').tooltip({ html: false, container: 'body' });
      }
    } catch (_) {}
  }

  syncContext() {
    const context = this.document.getElementById('archivosContexto');
    if (context?.dataset?.rutaActual) {
      this.window.rutaActual = context.dataset.rutaActual;
    }

    const data = this.document.getElementById('imagenesGaleriaData');
    if (!data) {
      this.window.imagenesGaleria = [];
      return;
    }

    try {
      const parsed = JSON.parse(data.textContent || '[]');
      this.window.imagenesGaleria = Array.isArray(parsed) ? parsed : [];
    } catch (_) {
      this.window.imagenesGaleria = [];
    }
  }

  getCurrentLimit() {
    const fromSelect = this.document.querySelector('#formLimite select[name="limite"]');
    if (fromSelect?.value?.trim()) return String(fromSelect.value);

    const fromContext = this.document.getElementById('archivosContexto')?.dataset?.limite;
    if (fromContext?.trim()) return String(fromContext);

    const fromUrl = new URLSearchParams(this.window.location.search).get('limite');
    if (fromUrl?.trim()) return String(fromUrl);

    const candidates = [
      this.document.querySelector('select[name="limite"]'),
      this.document.getElementById('limite'),
      this.document.querySelector('#formFiltros [name="limite"]')
    ];
    for (const element of candidates) {
      if (element?.value?.trim()) return String(element.value);
    }
    return null;
  }

  onPaginationClick(event) {
    const link = event.target.closest('.pagination .page-link');
    if (!link) return;

    const page = parseInt(link.getAttribute('data-pagina') || '0', 10);
    if (!page) return;

    event.preventDefault();
    event.__archivosPaginationHandled = true;

    const params = new URLSearchParams(this.window.location.search);
    params.set('pagina', String(page));

    const filters = typeof this.window.obtenerFiltros === 'function'
      ? this.window.obtenerFiltros()
      : {
          buscar: this.document.querySelector('#formFiltros [name="buscar"]')?.value ?? '',
          tipo: this.document.querySelector('#formFiltros [name="tipo"]')?.value ?? '',
          fecha_inicio: this.document.querySelector('#formFiltros [name="fecha_inicio"]')?.value ?? '',
          fecha_fin: this.document.querySelector('#formFiltros [name="fecha_fin"]')?.value ?? ''
        };

    ['buscar', 'tipo', 'fecha_inicio', 'fecha_fin'].forEach((key) => {
      const value = filters?.[key];
      if (value && String(value).trim() !== '') params.set(key, String(value));
      else params.delete(key);
    });

    const route = this.document.getElementById('archivosContexto')?.dataset?.rutaActual;
    if (route?.trim()) params.set('ruta', String(route));

    const limit = this.getCurrentLimit();
    if (limit) params.set('limite', limit);

    if (typeof this.window.actualizarBloqueArchivos === 'function') {
      this.window.actualizarBloqueArchivos(params);
      return;
    }

    const url = new URL(this.window.location.href);
    url.search = params.toString();
    this.window.location.href = url.toString();
  }

  finishLoader() {
    const wrap = this.document.getElementById('archivosWrap');
    const overlay = this.document.getElementById('archivosLoaderOverlay');
    const backdrop = this.document.getElementById('archivosLoaderBackdrop');
    if (wrap) {
      wrap.querySelectorAll('li.file-row').forEach((row) => {
        row.classList.remove('is-pending');
        row.classList.add('is-ready');
      });
      wrap.classList.remove('is-loading');
    }
    if (overlay) overlay.style.display = 'none';
    if (backdrop) backdrop.style.display = 'none';
  }

  selectedKeys() {
    const wrap = this.document.getElementById('archivosWrap') || this.document;
    return Array.from(wrap.querySelectorAll('input[name="archivos[]"]:checked'))
      .map((checkbox) => checkbox.value)
      .filter(Boolean);
  }

  onChange(event) {
    if (event.target?.id === 'checkAllFiles') {
      const checked = Boolean(event.target.checked);
      const wrap = this.document.getElementById('archivosWrap') || this.document;
      wrap.querySelectorAll('input[name="archivos[]"]').forEach((checkbox) => {
        checkbox.checked = checked;
      });
      this.updateSelectionCount();
      return;
    }

    if (event.target?.matches?.('input[name="archivos[]"]')) {
      this.updateSelectionCount();
    }
  }

  updateSelectionCount() {
    const element = this.document.getElementById('filesSelectedCount');
    if (!element) return;
    const count = this.selectedKeys().length;
    element.textContent = count ? `${count} seleccionado(s)` : '';
  }

  onBulkClick(event) {
    const button = event.target.closest('[data-file-bulk-action]');
    if (!button) return;
    event.preventDefault();
    const action = button.dataset.fileBulkAction;
    if (action === 'download') this.downloadSelected();
    else if (action === 'move') this.moveSelected();
    else if (action === 'delete') this.deleteSelected();
  }

  downloadSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo.');
      return;
    }

    const form = this.document.createElement('form');
    form.method = 'POST';
    form.action = 'descargar_zip.php';
    form.style.display = 'none';

    const input = this.document.createElement('input');
    input.type = 'hidden';
    input.name = 'archivos_json';
    input.value = JSON.stringify(selected);
    form.appendChild(input);
    this.document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  moveSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo para mover.');
      return;
    }

    const jsonInput = this.document.getElementById('archivosJson');
    if (jsonInput) jsonInput.value = JSON.stringify(selected);
    const modal = this.document.getElementById('modalMover');
    if (!modal) return;

    try {
      if (this.window.jQuery && typeof this.window.jQuery(modal).modal === 'function') {
        this.window.jQuery(modal).modal('show');
        return;
      }
    } catch (_) {}

    try {
      if (this.window.bootstrap?.Modal) {
        const modalApi = this.window.bootstrap.Modal;
        if (typeof modalApi.getOrCreateInstance === 'function') modalApi.getOrCreateInstance(modal).show();
        else new modalApi(modal).show();
        return;
      }
    } catch (_) {}

    modal.classList.add('show');
    modal.style.display = 'block';
  }

  async deleteSelected() {
    const selected = this.selectedKeys();
    if (!selected.length) {
      this.window.alert('Selecciona al menos un archivo.');
      return;
    }
    if (!this.window.confirm('¿Eliminar los archivos seleccionados?')) return;

    try {
      const body = new URLSearchParams({ archivos_json: JSON.stringify(selected) });
      const response = await this.window.fetch('delete_multiple.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok || json.estado !== 'ok') {
        throw new Error(json.mensaje || json.error || `HTTP ${response.status}`);
      }

      if (typeof this.window.actualizarBloqueArchivos === 'function') {
        await this.window.actualizarBloqueArchivos({ pagina: 1 });
      } else {
        this.window.location.reload();
      }
      this.document.dispatchEvent(new Event('drive:storage-changed'));
    } catch (error) {
      console.error(error);
      this.window.alert('❌ ' + (error.message || error));
    }
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const previous = win.ArcadeCloudDrive.modules.fileBlock;
    if (previous instanceof FileBlockApp) {
      previous.refresh();
      return previous;
    }
    const instance = new FileBlockApp(win, doc).init();
    win.ArcadeCloudDrive.modules.fileBlock = instance;
    return instance;
  }
}

FileBlockApp.boot();
''')

# -----------------------------------------------------------------------------
# 3) Encapsula scripts JS en clases. Conserva globals históricos como fachada.
# -----------------------------------------------------------------------------
def pascal(name: str) -> str:
    parts = re.split(r'[^A-Za-z0-9]+', name)
    value = ''.join(part[:1].upper() + part[1:] for part in parts if part)
    if not value or value[0].isdigit():
        value = 'Module' + value
    return value + 'Module'


def wrap_js(path: Path):
    if path.name == 'file-block.js':
        return
    text = path.read_text(encoding='utf-8', errors='ignore')
    # Idempotencia.
    if re.search(r'^class\s+[A-Za-z_$][\w$]*Module\s*\{', text, re.M):
        return
    if re.search(r'(?m)^\s*(?:import|export)\s+', text):
        raise SystemExit(f'ES module no soportado por wrapper automático: {path}')

    funcs = re.findall(r'(?m)^function\s+([A-Za-z_$][\w$]*)\s*\(', text)
    vars_ = re.findall(r'(?m)^(?:var|let|const)\s+([A-Za-z_$][\w$]*)', text)
    class_name = pascal(path.stem)
    module_key = path.stem
    indented = '\n'.join(('    ' + line) if line else '' for line in text.splitlines())
    facade = []
    for name in funcs:
        facade.append(f"    if (typeof {name} === 'function' && typeof window.{name} !== 'function') window.{name} = {name};")
    for name in vars_:
        facade.append(f"    if (typeof {name} !== 'undefined' && typeof window.{name} === 'undefined') window.{name} = {name};")
    facade_text = '\n'.join(facade)

    wrapped = f'''class {class_name} {{
  constructor(win, doc) {{
    this.window = win;
    this.document = doc;
  }}

  init() {{
    const window = this.window;
    const document = this.document;
{indented}
{facade_text}
    return this;
  }}

  static boot(win = window, doc = document) {{
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || {{ modules: {{}} }};
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {{}};
    const instance = new {class_name}(win, doc).init();
    win.ArcadeCloudDrive.modules[{module_key!r}] = instance;
    return instance;
  }}
}}

{class_name}.boot();
'''
    path.write_text(wrapped, encoding='utf-8')

for js_file in sorted(JS.glob('*.js')):
    wrap_js(js_file)

# -----------------------------------------------------------------------------
# 4) Incluye FileBlockApp una sola vez desde s3.php.
# -----------------------------------------------------------------------------
s3_path = DRIVE / 's3.php'
s3 = s3_path.read_text(encoding='utf-8')
needle = '<script src="js/archivos.js"></script>'
if needle not in s3:
    raise SystemExit('No se encontró inclusión de archivos.js')
if 'js/file-block.js' not in s3:
    s3 = s3.replace(needle, needle + '\n<script src="js/file-block.js"></script>', 1)
# CSS actual: styles.css es la fuente viva; styles-old.css queda solo como respaldo.
s3 = s3.replace('<link rel="stylesheet" href="css/styles-old.css">', '<link rel="stylesheet" href="css/styles.css">', 1)
s3_path.write_text(s3, encoding='utf-8')

print('Core OOP migration applied')
