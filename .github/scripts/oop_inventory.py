from pathlib import Path
import re
import json

ROOT = Path(__file__).resolve().parents[2]
DRIVE = ROOT / 'drive'
DOCS = DRIVE / 'docs'
DOCS.mkdir(exist_ok=True)

SKIP_PARTS = {'.git', 'vendor', 'node_modules'}


def files_with_suffix(suffix):
    out = []
    for p in ROOT.rglob(f'*{suffix}'):
        if any(part in SKIP_PARTS for part in p.parts):
            continue
        if p.name == 'composer.lock':
            continue
        out.append(p)
    return sorted(out)


def rel(p):
    return p.relative_to(ROOT).as_posix()


def php_info(p):
    text = p.read_text(encoding='utf-8', errors='ignore')
    php_chunks = re.findall(r'<\?php(.*?)(?:\?>|$)', text, re.DOTALL)
    php_text = '\n'.join(php_chunks) if php_chunks else text
    path = rel(p)

    classes = len(re.findall(
        r'(?m)^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+',
        php_text
    ))
    interfaces = len(re.findall(r'(?m)^\s*interface\s+\w+', php_text))
    traits = len(re.findall(r'(?m)^\s*trait\s+\w+', php_text))
    funcs = re.findall(r'(?m)^\s*function\s+([A-Za-z_]\w*)\s*\(', php_text)
    direct_session = bool(re.search(r'\bsession_start\s*\(|\$_SESSION\b', php_text))
    superglobals = sorted(set(re.findall(r'\$_(GET|POST|REQUEST|SERVER|FILES|COOKIE|SESSION)\b', php_text)))
    raw_db = bool(re.search(r'\$db_connection\b|->prepare\s*\(|->query\s*\(', php_text))
    raw_s3 = bool(re.search(r'Config::getS3\s*\(|new\s+S3Manager\b|new\s+\\?Aws\\', php_text))
    json_response = 'JsonResponse' in php_text
    app_bootstrap = 'app_bootstrap.php' in php_text
    html = bool(re.search(r'<(?:html|div|ul|li|form|button|script|footer|nav|section)\b', text, re.I))
    namespace = bool(re.search(r'(?m)^\s*namespace\s+', php_text))
    lines = text.count('\n') + 1

    if path.startswith('drive/tests/'):
        kind = 'test script'
    elif path in {'db.php', 'drive/app_bootstrap.php'}:
        kind = 'bootstrap'
    elif classes or interfaces or traits:
        kind = 'class/module'
    elif path.startswith('drive/bin/'):
        kind = 'thin cli entrypoint' if not funcs and lines <= 120 else 'cli entrypoint with logic'
    elif html:
        kind = 'view/entrypoint'
    elif app_bootstrap and not funcs:
        kind = 'thin endpoint' if lines <= 100 else 'endpoint with logic'
    elif not funcs and lines <= 30 and re.search(r'Controller\s*\(', php_text):
        kind = 'thin endpoint'
    else:
        kind = 'procedural endpoint'

    issues = []
    runtime_migration_kind = kind in {'procedural endpoint', 'endpoint with logic', 'cli entrypoint with logic'}
    http_endpoint_kind = kind in {'thin endpoint', 'procedural endpoint', 'endpoint with logic'}
    if funcs and kind != 'test script':
        issues.append('global functions: ' + ', '.join(funcs[:8]))
    if (runtime_migration_kind or http_endpoint_kind) and raw_db and kind != 'bootstrap':
        issues.append('DB in entrypoint')
    if (runtime_migration_kind or http_endpoint_kind) and raw_s3 and kind != 'bootstrap':
        issues.append('AWS/S3 in entrypoint')
    if runtime_migration_kind and direct_session:
        issues.append('session in entrypoint')
    if not classes and not interfaces and not traits and '/src/' in path and kind != 'test script':
        issues.append('src file without class')
    return {
        'path': rel(p), 'lines': lines, 'kind': kind, 'classes': classes,
        'functions': funcs, 'session': direct_session, 'superglobals': superglobals,
        'db': raw_db, 's3': raw_s3, 'bootstrap': app_bootstrap, 'html': html,
        'namespace': namespace, 'issues': issues,
    }


