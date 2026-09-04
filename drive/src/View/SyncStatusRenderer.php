<?php
declare(strict_types=1);
namespace ArcadeCloud\Drive\View;
final class SyncStatusRenderer
{
    public function render(array $status,bool $loading): string
    {
        $filesFound=number_format((int)$status['files_found']);$filesTotal=number_format((int)$status['files_total']);$foldersFound=number_format((int)$status['folders_found']);$foldersTotal=number_format((int)$status['folders_total']);$bytes=number_format((int)$status['bytes_total']);
        $head=$loading?'<span class="sync-spinner" aria-hidden="true"></span><strong>Sincronizando…</strong>':'<i class="fas fa-check-circle" aria-hidden="true"></i><strong>Estatus actualizado</strong>';
        $body=$loading?'Comparando S3 y la base de datos. Por favor espera…':"Archivos: <b>{$filesFound}</b> / {$filesTotal} encontrados · Carpetas: <b>{$foldersFound}</b> / {$foldersTotal} · Tamaño total: <b>{$bytes}</b> bytes";
        $class=$loading?'sync-loading':'sync-success';return '<div class="sync-box '.$class.' sync-green" role="status" style="margin:0"><div class="sync-line">'.$head.'</div><div class="sync-text">'.$body.'</div></div>';
    }
}
