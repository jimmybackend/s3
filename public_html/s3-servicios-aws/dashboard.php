<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header("Location: index.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cloud Drive</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="ellogo.png" type="image/x-icon">
  <link rel="stylesheet" href="css/styles.css" />
  <link rel="stylesheet" href="css/chat.css" />
</head>

<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

<nav class="navbar navbar-expand-lg navbar-dark px-3">
  <a class="navbar-brand" href="#">
    <!-- <img src="../assets/img/icono.png" width="30" height="30" class="d-inline-block align-top" alt="Logo"> Cloud Drive -->
    <img src="ellogo.png" width="30" height="30" class="rounded-circle mr-2" width="30" height="30" alt="Logo"> Cloud Drive
  </a>

    <div class="form-inline my-2 my-lg-0 ml-auto">
        <button id="btnRecargar" class="btn btn-primary ml-2" onclick="recargarPagina()" title="Recargar página">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>


    <ul class="navbar-nav ml-3">
       <li class="nav-item dropdown ml-2">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="temaMenu" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-palette mr-1"></i> Diseño
          </a>
        
          <div class="dropdown-menu dropdown-menu-right" aria-labelledby="temaMenu" style="min-width:280px;">
            
            <h6 class="dropdown-header">Color neón</h6>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-green">Verde neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-blue">Azul neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-red">Rojo neón</button>
            <button class="dropdown-item js-set-theme" data-theme="theme-neon-yellow">Amarillo neón</button>
        
            <div class="dropdown-divider"></div>
        
            <h6 class="dropdown-header">Modo</h6>
            <button class="dropdown-item js-set-mode" data-mode="theme-dark">Oscuro</button>
            <button class="dropdown-item js-set-mode" data-mode="theme-light">Claro</button>
        
            <div class="dropdown-divider"></div>
        
            <h6 class="dropdown-header">Visión</h6>
            <button class="dropdown-item js-set-vision" data-vision="vision-normal">Normal</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-myopia">Miopía</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-protanopia">Protanopia</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-deuteranopia">Deuteranopia</button>
            <button class="dropdown-item js-set-vision" data-vision="vision-tritanopia">Tritanopia</button>
        
            <div class="dropdown-divider"></div>
        
            <button class="dropdown-item" id="btnToggleAscii">
              <i class="fas fa-terminal mr-1"></i> Alternar ASCII
            </button>
        
          </div>
        </li>    
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle d-flex align-items-center text-white" href="#" id="usuarioMenu" role="button" data-toggle="dropdown">
          <img src="logo1.png" alt="Perfil" class="rounded-circle mr-2" width="30" height="30">
          <?= htmlspecialchars($_SESSION['usuario']) ?>
        </a>

        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="usuarioMenu">
          <button class="dropdown-item" data-toggle="modal" data-target="#modalPreferencias">
            <i class="fas fa-sliders-h"></i> Preferencias
          </button>
          <button class="dropdown-item" data-toggle="modal" data-target="#modalEnlacesUtiles">
            <i class="fas fa-link"></i> Enlaces
          </button>
          <button id="btnSyncS3" class="dropdown-item">
              <i class="fas fa-rotate"></i> Sincronizar S3
          </button>
          
          <div class="dropdown-divider"></div>
           <a class="dropdown-item text-danger" href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar sesión
          </a>
        </div>
      </li>
    </ul>

</nav>


<div class="container-fluid">


  

<!-- Agrega esta parte al final del body para mostrar el modal -->


</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script> 
<!--<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>-->
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>



<script src="js/actualizar-hora.js"></script>
<script src="js/actualizarbloquefooter.js"></script>
<script src="js/recargarPagina.js"></script>


<script src="js/sincronizar.js"></script>

<script src="js/estilo.js"></script>



<script>
window.rutaActual = <?= json_encode($basePrefix) ?>;
</script>




</body>
</html>