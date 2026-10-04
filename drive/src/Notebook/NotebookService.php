<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Notebook;

use ArcadeCloud\Drive\Aws\FileMetadataRepository;
use ArcadeCloud\Drive\Aws\FileRecordLocator;
use ArcadeCloud\Drive\Aws\GeneratedFileRepository;
use ArcadeCloud\Drive\Aws\TextractFileService;
use ArcadeCloud\Drive\Core\DriveApplication;
use RuntimeException;

final class NotebookService
{
    public function __construct(private DriveApplication $app) {}

    public function listNotebooks(int $userId): array
    {
        $root = $this->app->userStorageProvisioner()->ensureRoot($userId);
        $libretas = $this->ensureVisibleFolder($userId, $root, 'Libretas');
        $out = [];
        foreach ($this->app->folderRepository()->listHierarchyRows($userId) as $row) {
            if ((string)($row['ParentPrefix'] ?? '') !== $libretas) continue;
            $route = (string)$row['Prefix'];
            $out[] = ['name' => (string)$row['Nombre'], 'route' => $route] + $this->manifestForRoute($userId, $route);
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return ['root' => $libretas, 'notebooks' => $out];
    }

    public function createNotebook(int $userId, array $input): array
    {
        $name = $this->cleanNotebookName((string)($input['name'] ?? ''));
        $pages = max(1, min(2000, (int)($input['pages'] ?? 100)));
        $paper = $this->enum((string)($input['paper'] ?? 'letter'), ['letter','legal','tabloid','half-letter'], 'letter');
        $orientation = $this->enum((string)($input['orientation'] ?? 'portrait'), ['portrait','landscape'], 'portrait');
        $background = $this->enum((string)($input['background'] ?? 'blank'), ['blank','ruled','grid','millimeter','dots','notes'], 'blank');

        $list = $this->listNotebooks($userId);
        foreach ($list['notebooks'] as $notebook) {
            if (strcasecmp((string)$notebook['name'], $name) === 0) {
                throw new RuntimeException('Ya existe una libreta con ese nombre.');
            }
        }
        $created = $this->app->folderMutationService()->create($userId, (string)$list['root'], $name);
        $route = (string)$created['ruta'];
        $manifest = [
            'name' => $name,
            'pages' => $pages,
            'paper' => $paper,
            'orientation' => $orientation,
            'background' => $background,
            'created_at' => date(DATE_ATOM),
        ];
        $this->saveGenerated($userId, $route, 'notebook.json', json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}', 'application/json', ['notebook_manifest' => true]);
        return ['ok' => true, 'notebook' => ['name' => $name, 'route' => $route] + $manifest];
    }

    public function savePage(int $userId, array $input, string $userName): array
    {
        $notebook = $this->requireNotebook($userId, (string)($input['notebook'] ?? ''));
        $page = max(1, min(2000, (int)($input['page'] ?? 1)));
        $png = (string)($input['png'] ?? '');
        $json = (string)($input['json'] ?? '');
        if (!str_starts_with($png, 'data:image/png;base64,')) throw new RuntimeException('Imagen PNG inválida.');
        $bytes = base64_decode(substr($png, 22), true);
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('No se pudo decodificar la hoja.');
        if (strlen($bytes) > 16 * 1024 * 1024) throw new RuntimeException('La hoja excede 16 MB.');
        json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Estado editable JSON inválido.');

        $stamp = date('Ymd-His');
        $node = $this->slug((string)(getenv('ARCADECLOUD_NODE_NAME') ?: gethostname() ?: 'nodo'));
        $user = $this->slug($userName !== '' ? $userName : ('user-' . $userId));
        $base = 'pag' . $page . '-' . $stamp . '-' . $node . '-' . $user;

        $pngSaved = $this->saveGenerated($userId, $notebook['route'], $base . '.png', $bytes, 'image/png', [
            'notebook' => $notebook['name'], 'page' => $page, 'editable_json' => $base . '.json'
        ]);
        $jsonSaved = $this->saveGenerated($userId, $notebook['route'], $base . '.json', $json, 'application/json', [
            'notebook' => $notebook['name'], 'page' => $page, 'png' => $base . '.png'
        ]);

        $text = '';
        $ocrError = null;
        try {
            $textract = new TextractFileService(
                new FileRecordLocator($this->app->db()),
                new FileMetadataRepository($this->app->db()),
                \Config::getTextract(),
                $this->app->bucket()
            );
            $text = $textract->extractText($userId, (string)$pngSaved['key']);
        } catch (\Throwable $e) {
            $ocrError = $e->getMessage();
        }

        $txtSaved = $this->saveGenerated($userId, $notebook['route'], $base . '.txt', $text, 'text/plain; charset=UTF-8', [
            'notebook' => $notebook['name'], 'page' => $page, 'source_png' => $base . '.png',
            'transcription' => 'Textract', 'transcription_ok' => $ocrError === null
        ]);

        return [
            'ok' => true, 'base' => $base, 'page' => $page,
            'png' => $pngSaved['name'], 'json' => $jsonSaved['name'], 'txt' => $txtSaved['name'],
            'transcription' => $text, 'transcription_error' => $ocrError
        ];
    }

    public function loadPage(int $userId, string $notebookName, int $page): array
    {
        $notebook = $this->requireNotebook($userId, $notebookName);
        $stmt = $this->app->db()->prepare(
            "SELECT Nombre, Encriptado, Fecha FROM FileS3
             WHERE user_id_=? AND Ruta=? AND Found=1 AND Nombre LIKE ?
             ORDER BY Fecha DESC, id_ DESC LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo consultar la página.');
        $like = 'pag' . max(1, $page) . '-%.json';
        $stmt->bind_param('iss', $userId, $notebook['route'], $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return ['ok' => true, 'empty' => true, 'page' => $page];

        $obj = $this->app->s3()->getObject(['Bucket' => $this->app->bucket(), 'Key' => (string)$row['Encriptado']]);
        $body = (string)($obj['Body'] ?? '');
        return ['ok' => true, 'empty' => false, 'page' => $page, 'json' => $body, 'name' => (string)$row['Nombre']];
    }

    public function movePage(int $userId, string $sourceName, int $page, string $targetName): array
    {
        $source = $this->requireNotebook($userId, $sourceName);
        $target = $this->requireNotebook($userId, $targetName);
        if ($source['route'] === $target['route']) throw new RuntimeException('La libreta destino es la misma.');

        $stmt = $this->app->db()->prepare(
            "SELECT id_, Nombre, Encriptado, Tamano, Metadatos FROM FileS3
             WHERE user_id_=? AND Ruta=? AND Found=1 AND Nombre LIKE ?
             ORDER BY Fecha DESC, id_ DESC"
        );
        if (!$stmt) throw new RuntimeException('No se pudo localizar la página.');
        $like = 'pag' . max(1, $page) . '-%';
        $stmt->bind_param('iss', $userId, $source['route'], $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$rows) throw new RuntimeException('No existe una versión guardada de esa página.');

        $latestBase = null;
        $selected = [];
        foreach ($rows as $row) {
            $base = pathinfo((string)$row['Nombre'], PATHINFO_FILENAME);
            if ($latestBase === null) $latestBase = $base;
            if ($base !== $latestBase) continue;
            $selected[] = $row;
        }

        $generated = new GeneratedFileRepository($this->app->db());
        $prepared = [];
        try {
            foreach ($selected as $row) {
                $body = (string)$this->app->s3()->getObject([
                    'Bucket'=>$this->app->bucket(),
                    'Key'=>(string)$row['Encriptado']
                ])['Body'];
                $ext = strtolower(pathinfo((string)$row['Nombre'], PATHINFO_EXTENSION));
                $type = match($ext){'png'=>'image/png','json'=>'application/json',default=>'text/plain; charset=UTF-8'};
                $newKey = rtrim($target['route'],'/') . '/f_' . bin2hex(random_bytes(16)) . '.' . $ext;
                $this->app->s3()->putObject([
                    'Bucket'=>$this->app->bucket(),'Key'=>$newKey,'Body'=>$body,'ACL'=>'private','ContentType'=>$type
                ]);
                $generated->upsert(
                    $userId,
                    (string)$row['Nombre'],
                    $newKey,
                    strlen($body),
                    ['notebook'=>$target['name'],'moved_from'=>$source['name'],'page'=>$page],
                    $target['route']
                );
                $prepared[] = ['source'=>$row, 'new_key'=>$newKey];
            }
        } catch (\Throwable $error) {
            foreach ($prepared as $copy) {
                try {
                    $this->app->s3()->deleteObject(['Bucket'=>$this->app->bucket(),'Key'=>(string)$copy['new_key']]);
                    $cleanup = $this->app->db()->prepare('UPDATE FileS3 SET Found=0 WHERE user_id_=? AND Encriptado=?');
                    $cleanupKey = (string)$copy['new_key'];
                    $cleanup->bind_param('is',$userId,$cleanupKey); $cleanup->execute(); $cleanup->close();
                } catch (\Throwable) {
                }
            }
            throw $error;
        }

        $moved = [];
        foreach ($prepared as $copy) {
            $row = $copy['source'];
            $this->app->s3()->deleteObject(['Bucket'=>$this->app->bucket(),'Key'=>(string)$row['Encriptado']]);
            $id = (int)$row['id_'];
            $up = $this->app->db()->prepare('UPDATE FileS3 SET Found=0 WHERE id_=? AND user_id_=?');
            if (!$up) throw new RuntimeException('No se pudo finalizar el movimiento de la hoja.');
            $up->bind_param('ii',$id,$userId); $up->execute(); $up->close();
            $moved[] = (string)$row['Nombre'];
        }
        return ['ok'=>true,'moved'=>$moved,'target'=>$target['name']];
    }

    private function manifestForRoute(int $userId, string $route): array
    {
        $defaults = ['pages'=>100,'paper'=>'letter','orientation'=>'portrait','background'=>'blank'];
        $stmt = $this->app->db()->prepare(
            "SELECT Encriptado FROM FileS3
             WHERE user_id_=? AND Ruta=? AND Found=1 AND Nombre='notebook.json'
             ORDER BY id_ DESC LIMIT 1"
        );
        if (!$stmt) return $defaults;
        $stmt->bind_param('is',$userId,$route);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return $defaults;
        try {
            $object = $this->app->s3()->getObject(['Bucket'=>$this->app->bucket(),'Key'=>(string)$row['Encriptado']]);
            $decoded = json_decode((string)($object['Body'] ?? ''), true);
            if (!is_array($decoded)) return $defaults;
            return [
                'pages'=>max(1,min(2000,(int)($decoded['pages'] ?? 100))),
                'paper'=>$this->enum((string)($decoded['paper'] ?? 'letter'),['letter','legal','tabloid','half-letter'],'letter'),
                'orientation'=>$this->enum((string)($decoded['orientation'] ?? 'portrait'),['portrait','landscape'],'portrait'),
                'background'=>$this->enum((string)($decoded['background'] ?? 'blank'),['blank','ruled','grid','millimeter','dots','notes'],'blank'),
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function requireNotebook(int $userId, string $name): array
    {
        $name = $this->cleanNotebookName($name);
        $list = $this->listNotebooks($userId);
        foreach ($list['notebooks'] as $notebook) {
            if (strcasecmp((string)$notebook['name'], $name) === 0) return $notebook;
        }
        throw new RuntimeException('Libreta no encontrada.');
    }

    private function ensureVisibleFolder(int $userId, string $parent, string $name): string
    {
        foreach ($this->app->folderRepository()->listHierarchyRows($userId) as $row) {
            if ((string)($row['ParentPrefix'] ?? '') === $parent && strcasecmp((string)$row['Nombre'], $name) === 0) {
                return (string)$row['Prefix'];
            }
        }
        $created = $this->app->folderMutationService()->create($userId, $parent, $name);
        return (string)$created['ruta'];
    }

    private function saveGenerated(int $userId, string $route, string $name, string $body, string $contentType, array $metadata): array
    {
        $key = rtrim($route, '/') . '/f_' . bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->app->s3()->putObject(['Bucket'=>$this->app->bucket(),'Key'=>$key,'Body'=>$body,'ACL'=>'private','ContentType'=>$contentType]);
        (new GeneratedFileRepository($this->app->db()))->upsert($userId,$name,$key,strlen($body),$metadata,$route);
        return ['name'=>$name,'key'=>$key,'route'=>$route];
    }

    private function cleanNotebookName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nombre de libreta inválido.');
        if (preg_match('/[\\\/:*?"<>|\x00-\x1F]/u', $name)) throw new RuntimeException('El nombre contiene caracteres no permitidos.');
        return $name;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/u','-',$value) ?? 'valor';
        return trim($value,'-') ?: 'valor';
    }

    private function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
