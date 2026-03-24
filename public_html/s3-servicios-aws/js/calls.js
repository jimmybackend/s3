// ====== Endpoints ======
const INCOMING_CHECK_URL = 'calls.php?action=incoming&mine=1';
const ACCEPT_URL         = 'calls.php?action=accept';
const REJECT_URL         = 'calls.php?action=reject';
const END_URL            = 'calls.php?action=end';
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

  if (window.bootstrap && typeof window.bootstrap.Tab === 'function') {
    try {
      const tab = new window.bootstrap.Tab(trigger);
      tab.show();
      return;
    } catch (_) {}
  }

  if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.tab === 'function') {
    try {
      window.jQuery(trigger).tab('show');
      return;
    } catch (_) {}
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

function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

async function fetchJson(url, options = {}) {
  const response = await fetch(cacheBuster(url), {
    cache: 'no-store',
    credentials: 'same-origin',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {})
    },
    ...options
  });

  let data = null;
  try {
    data = await response.json();
  } catch (_) {
    throw new Error('Respuesta inválida del servidor');
  }

  if (!data || !data.ok) {
    throw new Error((data && data.error) ? data.error : 'Error de servidor');
  }

  return data;
}

// ====== Estado ======
let currentCall = null;
let pollingEnabled = false;
let pollingTimer = null;
let lastRenderedCallId = null;
let activeReceiverCallId = null;
let receiverPanelBound = false;

// ====== Bitácora ======
async function loadBitacora() {
  const body = $('#bitacoraBody');
  if (!body) return;

  try {
    const response = await fetch(cacheBuster(LIST_URL_HTML), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const html = await response.text();
    body.innerHTML = html || '<div class="text-muted">No hay registros aún.</div>';
  } catch (_) {
    body.innerHTML = '<div class="text-danger">No se pudo cargar la bitácora.</div>';
  }
}

// ====== Acciones ======
async function acceptCall(id) {
  const data = await fetchJson(ACCEPT_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ id: Number(id) })
  });

  return data.call || { id: Number(id), status: 'accepted' };
}

async function rejectCall(id) {
  const data = await fetchJson(REJECT_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ id: Number(id) })
  });

  return data.call || { id: Number(id), status: 'rejected' };
}

async function endCall(id) {
  const data = await fetchJson(END_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ id: Number(id) })
  });

  return data.call || { id: Number(id), status: 'ended' };
}

// ====== Panel receptor ======
function setReceiverNotice(text, type = 'secondary') {
  const notice = $('#receiverCallNotice');
  if (!notice) return;
  notice.className = 'alert alert-' + type + ' mb-2';
  notice.textContent = text;
}

function closeReceiverPanel() {
  const panel = $('#receiverCallPanel');
  const iframe = $('#receiverCallFrame');
  const title = $('#receiverCallTitle');
  const meta = $('#receiverCallMeta');
  const callIdBox = $('#receiverCallId');

  activeReceiverCallId = null;

  if (iframe) iframe.src = 'about:blank';
  if (title) title.textContent = 'Sin llamada activa';
  if (meta) meta.textContent = '';
  if (callIdBox) callIdBox.textContent = '';
  setReceiverNotice('Esperando llamadas...', 'secondary');

  if (panel) {
    panel.style.display = 'none';
  }
}

function ensureReceiverPanelEvents() {
  if (receiverPanelBound) return;
  receiverPanelBound = true;

  const btnHangup = $('#btnReceiverHangup');
  if (btnHangup) {
    btnHangup.addEventListener('click', async () => {
      if (!activeReceiverCallId) {
        closeReceiverPanel();
        startIncomingPolling();
        return;
      }

      btnHangup.disabled = true;
      try {
        await endCall(activeReceiverCallId);
        await loadBitacora();
        closeReceiverPanel();
        startIncomingPolling();
      } catch (err) {
        alert(err.message || 'No se pudo finalizar la llamada');
      } finally {
        btnHangup.disabled = false;
      }
    });
  }
}

function openReceiverPanel(call, noticeText = 'Llamada aceptada.') {
  ensureReceiverPanelEvents();

  const panel = $('#receiverCallPanel');
  const iframe = $('#receiverCallFrame');
  const title = $('#receiverCallTitle');
  const meta = $('#receiverCallMeta');
  const callIdBox = $('#receiverCallId');

  if (!panel || !iframe || !call || !call.id) return;

  activeReceiverCallId = Number(call.id);

  if (title) {
    title.textContent = 'Atendiendo llamada';
  }

  if (meta) {
    const from = (call.from != null && call.from !== '') ? String(call.from) : 'Desconocido';
    const to = (call.to != null && call.to !== '') ? String(call.to) : '';
    meta.textContent = 'De: ' + from + (to ? ' | Para: ' + to : '');
  }

  if (callIdBox) {
    callIdBox.textContent = 'Call ID: ' + call.id;
  }

  setReceiverNotice(noticeText, 'success');
  iframe.src = 'videollamada.php?call_id=' + encodeURIComponent(call.id) + '&mode=receiver';
  panel.style.display = 'block';
  openBitacoraTab();
}

// ====== UI llamada entrante ======
function removeIncomingToast(callId) {
  const node = document.getElementById('toast-incoming-' + callId);
  if (node) node.remove();
}

