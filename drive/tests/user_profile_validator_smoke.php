<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Security/UserProfileValidator.php';

use ArcadeCloud\Drive\Security\UserProfileValidator;

$valid = UserProfileValidator::personal([
    'firstname' => 'Usuario',
    'lastname' => 'Prueba',
    'curp' => 'TEST900101HXXABC01',
    'gender' => 'Otro',
    'birthdate' => '1990-01-01',
    'address' => 'Calle Demo',
    'neighborhood' => 'Centro',
    'postalcode' => '00000',
    'state' => 'Estado Demo',
    'country' => 'Pais Demo',
    'homephone' => '5550000000',
    'mobilephone' => '+525550000000',
]);

if ($valid['firstname'] !== 'Usuario' || $valid['curp'] !== 'TEST900101HXXABC01') {
    fwrite(STDERR, "Valid profile normalization failed.\n");
    exit(1);
}

$rejected = false;
try {
    UserProfileValidator::personal(array_merge($valid, ['gender' => 'Administrador']));
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Invalid gender was accepted.\n");
    exit(1);
}

$rejected = false;
try {
    UserProfileValidator::password('corta', 'corta');
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Short password was accepted.\n");
    exit(1);
}

$password = 'frase-segura-de-prueba-2026';
if (UserProfileValidator::password($password, $password) !== $password) {
    fwrite(STDERR, "Valid password was rejected.\n");
    exit(1);
}

echo "user profile validator smoke: OK\n";
