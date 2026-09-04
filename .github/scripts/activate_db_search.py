from pathlib import Path

root = Path(__file__).resolve().parents[2]
js_path = root / 'drive/js/archivos.js'
s3_path = root / 'drive/s3.php'

js = js_path.read_text(encoding='utf-8')

replacements = [
    (
        "    const key = (opts && opts.key) || '';\n    const args = { pagina: 1, ruta: ruta, rutaNueva: ruta };",
        "    const key = (opts && opts.key) || '';\n    const nombre = (opts && opts.nombre) || '';\n    const args = { pagina: 1, ruta: ruta, rutaNueva: ruta };\n    // Filtramos temporalmente por nombre para garantizar que el archivo buscado\n    // aparezca aunque normalmente estuviera en otra página de la carpeta.\n    if (nombre) args.buscar = nombre;"
    ),
    (
        "      const nombre = window.escapeHtml(it.nombre || '');\n      const ruta = it.ruta || '';\n      const key = it.key || '';\n      const tamKB = (it.tamano_kb != null) ? ` | ${it.tamano_kb} KB` : '';",
        "      const nombreRaw = it.nombre_real || it.nombre || '';\n      const nombre = window.escapeHtml(nombreRaw);\n      const ruta = it.ruta || '';\n      const key = it.key || '';\n      const tamKB = it.tamano_formateado\n        ? ` | ${window.escapeHtml(it.tamano_formateado)}`\n        : ((it.tamano_kb != null) ? ` | ${it.tamano_kb} KB` : '');"
    ),
    (
        "          <button type=\"button\" class=\"btn btn-sm btn-outline-primary btn-ir\" data-ruta=\"${window.escapeHtml(ruta)}\" data-key=\"${window.escapeHtml(key)}\">",
        "          <button type=\"button\" class=\"btn btn-sm btn-outline-primary btn-ir\" data-ruta=\"${window.escapeHtml(ruta)}\" data-key=\"${window.escapeHtml(key)}\" data-nombre=\"${window.escapeHtml(nombreRaw)}\">"
    ),
    (
        "    const ruta = btn.getAttribute('data-ruta') || '';\n    const key = btn.getAttribute('data-key') || '';",
        "    const ruta = btn.getAttribute('data-ruta') || '';\n    const key = btn.getAttribute('data-key') || '';\n    const nombre = btn.getAttribute('data-nombre') || '';"
    ),
    (
        "    abrirCarpetaSinRefresco({ ruta, key })",
        "    abrirCarpetaSinRefresco({ ruta, key, nombre })"
    ),
]

for old, new in replacements:
    if old not in js:
        raise SystemExit('No se encontró un bloque esperado en archivos.js: ' + old[:80])
    js = js.replace(old, new, 1)

js_path.write_text(js, encoding='utf-8')

s3 = s3_path.read_text(encoding='utf-8')
old = '''              <label for="terminoBusqueda">Nombre o patrón de archivo (ej. <code>*.pdf</code>, <code>jimm*</code>)</label>\n              <input type="text" class="form-control" id="terminoBusqueda" name="termino" placeholder="Ingrese nombre o patrón del archivo" required>'''
new = '''              <label for="terminoBusqueda">Buscar por nombre</label>\n              <input type="text" class="form-control" id="terminoBusqueda" name="termino" placeholder="factura, fact*, *.pdf, *2026*" required>\n              <small class="form-text text-muted">\n                Texto normal busca en cualquier parte del nombre. <code>fact*</code> busca al inicio,\n                <code>*.pdf</code> al final, <code>*2026*</code> en cualquier parte y <code>?</code> representa un carácter.\n              </small>'''
if old not in s3:
    raise SystemExit('No se encontró el formulario de búsqueda en s3.php')
s3 = s3.replace(old, new, 1)
s3_path.write_text(s3, encoding='utf-8')
