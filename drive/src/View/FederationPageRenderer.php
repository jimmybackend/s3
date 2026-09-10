<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationPageRenderer
{
    public function render(
        int $userId,
        ?array $inspected = null,
        ?string $notice = null,
        ?string $error = null,
        ?array $node = null
    ): void {
        $h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $resource = is_array($inspected['resource'] ?? null) ? $inspected['resource'] : null;
        $raw = is_string($inspected['raw'] ?? null) ? $inspected['raw'] : '';
        $statusLabels = [
            'available' => 'Disponible',
            'not_available' => 'No disponible en el nodo original',
            'private_auth_required' => 'Privado: requiere autenticación del propietario',
            'secure_resource_requires_drive' => 'Protegido: debe abrirse desde el Drive del propietario',
            'content_changed' => 'El contenido local cambió',
            'origin_identity_mismatch' => 'Identidad del nodo origen no coincide',
            'origin_unavailable' => 'Nodo origen disponible, recurso no confirmado',
            'origin_unreachable' => 'Nodo origen no disponible',
        ];
        ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FederationCloud · ArcadeCloud Drive</title>
<style>
:root{color-scheme:light dark;--bg:#0b1020;--panel:#151c31;--soft:#202a45;--text:#f5f7ff;--muted:#b8c0d9;--line:#34405f;--accent:#72a7ff;--ok:#8de6b3;--bad:#ff9c9c}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1000px;margin:auto;padding:28px 18px 64px}h1,h2{line-height:1.15}h1{font-size:clamp(2rem,5vw,3.5rem);margin:.3rem 0}.lead{color:var(--muted);max-width:760px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:18px;margin-top:26px}.card{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px}.wide{grid-column:1/-1}.drop{display:block;border:2px dashed var(--line);border-radius:14px;padding:28px;text-align:center;cursor:pointer}.drop.drag{border-color:var(--accent);background:var(--soft)}input,select,button{font:inherit}input[type=number],select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--text);margin:5px 0 14px}button,.button{display:inline-block;border:0;border-radius:10px;padding:10px 15px;background:var(--accent);color:#07101f;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:var(--soft);color:var(--text);border:1px solid var(--line)}.notice,.error{padding:12px 14px;border-radius:12px;margin:18px 0}.notice{border:1px solid var(--ok)}.error{border:1px solid var(--bad)}dl{display:grid;grid-template-columns:minmax(120px,180px) 1fr;gap:8px 14px}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.92em}.small{font-size:.9rem;color:var(--muted)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}@media(max-width:560px){dl{grid-template-columns:1fr}dt{margin-top:8px}}
</style>
</head>
<body><main class="wrap">
<p class="small">ArcadeCloud Drive · FederationCloud v1</p>
<h1>Abre un <span class="mono">.arcadelink</span></h1>
<p class="lead">Un ArcadeLink es un pasaporte portable del recurso: conserva identidad lógica, procedencia y firma sin publicar claves AWS, cookies, sesiones ni rutas físicas privadas de S3.</p>
<?php if ($notice): ?><div class="notice" role="status"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error" role="alert"><?= $h($error) ?></div><?php endif; ?>
<div class="grid">
<section class="card">
<h2>Resolver ArcadeLink</h2>
<form method="post" enctype="multipart/form-data" id="inspectForm">
<input type="hidden" name="action" value="inspect">
<label class="drop" id="dropZone">Arrastra aquí un archivo <strong>.arcadelink</strong><br><span class="small">o selecciónalo desde tu dispositivo</span><br><br><input id="arcadeFile" type="file" name="arcadelink_file" accept=".arcadelink,application/json,text/plain" required></label>
<div class="actions"><button type="submit">Validar y resolver</button></div>
</form>
</section>
<section class="card">
<h2>Estado del nodo</h2>
<?php if ($node): ?>
<dl><dt>Node ID</dt><dd class="mono"><?= $h($node['node_id'] ?? '') ?></dd><dt>Federation URL</dt><dd><?= $h($node['federation_url'] ?? '') ?></dd><dt>Firma</dt><dd>Ed25519</dd><dt>Payload</dt><dd>XChaCha20-Poly1305</dd></dl>
<?php else: ?><p class="small">La identidad pública aparecerá cuando FederationCloud esté configurado, activado y la clave del nodo sea legible.</p><?php endif; ?>
</section>
<?php if ($userId > 0): ?>
<section class="card wide">
<h2>Crear un ArcadeLink</h2>
<p class="small">La primera fase usa el ID real de <span class="mono">FileS3</span>. El archivo debe pertenecer al usuario autenticado y existir con <span class="mono">Found=1</span>.</p>
<form method="post">
<input type="hidden" name="action" value="create">
<label>ID del archivo<input type="number" min="1" name="file_id" required></label>
<label>Visibilidad<select name="visibility"><option value="PRIVATE">PRIVATE — no publica SHA-256</option><option value="UNLISTED">UNLISTED — resuelve por ArcadeLink, no por búsqueda</option><option value="PUBLIC">PUBLIC — preparado para anunciarse</option></select></label>
<label>Derechos<select name="rights"><option value="unknown_rights">unknown_rights — sólo enlace por defecto</option><option value="link_only">link_only</option><option value="user_owned_authorized">user_owned_authorized</option><option value="copy_allowed">copy_allowed</option></select></label>
<button type="submit">Generar .arcadelink</button>
</form>
</section>
<?php endif; ?>
<?php if ($resource): ?>
<section class="card wide" id="resultado">
<h2>Recurso verificado</h2>
<dl>
<dt>Título</dt><dd><?= $h($resource['title'] ?? '') ?></dd>
<dt>Resource ID</dt><dd class="mono"><?= $h($resource['resource_id'] ?? '') ?></dd>
<dt>Tipo</dt><dd><?= $h($resource['media_type'] ?? $resource['resource_type'] ?? '') ?></dd>
<dt>Tamaño</dt><dd><?= $h(FileViewHelper::formatBytes((int)($resource['size_bytes'] ?? 0))) ?></dd>
<dt>Nodo origen</dt><dd class="mono"><?= $h($resource['origin_node_id'] ?? '') ?></dd>
<dt>Estado</dt><dd><?= $h($statusLabels[(string)($resource['status'] ?? '')] ?? (string)($resource['status'] ?? '')) ?></dd>
<dt>Visibilidad</dt><dd><?= $h($resource['visibility'] ?? '') ?></dd>
<dt>Derechos</dt><dd><?= $h($resource['rights'] ?? '') ?></dd>
<dt>Content ID</dt><dd class="mono"><?= $h($resource['content_id'] ?? 'No publicado') ?></dd>
<dt>Emitido</dt><dd><?= $h($resource['issued_at'] ?? '') ?></dd>
<dt>Firma</dt><dd><?= !empty($resource['signature_valid']) ? 'Ed25519 válida' : 'No verificada' ?></dd>
</dl>
<div class="actions">
<?php if (!empty($resource['can_open']) && !empty($resource['local']) && $raw !== ''): ?>
<form method="post"><input type="hidden" name="action" value="open"><input type="hidden" name="arcadelink_text" value="<?= $h($raw) ?>"><button type="submit">Abrir</button></form>
<?php endif; ?>
<?php if (empty($resource['local']) && !empty($resource['origin_reachable'])): ?><a class="button secondary" rel="noopener noreferrer" href="<?= $h($resource['federation_url'] ?? '#') ?>">Ir al nodo de origen</a><?php endif; ?>
</div>
<?php if (!empty($resource['mirror_lookup_ready'])): ?><p class="small">El Content ID está disponible para una futura búsqueda de mirrors idénticos. La búsqueda entre nodos todavía no está habilitada en esta fase.</p><?php endif; ?>
</section>
<?php endif; ?>
</div>
</main>
<script>
(()=>{const z=document.getElementById('dropZone'),f=document.getElementById('arcadeFile');if(!z||!f)return;['dragenter','dragover'].forEach(e=>z.addEventListener(e,x=>{x.preventDefault();z.classList.add('drag')}));['dragleave','drop'].forEach(e=>z.addEventListener(e,x=>{x.preventDefault();z.classList.remove('drag')}));z.addEventListener('drop',e=>{if(e.dataTransfer.files.length){f.files=e.dataTransfer.files}})})();
</script>
</body></html><?php
    }
}
