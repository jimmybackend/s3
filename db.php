<?php
/**
 * Archivo: db.php
 * Versión: 3.0
 * Descripción: Inicializa la conexión mysqli global usada por el proyecto.
 */
$servidor  = "servidor";
$usuario   = "usuario";
$clave     = "clave";
$basedatos = "basedatos";

// La conexión se mantiene en una variable global para reutilizarla en scripts legacy.
$db_connection = mysqli_connect($servidor, $usuario, $clave, $basedatos) or die(mysqli_error($db_connection));

if (!$db_connection) {
    die('No se ha podido conectar a la base de datos: ' . mysqli_connect_error());
}
?>
