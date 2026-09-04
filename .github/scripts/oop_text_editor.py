from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
DRIVE=ROOT/'drive'; SRC=DRIVE/'src'

def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True); path.write_text(content, encoding='utf-8')

write(SRC/'Application/TextFileService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use RuntimeException;

final class TextFileService
{
    private const EDITABLE_EXTENSIONS = [
        'txt','srt','vtt','md','markdown','html','htm','css','js','mjs','php','phtml','py',
        'json','csv','sql','jas','xml','yaml','yml','ini','cfg','conf','log'
    ];

    public function __construct(private \S3Manager $storage)
    {
    }

    public function read(string $key): mixed
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        return $this->storage->getTextFile($key);
    }

    public function save(string $key, string $content): mixed
    {
        $key = $this->validateKey($key);
        $this->assertEditable($key);
        return $this->storage->updateTextFile($key, $content);
    }

    private function validateKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key));
        if ($key === '' || str_contains($key, '../') || str_starts_with($key, '/')) {
            throw new RuntimeException('Clave de archivo inválida.');
        }
        return $key;
    }

    private function assertEditable(string $key): void
    {
        $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EDITABLE_EXTENSIONS, true)) {
            throw new RuntimeException('El tipo de archivo no es editable como texto.');
        }
    }
}
''')

write(SRC/'Application/PhpLintService.php', r'''<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use RuntimeException;

final class PhpLintService
{
    private const EXTENSIONS = ['php','phtml','inc'];

    public function validate(string $fileName, string $content): array
    {
        $extension = strtolower((string)pathinfo(basename($fileName), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Solo se permite validar archivos PHP.');
        }
        $this->assertExecAvailable();

        $directory = sys_get_temp_dir();
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('No hay acceso de escritura al directorio temporal.');
        }

        $base = tempnam($directory, 'lint_php_');
        if ($base === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }
        $path = $base . '.php';
        @rename($base, $path);

        try {
            if (@file_put_contents($path, $content) === false) {
                throw new RuntimeException('No se pudo escribir el archivo temporal.');
            }
            $output = [];
            $code = 0;
            exec(escapeshellcmd('php') . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
            $text = trim(implode("\n", $output));
            if ($code === 0) {
                return [
                    'estado'=>'ok','valido'=>true,
                    'mensaje'=>$text !== '' ? $text : 'No syntax errors detected','errores'=>[]
                ];
            }
            $line = 1;
            if (preg_match('/in\s+.+?\s+on\s+line\s+(\d+)/i', $text, $match)) {
                $line = max(1, (int)$match[1]);
            }
            return [
                'estado'=>'ok','valido'=>false,'mensaje'=>'Se detectaron errores de sintaxis PHP.',
                'errores'=>[['linea'=>$line,'columna'=>1,'finColumna'=>120,'mensaje'=>$text ?: 'Error de sintaxis PHP no identificado.']]
            ];
        } finally {
            @unlink($path);
        }
    }

    private function assertExecAvailable(): void
    {
        if (!function_exists('exec')) {
            throw new RuntimeException('La función exec() no está disponible en este servidor.');
        }
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (in_array('exec', $disabled, true)) {
            throw new RuntimeException('La función exec() está deshabilitada en php.ini.');
        }
    }
}
''')

write(SRC/'Http/Controller/TextEditorController.php', r'''<?php
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
''')

# Add raw string accessor to Request.
request=SRC/'Http/Request.php'; text=request.read_text(encoding='utf-8')
needle='''    public function postInt(string $name, int $default = 0): int\n    {\n'''
insert='''    public function postRawString(string $name, string $default = ''): string\n    {\n        $value = $this->post[$name] ?? $default;\n        return is_scalar($value) ? (string)$value : $default;\n    }\n\n'''
if 'postRawString(' not in text:
    text=text.replace(needle, insert+needle, 1)
request.write_text(text, encoding='utf-8')

for filename, method in {'leer_texto.php':'read','guardar_texto.php':'save','validar_php.php':'lintPhp'}.items():
    write(DRIVE/filename, f'''<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/app_bootstrap.php';\n(new \\ArcadeCloud\\Drive\\Http\\Controller\\TextEditorController(\n    \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app(),\n    \\ArcadeCloud\\Drive\\Http\\Request::fromGlobals()\n))->{method}();\n''')
print('Text editor OOP migration applied')
