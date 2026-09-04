<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Application;

use ArcadeCloud\Drive\Aws\FileRecordLocator;
use Aws\S3\S3Client;
use RuntimeException;
use ZipArchive;

final class ZipDownloadService
{
    public function __construct(
        private FileRecordLocator $locator,
        private S3Client $s3,
        private string $bucket
    ) {
    }

    public function create(int $userId, array $keys): TemporaryZip
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        if (!$keys) throw new RuntimeException('No hay archivos seleccionados.');
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('drive_zip_', true).'.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP.');
        }
        $temps=[]; $seen=[];
        try {
            foreach ($keys as $requestedKey) {
                $row = $this->locator->requireReadableByKey($userId, $requestedKey);
                $realKey = (string)$row['_key'];
                if ($realKey === '' || str_ends_with($realKey, '/')) continue;
                $entry = $this->uniqueName((string)($row['Nombre'] ?? basename($realKey)), $realKey, $seen);
                $ext = pathinfo($realKey, PATHINFO_EXTENSION);
                $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('drive_s3_', true).($ext ? '.'.$ext : '');
                $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$realKey,'SaveAs'=>$tmp]);
                $temps[]=$tmp;
                $zip->addFile($tmp, $entry);
            }
            $zip->close();
            return new TemporaryZip($zipPath, 'archivos_'.date('Ymd_His').'.zip', $temps);
        } catch (\Throwable $error) {
            $zip->close(); foreach ($temps as $tmp) @unlink($tmp); @unlink($zipPath); throw $error;
        }
    }

    private function uniqueName(string $visible, string $realKey, array &$seen): string
    {
        $ext = strtolower((string)pathinfo($realKey, PATHINFO_EXTENSION));
        $suffix = $ext !== '' ? '.'.$ext : '';
        $base = preg_replace('/\.[^.]+$/', '', $visible) ?: $visible;
        $base = trim((string)preg_replace('/[\/\\\\\x00-\x1F\x7F]/', '-', $base));
        if ($base === '') $base='archivo';
        $candidate=$base.$suffix; $n=2;
        while (isset($seen[strtolower($candidate)])) $candidate=$base.' ('.$n++.')'.$suffix;
        $seen[strtolower($candidate)]=true;
        return $candidate;
    }
}
