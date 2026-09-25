<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationReportPageRenderer
{
    public function render(string $type, string $id): void
    {
        $type = in_array($type, ['drop','resource'], true) ? $type : '';
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
        $driveRoot = dirname(__DIR__, 2);
        $cssVersion = is_file($driveRoot . '/css/federation-moderation.css') ? (int)filemtime($driveRoot . '/css/federation-moderation.css') : 1;
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Reportar abuso · ArcadeCloud Drive</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="../ellogo.png" type="image/png">
<link rel="stylesheet" href="../css/styles.css">
<link rel="stylesheet" href="../css/responsive.css">
<link rel="stylesheet" href="../css/vision-accessibility.css">
<link rel="stylesheet" href="../css/federation-moderation.css?v=<?= $cssVersion ?>">
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">
<script>
(function(){try{const s=JSON.parse(localStorage.getItem('ui-theme-state')||'{}'),b=document.body;['theme-neon-green','theme-neon-blue','theme-neon-red','theme-neon-yellow','theme-dark','theme-light','vision-normal','vision-myopia','vision-presbyopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'].forEach(c=>b.classList.remove(c));b.classList.add(s.theme||'theme-neon-green',s.mode||'theme-dark',s.vision||'vision-normal');if(s.ascii===false)b.classList.remove('ascii-on')}catch(e){}})();
</script>
<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">
  <a class="navbar-brand d-flex align-items-center" href="../s3.php"><img src="../ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo"> Cloud Drive</a>
  <div class="ml-auto d-flex flex-wrap">
    <a class="btn btn-outline-info btn-sm mr-2 mb-1" href="index.php"><i class="fas fa-file-code mr-1"></i> Volver a ArcadeLink</a>
    <a class="btn btn-outline-light btn-sm mb-1" href="../s3.php"><i class="fas fa-arrow-left mr-1"></i> Volver al Drive</a>
  </div>
</nav>
<main class="moderation-shell">
  <div class="moderation-header"><div class="moderation-eyebrow">FederationCloud · Moderación</div><h1><i class="fas fa-flag mr-2"></i>Reportar abuso o contenido dañino</h1><p class="mb-0 text-muted">El reporte queda pendiente de revisión humana. No elimina contenido por sí solo.</p></div>
  <div class="alert alert-info">Si el superusuario confirma el reporte, ArcadeCloud bloquea la huella SHA-256 exacta, elimina las copias administradas conocidas y después retira el reporte de la cola de pendientes.</div>
  <section class="moderation-card">
    <form id="reportForm" novalidate>
      <div class="form-row">
        <div class="form-group col-md-5"><label for="targetType">Tipo</label><select id="targetType" class="form-control" name="target_type" required><option value="drop" <?= $type==='drop'?'selected':'' ?>>FederationDrop</option><option value="resource" <?= $type==='resource'?'selected':'' ?>>Recurso federado</option></select></div>
        <div class="form-group col-md-7"><label for="targetId">Identificador</label><input id="targetId" class="form-control" name="target_id" required maxlength="128" value="<?= htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
      </div>
      <div class="form-group"><label for="category">Motivo</label><select id="category" class="form-control" name="category" required><option value="spam">Spam</option><option value="malware">Malware / archivo dañino</option><option value="illegal">Material presuntamente ilegal</option><option value="abuse">Abuso</option><option value="copyright">Derechos de autor</option><option value="other">Otro</option></select></div>
      <div class="form-group"><label for="details">Describe el problema</label><textarea id="details" class="form-control" name="details" required minlength="5" maxlength="2000" rows="6"></textarea><small class="form-text text-muted">Entre 5 y 2000 caracteres.</small></div>
      <div class="form-group"><label for="reporterEmail">Correo de contacto (opcional)</label><input id="reporterEmail" class="form-control" name="reporter_email" type="email" maxlength="320" autocomplete="email"></div>
      <div class="moderation-honeypot" aria-hidden="true"><input name="website" autocomplete="off" tabindex="-1"></div>
      <button id="submitReport" class="btn btn-danger" type="submit"><i class="fas fa-paper-plane mr-1"></i> Enviar reporte</button>
    </form>
    <div id="msg" class="alert mt-3 d-none" role="status"></div>
  </section>
</main>
<script>
const form=document.getElementById('reportForm'),msg=document.getElementById('msg'),submit=document.getElementById('submitReport');
function show(message,type){msg.textContent=message;msg.className='alert mt-3 alert-'+type;msg.classList.remove('d-none')}
form.addEventListener('submit',async e=>{e.preventDefault();if(!form.reportValidity())return;submit.disabled=true;show('Enviando reporte…','info');try{const r=await fetch('report-api.php',{method:'POST',body:new FormData(form),credentials:'same-origin',cache:'no-store'});const raw=await r.text();let j=null;try{j=JSON.parse(raw)}catch(_){}if(!r.ok||!j||!j.ok)throw new Error((j&&j.error)||('No se pudo registrar el reporte (HTTP '+r.status+').'));show('Reporte recibido: '+j.report_id+'. Quedó pendiente de revisión.','success');document.getElementById('details').value='';document.getElementById('reporterEmail').value=''}catch(err){show(err&&err.message?err.message:'Error al enviar el reporte.','danger')}finally{submit.disabled=false}});
</script>
</body>
</html>
<?php
    }
}
