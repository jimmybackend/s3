from pathlib import Path
import re


def replace_once(path: str, old: str, new: str, marker: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(marker)
    p.write_text(text.replace(old, new, 1))


# ------------------------------------------------------------------
# ec2.php: reparar fatal de render tras migración a Ec2PanelHelper
# ------------------------------------------------------------------
p = Path('drive/ec2.php')
s = p.read_text()
old = 'echo "<option value=\\\"".e($val)."\\\" $sel>".e($label)."</option>";'
new = 'echo "<option value=\\\"".H::e($val)."\\\" $sel>".H::e($label)."</option>";'
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('EC2_ESCAPE_HELPER')
p.write_text(s)


# ------------------------------------------------------------------
# s3.php: limpiar enlaces solicitados, logo 25% mayor y límite 10
# ------------------------------------------------------------------
p = Path('drive/s3.php')
s = p.read_text()

hrefs_to_remove = [
    'https://demo.filestash.app/login',
    'https://aws.amazon.com/console/',
    'https://s3.console.aws.amazon.com/s3/buckets',
    'https://esforzados.com/AI/index.html',
    'https://biblia.esforzados.com/index.php',
    'https://tiendas.esforzados.com/',
    'https://projects.esforzados.com/',
    'https://esforzados.com/drone/',
]

for href in hrefs_to_remove:
    pattern = re.compile(
        r'\n\s*<li class="list-group-item">\s*'
        r'<a href="' + re.escape(href) + r'"[^>]*>.*?</a>\s*'
        r'</li>',
        re.DOTALL,
    )
    s, count = pattern.subn('', s, count=1)
    if count != 1 and href in s:
        raise SystemExit('S3_REMOVE_LINK_' + href)

old_logo = '''    <img src="ellogo.png"
         width="38"
         height="30"
         class="drive-brand-logo mr-2"
         alt="Logo"> Cloud Drive'''
new_logo = '''    <img src="ellogo.png"
         width="48"
         height="38"
         class="drive-brand-logo mr-2"
         alt="Logo"> Cloud Drive'''
if old_logo in s:
    s = s.replace(old_logo, new_logo, 1)
elif new_logo not in s:
    raise SystemExit('S3_LOGO_SIZE')

s = s.replace("<?= (int)($_GET['limite'] ?? 5) ?>", "<?= (int)($_GET['limite'] ?? 10) ?>", 1)
p.write_text(s)


# ------------------------------------------------------------------
# bloque_archivos.php: filtros colapsados con botón también en desktop
# ------------------------------------------------------------------
p = Path('drive/bloque_archivos.php')
s = p.read_text()
anchor = '''      <span id="filesSelectedCount"
            class="text-muted small"></span>

      <button type="button"
              class="btn btn-sm btn-danger"'''
replacement = '''      <span id="filesSelectedCount"
            class="text-muted small"></span>

      <button type="button"
              class="btn btn-sm btn-outline-primary bulk-filter-toggle d-none d-lg-inline-flex align-items-center"
              data-toggle="collapse"
              data-target="#panelFiltrosArchivos"
              aria-expanded="false"
              aria-controls="panelFiltrosArchivos">
        <i class="fas fa-filter mr-1"></i>
        Filtros
        <i class="fas fa-chevron-down ml-1"></i>
      </button>

      <button type="button"
              class="btn btn-sm btn-danger"'''
if anchor in s:
    s = s.replace(anchor, replacement, 1)
elif 'bulk-filter-toggle d-none d-lg-inline-flex' not in s:
    raise SystemExit('BLOQUE_DESKTOP_FILTER_BUTTON')
p.write_text(s)


# ------------------------------------------------------------------
# responsive.css: logo 25% mayor y contenedor móvil menos contraído
# ------------------------------------------------------------------
p = Path('drive/css/responsive.css')
s = p.read_text()

old = '''.drive-brand-logo {
  width: 38px !important;
  height: 30px !important;

  min-width: 38px;

  object-fit: cover;

  border-radius: 8px;
}'''
new = '''.drive-brand-logo {
  width: 48px !important;
  height: 38px !important;

  min-width: 48px;

  object-fit: contain;
  object-position: center;

  border-radius: 8px;
}'''
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('RESPONSIVE_LOGO_MAIN')

old = '''  .drive-navbar .navbar-brand {
    width: 38px !important;

    flex:
      0 0 38px !important;
  }'''
new = '''  .drive-navbar .navbar-brand {
    width: 48px !important;

    flex:
      0 0 48px !important;
  }'''
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('RESPONSIVE_LOGO_BRAND')

old = '''.drive-brand-logo {
  width: 38px !important;
  height: 30px !important;
  object-fit: contain !important;
  object-position: center !important;
  padding: 2px !important;
}

@media (max-width: 991.98px), (pointer: coarse) {
  .drive-navbar .navbar-brand img.drive-brand-logo {
    width: 30px !important;
    height: 30px !important;
    object-fit: contain !important;
    object-position: center !important;
    padding: 2px !important;
  }
}'''
new = '''.drive-brand-logo {
  width: 48px !important;
  height: 38px !important;
  object-fit: contain !important;
  object-position: center !important;
  padding: 1px !important;
}

@media (max-width: 991.98px), (pointer: coarse) {
  .drive-navbar .navbar-brand img.drive-brand-logo {
    width: 48px !important;
    height: 38px !important;
    object-fit: contain !important;
    object-position: center !important;
    padding: 1px !important;
  }
}'''
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('RESPONSIVE_LOGO_OVERRIDE')
p.write_text(s)


# ------------------------------------------------------------------
# Límite inicial coherente = 10 en backend y JS
# ------------------------------------------------------------------
replacements = [
    ('drive/src/Application/FileListService.php', "$query['limite'] ?? 5", "$query['limite'] ?? 10", 'FILE_LIST_LIMIT'),
    ('drive/src/Application/DrivePageService.php', "$query['limite'] ?? 5", "$query['limite'] ?? 10", 'DRIVE_PAGE_LIMIT'),
    ('drive/js/carpetas.js', "?.value ?? 5) : 5", "?.value ?? 10) : 10", 'CARPETAS_LIMIT'),
    ('drive/js/obtenerFiltros.js', "'select[name=\"limite\"]', '5'", "'select[name=\"limite\"]', '10'", 'OBTENER_FILTROS_LIMIT'),
    ('drive/js/filtros.js', "?.value ?? 50", "?.value ?? 10", 'FILTROS_LIMIT'),
]

for path, old, new, marker in replacements:
    p = Path(path)
    text = p.read_text()
    if old in text:
        text = text.replace(old, new, 1)
    elif new not in text:
        raise SystemExit(marker)
    p.write_text(text)

print('UI_EC2_FILTERS_PATCH_OK')
