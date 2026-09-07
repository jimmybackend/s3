from pathlib import Path

# ------------------------------------------------------------
# s3.php
# ------------------------------------------------------------
p = Path('drive/s3.php')
s = p.read_text()

old = "$footerRutaActual = $basePrefix;"
new = "$footerRutaActual = $app->folderQueryService()->displayPathForUser($userId, $basePrefix);"
if old not in s:
    raise SystemExit('S3_FOOTER_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

old = '<link rel="stylesheet" href="css/styles.css?v=20260904-clean1">\n  <link rel="stylesheet"\n        href="css/responsive.css?v=20260904-8">'
new = '''<link rel="stylesheet" href="css/styles.css?v=<?= (int) filemtime(__DIR__ . '/css/styles.css') ?>">\n  <link rel="stylesheet"\n        href="css/responsive.css?v=<?= (int) filemtime(__DIR__ . '/css/responsive.css') ?>">'''
if old not in s:
    raise SystemExit('S3_CSS_VERSION_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

old = 'id="btnSubirUrl" class="btn btn-primary"'
new = 'id="btnSubirUrl" class="btn btn-success"'
if old not in s:
    raise SystemExit('S3_UPLOAD_URL_BUTTON_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

for old, new in [
    ('href="https://drive.esforzados.com/aws.php"', 'href="aws.php"'),
    ('href="https://drive.esforzados.com/ec2.php"', 'href="ec2.php"'),
]:
    if old not in s:
        raise SystemExit('S3_LINK_PATTERN_NOT_FOUND:' + old)
    s = s.replace(old, new, 1)

p.write_text(s)

# ------------------------------------------------------------
# bloque_archivos.php
# ------------------------------------------------------------
p = Path('drive/bloque_archivos.php')
s = p.read_text()

old = "$carpetaBytes = (int) $state['folder_bytes'];\n"
new = "$carpetaBytes = (int) $state['folder_bytes'];\n$rutaVisible = $app->folderQueryService()->displayPathForUser($userId, $rutaActual);\n"
if old not in s:
    raise SystemExit('FILES_VISIBLE_PATH_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

old = '''  <div id="archivosContexto"
       data-ruta-actual="<?= FileViewHelper::escape($rutaActual) ?>"
       data-pagina-actual="<?= (int)$pagina ?>"'''
new = '''  <div id="archivosContexto"
       data-ruta-actual="<?= FileViewHelper::escape($rutaActual) ?>"
       data-ruta-visible="<?= FileViewHelper::escape($rutaVisible) ?>"
       data-pagina-actual="<?= (int)$pagina ?>"'''
if old not in s:
    raise SystemExit('FILES_CONTEXT_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

old = '''              <span class="file-route"
                    title="<?= FileViewHelper::escape($rutaRow) ?>">
                <?= FileViewHelper::escape($rutaRow) ?>
              </span>

              <span class="file-meta-sep"> · </span>

              <span class="file-date">
                <?= $fechaTxt ?>
              </span>

              <span class="file-physical-key">
                <span class="file-meta-sep"> · </span>
                <span class="text-mono">
                  <?= FileViewHelper::escape($keyEnc) ?>
                </span>
              </span>

              <span class="file-meta-sep"> · </span>

              <strong class="file-size">
                <?= FileViewHelper::formatBytes($tamano) ?>
              </strong>'''
new = '''              <span class="file-date">
                <?= $fechaTxt ?>
              </span>

              <span class="file-meta-sep"> · </span>

              <strong class="file-size">
                <?= FileViewHelper::formatBytes($tamano) ?>
              </strong>'''
if old not in s:
    raise SystemExit('FILES_META_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

old = '''
            <div class="file-s3-location"
                 title="<?= FileViewHelper::escape($s3key) ?>">
              <span class="file-s3-label">
                <i class="fab fa-aws"></i> S3:
              </span>
              <code><?= FileViewHelper::escape($s3key) ?></code>
            </div>
'''
if old not in s:
    raise SystemExit('FILES_S3_LOCATION_PATTERN_NOT_FOUND')
s = s.replace(old, '\n', 1)

old = '''    Archivos: <strong><?= (int)$carpetaTotal ?></strong> |
    Peso: <strong><?= FileViewHelper::formatBytes($carpetaBytes) ?></strong> |
    Ruta: <code><?= FileViewHelper::escape($rutaActual) ?></code> |
    Visibles (página):'''
new = '''    Archivos: <strong><?= (int)$carpetaTotal ?></strong> |
    Peso: <strong><?= FileViewHelper::formatBytes($carpetaBytes) ?></strong> |
    Visibles (página):'''
if old not in s:
    raise SystemExit('FILES_SUMMARY_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)

p.write_text(s)

# ------------------------------------------------------------
# archivos.js
# ------------------------------------------------------------
p = Path('drive/js/archivos.js')
s = p.read_text()
old = '''      window.actualizarBloqueFooter = window.actualizarBloqueFooter || (async function (args) {
        const route = String(
          (args && (args.rutaNueva || args.ruta)) || window.rutaActual || ''
        ).trim();
        const routeNode = document.getElementById('footerRutaActual');
        if (routeNode && route) routeNode.textContent = route;
      });'''
new = '''      window.actualizarBloqueFooter = window.actualizarBloqueFooter || (async function () {
        const routeNode = document.getElementById('footerRutaActual');
        const visibleRoute = String(
          document.getElementById('archivosContexto')?.dataset?.rutaVisible || ''
        ).trim();

        if (routeNode && visibleRoute) {
          routeNode.textContent = visibleRoute;
          routeNode.setAttribute('title', visibleRoute);
        }
      });'''
if old not in s:
    raise SystemExit('ARCHIVOS_FOOTER_PATTERN_NOT_FOUND')
s = s.replace(old, new, 1)
p.write_text(s)

# ------------------------------------------------------------
# responsive.css
# ------------------------------------------------------------
p = Path('drive/css/responsive.css')
s = p.read_text()
block = r'''

/* ============================================================
   LOGO NAVBAR - mostrar imagen completa dentro del marco
   ============================================================ */
.drive-brand-logo {
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
}
'''
if 'LOGO NAVBAR - mostrar imagen completa dentro del marco' not in s:
    s += block
p.write_text(s)

# ------------------------------------------------------------
# styles.css
# ------------------------------------------------------------
p = Path('drive/css/styles.css')
s = p.read_text()
block = r'''

/* ============================================================
   SUBIR ARCHIVOS - acciones principales verdes
   ============================================================ */
body.ui-theme #pane-Subir #btnSubirUrl,
body.ui-theme #pane-Subir #uploadForm button[type="submit"],
body.ui-theme #pane-Subir #btnSubirGrande {
  background: #198754 !important;
  border-color: #146c43 !important;
  color: #ffffff !important;
  opacity: 1 !important;
}

body.ui-theme #pane-Subir #btnSubirUrl:not(:disabled):hover,
body.ui-theme #pane-Subir #uploadForm button[type="submit"]:not(:disabled):hover,
body.ui-theme #pane-Subir #btnSubirGrande:not(:disabled):hover {
  background: #157347 !important;
  border-color: #146c43 !important;
  color: #ffffff !important;
}
'''
if 'SUBIR ARCHIVOS - acciones principales verdes' not in s:
    s += block
p.write_text(s)
