from pathlib import Path

path = Path('drive/ec2.php')
text = path.read_text(encoding='utf-8')
marker = '// ===================== Config ====================='

if marker not in text:
    raise SystemExit('EC2_CONFIG_MARKER_NOT_FOUND')

body = marker + text.split(marker, 1)[1]

header = r'''<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\PersonalAwsPageRenderer;
use Aws\Ec2\Ec2Client;
use Aws\Rds\RdsClient;
use Aws\Exception\AwsException;

$app = ApplicationKernel::app();
$request = Request::fromGlobals();
$access = $app->personalToolAccessService();
$renderer = new PersonalAwsPageRenderer();
$accessState = $access->state();

if ($accessState === 'forbidden') {
    $renderer->forbidden();
}

if ($accessState === 'locked') {
    $configured = $app->personalAwsConfig()->isConfigured();

    if (
        $request->method() === 'POST'
        && $request->postString('action') === 'unlock'
    ) {
        if ($access->unlock($request->postRawString('access_password'))) {
            header('Location: ' . $request->serverString('PHP_SELF', 'ec2.php'));
            exit;
        }

        $renderer->locked($configured, 'Contraseña incorrecta.');
    }

    $renderer->locked($configured);
}

'''

new_text = header + body
new_text = '\n'.join(line.rstrip() for line in new_text.splitlines()) + '\n'

if new_text == text:
    print('EC2_ACCESS_ALREADY_CURRENT')
    raise SystemExit(0)

path.write_text(new_text, encoding='utf-8')
print('EC2_ACCESS_UPDATED')
