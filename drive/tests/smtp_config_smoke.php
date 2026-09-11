<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Mail/SmtpConfig.php';

use ArcadeCloud\Drive\Mail\SmtpConfig;

putenv('ARCADECLOUD_SMTP_HOST=smtp.example.test');
putenv('ARCADECLOUD_SMTP_PORT=587');
putenv('ARCADECLOUD_SMTP_SECURE=tls');
putenv('ARCADECLOUD_SMTP_USERNAME=mailer@example.test');
putenv('ARCADECLOUD_SMTP_PASSWORD=test-only-secret');
putenv('ARCADECLOUD_SMTP_FROM_EMAIL=mailer@example.test');
putenv('ARCADECLOUD_SMTP_FROM_NAME=ArcadeCloud Test');
putenv('ARCADECLOUD_SMTP_REPLY_TO=no-reply@example.test');
putenv('ARCADECLOUD_SMTP_TIMEOUT=20');
putenv('ARCADECLOUD_SMTP_DEBUG=false');

$config = SmtpConfig::fromEnvironment();

assert($config->host === 'smtp.example.test');
assert($config->port === 587);
assert($config->secure === 'tls');
assert($config->username === 'mailer@example.test');
assert($config->password === 'test-only-secret');
assert($config->fromEmail === 'mailer@example.test');
assert($config->fromName === 'ArcadeCloud Test');
assert($config->replyTo === 'no-reply@example.test');
assert($config->timeout === 20);
assert($config->debug === false);

putenv('ARCADECLOUD_SMTP_PORT=70000');
$invalidRejected = false;
try {
    SmtpConfig::fromEnvironment();
} catch (RuntimeException) {
    $invalidRejected = true;
}
assert($invalidRejected === true);

echo "smtp config smoke: OK\n";
