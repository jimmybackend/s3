from pathlib import Path
import re
import json

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'

php_files = sorted(p for p in DRIVE.rglob('*.php') if '.github' not in p.parts)
js_files = sorted(DRIVE.rglob('*.js'))
source_files = php_files + js_files

refs = {}
for target in php_files:
    name = target.name
    found = []
    for source in source_files:
        if source == target:
            continue
        text = source.read_text(encoding='utf-8', errors='ignore')
        # Exact basename reference is enough for current codebase routing/includes.
        if name in text:
            found.append(source.relative_to(ROOT).as_posix())
    refs[target.relative_to(ROOT).as_posix()] = found

entry_roots = {
    'drive/s3.php', 'drive/index.php', 'drive/login.php', 'drive/logout.php',
    'drive/up.php', 'drive/ec2.php', 'drive/aws.php'
}

active = set(entry_roots)
changed = True
while changed:
    changed = False
    for target, sources in refs.items():
        if target in active:
            continue
        if any(source in active for source in sources):
            active.add(target)
            changed = True

rows = []
for path, sources in refs.items():
    p = ROOT / path
    rows.append({
        'path': path,
        'size': p.stat().st_size,
        'active': path in active,
        'references': sources,
    })

out = DRIVE / 'docs/RUNTIME_ENDPOINTS.md'
out.parent.mkdir(exist_ok=True)
lines = [
    '# Mapa de runtime y endpoints', '',
    '> Generado por análisis estático de referencias. Un archivo sin referencias puede seguir siendo una URL externa conocida; antes de eliminar se valida manualmente.', '',
    '## Entradas consideradas', '',
]
for item in sorted(entry_roots):
    lines.append(f'- `{item}`')
lines += ['', '## PHP alcanzables desde el runtime principal', '', '| Archivo | Referenciado por |', '|---|---|']
for row in rows:
    if row['active']:
        srcs = ', '.join(f'`{s}`' for s in row['references']) or 'entrada directa'
        lines.append(f"| `{row['path']}` | {srcs} |")
lines += ['', '## PHP no alcanzables por referencias internas', '', '| Archivo | Referencias encontradas |', '|---|---|']
for row in rows:
    if not row['active']:
        srcs = ', '.join(f'`{s}`' for s in row['references']) or 'ninguna'
        lines.append(f"| `{row['path']}` | {srcs} |")
out.write_text('\n'.join(lines) + '\n', encoding='utf-8')
(DRIVE / 'docs/runtime_endpoints.json').write_text(json.dumps({'active': sorted(active), 'rows': rows}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print('active', len(active), 'php total', len(php_files))
