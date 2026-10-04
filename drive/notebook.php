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
<link rel="stylesheet" href="css/notebook.css">
</head>
<body>
<div class="nb-app">
  <header class="nb-topbar">
    <div class="nb-brand"><span class="nb-logo">✎</span><div><strong>Notebook</strong><small>ArcadeCloud</small></div></div>
    <div class="nb-book-picker">
      <select id="nbNotebook" aria-label="Libreta"></select>
      <button id="nbNewBook" type="button">Nueva</button>
      <button id="nbMovePage" type="button">Mover hoja</button>
    </div>
    <div class="nb-savebox"><span id="nbStatus">Lista</span><button id="nbSave" type="button">Guardar</button></div>
  </header>

  <main class="nb-main">
    <section class="nb-workspace">
      <div class="nb-options">
        <label>Instrumento
          <select id="nbTool" aria-label="Instrumento de escritura">
            <option value="pencil">Lápiz</option>
            <option value="pen">Bolígrafo</option>
            <option value="fountain">Pluma fuente</option>
            <option value="marker">Marcador</option>
            <option value="brush">Pincel</option>
            <option value="eraser">Borrador</option>
          </select>
        </label>
        <label>Color <input id="nbColor" type="color" value="#111111"></label>
        <label>Grosor <input id="nbSize" type="range" min="1" max="36" value="3"></label>
        <label>Hoja
          <select id="nbBackground">
            <option value="blank">Blanca</option><option value="ruled">Rayada</option>
            <option value="grid">Cuadros</option><option value="millimeter">Milimétrica</option>
            <option value="dots">Puntos</option><option value="notes">Notas</option>
          </select>
        </label>
        <label>Tamaño
          <select id="nbPaper">
            <option value="letter">Carta</option><option value="legal">Oficio</option>
            <option value="tabloid">Tabloide</option><option value="half-letter">Media carta</option>
          </select>
        </label>
        <label>Orientación
          <select id="nbOrientation"><option value="portrait">Vertical</option><option value="landscape">Horizontal</option></select>
        </label>
        <label class="nb-switch-label" title="Mantiene la escritura apoyada sobre las líneas de la hoja">
          <input id="nbOnLine" type="checkbox">
          <span>Sobre línea</span>
        </label>
        <label>Alineación
          <select id="nbAlignment">
            <option value="left">Izquierda</option>
            <option value="right">Derecha</option>
            <option value="justify">Justificada</option>
          </select>
        </label>
        <span class="nb-menu-page">Página <strong id="nbMenuPage">1</strong>/<strong id="nbMenuTotal">100</strong></span>
        <div class="nb-edit-actions" role="group" aria-label="Edición">
          <button id="nbUndo" type="button" title="Deshacer">↶</button>
          <button id="nbRedo" type="button" title="Rehacer">↷</button>
          <button id="nbClear" type="button" title="Borrar hoja">🗑</button>
        </div>
      </div>

      <div class="nb-page-shell">
        <div class="nb-page-counter" aria-label="Paginación de la hoja">
          <button id="nbPrev" type="button" aria-label="Página anterior">‹</button>
          <span><strong id="nbPageLabel">1</strong>/<strong id="nbTotal">100</strong></span>
          <button id="nbNext" type="button" aria-label="Página siguiente">›</button>
        </div>
        <canvas id="nbCanvas" aria-label="Hoja de Notebook"></canvas>
        <div class="nb-page-footer" role="group" aria-label="Escritura asistida">
          <input id="nbPrompt" type="text" placeholder="Escribe un mensaje y Notebook lo colocará como escritura manual">
          <button id="nbWritePrompt" type="button">Escribir a mano</button>
        </div>
      </div>

      <nav class="nb-nav" aria-label="Ir a página">
        <span>Ir a página <input id="nbPage" type="number" min="1" value="1"></span>
      </nav>
    </section>
  </main>
</div>

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