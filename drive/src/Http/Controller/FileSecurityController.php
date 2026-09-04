<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Security\FileSecurityRepository;
use ArcadeCloud\Drive\Security\FileSecurityService;

final class FileSecurityController extends AbstractJsonController
{
    private function service(): FileSecurityService
    {
        return new FileSecurityService(
            new FileSecurityRepository($this->app->db()),
            $this->app->session()
        );
    }

    public function setMode(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $keys = $this->keys();
            $result = $this->service()->setMode(
                $userId,
                $keys,
                $this->request->postString('mode'),
                $this->request->postString('password'),
                $this->request->postString('secure_hint')
            );
            JsonResponse::send($result);
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    public function unlock(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $service = $this->service();
            $key = $service->buildKey(
                $this->request->postString('key'),
                $this->request->postString('ruta'),
                $this->firstNonEmpty('encriptado', 'enc')
            );
            $password = $this->firstNonEmpty('password', 'pass');
            JsonResponse::send($service->unlock($userId, $key, $password));
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    public function relock(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();
            $service = $this->service();
            $keys = $this->keys();
            $compat = $service->buildKey(
                '',
                $this->request->postString('ruta'),
                $this->firstNonEmpty('encriptado', 'enc')
            );
            if ($compat !== '') {
                $keys[] = $compat;
            }
            JsonResponse::send($service->relock($userId, $keys));
        } catch (\Throwable $error) {
            JsonResponse::send(['ok' => false, 'msg' => $error->getMessage()], 400);
        }
    }

    private function keys(): array
    {
        $keys = $this->keysFromRequest();
        $single = $this->request->postString('key');
        if ($single !== '') {
            $keys[] = $single;
        }
        return $keys;
    }

    private function firstNonEmpty(string ...$names): string
    {
        foreach ($names as $name) {
            $value = $this->request->postString($name);
            if ($value !== '') return $value;
        }
        return '';
    }
}
