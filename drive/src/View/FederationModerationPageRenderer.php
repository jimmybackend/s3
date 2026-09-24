<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationModerationPageRenderer
{
    public function render(string $csrf): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Moderación FederationCloud</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#0f172a;color:#e5e7eb}
main{max-width:1100px;margin:32px auto;padding:20px}.card{background:#111827;border:1px solid #334155;border-radius:14px;padding:18px;margin:14px 0}
.meta{color:#94a3b8;font-size:.92rem;word-break:break-word}.details{white-space:pre-wrap;background:#0b1220;padding:12px;border-radius:10px;margin:12px 0}
button{padding:10px 14px;border:0;border-radius:9px;font-weight:700;cursor:pointer;margin-right:8px}.confirm{background:#dc2626;color:#fff}.reject{background:#475569;color:#fff}textarea{width:100%;box-sizing:border-box;min-height:72px;border-radius:9px;padding:10px;margin:8px 0;background:#0b1220;color:#fff;border:1px solid #475569}
</style>
</head>
<body>
<main>
<h1>Moderación FederationCloud</h1>
<p>Los reportes pendientes requieren decisión humana. Confirmar bloquea la huella exacta SHA-256, emite el evento federado y elimina las copias locales conocidas. Rechazar no bloquea contenido.</p>
<div id="list">Cargando…</div>
</main>
<script>
const csrf=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const list=document.getElementById('list');
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
async function load(){
 const r=await fetch('moderation-api.php',{credentials:'same-origin'}),j=await r.json();
 if(!r.ok||!j.ok){list.textContent=j.error||'No se pudieron cargar reportes.';return}
 if(!j.reports.length){list.innerHTML='<div class="card">No hay reportes pendientes.</div>';return}
 list.innerHTML=j.reports.map(x=>'<div class="card" data-id="'+esc(x.ReportId)+'">'
  +'<strong>'+esc(x.Category)+' · '+esc(x.TargetType)+' · '+esc(x.TargetId)+'</strong>'
  +'<div class="meta">Reporte '+esc(x.ReportId)+' · '+esc(x.CreatedAt)+'<br>SHA-256: '+esc(x.ContentId||'pendiente/no disponible')+'</div>'
  +'<div class="details">'+esc(x.Details)+'</div>'
  +'<textarea placeholder="Motivo de tu decisión"></textarea>'
  +'<button class="confirm" onclick="decide(this,\'confirm\')">Confirmar y bloquear</button>'
  +'<button class="reject" onclick="decide(this,\'reject\')">Rechazar</button></div>').join('');
}
async function decide(btn,decision){
 const card=btn.closest('.card'),id=card.dataset.id,reason=card.querySelector('textarea').value.trim();
 if(!reason){alert('Escribe el motivo de la decisión.');return}
 if(decision==='confirm'&&!confirm('Esto bloqueará la huella y ordenará la eliminación local. ¿Continuar?'))return;
 const body=new FormData(); body.set('report_id',id);body.set('decision',decision);body.set('reason',reason);
 const r=await fetch('moderation-api.php',{method:'POST',body,credentials:'same-origin',headers:{'X-Federation-Moderation-CSRF':csrf}});
 const j=await r.json();
 if(!r.ok||!j.ok){alert(j.error||'No se pudo decidir.');return}
 await load();
}
load();
</script>
</body>
</html>
<?php
    }
}
