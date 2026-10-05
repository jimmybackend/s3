<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

$userId = $session->userId();
if ($userId <= 0) {
    header('Location: logout.php');
    exit;
}

$csrf = (string)$session->get('upload_csrf', '');
if (!preg_match('/\A[a-f0-9]{64}\z/', $csrf)) {
    $csrf = bin2hex(random_bytes(32));
    $session->set('upload_csrf', $csrf);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Notebook · ArcadeCloud</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Shadows+Into+Light&family=Roboto+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/notebook.css">
</head>
<body>
<div class="nb-app">
  <header class="nb-topbar">
    <div class="nb-brand"><span class="nb-logo">✎</span><div><strong>Notebook</strong><small>ArcadeCloud</small></div></div>
    <div class="nb-top-context">ArcadeCloud Notebook</div>
  </header>

      <nav class="nb-menubar" aria-label="Menú de Notebook">
        <details class="nb-menu">
          <summary>Archivo</summary>
          <div class="nb-menu-panel">
            <label>Libreta <select id="nbNotebook" aria-label="Libreta"></select></label>
            <button id="nbNewBook" type="button">Nueva libreta</button>
            <button id="nbSave" type="button">Guardar</button>
            <button id="nbMovePage" type="button">Mover hoja</button>
            <span class="nb-menu-page">Página <strong id="nbMenuPage">1</strong>/<strong id="nbMenuTotal">100</strong></span>
          </div>
        </details>
        <details class="nb-menu">
          <summary>Editar</summary>
          <div class="nb-menu-panel">
            <label>Herramienta
              <select id="nbTool">
                <option value="select">Seleccionar / editar</option>
                <option value="pencil">Lápiz</option><option value="pen">Bolígrafo</option>
                <option value="fountain">Pluma fuente</option><option value="marker">Marcador</option>
                <option value="brush">Pincel</option><option value="eraser">Borrador</option>
                <option value="line-eraser">Borrador de línea</option>
              </select>
            </label>
            <button id="nbEditSelected" type="button">Editar texto</button>
            <button id="nbDeleteSelected" type="button">Eliminar selección</button>
            <button id="nbUndo" type="button">Deshacer</button>
            <button id="nbRedo" type="button">Rehacer</button>
            <button id="nbClear" type="button">Borrar hoja</button>
          </div>
        </details>
        <details class="nb-menu">
          <summary>Insertar</summary>
          <div class="nb-menu-panel">
            <label>Pegar imagen
              <select id="nbPasteMode">
                <option value="normal">Normal</option>
                <option value="outline">Solo contorno B/N</option>
                <option value="gray">Escala de grises</option>
                <option value="pencil">Efecto lápiz</option>
              </select>
            </label>
            <button id="nbPasteImage" type="button">Pegar imagen</button>
          </div>
        </details>
        <details class="nb-menu">
          <summary>Formato</summary>
          <div class="nb-menu-panel">
            <label>Tipo de letra
              <select id="nbFont">
                <option value="caveat" selected>Caveat</option><option value="dancing">Dancing Script</option>
                <option value="shadows">Shadows Into Light</option><option value="sans">Sans</option>
                <option value="serif">Serif</option><option value="mono">Mono compacta</option><option value="rounded">Redondeada</option>
              </select>
            </label>
            <label>Tamaño letra <input id="nbFontSize" type="number" min="12" max="96" step="1" value="28"></label>
            <label>Color <input id="nbColor" type="color" value="#111111"></label>
            <label>Grosor <input id="nbSize" type="range" min="1" max="36" value="3"></label>
            <label class="nb-switch-label"><input id="nbOnLine" type="checkbox"><span>Sobre línea</span></label>
            <label>Alineación
              <select id="nbAlignment"><option value="left">Izquierda</option><option value="right">Derecha</option><option value="justify">Justificada</option></select>
            </label>
          </div>
        </details>
        <details class="nb-menu">
          <summary>IA</summary>
          <div class="nb-menu-panel">
            <button id="nbImproveAi" type="button">Mejorar hoja con IA</button>
            <small class="nb-menu-help">Corrige texto y presentación con Amazon Bedrock, conservando objetos editables.</small>
          </div>
        </details>
        <details class="nb-menu">
          <summary>Vista</summary>
          <div class="nb-menu-panel">
            <label>Zoom
              <select id="nbZoom"><option value="25">25%</option><option value="50" selected>50%</option><option value="75">75%</option><option value="100">100%</option><option value="125">125%</option><option value="150">150%</option><option value="175">175%</option><option value="200">200%</option></select>
            </label>
            <label>Hoja
              <select id="nbBackground"><option value="blank">Blanca</option><option value="ruled">Rayada</option><option value="grid">Cuadros</option><option value="millimeter">Milimétrica</option><option value="dots">Puntos</option><option value="notes">Notas</option></select>
            </label>
            <label>Tamaño
              <select id="nbPaper"><option value="letter">Carta</option><option value="legal">Oficio</option><option value="tabloid">Tabloide</option><option value="half-letter">Media carta</option></select>
            </label>
            <label>Orientación <select id="nbOrientation"><option value="portrait">Vertical</option><option value="landscape">Horizontal</option></select></label>
          </div>
        </details>
        <span class="nb-statusbar" id="nbStatus">Lista</span>
      </nav>

  <main class="nb-main">
    <section class="nb-workspace">
      <div class="nb-page-shell">
        <div class="nb-page-stage">
          <div class="nb-page-counter" aria-label="Paginación de la hoja">
            <button id="nbPrev" type="button" aria-label="Página anterior">‹</button>
            <span><strong id="nbPageLabel">1</strong>/<strong id="nbTotal">100</strong></span>
            <button id="nbNext" type="button" aria-label="Página siguiente">›</button>
          </div>
          <canvas id="nbCanvas" aria-label="Hoja de Notebook"></canvas>
        </div>
      </div>

      <nav class="nb-nav" aria-label="Ir a página">
        <span>Ir a página <input id="nbPage" type="number" min="1" value="1"></span>
      </nav>

      <div class="nb-composer" role="group" aria-label="Escritura asistida">
        <input id="nbPrompt" type="text" placeholder="Escribe aquí; toca un renglón para elegir dónde colocarlo" autocomplete="off">
        <button id="nbWritePrompt" type="button">Enviar</button>
      </div>
    </section>
  </main>
</div>

<dialog id="nbEditDialog">
  <form method="dialog" id="nbEditForm">
    <h2>Editar texto</h2>
    <label>Texto <textarea id="nbEditText" rows="5"></textarea></label>
    <menu><button value="cancel">Cancelar</button><button id="nbApplyEdit" value="default">Aplicar</button></menu>
  </form>
</dialog>

<dialog id="nbNewDialog">
  <form method="dialog" id="nbNewForm">
    <h2>Nueva libreta</h2>
    <label>Nombre <input id="nbNewName" required maxlength="120"></label>
    <label>Páginas <input id="nbNewPages" type="number" min="1" max="2000" value="100"></label>
    <menu><button value="cancel">Cancelar</button><button id="nbCreateBook" value="default">Crear</button></menu>
  </form>
</dialog>

<dialog id="nbMoveDialog">
  <form method="dialog">
    <h2>Mover hoja</h2>
    <label>Destino <select id="nbMoveTarget"></select></label>
    <menu><button value="cancel">Cancelar</button><button id="nbConfirmMove" value="default">Mover</button></menu>
  </form>
</dialog>

<script>window.DRIVE_UPLOAD_CSRF=<?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="js/notebook.js"></script>
</body>
</html>