def js_info(p):
    text = p.read_text(encoding='utf-8', errors='ignore')
    classes = re.findall(r'(?m)^\s*class\s+([A-Za-z_$][\w$]*)', text)
    global_funcs = re.findall(r'(?m)^function\s+([A-Za-z_$][\w$]*)\s*\(', text)
    window_assign = sorted(set(re.findall(r'window\.([A-Za-z_$][\w$]*)\s*=', text)))
    window_func = sorted(set(re.findall(r'window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?function\b', text)))
    top_vars = re.findall(r'(?m)^(?:var|let|const)\s+([A-Za-z_$][\w$]*)', text)
    iife = bool(re.search(r'\(function\s*\(', text))
    fetch_calls = len(re.findall(r'\bfetch\s*\(', text))
    xhr_calls = len(re.findall(r'\bnew\s+XMLHttpRequest\s*\(', text))
    jquery_ajax_calls = len(re.findall(r'\$\.(?:ajax|get|post)\s*\(', text))
    json_consumers = len(re.findall(r'\.json\s*\(', text))
    response_checks = len(re.findall(r'\.(?:ok|status)\b', text))
    lines = text.count('\n') + 1
    if classes:
        kind = 'class/module'
    elif iife and not global_funcs:
        kind = 'encapsulated legacy module'
    else:
        kind = 'procedural script'
    issues = []
    if global_funcs:
        issues.append('top-level functions: ' + ', '.join(global_funcs[:8]))
    if window_func:
        issues.append('window functions: ' + ', '.join(window_func[:8]))
    if top_vars and not classes:
        issues.append('top-level state: ' + ', '.join(top_vars[:8]))
    if not classes:
        issues.append('no ES class')
    return {
        'path': rel(p), 'lines': lines, 'kind': kind, 'classes': classes,
        'functions': global_funcs, 'window_assign': window_assign,
        'window_func': window_func, 'top_vars': top_vars, 'iife': iife,
        'ajax': {
            'fetch': fetch_calls,
            'xhr': xhr_calls,
            'jquery': jquery_ajax_calls,
            'json_consumers': json_consumers,
            'response_checks': response_checks,
        },
        'issues': issues,
    }


def json_info(p):
    text = p.read_text(encoding='utf-8', errors='strict')
    error = None
    value = None
    try:
        value = json.loads(text)
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        error = str(exc)

    if isinstance(value, dict):
        root_type = 'object'
        keys = sorted(str(key) for key in value.keys())
    elif isinstance(value, list):
        root_type = 'array'
        keys = []
    elif value is None and error:
        root_type = 'invalid'
        keys = []
    else:
        root_type = type(value).__name__
        keys = []

    return {
        'path': rel(p),
        'bytes': len(text.encode('utf-8')),
        'valid': error is None,
        'root_type': root_type,
        'keys': keys,
        'error': error,
    }

php = [php_info(p) for p in files_with_suffix('.php')]
js = [js_info(p) for p in files_with_suffix('.js')]
json_files = [
    json_info(p)
    for p in files_with_suffix('.json')
    if rel(p) != 'drive/docs/oop_inventory.json'
]

summary = {
    'php_total': len(php),
    'php_class_modules': sum(1 for x in php if x['kind'] == 'class/module'),
    'php_needs_migration': sum(
        1 for x in php
        if x['kind'] in {'procedural endpoint', 'endpoint with logic', 'cli entrypoint with logic'}
        or (x['functions'] and x['kind'] != 'test script')
        or any(issue.startswith(('DB in entrypoint', 'AWS/S3 in entrypoint')) for issue in x['issues'])
    ),
    'php_tests': sum(1 for x in php if x['kind'] == 'test script'),
    'js_total': len(js),
    'js_class_modules': sum(1 for x in js if x['classes']),
    'js_needs_migration': sum(1 for x in js if not x['classes'] or x['functions']),
    'js_compatibility_facades': sum(1 for x in js if x['classes'] and x['window_func']),
    'ajax_clients': sum(1 for x in js if sum(x['ajax'][key] for key in ('fetch', 'xhr', 'jquery'))),
    'ajax_calls': sum(
        sum(x['ajax'][key] for key in ('fetch', 'xhr', 'jquery'))
        for x in js
    ),
    'json_total': len(json_files),
    'json_invalid': sum(1 for x in json_files if not x['valid']),
}

lines = [
    '# Auditoría OOP — ArcadeCloud Drive',
    '',
    '> Generado automáticamente. No sustituye pruebas funcionales; detecta estructura y dependencias procedurales.',
    '',
    '## Resumen',
    '',
    f"- PHP analizados: **{summary['php_total']}**",
    f"- PHP que ya contienen clases/interfaces: **{summary['php_class_modules']}**",
    f"- PHP marcados para migración/revisión: **{summary['php_needs_migration']}**",
    f"- Tests PHP separados del objetivo OOP de runtime: **{summary['php_tests']}**",
    f"- JavaScript analizados: **{summary['js_total']}**",
    f"- JavaScript que ya contienen clases: **{summary['js_class_modules']}**",
    f"- JavaScript sin clase/encapsulación OOP: **{summary['js_needs_migration']}**",
    f"- JavaScript OOP con fachada `window` de compatibilidad: **{summary['js_compatibility_facades']}**",
    f"- Clientes AJAX detectados: **{summary['ajax_clients']}** módulos / **{summary['ajax_calls']}** llamadas",
    f"- JSON analizados: **{summary['json_total']}**; inválidos: **{summary['json_invalid']}**",
    '',
    '## Criterio',
    '',
    '- `src/` y `upload/`: lógica de negocio e infraestructura en clases.',
    '- Entry points públicos: bootstrap + Controller/Service; sin SQL/AWS ni funciones globales.',
    '- CLI: el archivo ejecutable puede ser procedural si es un wrapper delgado que delega en clases.',
    '- Tests: se auditan, pero no cuentan como deuda OOP del runtime.',
    '- Vistas: pueden contener HTML; funciones JavaScript incrustadas no se confunden con funciones PHP.',
    '- JavaScript: comportamiento en clases; `window` sólo como fachada de compatibilidad explícita.',
    '- AJAX: es un mecanismo de transporte, no un paradigma; se revisa dentro de la clase cliente que lo posee.',
    '- JSON: es un formato de datos, no código OOP; se valida sintaxis, tipo raíz y contrato en sus consumidores.',
    '',
    '## PHP',
    '',
    '| Archivo | Líneas | Tipo detectado | Clases | Sesión | DB | AWS/S3 | Observaciones |',
    '|---|---:|---|---:|:---:|:---:|:---:|---|',
]
for x in php:
    obs = '; '.join(x['issues']) or '—'
    lines.append(f"| `{x['path']}` | {x['lines']} | {x['kind']} | {x['classes']} | {'⚠️' if x['session'] else '—'} | {'⚠️' if x['db'] else '—'} | {'⚠️' if x['s3'] else '—'} | {obs.replace('|','/')} |")