function renderIncomingToast(call) {
  const toasts = $('#incomingToasts');
  if (!toasts || !call || !call.id) return;

  const existing = document.getElementById('toast-incoming-' + call.id);
  if (existing) return;

  const from = (call.from != null && call.from !== '') ? String(call.from) : 'Desconocido';
  const created = (call.created_at != null) ? call.created_at : Date.now();

  const div = document.createElement('div');
  div.className = 'chat-toast';
  div.id = 'toast-incoming-' + call.id;

  div.innerHTML = `
    <div class="ct-title">Llamada entrante</div>
    <div class="ct-body">
      De: <strong>${escapeHtml(from)}</strong> — ${escapeHtml(timeAgo(created))}
    </div>
    <div class="ct-actions">
      <button type="button" class="btn-accept">Aceptar</button>
      <button type="button" class="btn-reject">Rechazar</button>
      <button type="button" class="btn-voicemail">Buzón</button>
      <button type="button" class="ct-close" title="Cerrar">✕</button>
    </div>
  `;

  toasts.appendChild(div);
  openBitacoraTab();

  const btnAccept = div.querySelector('.btn-accept');
  const btnReject = div.querySelector('.btn-reject');
  const btnVoicemail = div.querySelector('.btn-voicemail');
  const btnClose  = div.querySelector('.ct-close');

  if (btnAccept) {
    btnAccept.addEventListener('click', async () => {
      btnAccept.disabled = true;
      if (btnReject) btnReject.disabled = true;
      if (btnVoicemail) btnVoicemail.disabled = true;

      try {
        const acceptedCall = await acceptCall(call.id);
        currentCall = acceptedCall;
        lastRenderedCallId = acceptedCall.id;

        removeIncomingToast(call.id);
        await loadBitacora();
        stopIncomingPolling();
        openReceiverPanel(acceptedCall, 'Llamada aceptada. Conectando panel del receptor...');
      } catch (err) {
        alert(err.message || 'No se pudo aceptar la llamada');
        btnAccept.disabled = false;
        if (btnReject) btnReject.disabled = false;
        if (btnVoicemail) btnVoicemail.disabled = false;
      }
    });
  }

  if (btnReject) {
    btnReject.addEventListener('click', async () => {
      btnReject.disabled = true;
      if (btnAccept) btnAccept.disabled = true;
      if (btnVoicemail) btnVoicemail.disabled = true;

      try {
        const rejectedCall = await rejectCall(call.id);
        currentCall = rejectedCall;
        lastRenderedCallId = rejectedCall.id;

        removeIncomingToast(call.id);
        await loadBitacora();
      } catch (err) {
        alert(err.message || 'No se pudo rechazar la llamada');
        btnReject.disabled = false;
        if (btnAccept) btnAccept.disabled = false;
        if (btnVoicemail) btnVoicemail.disabled = false;
      }
    });
  }

  if (btnVoicemail) {
    btnVoicemail.addEventListener('click', async () => {
      btnVoicemail.disabled = true;
      if (btnAccept) btnAccept.disabled = true;
      if (btnReject) btnReject.disabled = true;

      try {
        const endedCall = await endCall(call.id);
        currentCall = endedCall;
        lastRenderedCallId = endedCall.id;

        removeIncomingToast(call.id);
        await loadBitacora();
      } catch (err) {
        alert(err.message || 'No se pudo enviar al buzón');
        btnVoicemail.disabled = false;
        if (btnAccept) btnAccept.disabled = false;
        if (btnReject) btnReject.disabled = false;
      }
    });
  }

  if (btnClose) {
    btnClose.addEventListener('click', () => {
      removeIncomingToast(call.id);
    });
  }
}

// ====== Polling manual ======
async function checkIncoming() {
  if (!pollingEnabled) return;

  try {
    const data = await fetchJson(INCOMING_CHECK_URL, {
      method: 'GET'
    });

    if (data.call && data.call.id) {
      const incomingId = Number(data.call.id);

      if (lastRenderedCallId !== incomingId) {
        currentCall = data.call;
        lastRenderedCallId = incomingId;
        renderIncomingToast(data.call);
        await loadBitacora();
      }
    }
  } catch (_) {
    // error silencioso para no romper el ciclo
  } finally {
    if (pollingEnabled) {
      pollingTimer = setTimeout(checkIncoming, 2000);
    }
  }
}

function startIncomingPolling() {
  if (pollingEnabled) return;
  pollingEnabled = true;
  checkIncoming();
}

function stopIncomingPolling() {
  pollingEnabled = false;
  if (pollingTimer) {
    clearTimeout(pollingTimer);
    pollingTimer = null;
  }
}

// ====== Init ======
async function initCallsModule() {
  ensureReceiverPanelEvents();
  await loadBitacora();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initCallsModule);
} else {
  initCallsModule();
}

window.loadBitacora = loadBitacora;
window.startIncomingPolling = startIncomingPolling;
window.stopIncomingPolling = stopIncomingPolling;
window.acceptCall = acceptCall;
window.rejectCall = rejectCall;
window.endCall = endCall;
window.openReceiverPanel = openReceiverPanel;
window.closeReceiverPanel = closeReceiverPanel;
 