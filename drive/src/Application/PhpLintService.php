<?php
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
