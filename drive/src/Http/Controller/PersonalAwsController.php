<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Aws\PersonalAwsRuntime;
use ArcadeCloud\Drive\Http\Request;
use ArcadeCloud\Drive\View\PersonalAwsPageRenderer;
use Throwable;

final class PersonalAwsController
{
    public function __construct(
        private PersonalAwsRuntime $runtime,
        private Request $request,
        private PersonalAwsPageRenderer $renderer = new PersonalAwsPageRenderer()
    ) {
    }

    public function handle(): never
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');

        $access = $this->runtime->access();
        $state = $access->state();

        if ($state === 'forbidden') {
            $this->renderer->forbidden();
        }

        if ($state === 'locked') {
            if ($this->request->method() === 'POST' && $this->request->postString('action') === 'unlock') {
                if ($access->unlock($this->request->postRawString('access_password'))) {
                    header('Location: aws.php');
                    exit;
                }

                usleep(350000);
                $this->renderer->locked(
                    $this->runtime->config()->passwordHash() !== '',
                    'Acceso no autorizado.'
                );
            }

            $this->renderer->locked(
                $this->runtime->config()->passwordHash() !== ''
            );
        }

        $result = null;
        $error = '';

        if ($this->request->method() === 'POST' && $this->request->postString('action') === 'generate') {
            try {
                $result = $this->runtime->totp()->generate(
                    $this->request->postString('account_id')
                );
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->renderer->tool(
            $this->runtime->totp()->accounts(),
            $result,
            $error
        );
    }
}
