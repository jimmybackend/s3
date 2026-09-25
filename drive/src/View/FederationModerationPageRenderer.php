<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationModerationPageRenderer
{
    public function render(string $csrf): void
    {
        $driveRoot = dirname(__DIR__, 2);
        $cssVersion = is_file($driveRoot . '/css/federation-moderation.css') ? (int)filemtime($driveRoot . '/css/federation-moderation.css') : 1;
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Moderación · ArcadeCloud Drive</title>
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
<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar"><a class="navbar-brand d-flex align-items-center" href="../s3.php"><img src="../ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo"> Cloud Drive</a><div class="ml-auto"><a class="btn btn-outline-light btn-sm" href="../s3.php"><i class="fas fa-arrow-left mr-1"></i> Volver al Drive</a></div></nav>
<main class="moderation-shell">
  <div class="moderation-header"><div class="moderation-eyebrow">FederationCloud · Superusuario</div><h1><i class="fas fa-shield-halved mr-2"></i>Moderación</h1><p class="text-muted mb-0">Revisión humana, bloqueo por huella y auditoría federada.</p></div>
  <div id="status" class="alert d-none" role="status"></div>
  <section class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="moderation-section-title">Reportes pendientes</h2><span id="reportCount" class="badge badge-warning">—</span></div>
    <div class="alert alert-info small"><strong>Confirmar y bloquear</strong>: bloquea la huella, elimina primero las copias conocidas y después saca el reporte de pendientes. <strong>Rechazar / retirar</strong>: saca el reporte de pendientes sin eliminar ni bloquear el contenido.</div>
    <div id="reportList">Cargando…</div>
  </section>
  <section>
    <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="moderation-section-title">Bloqueos activos emitidos por este nodo</h2><span id="blockCount" class="badge badge-danger">—</span></div>
    <div class="alert alert-warning small"><strong>Revocar bloqueo</strong> permite volver a subir los mismos bytes. No restaura archivos ya eliminados; deben volver a subirse desde una copia legítima.</div>
    <div id="blockList">Cargando…</div>
  </section>
</main>
<script>
const csrf=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,reportList=document.getElementById('reportList'),blockList=document.getElementById('blockList'),statusBox=document.getElementById('status');
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function status(message,type='info'){statusBox.textContent=message;statusBox.className='alert alert-'+type;statusBox.classList.remove('d-none')}
async function request(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});const raw=await r.text();let j=null;try{j=JSON.parse(raw)}catch(_){}if(!r.ok||!j||!j.ok)throw new Error((j&&j.error)||('Solicitud fallida (HTTP '+r.status+').'));return j}
async function load(){try{const j=await request('moderation-api.php'),reports=Array.isArray(j.reports)?j.reports:[],blocks=Array.isArray(j.active_blocks)?j.active_blocks:[];document.getElementById('reportCount').textContent=String(reports.length);document.getElementById('blockCount').textContent=String(blocks.length);reportList.innerHTML=reports.length?reports.map(x=>`<article class="moderation-card" data-report-id="${esc(x.ReportId)}"><div class="d-flex flex-wrap justify-content-between"><strong>${esc(x.Category)} · ${esc(x.TargetType)} · ${esc(x.TargetId)}</strong><span class="badge badge-warning">pendiente</span></div><div class="moderation-meta mt-1">Reporte ${esc(x.ReportId)} · ${esc(x.CreatedAt)}</div><div class="moderation-meta moderation-hash">SHA-256: ${esc(x.ContentId||'pendiente/no disponible')}</div><div class="moderation-details">${esc(x.Details)}</div><textarea class="form-control decision-reason mb-2" rows="3" maxlength="1000" placeholder="Motivo de tu decisión"></textarea><div class="moderation-actions"><button type="button" class="btn btn-danger" onclick="decide(this,'confirm')"><i class="fas fa-ban mr-1"></i> Confirmar y bloquear</button><button type="button" class="btn btn-outline-secondary" onclick="decide(this,'reject')"><i class="fas fa-xmark mr-1"></i> Rechazar / retirar</button></div></article>`).join(''):'<div class="moderation-empty">No hay reportes pendientes.</div>';blockList.innerHTML=blocks.length?blocks.map(x=>`<article class="moderation-card" data-content-id="${esc(x.ContentId)}"><div class="d-flex flex-wrap justify-content-between"><strong>${esc(x.ReasonCode||'bloqueado')}</strong><span class="badge badge-danger">huella bloqueada</span></div><div class="moderation-meta moderation-hash mt-1">${esc(x.ContentId)}</div><div class="moderation-meta">Reporte: ${esc(x.ReportId||'—')} · ${esc(x.TargetType||'')} ${esc(x.TargetId||'')} · bloqueado ${esc(x.BlockedAt||'')}</div><textarea class="form-control unblock-reason my-2" rows="2" maxlength="1000" placeholder="Motivo para revocar este bloqueo"></textarea><button type="button" class="btn btn-outline-warning" onclick="unblock(this)"><i class="fas fa-unlock mr-1"></i> Revocar bloqueo</button></article>`).join(''):'<div class="moderation-empty">Este nodo no tiene bloqueos activos propios.</div>'}catch(e){status(e.message||'No se pudo cargar moderación.','danger')}}
async function decide(btn,decision){const card=btn.closest('[data-report-id]'),id=card.dataset.reportId,reason=card.querySelector('.decision-reason').value.trim();if(!reason){status('Escribe el motivo de la decisión.','warning');return}if(decision==='confirm'&&!confirm('Se bloqueará la huella y se eliminarán las copias administradas conocidas. ¿Continuar?'))return;const body=new FormData();body.set('report_id',id);body.set('decision',decision);body.set('reason',reason);btn.disabled=true;try{const j=await request('moderation-api.php',{method:'POST',body,headers:{'X-Federation-Moderation-CSRF':csrf}});if(decision==='confirm'){const deleted=j.cleanup&&Number.isFinite(Number(j.cleanup.deleted_objects))?Number(j.cleanup.deleted_objects):0;status('Reporte confirmado. Copias eliminadas: '+deleted+'. El reporte salió de pendientes.','success')}else status('Reporte rechazado/retirado de pendientes. El contenido no fue bloqueado.','success');await load()}catch(e){status(e.message||'No se pudo decidir.','danger')}finally{btn.disabled=false}}
async function unblock(btn){const card=btn.closest('[data-content-id]'),contentId=card.dataset.contentId,reason=card.querySelector('.unblock-reason').value.trim();if(!reason){status('Escribe por qué se revoca el bloqueo.','warning');return}if(!confirm('Esto permitirá volver a subir los mismos bytes. No restaurará archivos eliminados. ¿Continuar?'))return;const body=new FormData();body.set('action','unblock');body.set('content_id',contentId);body.set('reason',reason);btn.disabled=true;try{const j=await request('moderation-api.php',{method:'POST',body,headers:{'X-Federation-Moderation-CSRF':csrf}});status(j.message||'Huella desbloqueada.','success');await load()}catch(e){status(e.message||'No se pudo revocar el bloqueo.','danger')}finally{btn.disabled=false}}
load();
</script>
</body>
</html>
<?php
    }
}
