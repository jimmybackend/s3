<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationReportPageRenderer
{
    public function render(string $type, string $id): void
    {
        $type = in_array($type, ['drop','resource'], true) ? $type : '';
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reportar abuso · FederationCloud</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f5f7fb;color:#111827}
main{max-width:720px;margin:40px auto;padding:24px;background:#fff;border-radius:16px;box-shadow:0 10px 30px #00000012}
label{display:block;font-weight:700;margin-top:16px} input,select,textarea,button{width:100%;box-sizing:border-box;padding:12px;margin-top:6px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}
textarea{min-height:140px} button{background:#111827;color:#fff;cursor:pointer;font-weight:700}.muted{color:#64748b;font-size:.95rem}.hidden{position:absolute;left:-9999px}
#msg{margin-top:16px;padding:12px;border-radius:10px;background:#eef2ff;display:none}
</style>
</head>
<body>
<main>
<h1>Reportar abuso o contenido dañino</h1>
<p class="muted">El reporte no elimina automáticamente un archivo. El superusuario del nodo responsable debe revisarlo. Si se confirma, su huella SHA-256 se bloquea y el contenido se elimina de las copias administradas por FederationCloud.</p>
<form id="reportForm">
<label>Tipo
<select name="target_type" required>
<option value="drop" <?= $type==='drop'?'selected':'' ?>>FederationDrop</option>
<option value="resource" <?= $type==='resource'?'selected':'' ?>>Recurso federado</option>
</select></label>
<label>Identificador
<input name="target_id" required maxlength="128" value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Motivo
<select name="category" required>
<option value="spam">Spam</option>
<option value="malware">Malware / archivo dañino</option>
<option value="illegal">Material presuntamente ilegal</option>
<option value="abuse">Abuso</option>
<option value="copyright">Derechos de autor</option>
<option value="other">Otro</option>
</select></label>
<label>Describe el problema
<textarea name="details" required minlength="5" maxlength="2000"></textarea></label>
<label>Correo de contacto (opcional)
<input name="reporter_email" type="email" maxlength="320"></label>
<div class="hidden"><label>Website<input name="website" autocomplete="off" tabindex="-1"></label></div>
<button type="submit">Enviar reporte</button>
</form>
<div id="msg"></div>
</main>
<script>
const form=document.getElementById('reportForm'),msg=document.getElementById('msg');
form.addEventListener('submit',async e=>{
 e.preventDefault(); msg.style.display='block'; msg.textContent='Enviando…';
 try{
  const r=await fetch('report-api.php',{method:'POST',body:new FormData(form),credentials:'same-origin'});
  const j=await r.json();
  if(!r.ok||!j.ok) throw new Error(j.error||'No se pudo enviar el reporte.');
  msg.textContent='Reporte recibido: '+j.report_id+'. Quedó pendiente de revisión.';
  form.reset();
 }catch(err){msg.textContent=err.message||'Error al enviar el reporte.'}
});
</script>
</body>
</html>
<?php
    }
}
