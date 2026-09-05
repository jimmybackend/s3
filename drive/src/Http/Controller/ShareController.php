<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sharing\ShareException;
use Throwable;

final class ShareController extends AbstractJsonController
{
    public function create(): void
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();

            $key = $this->requireNonEmpty(
                $this->request->postString('archivo'),
                'Falta parámetro "archivo".'
            );

            $result = $this->app->shareLinkService()->create(
                $userId,
                $key,
                $this->request->postString('tipo', 'otro'),
                $this->request->postInt('dias', 1)
            );

            $url = $this->baseUrl()
                . $result['endpoint']
                . '?t=' . rawurlencode((string)$result['token']);

            JsonResponse::send([
                'estado' => 'ok',
                'url' => $url,
                'token' => $result['token'],
                'expira_iso' => $result['expira_iso'],
                'dias' => $result['dias'],
                'calendario' => $result['calendario'],
            ]);
        } catch (ShareException $e) {
            $this->fail($e, $e->httpStatus());
        } catch (Throwable $e) {
            $this->fail($e, 500);
        }
    }

    private function baseUrl(): string
    {
        $host = $this->request->serverString('HTTP_HOST');
        if ($host === '' || !preg_match('/\A[a-z0-9.-]+(?::\d+)?\z/i', $host)) {
            throw new ShareException('Host HTTP inválido.', 400);
        }

        $forwardedProto = strtolower(trim(explode(',', $this->request->serverString('HTTP_X_FORWARDED_PROTO'))[0] ?? ''));
        $https = strtolower($this->request->serverString('HTTPS'));
        $scheme = ($forwardedProto === 'https' || $https === 'on' || $https === '1') ? 'https' : 'http';

        $scriptName = str_replace('\\', '/', $this->request->serverString('SCRIPT_NAME'));
        $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($directory === '.' || $directory === '/') {
            $directory = '';
        }

        return $scheme . '://' . $host . $directory . '/';
    }
}
