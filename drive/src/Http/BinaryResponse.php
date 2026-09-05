<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http;

final class BinaryResponse
{
    public function text(int $status, string $message): never
    {
        http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $message; exit;
    }

    public function redirect(string $url): never
    {
        header('Location: '.$url); exit;
    }

    public function streamBody(mixed $body): void
    {
        if (is_object($body) && method_exists($body, 'rewind')) { try {$body->rewind();} catch (\Throwable) {} }
        if (is_object($body) && method_exists($body, 'read')) {
            while (!$body->eof()) { echo $body->read(8192); if (function_exists('ob_flush')) @ob_flush(); flush(); }
            return;
        }
        echo (string)$body;
    }

    public function attachment(array $file): never
    {
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);
        $name=(string)$file['name']; $encoded=rawurlencode($name);
        header('Content-Type: '.(string)$file['mime']);
        header('Content-Disposition: attachment; filename="'.$name.'"; filename*=UTF-8\'\''.$encoded);
        header('X-Filename: '.$encoded);
        header('Cache-Control: private, max-age=0, must-revalidate'); header('Pragma: public');
        if ($file['length'] !== null) header('Content-Length: '.(int)$file['length']);
        $this->streamBody($file['body']); exit;
    }

    public function inlineDocument(array $file, string $mime = 'application/pdf', int $maxAge = 86400): never
    {
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);

        $name = str_replace(["\r", "\n", '"'], ['', '', "'"], (string)($file['name'] ?? 'archivo.pdf'));
        if ($name === '') $name = 'archivo.pdf';

        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="'.$name.'"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age='.max(0, $maxAge));
        if (($file['length'] ?? null) !== null) header('Content-Length: '.(int)$file['length']);

        $this->streamBody($file['body']);
        exit;
    }

    public function inlineRange(array $file): never
    {
        /** @var ByteRange $range */ $range=$file['range'];
        http_response_code($range->status);
        header('Content-Type: '.$file['mime']); header('Accept-Ranges: bytes');
        header('Content-Length: '.$range->length);
        header('Content-Range: bytes '.$range->start.'-'.$range->end.'/'.$file['size']);
        header('Cache-Control: private, no-store, no-cache, must-revalidate'); header('Pragma: no-cache'); header('Expires: 0');
        if ($file['etag'] !== '') header('ETag: '.$file['etag']);
        if ($file['last_modified'] instanceof \DateTimeInterface) header('Last-Modified: '.gmdate('D, d M Y H:i:s', $file['last_modified']->getTimestamp()).' GMT');
        $name=str_replace(["\r","\n",'"'], ['', '', "'"], (string)($file['row']['Nombre'] ?? ''));
        if ($name !== '') header('Content-Disposition: inline; filename="'.$name.'"');
        $this->streamBody($file['body']); exit;
    }

    public function zip(\ArcadeCloud\Drive\Application\TemporaryZip $zip): never
    {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.rawurlencode($zip->downloadName).'"');
        header('X-Filename: '.rawurlencode($zip->downloadName));
        header('Content-Length: '.filesize($zip->path));
        $fp=fopen($zip->path, 'rb');
        if ($fp) { while (!feof($fp)) echo fread($fp, 8192); fclose($fp); }
        $zip->cleanup(); exit;
    }
}
