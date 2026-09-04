<?php
require_once __DIR__ . '/app_bootstrap.php';
session_start();

/*
|--------------------------------------------------------------------------
| Protección simple por contraseña
|--------------------------------------------------------------------------
| Cambia esta contraseña por la tuya.
| No debe haber espacios ni HTML antes de este <?php
*/

$PASSWORD_CORRECTA = 'Us1317mx@777'; // <-- CAMBIA ESTO

// Si ya ingresó correctamente, dejamos ver la página
if (isset($_SESSION['pagina_autorizada']) && $_SESSION['pagina_autorizada'] === true) {
    // Continúa cargando la página normal
} else {

    // Si enviaron la contraseña
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';

        if (hash_equals($PASSWORD_CORRECTA, $password)) {
            $_SESSION['pagina_autorizada'] = true;

            // Recargamos la misma página ya autorizada
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            // Contraseña incorrecta: mandar al index.php
            header('Location: index.php');
            exit;
        }
    }

    // Si todavía no manda contraseña, mostramos formulario
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso protegido</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f4f4f4;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }

            .box {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 15px rgba(0,0,0,0.15);
                width: 320px;
                text-align: center;
            }

            input[type="password"] {
                width: 100%;
                padding: 12px;
                margin: 15px 0;
                border: 1px solid #ccc;
                border-radius: 6px;
                box-sizing: border-box;
            }

            button {
                width: 100%;
                padding: 12px;
                background: #0d6efd;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
            }

            button:hover {
                background: #0b5ed7;
            }
        </style>
    </head>
    <body>

    <div class="box">
        <h2>Acceso privado</h2>

        <form method="post">
            <input type="password" name="password" placeholder="Contraseña" required autofocus>
            <button type="submit">Entrar</button>
        </form>
    </div>

    </body>
    </html>
    <?php
    exit;
}
?>
<?php
/**session_start();
if (isset($_SESSION['usuario']) && !empty($_SESSION['usuario'])) {
    //usuario permitido.
} else {
    header("Location: index.php");
    exit;
}**/


