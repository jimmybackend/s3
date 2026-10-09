<?php
/**
 * Editor de texto con Monaco.
 *
 * Requisitos backend esperados:
 * - leer_texto.php?archivo=...        => JSON { estado, data: { nombre, contenido, lenguaje? } }
 * - guardar_texto.php                 => POST archivo, contenido => JSON { estado, mensaje? }
 * - validar_php.php                   => POST archivo, contenido => JSON { estado, valido, errores[] }
 */

if (!isset($_GET['archivo']) || trim((string)$_GET['archivo']) === '') {
    die('Falta la clave del archivo.');
}

$archivo = trim((string)$_GET['archivo']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Editor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="ellogo.png" type="image/x-icon">
<style>
html, body {
    height: 100%;
    height: 100dvh;
    margin: 0;
    background: #111827;
    color: #e5e7eb;
    font-family: Arial, Helvetica, sans-serif;
}

* { box-sizing: border-box; }
body { display: flex; flex-direction: column; min-height: 0; }

#toolbar {
    min-height: 52px;
    background: #111827;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid #374151;
    flex-wrap: wrap;
}

.group {
    display: flex;
    align-items: center;
    gap: 6px;
}

.spacer {
    flex: 1 1 auto;
}

button, select {
    padding: 6px 10px;
    cursor: pointer;
    border: 1px solid #4b5563;
    background: #1f2937;
    color: #fff;
    border-radius: 6px;
    font-size: 13px;
}

button:hover, select:hover {
    background: #374151;
}

button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

#status {
    font-size: 13px;
    min-width: 130px;
}

#status.ok { color: #86efac; }
#status.warn { color: #fde68a; }
#status.error { color: #fca5a5; }
#status.info { color: #93c5fd; }

#toolbar .file-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 40vw;
    opacity: .95;
    font-weight: 600;
}

#editor {
    flex: 1 1 0;
    min-height: 0;
    width: 100%;
}
#mobileSelectionBar {
    flex: 0 0 auto;
    padding: 7px 10px;
    background: #162338;
    border-bottom: 1px solid #475569;
}
#mobileSelectionButtons {
    display: flex;
    gap: 6px;
    align-items: center;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    padding-bottom: 4px;
}
#mobileSelectionButtons button { flex: 0 0 auto; min-height: 38px; }
#mobileSelectionStatus {
    display: block;
    font-size: 12px;
    color: #bfdbfe;
    padding: 3px 0;
    white-space: normal;
}
#mobileSelectionStatus[data-severity="warn"] { color: #fde68a; }
#mobileSelectionStatus[data-severity="ok"] { color: #86efac; }
#mobilePastePanel { padding: 7px 0; }
#mobilePastePanel[hidden] { display: none; }
#mobilePasteText {
    display: block;
    width: 100%;
    min-height: 76px;
    max-height: 28dvh;
    padding: 8px;
    border: 1px solid #64748b;
    border-radius: 6px;
    background: #0f172a;
    color: #fff;
    font-size: 16px;
}
@media (max-width: 760px), (pointer: coarse) {
    #toolbar { padding: 6px 8px; gap: 6px; flex: 0 0 auto; }
    #toolbar > .group:first-child {
        flex: 1 0 100%;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    #toolbar button, #mobileSelectionButtons button { min-height: 40px; }
    #toolbar .file-name { max-width: 55vw; }
}

