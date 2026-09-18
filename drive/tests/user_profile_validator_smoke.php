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

$passwordCases = ['1', '123456', 'abc123', 'ABC', 'a!2#'];
foreach ($passwordCases as $password) {
    if (UserProfileValidator::password($password, $password) !== $password) {
        fwrite(STDERR, "Valid short password was rejected: {$password}.\n");
        exit(1);
    }
}

foreach (['', '1234567'] as $invalidPassword) {
    $rejected = false;
    try {
        UserProfileValidator::password($invalidPassword, $invalidPassword);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    if (!$rejected) {
        fwrite(STDERR, "Invalid password length was accepted.\n");
        exit(1);
    }
}

$rejected = false;
try {
    UserProfileValidator::password('123456', '654321');
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Mismatched password confirmation was accepted.\n");
    exit(1);
}

echo "user profile validator smoke: OK\n";