use OTPHP\TOTP;
$currentCode ='';
$timeRemaining = '';
// Verificar si se ha enviado el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $secretKey = $_POST['secret_key'] ?? '';

    // Validar que la clave secreta no esté vacía
    if (empty($secretKey)) {
        echo "<p style='color: red;'>Error: Debes ingresar una clave secreta.</p>";
    } else {
        try {
            // Crear un objeto TOTP con la clave secreta
            $totp = TOTP::create($secretKey);

            if($secretKey=='g7wervjpucdodhhc2wvcbqrk'){
            $totp->setLabel('g7wervjpucdodhhc2wvcbqrk'); // Nombre del usuario (opcional) (084375544416-root-)-no funciona  (esforzados)OK
            }

            if($secretKey=='PX3TFJB6GTSZHQQWV6YQXOI7KY3YV6IAC34KKRJJRSNSC7M2HCXGDIJYB4Y6VLWR'){
            $totp->setLabel('esforzados'); // Nombre del usuario (opcional) (084375544416-root-)-no funciona  (esforzados)OK
            }
            if($secretKey=='7HSWNZAN25IZZT5OW4NQDVLHTBLUCZ7EVFLNR3RKR3VT6YEDG2DDZ2LQM4HOBJIZ')
            $totp->setLabel('esforzadosMFA');

            if($secretKey=='BPCOI6ECE72VOHPLJU5ZCBVGQF2VVDHEU63GWA6P2GBBHRDUFJSAO4KYDZHN4NEG')
            $totp->setLabel('soporteesforzados');

            if($secretKey=='4CTWROWI6274VQWDFFZU4GMJ2AJDOMOV')
            $totp->setLabel('Aide');
            //google backend
            if($secretKey=='femj4y7shlno23jbiu4zgtk4gjewzhvx')
            $totp->setLabel('femj4y7shlno23jbiu4zgtk4gjewzhvx');
            //google Aquilez
            if($secretKey=='74ynsqpy2l3hnvtwykom7phwbfrm4xvw')
            $totp->setLabel('74ynsqpy2l3hnvtwykom7phwbfrm4xvw');
            //google jimmy
            if($secretKey=='gewdyges3fnp3jduqb4loglxoevum7kj')
            $totp->setLabel('gewdyges3fnp3jduqb4loglxoevum7kj');
       
            //hotmail
            if($secretKey=='qlz3blkwxv3cvrff')
            $totp->setLabel('qlz3blkwxv3cvrff');
            if($secretKey=='bdlfpke4d3zhmns4')
            $totp->setLabel('bdlfpke4d3zhmns4');

            //yahoo
            if($secretKey=='B275BEOYNW66NFIIBW4RY3XB2KB3ZL4M')
            $totp->setLabel('B275BEOYNW66NFIIBW4RY3XB2KB3ZL4M');
            
            //google Miguel
            if($secretKey=='4ui2c7p7zfx6zzpnhfljtuu7oftqtowl')
            $totp->setLabel('4ui2c7p7zfx6zzpnhfljtuu7oftqtowl');
            
            $totp->setIssuer('esforzados'); // Nombre de tu dominio o empresa

            // Generar el código OTP actual
            $currentCode = $totp->now();

            // Calcular el tiempo restante antes de que cambie el código
            $timeRemaining = 30 - (time() % 30);

        } catch (Exception $e) {
            echo "<p style='color: red;'>Error: La clave secreta no es válida. Asegúrate de ingresar una clave secreta correcta.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/img/icono.png" type="image/x-icon">
    <title>Generador de Códigos OTP</title>
    <style>
    body {
            font-family: Arial, sans-serif;
            margin: 20px;
            text-align: center;
        }
        form {
            margin-bottom: 20px;
        }
        label {
            font-size: 16px;
            font-weight: bold;
            display: block;
            margin-bottom: 10px;
        }
        select {
            padding: 10px; /* Aumentar el relleno interno */
            font-size: 16px; /* Tamaño de fuente más grande */
            width: 300px; /* Ancho fijo para el select */
            border: 1px solid #ccc; /* Borde suave */
            border-radius: 5px; /* Bordes redondeados */
            background-color: #fff; /* Fondo blanco */
            cursor: pointer; /* Cambiar el cursor al pasar por encima */
            appearance: none; /* Eliminar el estilo predeterminado del navegador */
            -webkit-appearance: none; /* Para navegadores basados en WebKit */
            -moz-appearance: none; /* Para Firefox */
            background-image: url('data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23007bff"><path d="M7 10l5 5 5-5z"/></svg>'); /* Flecha personalizada */
            background-repeat: no-repeat;
            background-position: right 10px center; /* Posición de la flecha */
        }
        select:focus {
            outline: none; /* Eliminar el contorno al hacer foco */
            border-color: #007bff; /* Cambiar el color del borde al hacer foco */
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5); /* Sombra suave */
        }
        input[type="submit"] {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #0056b3; /* Cambio de color al pasar el mouse */
        }

        input[type="text"] {
            padding: 5px;
            width: 300px;
        }

        pre {
            background-color: #f4f4f4;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            display: inline-block;
        }
        .timer-container {
            margin-top: 20px;
            font-size: 18px;
        }
        .spinner {
            display: inline-block;
            width: 20px; /* Reducido para que quepa junto al texto */
            height: 20px; /* Reducido para que quepa junto al texto */
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-left-color: #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            vertical-align: middle; /* Alineado verticalmente con el texto */
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        .header {
            margin-bottom: 20px;
        }
        .header a {
            margin: 0 10px;
            text-decoration: none;
            color: #007bff;
        }
        .header a:hover {
            text-decoration: underline;
        }
        .timer-with-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px; /* Espacio entre el texto y el spinner */
        }
    </style>
</head>
<body>
    <!-- Encabezado con enlaces -->
    <div class="header">
        <a href="https://esforzados.signin.aws.amazon.com/console" target="_blank" onclick="redirectToNewPage(event)">soporte</a>
        <a href="https://d-9067c8ec7f.awsapps.com/start/#" target="_blank" onclick="redirectToNewPage(event)">Aide</a>
        <a href="https://aws.amazon.com/es/console/" target="_blank" onclick="redirectToNewPage(event)">AWS</a>
        <a href="https://console.aws.amazon.com/console/home" target="_blank" onclick="redirectToNewPage(event)">AWS</a>
        <a href="test.html" target="_blank" onclick="redirectToNewPage(event)">test</a>
        <a href="index.php" >Salir</a>


    </div>

    <!-- Mostrar el código OTP actual -->
    <h2>Código OTP actual:</h2>
    <pre id="otp-code"><?php echo htmlspecialchars($currentCode); ?></pre>

    <!-- Mostrar el temporizador con el spinner -->
    <h2>Tiempo restante antes del próximo código:</h2>
    <div class="timer-with-spinner">
        <pre id="timer"><?php echo $timeRemaining; ?> segundos</pre>
        <div class="spinner"></div>
    </div>

    <!-- Formulario para ingresar la clave secreta -->
    <form method="POST" action="">
        <label for="secret_key">Clave secreta TOTP:</label><br>
        <select id="secret_key" name="secret_key" required>
            <option value="" disabled selected>Selecciona una clave secreta</option>
            <option value="PX3TFJB6GTSZHQQWV6YQXOI7KY3YV6IAC34KKRJJRSNSC7M2HCXGDIJYB4Y6VLWR">@esforzados</option>
            <option value="BPCOI6ECE72VOHPLJU5ZCBVGQF2VVDHEU63GWA6P2GBBHRDUFJSAO4KYDZHN4NEG">@soporte-esforzados</option>
            <option value="7HSWNZAN25IZZT5OW4NQDVLHTBLUCZ7EVFLNR3RKR3VT6YEDG2DDZ2LQM4HOBJIZ">@esforzadosMFA</option>
            <option value="4CTWROWI6274VQWDFFZU4GMJ2AJDOMOV">Aide</option>
            <option value="g7wervjpucdodhhc2wvcbqrk">Stripe</option>
             <option value="femj4y7shlno23jbiu4zgtk4gjewzhvx">Google BACKEND</option>
             <option value="gewdyges3fnp3jduqb4loglxoevum7kj">Google JIMMY</option>
             <option value="74ynsqpy2l3hnvtwykom7phwbfrm4xvw">Google Aquilez</option>
             <option value="qlz3blkwxv3cvrff">Hotmail Jimmy</option>
             <option value="bdlfpke4d3zhmns4">Hotmail2 Jimmy</option>
             <option value="4ui2c7p7zfx6zzpnhfljtuu7oftqtowl">Google Miguel</option>
             
             <option value="B275BEOYNW66NFIIBW4RY3XB2KB3ZL4M">Yahoo Miguel</option>
             

        </select>
        <input type="submit" value="Generar Código OTP">
    </form>

    <?php
    $secretKey = $_POST['secret_key'] ?? '';

     if($secretKey=='g7wervjpucdodhhc2wvcbqrk'){
            echo 'tbtb-uwyw-orix-jonj-ujsk';
            }


    ?>


    <!-- Temporizador descendente con JavaScript -->
    <script>
        // Duración del temporizador en segundos (generalmente 30 segundos para TOTP)
        const TIMER_DURATION = <?php echo $timeRemaining; ?>;

        // Función para inicializar el temporizador
        function startTimer() {
            const timerElement = document.getElementById('timer');
            let remainingTime = TIMER_DURATION;

            // Actualizar el temporizador cada segundo
            const intervalId = setInterval(() => {
                remainingTime--;
                timerElement.textContent = remainingTime + " segundos";

                if (remainingTime <= 0) {
                    clearInterval(intervalId); // Detener el temporizador
                    location.reload(); // Recargar la página para generar un nuevo código OTP
                }
            }, 1000);
        }

        // Iniciar el temporizador al cargar la página
        window.onload = startTimer;
    </script>
    <script>
        // Función para redirigir a la nueva página y evitar el retorno
        function redirectToNewPage(event) {
            event.preventDefault(); // Prevenir el comportamiento predeterminado del enlace

            // URL de destino
            const url = event.target.href;

            // Abrir la nueva página en una nueva pestaña
            window.open(url, '_blank');

            // Redirigir la página actual a una página vacía o de cierre
            //window.location.replace('about:blank'); // Opcional: puedes redirigir a otra página
        }
    </script>
</body>
</html>