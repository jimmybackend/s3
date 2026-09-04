from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'


def replace_once(text, old, new, label):
    if old not in text:
        raise SystemExit(f'No se encontró bloque para {label}')
    return text.replace(old, new, 1)

# 1) Bloque archivos: formatos reales de AWS sin alterar reproductores.
path = DRIVE / 'bloque_archivos.php'
text = path.read_text(encoding='utf-8')
text = replace_once(
    text,
    "$analizarExt = ['jpg','jpeg','png','tif','tiff','bmp'];\n$comprehendExt =",
    "$analizarExt = ['jpg','jpeg','png'];\n$transcribeExt = ['amr','flac','m4a','mp3','mp4','ogg','webm','wav'];\n$comprehendExt =",
    'formatos AWS'
)
text = replace_once(
    text,
    "      $puedeAna= in_array($ext, $analizarExt, true);\n      $puedeComprehend = in_array($ext, $comprehendExt, true);",
    "      $puedeAna= in_array($ext, $analizarExt, true);\n      $puedeTranscribir = in_array($ext, $transcribeExt, true);\n      $puedeComprehend = in_array($ext, $comprehendExt, true);",
    'flag Transcribe'
)
text = text.replace('<?php if ($esAudio): ?>\n  <button type="button"\n          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"',
                    '<?php if ($puedeTranscribir && !$esVideo): ?>\n  <button type="button"\n          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"', 1)
text = text.replace('<?php if ($esVideo): ?>\n  <button type="button"\n          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"',
                    '<?php if ($puedeTranscribir && $esVideo): ?>\n  <button type="button"\n          class="btn btn-sm btn-primary btn-accion-ia aws-file-action js-transcribir"', 1)
path.write_text(text, encoding='utf-8')

# 2) Comprehend: capturar user_id antes de liberar sesión PHP.
path = DRIVE / 'comprehend_archivo.php'
text = path.read_text(encoding='utf-8')
text = replace_once(
    text,
    "    $key = trim((string)($_POST['key'] ?? $_POST['archivo'] ?? ''));\n    if ($key === '') {",
    "    $userId = $session->userId();\n    $key = trim((string)($_POST['key'] ?? $_POST['archivo'] ?? ''));\n    if ($key === '') {",
    'captura userId'
)
text = replace_once(
    text,
    "        'analysis' => $service->analyze($session->userId(), $key),",
    "        'analysis' => $service->analyze($userId, $key),",
    'uso userId capturado'
)
path.write_text(text, encoding='utf-8')

# 3) Galería: una sola fuente y un solo evento de refresh.
path = DRIVE / 'js/imagenes.js'
text = path.read_text(encoding='utf-8')
old = '''  // Cada cambio AJAX de página sustituye #bloque-archivos. Reconstruimos
  // inmediatamente el buffer para que nunca queden imágenes de la página anterior.
  document.addEventListener('bloque-archivos:actualizado', function(){
    setTimeout(actualizarBufferGaleria, 0);
  });

  document.addEventListener('bloque-archivos:actualizado', function(){
    actualizarBufferGaleria();
  });

  document.addEventListener('bloque-archivos:updated', function(){
    actualizarBufferGaleria();
  });

  var target = document.getElementById('bloque-archivos') || document.body;
  if (target && window.MutationObserver) {
    var mo = new MutationObserver(function(){
      actualizarBufferGaleria();
    });
    mo.observe(target, { childList: true, subtree: true });
  }
'''
new = '''  // Cada cambio AJAX de página sustituye #bloque-archivos. Un único evento
  // reconstruye el buffer desde las filas visibles de ESA página.
  document.addEventListener('bloque-archivos:actualizado', function(){
    setTimeout(actualizarBufferGaleria, 0);
  });
'''
text = replace_once(text, old, new, 'listeners duplicados galería')
path.write_text(text, encoding='utf-8')
