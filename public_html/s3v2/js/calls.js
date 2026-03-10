// ====== Configura estas URLs a tu backend ======
const INCOMING_CHECK_URL = 'calls.php?action=incoming';
const ACCEPT_URL         = 'calls.php?action=accept';
const REJECT_URL         = 'calls.php?action=reject';
const LIST_URL_HTML      = 'calls.php?action=list&limit=50&format=html';

// ====== Utilidades ======
const $ = (s, scope = document) => scope.querySelector(s);

function cacheBuster(url) {
  try {
    const u = new URL(url, window.location.href);
    u.searchParams.set('_', Date.now().toString());
    return u.toString();
  } catch (_) {
    const sep = url.includes('?') ? '&' : '?';
    return url + sep + '_=' + Date.now();
  }
}

function openBitacoraTab() {
  const trigger = $('#tab-bitacora');
  if (!trigger) return;

  // Bootstrap 5 (vanilla)
  if (window.bootstrap && typeof window.bootstrap.Tab === 'function') {
    try {
      const tab = new window.bootstrap.Tab(trigger);
      tab.show();
    } catch (_) {}
    return;
  }

  // Bootstrap 4 (jQuery)
  if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.tab === 'function') {
    try { window.jQuery(trigger).tab('show'); } catch (_) {}
  }
}

function timeAgo(ts) {
  try {
    const d = new Date(ts);
    if (isNaN(d.getTime())) return String(ts);

    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'hace unos segundos';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    return d.toLocaleString();
  } catch (_) {
    return String(ts);
  }
}

// ====== Estado ======
let currentCall = null;
let polling = true;

// ====== Bitácora ======
async function loadBitacora() {
  const body = $('#bitacoraBody');
  if (!body) return;

  try {
    const r = await fetch(cacheBuster(LIST_URL_HTML), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const html = await r.text();
    body.innerHTML = html || '<div class="text-muted">No hay registros aún.</div>';
  } catch (_) {
    body.innerHTML = '<div class="text-danger">No se pudo cargar la bitácora.</div>';
  }
}

// ====== Toast entrante ======
function renderIncomingToast(call) {
  const toasts = $('#incomingToasts');
  if (!toasts || !call || !call.id) return;

  // Evitar duplicados por ID
  const existing = document.getElementById('toast-incoming-' + call.id);
  if (existing) return;

  const from = (call.from != null && call.from !== '') ? String(call.from) : 'Desconocido';
  const created = (call.created_at != null) ? call.created_at : Date.now();

  const div = document.createElement('div');
  div.className = 'chat-toast';
  div.id = 'toast-incoming-' + call.id;

  // Evito emojis “raros” si tu archivo no está en UTF-8 (puedes reponerlos cuando guardes en UTF-8)
  div.innerHTML = `
    <div class="ct-title">Llamada entrante</div>
    <div class="ct-body">De: <strong>${escapeHtml(from)}</strong> — ${escapeHtml(timeAgo(created))}</div>
    <div class="ct-actions">
      <button type="button" class="btn-accept">Aceptar</button>
      <button type="button" class="btn-reject">Rechazar</button>
      <button type="button" class="ct-close" title="Cerrar">✕</button>
    </div>
  `;

  toasts.appendChild(div);

  // Muestra la pestaña Bitácora cuando llega una entrante
  openBitacoraTab();

  // Acciones
  const btnAccept = div.querySelector('.btn-accept');
  const btnReject = div.querySelector('.btn-reject');
  const btnClose  = div.querySelector('.ct-close');

  if (btnAccept) {
    btnAccept.addEventListener('click', async () => {
      await acceptCall(call.id);
      openBitacoraTab();
      await loadBitacora();
      div.remove();
    });
  }

  if (btnReject) {
    btnReject.addEventListener('click', async () => {
      await rejectCall(call.id);
      openBitacoraTab();
      await loadBitacora();
      div.remove();
    });
  }

  if (btnClose) {
    btnClose.addEventListener('click', () => div.remove());
  }
}

// Seguridad básica para innerHTML (evita romper HTML si "from" trae caracteres raros)
function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

async function acceptCall(id) {
  try {
    await fetch(cacheBuster(ACCEPT_URL), {
      method: 'POST',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id })
    });
  } catch (_) {}
}

async function rejectCall(id) {
  try {
    await fetch(cacheBuster(REJECT_URL), {
      method: 'POST',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ id })
    });
  } catch (_) {}
}

// ====== Polling de entrantes ======
async function checkIncoming() {
  if (!polling) return;

  try {
    const r = await fetch(cacheBuster(INCOMING_CHECK_URL), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const j = await r.json();

    if (j && j.ok && j.call) {
      if (!currentCall || currentCall.id !== j.call.id) {
        currentCall = j.call;
        openBitacoraTab();
        renderIncomingToast(currentCall);
        await loadBitacora(); // para que "ringing" aparezca arriba
      }
    }
  } catch (_) {
    // Ignorar errores transitorios
  } finally {
    setTimeout(checkIncoming, 2000);
  }
}

// ====== Thumbs (tu segundo script) ======
async function initThumbs(scope = document) {
  const MAX_TRIES = 12;
  const BASE_DELAY = 400;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function loadThumb(img) {
    const url = img && img.dataset ? img.dataset.thumb : '';
    if (!url) return;

    for (let i = 1; i <= MAX_TRIES; i++) {
      try {
        await new Promise((res, rej) => {
          const t = new Image();
          t.onload = () => res(true);
          t.onerror = () => rej(new Error('thumb load error'));
          t.src = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now() + '_' + i;
        });

        img.src = url + (url.includes('?') ? '&' : '?') + 'ok=' + Date.now();
        return;
      } catch (_) {
        await sleep(BASE_DELAY * i);
      }
    }

    img.src = 'img/file.png';
  }

  const imgs = Array.from(scope.querySelectorAll('img.thumb-img[data-thumb]'));
  await Promise.all(imgs.map(loadThumb));
}

// ====== Init ======
(function init() {
  // Si esto se carga al final del body, puede ejecutarse ya.
  // Si se carga en head, esperamos DOMContentLoaded.
  const start = async () => {
    await loadBitacora();
    checkIncoming();
    // Si quieres cargar thumbs iniciales:
    // initThumbs(document);
  };

  if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Export opcional por si lo llamas después de AJAX
  window.initThumbs = initThumbs;
  window.loadBitacora = loadBitacora;
})();