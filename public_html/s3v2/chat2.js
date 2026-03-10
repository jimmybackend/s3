// chat2.js — Chat con Markdown, adjuntos en cola, generación img/video y notificaciones (toasts)
(function () {
  'use strict';

  window.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('pane-Chat2')) return;

    // ===============================
    // Endpoints del backend (misma ruta)
    // ===============================
    const API = {
      send:              'bedrock_chat2.php',
      sessions:          'chat2_sessions.php',
      createSession:     'chat2_session_create.php',
      renameSession:     'chat2_session_title.php',
      archiveSession:    'chat2_session_archive.php',
      restoreSession:    'chat2_session_restore.php',
      messages:          'chat2_messages.php',
      genImage:          'chat_gen_image.php',
      genVideoStart:     'chat_gen_video_start.php',
      genVideoStatus:    'chat_gen_video_status.php',
      notifyPoll:        'chat_notify_poll.php'
    };

    // ===============================
    // DOM refs
    // ===============================
    const $  = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));
    const el = {
      pane:          $('#pane-Chat2'),
      sessionsList:  $('#chat2SessionsList'),
      search:        $('#chat2Search'),
      reload:        $('#chat2Reload'),
      showArchived:  $('#chat2ShowArchived'),
      newBtn:        $('#chat2NewBtn'),
      title:         $('#chat2Title'),
      badge:         $('#chat2SessionBadge'),
      rename:        $('#chat2Rename'),
      archive:       $('#chat2Archive'),
      restore:       $('#chat2Restore'),

      model:         $('#chat2Model'),
      auto:          $('#chat2Auto'),
      temp:          $('#chat2Temp'),
      max:           $('#chat2Max'),
      topP:          $('#chat2TopP'),

      messages:      $('#chat2Messages'),
      status:        $('#chat2Status'),
      usage:         $('#chat2Usage'),

      input:         $('#chat2Input'),
      file:          $('#chat2File'),
      attach:        $('#chat2Attach'),

      btnGenImg:     $('#chat2BtnGenImg'),
      btnGenVid:     $('#chat2BtnGenVid'),
      btnSonic:      $('#chat2BtnSonic'),
      send:          $('#chat2Send'),

      queue:         $('#chat2Queue'),
      queueList:     $('#chat2QueueList'),
    };

    // ===============================
    // Estado
    // ===============================
    let currentSessionId = null;
    let sessions = [];
    let isSending = false;
    let pendingFiles = []; // {file: File, id: number}

    // ===============================
    // Utils
    // ===============================
    const esc = (s) => (s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');

    function fmtDate(dtStr) {
      if (!dtStr) return '';
      try {
        const d = new Date(dtStr.replace(' ', 'T'));
        return d.toLocaleString();
      } catch {
        return dtStr;
      }
    }

    function setStatus(txt) {
      if (!el.status) return;
      el.status.innerHTML = txt ? `⏳ ${esc(txt)}` : '';
    }
    function setUsage(txt) {
      if (el.usage) el.usage.textContent = txt || '';
    }
    function toJSONorThrow(text, status, label) {
      try { return JSON.parse(text); }
      catch {
        const snippet = text.slice(0, 280).replace(/\s+/g,' ').trim();
        throw new Error(`${label} no devolvió JSON (HTTP ${status}). Respuesta: ${snippet}`);
      }
    }
    function buildS3Url(key) {
      return 'descargar.php?archivo=' + encodeURIComponent(key) + '&nombre=' + encodeURIComponent(key.split('/').pop());
    }
    function scrollMessagesToBottom() {
      if (el.messages) el.messages.scrollTop = el.messages.scrollHeight;
    }
    function getUserId() {
      const hid = document.getElementById('chatUserId');
      if (hid && hid.value) {
        const n = parseInt(hid.value, 10);
        if (Number.isFinite(n) && n > 0) return String(n);
      }
      const ds = document.querySelector('[data-user-id]');
      if (ds) {
        const v = ds.getAttribute('data-user-id') || (ds.dataset ? ds.dataset.userId : '');
        const n = parseInt(v, 10);
        if (Number.isFinite(n) && n > 0) return String(n);
      }
      return '';
    }

    // ✅ Nuevo: exigir modelo seleccionado (backend ahora lo requiere)
    function requireModelSelected() {
      const model = el.model ? String(el.model.value || '').trim() : '';
      if (!model) {
        setStatus('Selecciona un modelo antes de continuar.');
        if (el.model) el.model.focus();
        return '';
      }
      return model;
    }

    // ===============================
    // Markdown básico → HTML
    // ===============================
    function mdSafe(html){
      return String(html || '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function mdToHtml(md){
      let s = mdSafe(md);
      s = s.replace(/```([\s\S]*?)```/g, (m,p1)=>'<pre><code>'+p1.trim()+'</code></pre>');
      s = s.replace(/^(######)\s*(.+)$/gm,'<h6>$2</h6>')
           .replace(/^(#####)\s*(.+)$/gm,'<h5>$2</h5>')
           .replace(/^(####)\s*(.+)$/gm,'<h4>$2</h4>')
           .replace(/^(###)\s*(.+)$/gm,'<h3>$2</h3>')
           .replace(/^(##)\s*(.+)$/gm,'<h2>$2</h2>')
           .replace(/^(#)\s*(.+)$/gm,'<h1>$2</h1>');
      s = s.replace(/^\s*>\s?(.+)$/gm,'<blockquote>$1</blockquote>');
      s = s.replace(/^(?:-|\*)\s+(.+)$/gm,'<li>$1</li>');
      s = s.replace(/(<li>[\s\S]+?<\/li>)(?!\s*<li>)/g, '<ul>$1</ul>');
      s = s.replace(/^\d+\.\s+(.+)$/gm,'<li>$1</li>');
      s = s.replace(/(<ul>[\s\S]+<\/ul>)(\s*<ul>)/g,'$1$2');
      s = s.replace(/(<li>[\s\S]+?<\/li>)(?!\s*<li>)/g, (m)=> m.includes('<ul>')? m : '<ol>'+m+'</ol>');
      s = s.replace(/^\s*([-*_]){3,}\s*$/gm,'<hr>');
      s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>');
      s = s.replace(/`([^`]+)`/g,'<code>$1</code>');
      s = s.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
      s = s.replace(/\*([^*]+)\*/g,'<em>$1</em>');
      s = s.replace(/__([^_]+)__/g,'<strong>$1</strong>');
      s = s.replace(/_([^_]+)_/g,'<em>$1</em>');
      s = s.replace(/^(?!<h\d|<ul>|<ol>|<li>|<pre>|<blockquote>|<hr>|<table>|<\/)(.+)$/gm,'<p>$1</p>');
      return s.trim();
    }

    // ===============================
    // Cola de adjuntos (chips)
    // ===============================
    let fileIdSeq = 1;

    function addFilesFromInput(fileList) {
      const arr = Array.from(fileList || []);
      if (!arr.length) return;
      // Agregar manteniendo los previos (el input se resetea)
      arr.forEach(f => pendingFiles.push({ file: f, id: fileIdSeq++ }));
      renderQueue();
      // si el textarea está vacío, sugerimos encabezado "Adjunto(s): ..."
      suggestAttachmentsHeader();
      // limpiar input file para permitir volver a seleccionar
      if (el.file) el.file.value = '';
    }

    function removeFileById(id) {
      pendingFiles = pendingFiles.filter(x => x.id !== id);
      renderQueue();
    }

    function renderQueue() {
      const has = pendingFiles.length > 0;
      if (!el.queue || !el.queueList) return;
      el.queue.classList.toggle('d-none', !has);
      if (!has) { el.queueList.innerHTML = ''; return; }

      el.queueList.innerHTML = pendingFiles.map(({file, id}) => {
        const name = esc(file.name);
        const size = humanSize(file.size);
        const mime = esc(file.type || 'application/octet-stream');
        return `
          <span class="file-chip" title="${name} • ${mime} • ${size}">
            <i class="fas fa-file mr-1"></i>${name}
            <button type="button" class="chip-x" data-id="${id}" aria-label="Quitar">&times;</button>
          </span>
        `;
      }).join('');

      // Listeners de quitar
      el.queueList.querySelectorAll('.chip-x').forEach(btn => {
        btn.addEventListener('click', (ev) => {
          const id = parseInt(btn.getAttribute('data-id'), 10);
          removeFileById(id);
        });
      });
    }

    function humanSize(n){
      if (!Number.isFinite(n)) return '';
      const k = 1024;
      const sizes = ['B','KB','MB','GB','TB'];
      if (n === 0) return '0 B';
      const i = Math.floor(Math.log(n)/Math.log(k));
      return (n/Math.pow(k,i)).toFixed(1) + ' ' + sizes[i];
    }

    function suggestAttachmentsHeader() {
      // Si no hay texto aún, coloca un encabezado con los nombres.
      if (!el.input) return;
      const txt = (el.input.value || '').trim();
      if (txt) return;
      if (!pendingFiles.length) return;
      const names = pendingFiles.map(x => x.file.name).join(', ');
      el.input.value = `Adjunto(s): ${names}\n\n`; // el usuario escribe abajo su mensaje
      el.input.focus();
      el.input.setSelectionRange(el.input.value.length, el.input.value.length);
    }

    // ===============================
    // Render de mensajes (usa .chat-md para asistente)
    // ===============================
    function pushLocal(role, content, opts = {}) {
      const ct = opts.content_type || 'text';
      const timeHtml = opts.created_at ? `<div class="msg-time">${esc(fmtDate(opts.created_at))}</div>` : '';
      let html = '';

      if (ct === 'image' && (opts.s3_key || opts.thumb_s3_key)) {
        const imgUrl  = buildS3Url(opts.thumb_s3_key || opts.s3_key);
        const fullUrl = buildS3Url(opts.s3_key || opts.thumb_s3_key);
        html = `
          <div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'}">
            <div><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
            ${content ? `<div>${esc(content)}</div>` : ''}
            <a href="${fullUrl}" target="_blank" rel="noopener">
              <img src="${imgUrl}" alt="imagen" style="max-width:320px; border-radius:8px; margin-top:.35rem;">
            </a>
            ${timeHtml}
          </div>`;
      } else if (ct === 'video' && opts.s3_key) {
        const vidUrl = buildS3Url(opts.s3_key);
        html = `
          <div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'}">
            <div><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
            ${content ? `<div>${esc(content)}</div>` : ''}
            <video controls style="max-width:420px; margin-top:.35rem;" preload="metadata">
              <source src="${vidUrl}" type="${esc(opts.mime_type || 'video/mp4')}">
              Tu navegador no soporta video embebido. <a href="${vidUrl}" target="_blank" rel="noopener">Descargar</a>
            </video>
            ${timeHtml}
          </div>`;
      } else if (ct === 'audio' && opts.s3_key) {
        const aUrl = buildS3Url(opts.s3_key);
        html = `
          <div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'}">
            <div><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
            ${content ? `<div>${esc(content)}</div>` : ''}
            <audio controls style="width:320px; margin-top:.35rem;">
              <source src="${aUrl}" type="${esc(opts.mime_type || 'audio/mpeg')}">
              <a href="${aUrl}" target="_blank" rel="noopener">Descargar audio</a>
            </audio>
            ${timeHtml}
          </div>`;
      } else {
        if (role === 'assistant') {
          const htmlContent = mdToHtml(content || '');
          html = `
            <div class="chat-msg assistant chat-assistant">
              <div class="chat-md">${htmlContent}</div>
              ${timeHtml}
            </div>`;
        } else {
          const userHtml = esc(content || '').replace(/\n/g, '<br>');
          html = `
            <div class="chat-msg user chat-user">
              <div>${userHtml}</div>
              ${timeHtml}
            </div>`;
        }
      }

      el.messages.insertAdjacentHTML('beforeend', html);
      scrollMessagesToBottom();
    }

    // ===============================
    // Sesiones
    // ===============================
    async function loadSessions() {
      setStatus('Cargando sesiones…');
      try {
        const qs = new URLSearchParams();
        const q = (el.search && el.search.value.trim()) || '';
        if (q) qs.set('q', q);
        if (el.showArchived && el.showArchived.checked) qs.set('archived', '1');

        const r = await fetch(`${API.sessions}?${qs.toString()}`, { credentials: 'same-origin' });
        const t = await r.text();
        const j = toJSONorThrow(t, r.status, 'La API de sesiones');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        sessions = Array.isArray(j.sessions) ? j.sessions : [];
        renderSessionsList();
      } catch (e) {
        console.error(e);
        if (el.sessionsList) {
          el.sessionsList.innerHTML = `<li class="list-group-item text-danger">${esc(e.message)}</li>`;
        }
      } finally {
        setStatus('');
      }
    }

    function renderSessionsList() {
      if (!el.sessionsList) return;
      const items = sessions.map(s => {
        const title = esc(s.title || `Sesión #${s.id}`);
        const small = esc(s.updated_at || s.created_at || '');
        const badge = s.archived ? `<span class="badge badge-secondary ml-1">archivada</span>` : '';
        const active = (s.id === currentSessionId) ? ' active' : '';
        return `
          <li class="list-group-item d-flex align-items-center${active}" data-id="${s.id}">
            <div class="flex-grow-1">
              <strong>${title}</strong> ${badge}<br>
              <small class="text-muted">${small}</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary js-rename" title="Renombrar"><i class="fas fa-pen"></i></button>
            ${s.archived
              ? `<button class="btn btn-sm btn-outline-success ml-1 js-restore" title="Restaurar"><i class="fas fa-undo"></i></button>`
              : `<button class="btn btn-sm btn-outline-danger ml-1 js-archive" title="Archivar"><i class="fas fa-archive"></i></button>`}
          </li>`;
      }).join('') || `<li class="list-group-item">Sin sesiones</li>`;
      el.sessionsList.innerHTML = items;

      el.sessionsList.querySelectorAll('li.list-group-item').forEach(li => {
        li.addEventListener('click', (ev) => {
          if (ev.target.closest('.js-rename,.js-archive,.js-restore')) return;
          const id = parseInt(li.getAttribute('data-id'), 10);
          selectSession(id);
        });
        const id = parseInt(li.getAttribute('data-id'), 10);
        const btnRename = li.querySelector('.js-rename');
        const btnArchive = li.querySelector('.js-archive');
        const btnRestore = li.querySelector('.js-restore');

        if (btnRename) btnRename.addEventListener('click', (e) => { e.stopPropagation(); promptRename(id); });
        if (btnArchive) btnArchive.addEventListener('click', (e) => { e.stopPropagation(); doArchive(id); });
        if (btnRestore) btnRestore.addEventListener('click', (e) => { e.stopPropagation(); doRestore(id); });
      });
    }

    async function createSession(title) {
      setStatus('Creando sesión…');
      const fd = new FormData();
      if (title) fd.append('title', title);
      const uid = getUserId();
      if (uid) fd.append('user_id', uid);

      const model = requireModelSelected();
      if (!model) throw new Error('Selecciona un modelo.');
      fd.append('model', model);

      const r = await fetch(API.createSession, { method:'POST', credentials:'same-origin', body: fd });
      const t = await r.text();
      const j = toJSONorThrow(t, r.status, 'Crear sesión');
      if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
      setStatus('');
      return j;
    }

    async function promptRename(id) {
      const s = sessions.find(x => x.id === id);
      const oldTitle = s ? (s.title || `Sesión #${id}`) : `Sesión #${id}`;
      const title = window.prompt('Nuevo título:', oldTitle);
      if (title == null) return;
      setStatus('Renombrando…');
      const fd = new FormData();
      fd.append('session_id', id);
      fd.append('title', title);
      const r = await fetch(API.renameSession, { method:'POST', credentials:'same-origin', body: fd });
      const t = await r.text();
      const j = toJSONorThrow(t, r.status, 'Renombrar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId && el.title) el.title.textContent = title;
      setStatus('');
    }

    async function doArchive(id) {
      setStatus('Archivando…');
      const fd = new FormData();
      fd.append('session_id', id);
      const r = await fetch(API.archiveSession, { method:'POST', credentials:'same-origin', body: fd });
      const t = await r.text();
      const j = toJSONorThrow(t, r.status, 'Archivar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId) {
        const s = sessions.find(x => x.id === id);
        if (el.badge) {
          if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
          else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
        }
        if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
        if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));
      }
      setStatus('');
    }

    async function doRestore(id) {
      setStatus('Restaurando…');
      const fd = new FormData();
      fd.append('session_id', id);
      const r = await fetch(API.restoreSession, { method:'POST', credentials:'same-origin', body: fd });
      const t = await r.text();
      const j = toJSONorThrow(t, r.status, 'Restaurar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId) {
        const s = sessions.find(x => x.id === id);
        if (el.badge) {
          if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
          else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
        }
        if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
        if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));
      }
      setStatus('');
    }

    async function selectSession(id) {
      if (!id || Number.isNaN(id)) return;
      currentSessionId = id;
      const s = sessions.find(x => x.id === id);
      if (el.title) el.title.textContent = s ? (s.title || `Sesión #${id}`) : `Sesión #${id}`;
      if (el.badge) {
        if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
        else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
      }
      if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
      if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));

      setStatus('Cargando mensajes…');
      try {
        const qs = new URLSearchParams({ session_id: String(id) });
        const r = await fetch(`${API.messages}?${qs.toString()}`, { credentials: 'same-origin' });
        const t = await r.text();
        const j = toJSONorThrow(t, r.status, 'Mensajes de la sesión');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        renderMessages(j.messages || []);
        $$('#chat2SessionsList .list-group-item').forEach(li => {
          const liId = parseInt(li.getAttribute('data-id'), 10);
          if (liId === id) li.classList.add('active'); else li.classList.remove('active');
        });
      } catch (e) {
        console.error(e);
        el.messages.innerHTML = `<div class="text-danger">Error cargando mensajes: ${e.message}</div>`;
      } finally {
        setStatus('');
      }
    }

    function renderMessages(msgs) {
      el.messages.innerHTML = '';
      (msgs || []).forEach(m => {
        pushLocal(m.role || 'assistant', m.content || '', {
          content_type: m.content_type || 'text',
          s3_key: m.s3_key || null,
          mime_type: m.mime_type || null,
          thumb_s3_key: m.thumb_s3_key || null,
          created_at: m.created_at || null,
        });
      });
    }

    // ===============================
    // Envío de mensajes
    // ===============================
    async function sendMessage() {
      if (isSending) return;

      const text = (el.input && el.input.value) ? el.input.value.trim() : '';
      const auto = !!(el.auto && el.auto.checked);

      const model = requireModelSelected();
      if (!model) return;

      const temperature = el.temp ? parseFloat(el.temp.value) : 0.7;
      const max_tokens   = el.max ? parseInt(el.max.value, 10) : 800;
      const top_p        = el.topP ? parseFloat(el.topP.value) : 0.9;

      // Requisito: si hay adjuntos, debe haber texto
      if (pendingFiles.length > 0 && !text) {
        suggestAttachmentsHeader();
        setStatus('Agrega un mensaje para enviar junto con tus archivos.');
        el.input && el.input.focus();
        return;
      }

      // Si no hay nada que enviar, salir
      if (!text && pendingFiles.length === 0) {
        el.input && el.input.focus();
        return;
      }

      // Crear sesión si no existe
      if (!currentSessionId) {
        try {
          const created = await createSession(text.slice(0, 64) || 'Nueva conversación (Auto)');
          currentSessionId = created.id;
          await loadSessions();
        } catch (e) {
          pushLocal('assistant', '⚠️ Error creando sesión: ' + e.message);
          return;
        }
      }

      isSending = true;
      setStatus('Enviando…');

      // Pinta el mensaje del usuario ya (texto)
      if (text) pushLocal('user', text, { created_at: new Date().toISOString() });

      try {
        const fd = new FormData();
        fd.append('session_id', String(currentSessionId));
        const uid = getUserId();
        if (uid) fd.append('user_id', uid);

        fd.append('text', text);
        fd.append('auto', auto ? '1' : '0');
        fd.append('model', model); // ✅ siempre enviar
        fd.append('temperature', String(temperature));
        fd.append('max_tokens', String(max_tokens));
        fd.append('top_p', String(top_p));

        // Adjuntar archivos de la cola
        if (pendingFiles.length > 0) {
          pendingFiles.forEach(({file}) => fd.append('files[]', file, file.name));
        }

        const r = await fetch(API.send, { method:'POST', credentials:'same-origin', body: fd });
        const t = await r.text();
        const j = toJSONorThrow(t, r.status, 'Enviar mensaje');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);

        if (j.reply) {
          pushLocal('assistant', j.reply, { created_at: new Date().toISOString() });
        }

        const action   = (j.action || '').toLowerCase();
        const improved = j.router && j.router.improved_prompt ? String(j.router.improved_prompt) : (text || '');

        if (action === 'gen_image') {
          await autoGenerateImage(improved);
        } else if (action === 'gen_video') {
          await autoGenerateVideo(improved);
        } else {
          await selectSession(currentSessionId);
        }

        // Limpiar UI tras envío
        if (el.input) el.input.value = '';
        clearQueue();

        if (j.usage) {
          const u = j.usage;
          setUsage(`Tokens ~ prompt ${u.prompt_tokens||0} + completion ${u.completion_tokens||0} = ${u.total_tokens||0}`);
        }

      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error: ' + e.message);
      } finally {
        setStatus('');
        isSending = false;
      }
    }

    function clearQueue(){
      pendingFiles = [];
      renderQueue();
    }

    // ===============================
    // Generación de imágenes / video
    // ===============================
    async function autoGenerateImage(prompt) {
      setStatus('Generando imagen…');
      const fd = new FormData();
      fd.append('session_id', String(currentSessionId || 0));
      const uid = getUserId();
      if (uid) fd.append('user_id', uid);
      fd.append('prompt', prompt);

      try {
        const r = await fetch(API.genImage, { method:'POST', credentials:'same-origin', body: fd });
        const t = await r.text();
        const j = toJSONorThrow(t, r.status, 'Generar imagen');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);

        await selectSession(currentSessionId);
        setStatus('Imagen lista.');
        setTimeout(() => setStatus(''), 1500);
      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error generando imagen: ' + e.message);
        setStatus('');
      }
    }

    async function autoGenerateVideo(prompt) {
      setStatus('Iniciando video…');

      const SERVER_WAIT_SECS = 120;
      const MAX_WAIT_MS      = 20 * 60 * 1000;
      const COOLDOWN_MS      = 1500;
      const FETCH_TIMEOUT_MS = (SERVER_WAIT_SECS + 30) * 1000;

      const wait = (ms) => new Promise(res => setTimeout(res, ms));
      const normalize  = (s) => String(s || '').toLowerCase();
      const isDone     = (s) => ['completed','complete','succeeded','success','done'].includes(normalize(s));
      const isWorking  = (s) => ['queued','processing','running','generating','in_progress','submitted','pending'].includes(normalize(s));

      const fd = new FormData();
      fd.append('session_id', String(currentSessionId || 0));
      const uid = getUserId(); if (uid) fd.append('user_id', uid);
      fd.append('prompt', prompt);
      fd.append('duration', '6'); // Reel single-shot

      try {
        const r = await fetch(API.genVideoStart, { method:'POST', credentials:'same-origin', body: fd });
        const t = await r.text();
        const j = toJSONorThrow(t, r.status, 'Iniciar video');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);

        const messageId = j.message_id || j.msg_id || j.id || null;
        const status0   = normalize(j.status);

        if (!messageId) throw new Error('No se recibió message_id del servidor.');

        if (status0 === 'unsupported') {
          pushLocal('assistant', '⚠️ Video no soportado en esta cuenta/región o sin permisos Async Invoke.');
          await selectSession(currentSessionId);
          setStatus('');
          return;
        }

        setStatus('Generando video…');
        const started = Date.now();

        while (true) {
          const qs = new URLSearchParams();
          qs.set('message_id', String(messageId));
          qs.set('wait_secs', String(SERVER_WAIT_SECS));

          const ctrl = new AbortController();
          const to = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);

          let js;
          try {
            const rq = await fetch(`${API.genVideoStatus}?${qs.toString()}`, {
              credentials:'same-origin',
              signal: ctrl.signal
            });
            const ts = await rq.text();
            js = toJSONorThrow(ts, rq.status, 'Estado de video');
            if (!rq.ok || js.ok === false) throw new Error(js.error || `HTTP ${rq.status}`);
          } catch (err) {
            clearTimeout(to);
            if (err.name === 'AbortError') {
              if (Date.now() - started > MAX_WAIT_MS) {
                throw new Error('Timeout de red consultando estado de video.');
              }
              await wait(COOLDOWN_MS);
              continue;
            }
            throw err;
          }
          clearTimeout(to);

          const st = normalize(js.status);

          if (isDone(st)) {
            await selectSession(currentSessionId);
            setStatus('Video listo.');
            setTimeout(() => setStatus(''), 1500);
            break;
          }

          if (!isWorking(st)) {
            if (st === 'unsupported') {
              pushLocal('assistant', '⚠️ Video no soportado en esta cuenta/región o sin permisos Async Invoke.');
            } else if (st === 'failed' || st === 'canceled') {
              pushLocal('assistant', `⚠️ Job de video ${st}.`);
            } else {
              pushLocal('assistant', `⚠️ Estado de video: ${st || 'desconocido'}.`);
            }
            await selectSession(currentSessionId);
            setStatus('');
            break;
          }

          if (Date.now() - started > MAX_WAIT_MS) {
            pushLocal('assistant', `⚠️ Timeout de generación de video (${st || 'sin estatus'})`);
            await selectSession(currentSessionId);
            setStatus('');
            break;
          }
          await wait(COOLDOWN_MS);
        }

      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error generando video: ' + e.message);
        setStatus('');
      }
    }

    // ===============================
    // Notificaciones (poll cada 20s): vídeos completados en otras sesiones
    // ===============================
    const NOTIFY_POLL_MS = 20000;
    let notifyTimer = null;
    let lastNotifyId = parseInt(localStorage.getItem('chat2_last_notify_id') || '0', 10);
    let notifiedSet = new Set(JSON.parse(localStorage.getItem('chat2_notified_ids') || '[]'));

    function ensureToastContainer(){
      if (!document.getElementById('chatToasts')) {
        const c = document.createElement('div');
        c.id = 'chatToasts';
        c.className = 'chat-toasts';
        document.body.appendChild(c);
      }
      return document.getElementById('chatToasts');
    }

    function showToastVideo(item){
      if (notifiedSet.has(item.id)) return; // evita duplicados
      notifiedSet.add(item.id);
      localStorage.setItem('chat2_notified_ids', JSON.stringify(Array.from(notifiedSet)));

      const $c = ensureToastContainer();
      const elx = document.createElement('div');
      elx.className = 'chat-toast';
      elx.innerHTML = `
        <div class="ct-title">🎬 Video listo</div>
        <div class="ct-body">En <strong>${(item.title || 'esta conversación')}</strong></div>
        <div class="ct-actions">
          <button class="ct-view">Ver</button>
          <button class="ct-close">Cerrar</button>
        </div>`;
      $c.appendChild(elx);

      elx.querySelector('.ct-close').addEventListener('click', () => elx.remove());
      elx.querySelector('.ct-view').addEventListener('click', async () => {
        elx.remove();
        if (item.session_id) {
          await selectSession(item.session_id);
          const box = document.getElementById('chat2Messages');
          if (box) box.scrollTop = box.scrollHeight;
        }
      });

      setTimeout(() => { try{ elx.remove(); }catch{} }, 12000);
    }

    async function pollNotifications(){
      try{
        const uid = getUserId();
        const qs = new URLSearchParams();
        if (lastNotifyId > 0) qs.set('last_id', String(lastNotifyId));
        if (uid) qs.set('user_id', uid);

        const r = await fetch(`${API.notifyPoll}?${qs.toString()}`, { credentials:'same-origin' });
        const t = await r.text();
        let j;
        try{ j = JSON.parse(t); }catch{ return; }
        if (!j || j.ok === false) return;

        const items = Array.isArray(j.items) ? j.items : [];
        if (items.length) {
          items.forEach(item => {
            showToastVideo(item);
            if (item.id && item.id > lastNotifyId) lastNotifyId = item.id;
          });
          localStorage.setItem('chat2_last_notify_id', String(lastNotifyId));
        }
      }catch(e){ /* silencio */ }
    }

    function startNotifyPoller(){
      // arranque inmediato + interval
      pollNotifications();
      if (notifyTimer) clearInterval(notifyTimer);
      notifyTimer = setInterval(pollNotifications, NOTIFY_POLL_MS);
    }

    // ===============================
    // Listeners UI
    // ===============================
    if (el.attach && el.file) el.attach.addEventListener('click', () => el.file.click());
    if (el.file) el.file.addEventListener('change', (ev) => addFilesFromInput(el.file.files));

    if (el.send) el.send.addEventListener('click', sendMessage);
    if (el.input) {
      el.input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.shiftKey) {
          ev.preventDefault();
          sendMessage();
        }
      });
    }

    if (el.btnGenImg) {
      el.btnGenImg.addEventListener('click', async () => {
        if (!currentSessionId) {
          const created = await createSession('Imágenes');
          currentSessionId = created.id;
          await loadSessions();
        }
        const prompt = (el.input && el.input.value.trim()) || window.prompt('Prompt de imagen:') || '';
        if (!prompt) return;
        await autoGenerateImage(prompt);
      });
    }
    if (el.btnGenVid) {
      el.btnGenVid.addEventListener('click', async () => {
        if (!currentSessionId) {
          const created = await createSession('Videos');
          currentSessionId = created.id;
          await loadSessions();
        }
        const prompt = (el.input && el.input.value.trim()) || window.prompt('Prompt de video (texto a video):') || '';
        if (!prompt) return;
        await autoGenerateVideo(prompt);
      });
    }

    if (el.newBtn) {
      el.newBtn.addEventListener('click', async () => {
        try {
          const created = await createSession('Nueva conversación (Auto)');
          currentSessionId = created.id;
          await loadSessions();
          await selectSession(currentSessionId);
          el.input && el.input.focus();
        } catch (e) {
          pushLocal('assistant', '⚠️ No se pudo crear la sesión: ' + e.message);
        }
      });
    }
    if (el.reload)       el.reload.addEventListener('click', () => loadSessions());
    if (el.search)       el.search.addEventListener('input', () => loadSessions());
    if (el.showArchived) el.showArchived.addEventListener('change', () => loadSessions());
    if (el.rename)  el.rename.addEventListener('click', () => currentSessionId && promptRename(currentSessionId));
    if (el.archive) el.archive.addEventListener('click', () => currentSessionId && doArchive(currentSessionId));
    if (el.restore) el.restore.addEventListener('click', () => currentSessionId && doRestore(currentSessionId));

    // ===============================
    // Manejo global de errores JS
    // ===============================
    window.addEventListener('error', (ev) => {
      const msg = ev && ev.message ? ev.message : 'Error JS no especificado';
      setStatus('Error en script');
      pushLocal('assistant', '⚠️ Error de script: ' + msg);
    });
    window.addEventListener('unhandledrejection', (ev) => {
      const msg = (ev && ev.reason && (ev.reason.message || ev.reason)) || 'Promise rechazada sin detalle';
      setStatus('Error en promesa');
      pushLocal('assistant', '⚠️ Error: ' + msg);
    });

    // ===============================
    // Boot
    // ===============================
    (async function boot() {
      setStatus('Cargando sesiones…');
      await loadSessions();
      setStatus('');
      startNotifyPoller();   // <— arranca el poll de notificaciones de videos completados
    })();
  });
})();
