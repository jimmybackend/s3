<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/View/UserIdentityPresenter.php';

use ArcadeCloud\Drive\View\UserIdentityPresenter;

$cases = [
    [UserIdentityPresenter::alias('jimmybackend@example.com'), '@jimmybackend'],
    [UserIdentityPresenter::initials('jimmybackend@example.com'), 'J'],
    [UserIdentityPresenter::initials('jimmy.backend@example.com'), 'JB'],
    [UserIdentityPresenter::initials('Jimmy Backend'), 'JB'],
    [UserIdentityPresenter::alias('soporte@example.com'), '@soporte'],
    [UserIdentityPresenter::initials('Soporte'), 'S'],
];

foreach ($cases as [$actual, $expected]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

if (str_contains(UserIdentityPresenter::alias('jimmybackend@example.com'), 'example.com')) {
    fwrite(STDERR, "Alias must never expose the email domain.\n");
    exit(1);
}

echo "user identity presenter smoke: OK\n";
