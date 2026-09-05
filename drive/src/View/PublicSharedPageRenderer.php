<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class PublicSharedPageRenderer
{
    public function render(array $state, string $error = ''): string
    {
        $route = (string)($state['route'] ?? '');
        $prefix = (string)($state['prefix'] ?? '');
        $parentRoute = (string)($state['parent_route'] ?? '');
        $folders = is_array($state['folders'] ?? null) ? $state['folders'] : [];
        $files = is_array($state['files'] ?? null) ? $state['files'] : [];

        $html = '<!DOCTYPE html>'
            . '<html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Subida y Navegación Pública</title>'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">'
            . '<link rel="icon" href="../assets/img/icono.png" type="image/x-icon">'
            . '<style>#zona-subida{position:sticky;top:0;z-index:1000;background:#fff;padding-top:20px}</style>'
            . '</head><body><div class="container py-4">';

        if ($prefix !== '') {
            $html .= '<div id="zona-subida">'
                . '<h5>⬆ Subir archivos</h5>'
                . '<form action="upload_publico.php?prefix=' . rawurlencode($prefix) . '" class="dropzone mb-4" id="dropzonePublico"></form>'
                . '</div>';
        }

        $html .= '<h4 class="mb-3">Carpeta actual: '
            . $this->e('Compartidos' . ($route !== '' ? '/' . $route : ''))
            . '</h4>';

        if ($error !== '') {
            $html .= '<div class="alert alert-danger">' . $this->e($error) . '</div>';
        }

        if ($route !== '') {
            $html .= '<a href="?ruta=' . rawurlencode($parentRoute) . '" class="btn btn-secondary mb-3">⬅ Volver</a>';
        }

        if ($prefix !== '') {
            $html .= '<div class="card mb-4">'
                . '<div class="card-header">Crear nueva carpeta</div>'
                . '<div class="card-body"><form method="post" class="form-inline">'
                . '<input type="hidden" name="ruta" value="' . $this->e($route) . '">'
                . '<input type="text" name="nueva" class="form-control mr-2" placeholder="Nombre carpeta" required>'
                . '<button class="btn btn-primary">Crear</button>'
                . '</form></div></div>';
        }

        $html .= '<div class="row"><div class="col-md-6"><h5>📁 Subcarpetas</h5><ul class="list-group mb-4">';

        foreach ($folders as $folder) {
            $folder = (string)$folder;
            $childRoute = trim($route . '/' . $folder, '/');
            $html .= '<li class="list-group-item"><a href="?ruta=' . rawurlencode($childRoute) . '">📁 '
                . $this->e($folder)
                . '</a></li>';
        }

        if ($folders === []) {
            $html .= '<li class="list-group-item text-muted">Sin subcarpetas</li>';
        }

        $html .= '</ul></div><div class="col-md-6"><h5>📄 Archivos</h5><ul class="list-group mb-4">';

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $visibleName = (string)($file['visible_name'] ?? $file['physical_name'] ?? 'archivo');
            $modified = (string)($file['modified'] ?? '');
            $sizeMb = (string)($file['size_mb'] ?? '0');

            $html .= '<li class="list-group-item d-flex justify-content-between align-items-center"><div>'
                . '<strong>' . $this->e($visibleName) . '</strong><br>'
                . '<small class="text-muted">' . $this->e($modified) . ' | ' . $this->e($sizeMb) . ' MB</small>'
                . '</div></li>';
        }

        if ($files === []) {
            $html .= '<li class="list-group-item text-muted">Sin archivos</li>';
        }

        $html .= '</ul></div></div></div>'
            . '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>'
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>'
            . '</body></html>';

        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
