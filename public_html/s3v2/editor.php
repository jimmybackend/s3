<?php
/**
 * Editor de texto basado en Monaco usando la KEY S3.
 * - Detecta lenguaje por extensión
 * - Muestra nombre del archivo
 */

if (!isset($_GET['archivo']) || trim($_GET['archivo']) === '') {
    die('Falta la clave del archivo.');
}

$archivo = trim($_GET['archivo']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Editor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html, body {
    height: 100%;
    margin: 0;
}
#toolbar {
    height: 44px;
    background: #1e1e1e;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    box-sizing: border-box;
}
#toolbar .file-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 50vw;
    opacity: .9;
}
#editor {
    height: calc(100% - 44px);
    width: 100%;
}
button {
    padding: 6px 12px;
    cursor: pointer;
}
#status {
    font-size: 13px;
    opacity: 0.9;
}
</style>
</head>
<body>

<div id="toolbar">
    <button type="button" onclick="guardar()">Guardar</button>
    <span id="status"></span>
    <span id="fileName" class="file-name"></span>
</div>

<div id="editor"></div>

<script src="https://unpkg.com/monaco-editor@0.44.0/min/vs/loader.js"></script>
<script>
const archivo = <?= json_encode($archivo) ?>;
let editor = null;

function detectarLenguaje(nombre) {
    const base = String(nombre || '').toLowerCase().split('/').pop();
    const ext = base.includes('.') ? base.split('.').pop() : '';

    const mapa = {
        txt: 'plaintext', text: 'plaintext', log: 'plaintext',
        md: 'markdown', markdown: 'markdown',
        html: 'html', htm: 'html', css: 'css', scss: 'scss', less: 'less',
        js: 'javascript', mjs: 'javascript', cjs: 'javascript', jas: 'javascript', jsx: 'javascript',
        ts: 'typescript', tsx: 'typescript',
        json: 'json', jsonl: 'json',
        xml: 'xml', yaml: 'yaml', yml: 'yaml', toml: 'ini', ini: 'ini', conf: 'ini', cfg: 'ini',
        php: 'php', phtml: 'php', inc: 'php',
        py: 'python', rb: 'ruby', java: 'java', c: 'c', h: 'c', cpp: 'cpp', hpp: 'cpp',
        cs: 'csharp', go: 'go', rs: 'rust', swift: 'swift', kt: 'kotlin', kts: 'kotlin',
        sh: 'shell', bash: 'shell', zsh: 'shell', bat: 'bat', cmd: 'bat', ps1: 'powershell',
        sql: 'sql', csv: 'plaintext', tsv: 'plaintext',
        srt: 'plaintext', vtt: 'plaintext',
        vue: 'html'
    };

    if (base === 'dockerfile') return 'dockerfile';
    if (base === 'makefile') return 'makefile';
    if (base === '.env' || base === '.gitignore' || base === '.htaccess') return 'shell';

    return mapa[ext] || 'plaintext';
}

require.config({
    paths: {
        vs: 'https://unpkg.com/monaco-editor@0.44.0/min/vs'
    }
});

require(['vs/editor/editor.main'], function () {
    editor = monaco.editor.create(document.getElementById('editor'), {
        value: '',
        language: 'plaintext',
        theme: 'vs-dark',
        automaticLayout: true,
        minimap: { enabled: true }
    });

    cargarArchivo();
});

async function cargarArchivo() {
    document.getElementById('status').innerText = 'Cargando...';

    const res = await fetch('leer_texto.php?archivo=' + encodeURIComponent(archivo));
    const json = await res.json();

    if (json.estado === 'ok') {
        const nombre = json.data.nombre || archivo;
        const lenguaje = json.data.lenguaje || detectarLenguaje(nombre);

        editor.setValue(json.data.contenido || '');
        monaco.editor.setModelLanguage(editor.getModel(), lenguaje);

        document.getElementById('fileName').innerText = nombre;
        document.title = 'Editor - ' + nombre;
        document.getElementById('status').innerText = 'Archivo cargado';
    } else {
        document.getElementById('status').innerText = json.mensaje || 'Error al leer';
    }
}

async function guardar() {
    document.getElementById('status').innerText = 'Guardando...';

    const formData = new FormData();
    formData.append('archivo', archivo);
    formData.append('contenido', editor.getValue());

    const res = await fetch('guardar_texto.php', {
        method: 'POST',
        body: formData
    });

    const json = await res.json();

    if (json.estado === 'ok') {
        document.getElementById('status').innerText = 'Guardado correctamente';
    } else {
        document.getElementById('status').innerText = json.mensaje || 'Error al guardar';
    }
}
</script>

</body>
</html>