.badge {
    display: inline-flex;
    align-items: center;
    height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    border: 1px solid #4b5563;
    background: #1f2937;
    font-size: 12px;
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="group">
        <button type="button" id="btnGuardar" onclick="guardar()">Guardar</button>
        <button type="button" onclick="deshacer()">Deshacer</button>
        <button type="button" onclick="rehacer()">Rehacer</button>
        <button type="button" onclick="buscar()">Buscar</button>
        <button type="button" onclick="reemplazar()">Reemplazar</button>
        <button type="button" onclick="irALinea()">Ir a l¨ªnea</button>
        <button type="button" onclick="toggleWordWrap()">Word wrap</button>
        <button type="button" id="btnValidarPhp" onclick="validarPHP()" style="display:none;">Validar PHP</button>
    </div>

    <span id="status" class="info">Inicializando...</span>
    <span id="fileName" class="file-name"></span>

    <div class="spacer"></div>

    <span id="modoArchivo" class="badge">-</span>

    <div class="group">
        <label for="languageSelect">Lenguaje</label>
        <select id="languageSelect"></select>
    </div>

    <div class="group">
        <label for="themeSelect">Tema</label>
        <select id="themeSelect">
            <option value="vs-dark">Oscuro</option>
            <option value="vs">Claro</option>
            <option value="hc-black">Alto contraste</option>
        </select>
    </div>
</div>

<section id="mobileSelectionBar" aria-label="Edición y selección táctil">
    <div id="mobileSelectionButtons">
        <button type="button" data-mobile-edit="start" title="Marcar dónde comienza la selección">Marcar inicio</button>
        <button type="button" data-mobile-edit="end" title="Marcar dónde termina la selección">Marcar fin</button>
        <button type="button" data-mobile-edit="all">Todo</button>
        <button type="button" data-mobile-edit="copy">Copiar</button>
        <button type="button" data-mobile-edit="cut">Cortar</button>
        <button type="button" data-mobile-edit="paste">Pegar</button>
    </div>
    <output id="mobileSelectionStatus" aria-live="polite">Toca el texto para ubicar el cursor.</output>
    <div id="mobilePastePanel" hidden>
        <label for="mobilePasteText">Pega aquí usando el menú del teléfono (mantén pulsado), luego insértalo:</label>
        <textarea id="mobilePasteText" rows="3" aria-label="Texto para insertar cuando el navegador no permite leer el portapapeles"></textarea>
        <button type="button" data-mobile-edit="insert">Insertar texto</button>
        <button type="button" data-mobile-edit="cancel">Cancelar</button>
    </div>
</section>
<div id="editor"></div>

<script src="https://unpkg.com/monaco-editor@0.44.0/min/vs/loader.js"></script>
<script src="js/editor-mobile-selection.js?v=<?= (int)filemtime(__DIR__ . '/js/editor-mobile-selection.js') ?>"></script>
<script>
const archivo = <?= json_encode($archivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let editor = null;
let tieneCambios = false;
let wordWrapActivo = false;
let lenguajeActual = 'plaintext';

const lenguajesDisponibles = [
    'plaintext','markdown','html','css','scss','less',
    'javascript','typescript','json','php','python','ruby','java',
    'c','cpp','csharp','go','rust','swift','kotlin',
    'xml','yaml','ini','shell','bat','powershell',
    'sql','dockerfile','makefile'
];

function detectarLenguaje(nombre) {
    const base = String(nombre || '').toLowerCase().split('/').pop();
    const ext = base.includes('.') ? base.split('.').pop() : '';

    const mapa = {
        txt: 'plaintext', text: 'plaintext', log: 'plaintext',
        md: 'markdown', markdown: 'markdown',
        html: 'html', htm: 'html', css: 'css', scss: 'scss', less: 'less',
        js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript',
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

function setStatus(texto, tipo = 'info') {
    const el = document.getElementById('status');
    el.className = tipo;
    el.textContent = texto;
}

function limpiarMarcadores() {
    if (!editor) return;
    monaco.editor.setModelMarkers(editor.getModel(), 'php-lint', []);
}

function marcarGuardado(guardado) {
    tieneCambios = !guardado;
    if (guardado) {
        setStatus('Guardado', 'ok');
    } else {
        setStatus('Cambios sin guardar', 'warn');
    }
}

function actualizarBotonValidar() {
    const boton = document.getElementById('btnValidarPhp');
    boton.style.display = lenguajeActual === 'php' ? 'inline-block' : 'none';
}

function aplicarLenguaje(lang) {
    lenguajeActual = lang;
    if (editor) {
        monaco.editor.setModelLanguage(editor.getModel(), lang);
    }
    document.getElementById('languageSelect').value = lang;
    document.getElementById('modoArchivo').textContent = lang;
    actualizarBotonValidar();

    // Validaciones nativas b¨¢sicas donde Monaco ayuda m¨¢s.
    if (lang === 'javascript' || lang === 'typescript') {
        monaco.languages.typescript.javascriptDefaults.setDiagnosticsOptions({
            noSemanticValidation: false,
            noSyntaxValidation: false
        });
        monaco.languages.typescript.typescriptDefaults.setDiagnosticsOptions({
            noSemanticValidation: false,
            noSyntaxValidation: false
        });
    }

    if (lang === 'json') {
        monaco.languages.json.jsonDefaults.setDiagnosticsOptions({
            validate: true,
            allowComments: true,
            trailingCommas: 'ignore'
        });
    }

    if (lang !== 'php') {
        limpiarMarcadores();
    }
}

function poblarLenguajes(actual) {
    const select = document.getElementById('languageSelect');
    select.innerHTML = '';

    lenguajesDisponibles.forEach(lang => {
        const option = document.createElement('option');
        option.value = lang;
        option.textContent = lang;
        if (lang === actual) option.selected = true;
        select.appendChild(option);
    });

    select.addEventListener('change', function () {
        aplicarLenguaje(this.value);
        setStatus('Lenguaje cambiado a ' + this.value, 'info');
    });
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
        minimap: { enabled: true },
        fontSize: 14,
        lineNumbers: 'on',
        roundedSelection: false,
        scrollBeyondLastLine: false,
        readOnly: false,
        wordWrap: 'off',
        formatOnPaste: true,
        formatOnType: true,
        tabSize: 4,
        insertSpaces: true
    });

    editor.onDidChangeModelContent(() => {
        marcarGuardado(false);
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function () {
        guardar();
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyF, function () {
        buscar();
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyH, function () {
        reemplazar();
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyG, function () {
        irALinea();
    });

    editor.addCommand(monaco.KeyMod.Alt | monaco.KeyCode.KeyZ, function () {
        toggleWordWrap();
    });

    poblarLenguajes('plaintext');
    if (window.ArcadeCloudMobileEditorSelection) {
        window.arcadeMobileEditor = new window.ArcadeCloudMobileEditorSelection(window, document, editor).init();
    }
    cargarArchivo();
});

document.getElementById('themeSelect').addEventListener('change', function () {
    if (!editor) return;
    monaco.editor.setTheme(this.value);
});

window.addEventListener('beforeunload', function (e) {
    if (!tieneCambios) return;
    e.preventDefault();
    e.returnValue = '';
});

async function cargarArchivo() {
    setStatus('Cargando...', 'info');

    try {
        const res = await fetch('leer_texto.php?archivo=' + encodeURIComponent(archivo));
        const json = await res.json();

        if (json.estado === 'ok') {
            const nombre = json.data.nombre || archivo;
            const lenguaje = json.data.lenguaje || detectarLenguaje(nombre);

            editor.setValue(json.data.contenido || '');
            document.getElementById('fileName').textContent = nombre;
            document.title = 'Editor - ' + nombre;
            aplicarLenguaje(lenguaje);
            marcarGuardado(true);
        } else {
            setStatus(json.mensaje || 'Error al leer', 'error');
        }
    } catch (error) {
        console.error(error);
        setStatus('Error al cargar archivo', 'error');
    }
}

async function guardar() {
    if (!editor) return;

    setStatus('Guardando...', 'info');

    try {
        const formData = new FormData();
        formData.append('archivo', archivo);
        formData.append('contenido', editor.getValue());

        const res = await fetch('guardar_texto.php', {
            method: 'POST',
            body: formData
        });

        const json = await res.json();

        if (json.estado === 'ok') {
            marcarGuardado(true);
        } else {
            setStatus(json.mensaje || 'Error al guardar', 'error');
        }
    } catch (error) {
        console.error(error);
        setStatus('Error al guardar', 'error');
    }
}

async function validarPHP() {
    if (!editor || lenguajeActual !== 'php') {
        return;
    }

    setStatus('Validando PHP...', 'info');
    limpiarMarcadores();

    try {
        const formData = new FormData();
        formData.append('archivo', archivo);
        formData.append('contenido', editor.getValue());

        const res = await fetch('validar_php.php', {
            method: 'POST',
            body: formData
        });

        const json = await res.json();

        if (json.estado !== 'ok') {
            setStatus(json.mensaje || 'No se pudo validar PHP', 'error');
            return;
        }

        const markers = Array.isArray(json.errores)
            ? json.errores.map(error => ({
                startLineNumber: Number(error.linea || 1),
                startColumn: Number(error.columna || 1),
                endLineNumber: Number(error.linea || 1),
                endColumn: Number(error.finColumna || error.columna || 1) + 1,
                message: String(error.mensaje || 'Error PHP'),
                severity: monaco.MarkerSeverity.Error
            }))
            : [];

        monaco.editor.setModelMarkers(editor.getModel(), 'php-lint', markers);

        if (json.valido) {
            setStatus('PHP v¨¢lido', 'ok');
            return;
        }

        if (markers.length > 0) {
            const primer = markers[0];
            editor.revealPositionInCenter({
                lineNumber: primer.startLineNumber,
                column: primer.startColumn
            });
            editor.setPosition({
                lineNumber: primer.startLineNumber,
                column: primer.startColumn
            });
        }

        setStatus('Se encontraron ' + markers.length + ' error(es) de PHP', 'error');
    } catch (error) {
        console.error(error);
        setStatus('Error al validar PHP', 'error');
    }
}

function deshacer() {
    if (!editor) return;
    editor.trigger('toolbar', 'undo', null);
}

function rehacer() {
    if (!editor) return;
    editor.trigger('toolbar', 'redo', null);
}

function buscar() {
    if (!editor) return;
    editor.getAction('actions.find').run();
}

function reemplazar() {
    if (!editor) return;
    editor.getAction('editor.action.startFindReplaceAction').run();
}

function irALinea() {
    if (!editor) return;
    editor.getAction('editor.action.gotoLine').run();
}

function toggleWordWrap() {
    if (!editor) return;
    wordWrapActivo = !wordWrapActivo;
    editor.updateOptions({ wordWrap: wordWrapActivo ? 'on' : 'off' });
    setStatus('Word wrap ' + (wordWrapActivo ? 'activado' : 'desactivado'), 'info');
}
</script>

</body>
</html>