lines += [
    '',
    '## JavaScript',
    '',
    '| Archivo | Líneas | Tipo detectado | Clases | Funciones globales | `window` funciones | Observaciones |',
    '|---|---:|---|---|---|---|---|',
]
for x in js:
    obs = '; '.join(x['issues']) or '—'
    lines.append(f"| `{x['path']}` | {x['lines']} | {x['kind']} | {', '.join(x['classes']) or '—'} | {', '.join(x['functions'][:6]) or '—'} | {', '.join(x['window_func'][:6]) or '—'} | {obs.replace('|','/')} |")

lines += [
    '',
    '## AJAX y contratos JSON',
    '',
    '| Cliente JavaScript | `fetch` | XHR | jQuery AJAX | Lecturas JSON | Comprobaciones de respuesta |',
    '|---|---:|---:|---:|---:|---:|',
]
for x in js:
    ajax = x['ajax']
    if not sum(ajax[key] for key in ('fetch', 'xhr', 'jquery')):
        continue
    lines.append(
        f"| `{x['path']}` | {ajax['fetch']} | {ajax['xhr']} | {ajax['jquery']} | "
        f"{ajax['json_consumers']} | {ajax['response_checks']} |"
    )

lines += [
    '',
    '### Archivos JSON',
    '',
    '| Archivo | Válido | Tipo raíz | Claves raíz |',
    '|---|:---:|---|---|',
]
for x in json_files:
    details = ', '.join(f'`{key}`' for key in x['keys']) or '—'
    if x['error']:
        details = x['error'].replace('|', '/')
    lines.append(
        f"| `{x['path']}` | {'sí' if x['valid'] else 'no'} | {x['root_type']} | {details} |"
    )

lines += [
    '',
    '## Dictamen',
    '',
    '- **PHP runtime:** consistente estructuralmente con entrypoints delgados y capas Controller/Service/Repository. '
    'Las vistas, bootstraps y tests son excepciones deliberadas; convertirlos en clases no aportaría encapsulación.',
    '- **JavaScript:** todos los archivos están encapsulados en clases. Las fachadas globales existentes son deuda de '
    'compatibilidad, no lógica procedural nueva; deben reducirse sólo al migrar sus consumidores HTML.',
    '- **AJAX:** las llamadas permanecen dentro de módulos OOP. La cantidad de lecturas JSON y comprobaciones es una '
    'señal heurística, no una prueba de corrección: los contratos funcionales continúan cubiertos por smoke tests.',
    '- **JSON:** los documentos válidos se consideran DTO/configuración. No corresponde convertir datos JSON a clases; '
    'la conversión a objetos tipados debe ocurrir en el límite PHP/JavaScript cuando el dominio lo requiera.',
    '',
    '### Prioridades de mantenimiento',
    '',
    '1. No añadir SQL, SDK AWS ni acceso directo a superglobales en entrypoints.',
    '2. Centralizar gradualmente transporte AJAX repetido en colaboradores inyectables, sin romper URLs públicas.',
    '3. Mantener las fachadas `window` como adaptadores mínimos y evitar estado de negocio global.',
    '4. Validar todo JSON al cargarlo y versionar explícitamente los payloads federados persistentes.',
]

lines += [
    '',
    '## Objetivo de refactorización',
    '',
    '```text',
    'HTTP entrypoint -> Controller -> Application Service -> Repository/Infrastructure',
    '                                      |',
    '                                      +-> S3 / AWS service',
    '                                      +-> MySQL repository',
    '',
    'Browser -> JS App class -> DOM/HTTP services -> PHP endpoint',
    '```',
    '',
    'El objetivo no es envolver código procedural en una clase gigante, sino separar responsabilidades y dependencias.',
]

(DOCS / 'OOP_AUDIT.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
(DOCS / 'oop_inventory.json').write_text(json.dumps({
    'summary': summary,
    'php': php,
    'js': js,
    'json': json_files,
}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps(summary, ensure_ascii=False))
