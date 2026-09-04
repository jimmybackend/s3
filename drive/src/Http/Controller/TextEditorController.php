<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Application\PhpLintService;
use ArcadeCloud\Drive\Application\TextFileService;
use ArcadeCloud\Drive\Http\JsonResponse;

final class TextEditorController extends AbstractJsonController
{
    public function read(): never
    {
        try {
            $this->guardAuthenticated();
            $key = $this->request->queryString('archivo');
            $service = new TextFileService($this->app->s3Manager());
            JsonResponse::send(['estado'=>'ok','data'=>$service->read($key)]);
        } catch (\Throwable $error) {
            JsonResponse::send(['estado'=>'error','mensaje'=>$error->getMessage()], 500);
        }
    }

    public function save(): never
    {
        try {
            $this->requirePost();
            $this->guardAuthenticated();
            $key = $this->request->postString('archivo');
            $content = $this->request->postRawString('contenido');
            $service = new TextFileService($this->app->s3Manager());
            JsonResponse::send(['estado'=>'ok','data'=>$service->save($key, $content)]);
        } catch (\Throwable $error) {
            JsonResponse::send(['estado'=>'error','mensaje'=>$error->getMessage()], 500);
        }
    }

    public function lintPhp(): never
    {
        try {
            $this->requirePost();
            $this->guardAuthenticated();
            $service = new PhpLintService();
            JsonResponse::send($service->validate(
                $this->request->postString('archivo'),
                $this->request->postRawString('contenido')
            ));
        } catch (\Throwable $error) {
            JsonResponse::send(['estado'=>'error','mensaje'=>$error->getMessage()], 400);
        }
    }
}
