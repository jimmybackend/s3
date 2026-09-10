<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Sharing\ShareException;
use Throwable;

final class ShareController extends AbstractJsonController
{
    public function create(): void
    {
        $userId = 0;
        $started = microtime(true);
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $key = $this->request->postString('archivo');
            if ($key === '') throw new ShareException('Falta parámetro "archivo".', 400);

            $fileId = null;
            try {
                $file = $this->app->shareFileRepository()->requireOwnedByKey($userId, $key);
                $fileId = (int)($file['id_'] ?? 0) ?: null;
            } catch (Throwable) {
            }

            $result = $this->app->shareLinkService()->create(
                $userId,
                $key,
                $this->request->postString('tipo', 'otro'),
                $this->request->postInt('dias', 1)
            );

            $this->activity()->success($userId, 'share', 'Drive', $fileId, ['drive.no_direct_aws_charge' => 1], $started, [
                'days' => (int)($result['dias'] ?? 0),
                'aws_direct' => false,
            ]);

            $url = $this->baseUrl() . $result['endpoint'] . '?t=' . rawurlencode((string)$result['token']);
            JsonResponse::send([
                'estado' => 'ok',
                'url' => $url,
                'token' => $result['token'],
                'expira_iso' => $result['expira_iso'],
                'dias' => $result['dias'],
                'calendario' => $result['calendario'],
            ]);
        } catch (ShareException $e) {
            if ($userId > 0) $this->activity()->failure($userId, 'share', 'Drive', $started);
            JsonResponse::send(['ok' => false, 'estado' => 'error', 'mensaje' => $e->getMessage(), 'error' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable) {
            if ($userId > 0) $this->activity()->failure($userId, 'share', 'Drive', $started);
            JsonResponse::send(['ok' => false, 'estado' => 'error', 'mensaje' => 'No se pudo generar el enlace compartido.', 'error' => 'No se pudo generar el enlace compartido.'], 500);
        }
    }

    private function activity(): ActivityCostRecorder
    {
        return ActivityCostRecorder::fromDatabase($this->app->db());
    }

    private function baseUrl(): string
    {
        $host = $this->request->serverString('HTTP_HOST');
        if ($host === '' || !preg_match('/\A[a-z0-9.-]+(?::\d+)?\z/i', $host)) throw new ShareException('Host HTTP inválido.', 400);
        $forwardedProto = strtolower(trim(explode(',', $this->request->serverString('HTTP_X_FORWARDED_PROTO'))[0] ?? ''));
        $https = strtolower($this->request->serverString('HTTPS'));
        $scheme = ($forwardedProto === 'https' || $https === 'on' || $https === '1') ? 'https' : 'http';
        $scriptName = str_replace('\\', '/', $this->request->serverString('SCRIPT_NAME'));
        $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($directory === '.' || $directory === '/') $directory = '';
        return $scheme . '://' . $host . $directory . '/';
    }
}
