<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FileIconResolver
{
    /**
     * Devuelve un icono Font Awesome y una categoría visual estable.
     * Los formatos desconocidos siempre caen en un archivo genérico.
     *
     * @return array{icon:string, category:string, label:string}
     */
    public static function resolve(string $extension): array
    {
        $ext = strtolower(ltrim(trim($extension), '.'));

        $groups = [
            'pdf' => [
                'extensions' => ['pdf'],
                'icon' => 'fa-file-pdf',
                'label' => 'PDF',
            ],
            'word' => [
                'extensions' => ['doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'rtf'],
                'icon' => 'fa-file-word',
                'label' => 'Documento',
            ],
            'excel' => [
                'extensions' => ['xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'ods', 'csv'],
                'icon' => 'fa-file-excel',
                'label' => 'Hoja de cálculo',
            ],
            'powerpoint' => [
                'extensions' => ['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'odp'],
                'icon' => 'fa-file-powerpoint',
                'label' => 'Presentación',
            ],
            'archive' => [
                'extensions' => ['zip', 'rar', '7z', 'tar', 'gz', 'gzip', 'tgz', 'bz', 'bz2', 'xz', 'zst', 'cab', 'jar', 'war'],
                'icon' => 'fa-file-archive',
                'label' => 'Archivo comprimido',
            ],
            'text' => [
                'extensions' => ['txt', 'jas', 'log', 'md', 'markdown', 'ini', 'cfg', 'conf', 'properties', 'nfo', 'srt', 'vtt'],
                'icon' => 'fa-file-lines',
                'label' => 'Texto',
            ],
            'code' => [
                'extensions' => [
                    'php', 'phtml', 'phar', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
                    'html', 'htm', 'xhtml', 'css', 'scss', 'sass', 'less',
                    'json', 'jsonl', 'xml', 'xsl', 'yaml', 'yml', 'toml', 'sql',
                    'py', 'pyw', 'java', 'class', 'c', 'cc', 'cpp', 'cxx', 'h', 'hpp',
                    'cs', 'go', 'rs', 'rb', 'swift', 'kt', 'kts', 'dart', 'lua',
                    'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd', 'vue', 'svelte',
                    'graphql', 'gql', 'env'
                ],
                'icon' => 'fa-file-code',
                'label' => 'Código',
            ],
            'image' => [
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif', 'tif', 'tiff', 'svg'],
                'icon' => 'fa-file-image',
                'label' => 'Imagen',
            ],
            'audio' => [
                'extensions' => ['mp3', 'wav', 'ogg', 'opus', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'aif', 'mid', 'midi'],
                'icon' => 'fa-file-audio',
                'label' => 'Audio',
            ],
            'video' => [
                'extensions' => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'mpeg', 'mpg', 'wmv', 'flv', '3gp', 'ts', 'mts', 'm2ts'],
                'icon' => 'fa-file-video',
                'label' => 'Video',
            ],
            'database' => [
                'extensions' => ['db', 'db3', 'sqlite', 'sqlite3', 'mdb', 'accdb', 'bak', 'dump'],
                'icon' => 'fa-database',
                'label' => 'Base de datos',
            ],
            'ebook' => [
                'extensions' => ['epub', 'mobi', 'azw', 'azw3', 'fb2'],
                'icon' => 'fa-book',
                'label' => 'Libro electrónico',
            ],
            'mail' => [
                'extensions' => ['eml', 'msg', 'mbox', 'pst', 'ost'],
                'icon' => 'fa-envelope',
                'label' => 'Correo',
            ],
            'font' => [
                'extensions' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
                'icon' => 'fa-font',
                'label' => 'Fuente',
            ],
            'certificate' => [
                'extensions' => ['pem', 'crt', 'cer', 'key', 'pfx', 'p12', 'csr', 'der'],
                'icon' => 'fa-key',
                'label' => 'Certificado / clave',
            ],
            'package' => [
                'extensions' => ['apk', 'aab', 'ipa', 'exe', 'msi', 'dmg', 'pkg', 'deb', 'rpm', 'appimage', 'bin', 'iso'],
                'icon' => 'fa-cube',
                'label' => 'Paquete / ejecutable',
            ],
            'design' => [
                'extensions' => ['psd', 'psb', 'ai', 'eps', 'indd', 'sketch', 'fig'],
                'icon' => 'fa-pen-ruler',
                'label' => 'Diseño',
            ],
            'model' => [
                'extensions' => ['dwg', 'dxf', 'stl', 'obj', 'fbx', 'blend', 'gltf', 'glb', 'step', 'stp', 'iges', 'igs'],
                'icon' => 'fa-cubes',
                'label' => 'CAD / 3D',
            ],
        ];

        foreach ($groups as $category => $group) {
            if (in_array($ext, $group['extensions'], true)) {
                return [
                    'icon' => $group['icon'],
                    'category' => $category,
                    'label' => $group['label'],
                ];
            }
        }

        return [
            'icon' => 'fa-file',
            'category' => 'generic',
            'label' => $ext !== '' ? strtoupper($ext) : 'Archivo',
        ];
    }
}
