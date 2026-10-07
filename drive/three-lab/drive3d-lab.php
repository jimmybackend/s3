<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app_bootstrap.php';
$session = \ArcadeCloud\Drive\Core\ApplicationKernel::app()->session();
$session->start();
$session->requireAuthenticated('../index.php');
$session->closeWrite();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Drive 3D · Laboratorio espacial</title>
<link rel="stylesheet" href="../css/drive3d-three-lab.css">
<script type="module" src="../js/drive3d-three-lab.js"></script>
</head>
<body>
<main id="lab" aria-label="Laboratorio tridimensional del domo">
<div id="viewport" tabindex="0" aria-label="Vista 3D. Arrastra para mirar; W A S D para caminar."></div>
<header class="panel heading"><div><small>ARCADECLOUD OS / LABORATORIO</small><h1>Biblioteca 3D</h1><span>Domo de cristal · estudio visual</span></div><a href="../dataword3d.php">Volver a Drive 3D</a></header>
<nav class="panel views" aria-label="Vistas de comprobación"><button data-view="front">Frente</button><button data-view="left">Extremo izquierdo</button><button data-view="right">Extremo + lámpara</button><button data-view="center">Centrar</button></nav>
<aside class="panel map"><strong>Planta del domo</strong><canvas id="minimap" width="240" height="240" aria-label="Vista superior: cámara azul, libreros marrones y lámpara dorada"></canvas><small>▲ Tú · ▰ Libreros · ● Lámpara</small><output id="coordinates"></output></aside>
<footer class="panel help">Arrastra para mirar · WASD: caminar · Flechas: girar<br>En móvil: arrastra la escena y mantén los botones.</footer>
<div class="walk panel" role="group" aria-label="Movimiento"><button data-move="forward" aria-label="Avanzar">↑</button><div><button data-move="left" aria-label="Desplazarse a izquierda">←</button><button data-move="back" aria-label="Retroceder">↓</button><button data-move="right" aria-label="Desplazarse a derecha">→</button></div></div>
<p id="status" class="panel status" role="status">Preparando escena 3D…</p>
<noscript>Activa JavaScript para abrir este laboratorio.</noscript>
</main>
</body>
</html>
