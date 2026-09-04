class SoportesMediaTypesModule {
  constructor(win, doc) {
    this.window = win;
    this.document = doc;
  }

  init() {
    const window = this.window;
    const document = this.document;

    (function(){
      // --- Helpers ---
      const $  = s => document.querySelector(s);
      const $$ = s => document.querySelectorAll(s);
      const estado = t => { const el = $('#gaEstado'); if (el) el.textContent = t; };
      const fmt2 = n => (n<10?'0':'')+n;

      function soportesMediaTypes(){
        if (!window.MediaRecorder) return [];
        const cands = [
          'audio/webm;codecs=opus',
          'audio/ogg;codecs=opus',
          'audio/mp4',
          'audio/webm',
          'audio/ogg'
        ];
        return cands.filter(t => MediaRecorder.isTypeSupported(t));
      }

      function extPorMime(m){
        if (!m) return '.webm';
        if (m.includes('ogg'))  return '.ogg';
        if (m.includes('webm')) return '.webm';
        if (m.includes('mp4'))  return '.m4a'; // Safari suele dar audio/mp4 (AAC)
        return '.webm';
      }

      function nombreSugerido(mime){
        const now = new Date();
        const base = `Grabacion_${now.getFullYear()}-${fmt2(now.getMonth()+1)}-${fmt2(now.getDate())}_${fmt2(now.getHours())}-${fmt2(now.getMinutes())}-${fmt2(now.getSeconds())}`;
        return base + extPorMime(mime);
      }

      function saneaNombre(s){
        s = (s||'').trim();
        s = s.replace(/[\\/:*?"<>|]+/g, ' ');
        s = s.replace(/\s+/g,' ').trim();
        // Sin extensión aquí
        return s || '';
      }

      // --- Estado/vars ---
      let stream = null;
      let rec = null;
      let chunks = [];
      let mimeElegido = null;
      let blobFinal = null;
      let timer = null;
      let segundos = 0;

      // Nodos WebAudio
      let audioCtx = null, sourceNode = null, gainNode = null, compNode = null, destNode = null;

      // --- DOM refs ---
      const btnIniciar  = $('#gaBtnIniciar');
      const btnPausar   = $('#gaBtnPausar');
      const btnReanudar = $('#gaBtnReanudar');
      const btnDetener  = $('#gaBtnDetener');
      const btnGuardar  = $('#gaBtnGuardar');
      const preview     = $('#gaPreview');
      const bar         = $('#gaProgress');
      const timerSpan   = $('#gaTimer');
      const nombreInput = $('#gaNombre');
      const warnHttps   = $('#gaHttpsWarn');

      // Controles de ganancia
      const gainSlider  = $('#gaGain');
      const gainLabel   = $('#gaGainLabel');

      // Función para actualizar ganancia y etiqueta
      function setGain(){
        const val = parseFloat((gainSlider && gainSlider.value) || '1');
        if (gainNode) gainNode.gain.value = val;
        if (gainLabel) gainLabel.textContent = Math.round(val * 100) + '%';
      }
      if (gainSlider) gainSlider.addEventListener('input', setGain); // se puede adjuntar una vez; setGain valida gainNode

      // HTTPS warning
      if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        if (warnHttps) warnHttps.style.display = 'block';
      }

      // Reset modal al abrir/cerrar
      const modalEl = $('#modalGrabarAudio');
      if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal' in window ? 'hidden.bs.modal' : 'hidden', resetTodo);
        modalEl.addEventListener('show.bs.modal'   in window ? 'show.bs.modal'   : 'show',   resetTodo);
      }

      function resetTodo(){
        detenerTimer();
        segundos = 0;
        if (timerSpan) timerSpan.textContent = '00:00';
        estado('Listo');
        if (bar) {
          bar.style.width = '0%';
          bar.parentElement && bar.parentElement.classList.add('d-none');
        }

        chunks = [];
        blobFinal = null;
        mimeElegido = null;
        if (preview && preview.src) URL.revokeObjectURL(preview.src);
        if (preview) {
          preview.src = '';
          preview.style.display = 'none';
        }

        habilitar(btnIniciar,  true);
        habilitar(btnPausar,   false);
        habilitar(btnReanudar, false);
        btnReanudar && btnReanudar.classList.add('d-none');
        habilitar(btnDetener,  false);
        habilitar(btnGuardar,  false);

        // Corta el stream si quedó abierto
        if (stream) {
          stream.getTracks().forEach(t => t.stop());
          stream = null;
        }
        rec = null;

        // Cierra el contexto de audio si existe
        if (audioCtx) { try { audioCtx.close(); } catch(e){} audioCtx = null; }
        sourceNode = gainNode = compNode = destNode = null;

        // Actualiza la etiqueta de ganancia (aunque no haya gainNode)
        if (gainLabel && gainSlider) gainLabel.textContent = Math.round(parseFloat(gainSlider.value || '1') * 100) + '%';
      }

      function habilitar(el, v){
        if (!el) return;
        el.disabled = !v;
      }

      function iniciarTimer(){
        detenerTimer();
        timer = setInterval(()=>{
          segundos++;
          const mm = Math.floor(segundos/60);
          const ss = segundos%60;
          if (timerSpan) timerSpan.textContent = `${fmt2(mm)}:${fmt2(ss)}`;
        },1000);
      }

      function detenerTimer(){
        if (timer) { clearInterval(timer); timer=null; }
      }

      // --- Lógica de grabación ---
      if (btnIniciar) btnIniciar.addEventListener('click', async ()=>{
        try {
          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            estado('Tu navegador no permite usar el micrófono');
            return;
          }
          const soportes = soportesMediaTypes();
          if (!window.MediaRecorder || soportes.length === 0) {
            estado('Grabación no soportada en este navegador');
            return;
          }
          mimeElegido = soportes[0];

          // 1) Solicita micrófono (desactiva AGC propia del navegador para que el preamp no sea contrarrestado)
          stream = await navigator.mediaDevices.getUserMedia({
            audio: {
              echoCancellation: true,
              noiseSuppression: true,
              autoGainControl: false, // controlaremos la ganancia con WebAudio
              channelCount: 1
              // sampleRate: 48000, // opcional, algunos navegadores no lo respetan
            }
          });

          // 2) Cadena WebAudio: Mic -> Gain -> Compressor -> Destination(MediaStream)
          audioCtx   = new (window.AudioContext || window.webkitAudioContext)();
          sourceNode = audioCtx.createMediaStreamSource(stream);

          gainNode = audioCtx.createGain();
          setGain(); // inicializa ganancia y etiqueta a partir del slider

          compNode = audioCtx.createDynamicsCompressor();
          // Ajustes de compresor “seguros” para elevar nivel sin clip duro
          compNode.threshold.value = -24; // dB
          compNode.knee.value      = 30;  // dB
          compNode.ratio.value     = 12;  // :1
          compNode.attack.value    = 0.003; // s
          compNode.release.value   = 0.25;  // s

          destNode = audioCtx.createMediaStreamDestination();

          sourceNode.connect(gainNode);
          gainNode.connect(compNode);
          compNode.connect(destNode);

          // (Opcional) Monitoreo en vivo: cuidado con eco
          // compNode.connect(audioCtx.destination);

          // 3) Graba el stream PROCESADO
          rec = new MediaRecorder(destNode.stream, { mimeType: mimeElegido });

          chunks = [];
          rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };

          rec.onstart = ()=>{
            estado('Grabando…');
            iniciarTimer();
            habilitar(btnIniciar, false);
            habilitar(btnPausar,  true);
            habilitar(btnDetener, true);
            habilitar(btnGuardar, false);
          };

          rec.onpause = ()=>{
            estado('Pausado');
            detenerTimer();
            btnPausar && btnPausar.classList.add('d-none');
            btnReanudar && btnReanudar.classList.remove('d-none');
            habilitar(btnReanudar, true);
          };

          rec.onresume = ()=>{
            estado('Grabando…');
            iniciarTimer();
            btnReanudar && btnReanudar.classList.add('d-none');
            btnPausar && btnPausar.classList.remove('d-none');
            habilitar(btnPausar, true);
          };

          rec.onstop = ()=>{
            detenerTimer();
            blobFinal = new Blob(chunks, { type: mimeElegido });
            if (preview) {
              preview.src = URL.createObjectURL(blobFinal);
              preview.style.display = 'block';
            }
            estado('Listo para guardar');
            habilitar(btnGuardar, true);
            habilitar(btnPausar, false);
            habilitar(btnReanudar, false);
            btnReanudar && btnReanudar.classList.add('d-none');
            habilitar(btnDetener, false);

            // Libera micrófono
            if (stream) { stream.getTracks().forEach(t=>t.stop()); stream=null; }
            // Cierra contexto
            if (audioCtx) { try { audioCtx.close(); } catch(e){} audioCtx = null; }
            sourceNode = gainNode = compNode = destNode = null;
          };

          rec.start(); // puedes usar timeslice si quieres fragmentos
        } catch (err) {
          console.error(err);
          estado(err?.name === 'NotAllowedError' ? 'Permiso de micrófono denegado' : 'Error al iniciar micrófono');
        }
      });

      if (btnPausar)   btnPausar.addEventListener('click', ()=> { if (rec && rec.state==='recording') rec.pause(); });
      if (btnReanudar) btnReanudar.addEventListener('click', ()=> { if (rec && rec.state==='paused')   rec.resume(); });
      if (btnDetener)  btnDetener.addEventListener('click', ()=> { if (rec && (rec.state==='recording' || rec.state==='paused')) rec.stop(); });

      if (btnGuardar) btnGuardar.addEventListener('click', ()=>{
        if (!blobFinal) return;
        const base = saneaNombre(nombreInput && nombreInput.value);
        const finalName = (base ? base : nombreSugerido(mimeElegido).replace(/\.[^.]+$/,'')) + extPorMime(mimeElegido);
        subirBlob(blobFinal, finalName, mimeElegido);
      });

      /**
       * Subida de la grabación mediante el mismo flujo unificado del Drive.
       * La ruta queda congelada al pulsar Guardar y FileS3 se registra solo
       * después de que el PUT a S3 terminó correctamente.
       */
      async function subirBlob(blob, filename, mime){
        let rutaObjetivo = '';
        try {
          rutaObjetivo = window.DriveUploadDestination.capture();
        } catch (err) {
          estado(err.message || 'No se pudo determinar la carpeta destino');
          return;
        }

        estado('Preparando subida a ' + rutaObjetivo + '…');
        if (bar) {
          bar.parentElement && bar.parentElement.classList.remove('d-none');
          bar.style.width = '0%';
        }
        habilitar(btnGuardar, false);

        try {
          const API = window.UPLOAD_API || 'api/upload.php';
          const initParams = new URLSearchParams({
            mode: 'local_put',
            action: 'init',
            nombre: filename,
            ruta_objetivo: rutaObjetivo
          });

          const initResponse = await fetch(API + '?' + initParams.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          });
          const initJson = await initResponse.json().catch(() => null);
          if (!initResponse.ok || !initJson || !initJson.ok || !initJson.url || !initJson.upload_token) {
            throw new Error((initJson && initJson.error) || ('No se pudo iniciar la subida (HTTP ' + initResponse.status + ')'));
          }

          estado('Subiendo a S3…');
          await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', initJson.url, true);
            xhr.setRequestHeader('Content-Type', mime || 'application/octet-stream');
            xhr.upload.onprogress = (e) => {
              if (e.lengthComputable && bar) {
                bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
              }
            };
            xhr.onload = () => {
              if (xhr.status >= 200 && xhr.status < 300) resolve();
              else reject(new Error('Fallo PUT S3 (' + xhr.status + ')'));
            };
            xhr.onerror = () => reject(new Error('Error de red durante la subida'));
            xhr.send(blob);
          });

          estado('Registrando archivo…');
          const completeBody = new URLSearchParams({
            upload_token: initJson.upload_token,
            tamano: String(blob.size || 0)
          });
          const completeResponse = await fetch(API + '?mode=local_put&action=complete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: completeBody.toString()
          });
          const completeJson = await completeResponse.json().catch(() => null);
          if (!completeResponse.ok || !completeJson || !completeJson.ok) {
            throw new Error((completeJson && completeJson.error) || ('No se pudo registrar en FileS3 (HTTP ' + completeResponse.status + ')'));
          }

          if (bar) bar.style.width = '100%';
          estado('Guardado en ' + rutaObjetivo);
          await window.DriveUploadDestination.afterSuccess(rutaObjetivo);

          setTimeout(() => {
            try {
              if (typeof jQuery !== 'undefined' && jQuery(modalEl).modal) {
                jQuery(modalEl).modal('hide');
              } else if (window.bootstrap?.Modal) {
                (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
              }
            } catch(e){}
          }, 600);
        } catch (err) {
          console.error(err);
          estado(err.message || 'Error subiendo la grabación');
          habilitar(btnGuardar, true);
        }
      }
    })();

    return this;
  }

  static boot(win = window, doc = document) {
    win.ArcadeCloudDrive = win.ArcadeCloudDrive || { modules: {} };
    win.ArcadeCloudDrive.modules = win.ArcadeCloudDrive.modules || {};
    const instance = new SoportesMediaTypesModule(win, doc).init();
    win.ArcadeCloudDrive.modules['soportesMediaTypes'] = instance;
    return instance;
  }
}

SoportesMediaTypesModule.boot();
