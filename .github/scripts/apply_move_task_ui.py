from pathlib import Path


def replace_once(path: str, old: str, new: str, marker: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(marker)
    p.write_text(text.replace(old, new, 1))


# ------------------------------------------------------------------
# DriveApplication composition
# ------------------------------------------------------------------
p = Path('drive/src/Core/DriveApplication.php')
s = p.read_text()

old = "use ArcadeCloud\\Drive\\Application\\FileMutationService;\n"
new = old + "use ArcadeCloud\\Drive\\Application\\MoveJobService;\n"
if 'use ArcadeCloud\\Drive\\Application\\MoveJobService;' not in s:
    if old not in s:
        raise SystemExit('DRIVE_APP_IMPORT_SERVICE')
    s = s.replace(old, new, 1)

old = "use ArcadeCloud\\Drive\\Storage\\FolderRepository;\n"
new = old + "use ArcadeCloud\\Drive\\Storage\\MoveJobStore;\n"
if 'use ArcadeCloud\\Drive\\Storage\\MoveJobStore;' not in s:
    if old not in s:
        raise SystemExit('DRIVE_APP_IMPORT_STORE')
    s = s.replace(old, new, 1)

old = "    private ?FileMutationService $fileMutationService = null;\n"
new = old + "    private ?MoveJobStore $moveJobStore = null;\n    private ?MoveJobService $moveJobService = null;\n"
if 'private ?MoveJobService $moveJobService' not in s:
    if old not in s:
        raise SystemExit('DRIVE_APP_PROPERTIES')
    s = s.replace(old, new, 1)

anchor = '''    public function fileKeyRotationService(): FileKeyRotationService
    {
'''
methods = '''    public function moveJobStore(): MoveJobStore
    {
        if ($this->moveJobStore === null) {
            $directory = trim((string)(getenv('ARCADECLOUD_MOVE_JOB_DIR') ?: ''));
            if ($directory === '') {
                $directory = sys_get_temp_dir() . '/arcadecloud-drive-move-jobs';
            }
            $this->moveJobStore = new MoveJobStore($directory);
        }
        return $this->moveJobStore;
    }

    public function moveJobService(): MoveJobService
    {
        return $this->moveJobService ??= new MoveJobService(
            $this->fileMutationService(),
            $this->folderMutationService(),
            $this->fileRecordRepository(),
            $this->folderMutationRepository(),
            $this->userStoragePath(),
            $this->moveJobStore()
        );
    }

'''
if 'public function moveJobService()' not in s:
    if anchor not in s:
        raise SystemExit('DRIVE_APP_METHOD_ANCHOR')
    s = s.replace(anchor, methods + anchor, 1)

p.write_text(s)


# ------------------------------------------------------------------
# s3.php: destination labels + JS task module
# ------------------------------------------------------------------
replace_once(
    'drive/s3.php',
    "$todasLasCarpetas = $app->folderQueryService()->allForUser($userId, true);",
    "$todasLasCarpetas = $app->folderQueryService()->destinationsForUser($userId, true);",
    'S3_DESTINATIONS_CALL'
)

old = '''            <?php foreach ($todasLasCarpetas as $ruta): ?>
              <?php
                $nivel = substr_count(trim($ruta, '/'), '/');
                $espacio = str_repeat('&nbsp;&nbsp;&nbsp;', $nivel);
              ?>
              <option value="<?= htmlspecialchars($ruta) ?>"><?= $espacio . htmlspecialchars($ruta) ?></option>
            <?php endforeach; ?>'''
new = '''            <?php foreach ($todasLasCarpetas as $destino): ?>
              <?php
                $rutaFisica = (string)($destino['value'] ?? '');
                $rutaVisible = (string)($destino['label'] ?? '');
                $nivel = max(0, substr_count(trim($rutaVisible, '/'), '/'));
                $espacio = str_repeat('&nbsp;&nbsp;&nbsp;', $nivel);
              ?>
              <option value="<?= htmlspecialchars($rutaFisica) ?>"><?= $espacio . htmlspecialchars($rutaVisible) ?></option>
            <?php endforeach; ?>'''
replace_once('drive/s3.php', old, new, 'S3_DESTINATION_OPTIONS')

old = '''<script src="js/carpetas.js"></script>
<script src="js/archivos.js?v=20260904-2205"></script>
<script src="js/file-block.js"></script>'''
new = '''<script src="js/move-tasks.js?v=<?= (int) filemtime(__DIR__ . '/js/move-tasks.js') ?>"></script>
<script src="js/carpetas.js?v=<?= (int) filemtime(__DIR__ . '/js/carpetas.js') ?>"></script>
<script src="js/archivos.js?v=<?= (int) filemtime(__DIR__ . '/js/archivos.js') ?>"></script>
<script src="js/file-block.js?v=<?= (int) filemtime(__DIR__ . '/js/file-block.js') ?>"></script>'''
replace_once('drive/s3.php', old, new, 'S3_MOVE_TASK_SCRIPT')


# ------------------------------------------------------------------
# archivos.js: mover single/multiple as background task
# ------------------------------------------------------------------
p = Path('drive/js/archivos.js')
s = p.read_text()

old = '''        if (nuevaRuta === '__crear__') {
          if (!nuevaCarp) {
            alert('Escribe el nombre de la nueva carpeta.');
            return;
          }
          if (/[\\\\/]/.test(nuevaCarp)) {
            alert('El nombre no debe contener "/" ni "\\\\".');
            return;
          }

          nuevaRuta = (rutaActual.replace(/\\/?$/, '/')) + nuevaCarp.replace(/^\\/+|\\/+$/g, '') + '/';
        }'''
new = '''        if (nuevaRuta === '__crear__') {
          if (!nuevaCarp) {
            alert('Escribe el nombre de la nueva carpeta.');
            return;
          }
          if (/[\\\\/]/.test(nuevaCarp)) {
            alert('El nombre no debe contener "/" ni "\\\\".');
            return;
          }
        }'''
if old not in s:
    raise SystemExit('ARCHIVOS_CREATE_DESTINATION')
s = s.replace(old, new, 1)

old = '''          const body = new URLSearchParams({
            ruta_actual: rutaActual,
            archivos_json: archivosJSON,
            nueva_ruta: nuevaRuta
          });

          const { res, json, text } = await fetchJson(URL_MOVER, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body
          });

          if (!json) throw new Error(text || ('HTTP ' + res.status));
          if (!res.ok) throw new Error(json.error || json.mensaje || ('HTTP ' + res.status));
          if (!json.ok) throw new Error(json.error || json.mensaje || 'No se pudo mover.');'''
new = '''          if (!window.DriveMoveTasks || typeof window.DriveMoveTasks.start !== 'function') {
            throw new Error('El módulo de tareas de movimiento no está disponible.');
          }

          const taskPayload = {
            type: 'files',
            ruta_actual: rutaActual,
            archivos_json: archivosJSON,
            nueva_ruta: nuevaRuta
          };

          if (nuevaRuta === '__crear__') {
            taskPayload.nueva_carpeta = nuevaCarp;
          }

          await window.DriveMoveTasks.start(taskPayload);'''
if old not in s:
    raise SystemExit('ARCHIVOS_MOVE_REQUEST')
s = s.replace(old, new, 1)

old = '''          if (typeof window.actualizarBloqueCarpetas === 'function') {
            await window.actualizarBloqueCarpetas();
          }

          if (typeof window.actualizarBloqueArchivos === 'function') {
            await window.actualizarBloqueArchivos({ pagina: 1 });
          }'''
new = '''          // El refresco se realiza al terminar la tarea, no al aceptarla.
          // Así el usuario puede seguir usando el Drive mientras S3 completa el movimiento.'''
if old not in s:
    raise SystemExit('ARCHIVOS_EAGER_REFRESH')
s = s.replace(old, new, 1)
p.write_text(s)


# ------------------------------------------------------------------
# carpetas.js: readable destinations + background task
# ------------------------------------------------------------------
p = Path('drive/js/carpetas.js')
s = p.read_text()

old = '''      async function cargarDestinosMover(origen) {
        const res = await fetchNoCache(CFG.urls.listarCarpetas, { method: 'GET' });
        const j = await res.json().catch(() => ({}));
        if (!j.ok || !Array.isArray(j.carpetas)) throw new Error(j.error || 'No se pudieron cargar las carpetas.');

        // tu mover-carpeta.js re-ordenaba con localeCompare
        j.carpetas.sort((a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' }));
        return j.carpetas;
      }'''
new = '''      async function cargarDestinosMover(origen) {
        const res = await fetchNoCache(CFG.urls.listarCarpetas, { method: 'GET' });
        const j = await res.json().catch(() => ({}));
        if (!j.ok) throw new Error(j.error || 'No se pudieron cargar las carpetas.');

        const lista = Array.isArray(j.destinos)
          ? j.destinos
          : (Array.isArray(j.carpetas) ? j.carpetas.map((ruta) => ({ value: ruta, label: ruta })) : []);

        lista.sort((a, b) => String(a.label || '').localeCompare(String(b.label || ''), undefined, { numeric: true, sensitivity: 'base' }));
        return lista;
      }'''
if old not in s:
    raise SystemExit('CARPETAS_LOAD_DESTINATIONS')
s = s.replace(old, new, 1)

old = '''        (lista || []).forEach((ruta) => {
          ruta = String(ruta || '');
          // No permitir mover dentro de sí misma o hijos
          if (!ruta) return;
          if (ruta === ban || ruta.startsWith(ban)) return;

          const opt = document.createElement('option');
          opt.value = ruta;

          // Indent como tu script (aprox por profundidad de "/")
          const depth = Math.max(0, ((ruta.match(/\\//g) || []).length - 1));
          opt.innerHTML = '&nbsp;'.repeat(depth * 3) + ruta;

          selectEl.appendChild(opt);
        });'''
new = '''        (lista || []).forEach((item) => {
          const ruta = String((item && item.value) || '');
          const label = String((item && item.label) || '');
          // No permitir mover dentro de sí misma o hijos. Se compara el Prefix interno.
          if (!ruta) return;
          if (ruta === ban || ruta.startsWith(ban)) return;

          const opt = document.createElement('option');
          opt.value = ruta;
          opt.textContent = label || ruta;
          selectEl.appendChild(opt);
        });'''
if old not in s:
    raise SystemExit('CARPETAS_RENDER_DESTINATIONS')
s = s.replace(old, new, 1)

old = '''      function updatePreviewMover(origen, destino, previewEl) {
        if (!previewEl) return;
        const org = String(origen || '').trim();
        const dst = String(destino || '').trim();
        const bn = baseNameOf(org);
        safeSetText(previewEl, dst ? (dst + bn + '/') : '');
      }'''
new = '''      function updatePreviewMover(origen, destino, previewEl) {
        if (!previewEl) return;
        const selectEl = $id(CFG.ids.moverDestino);
        const selected = selectEl && selectEl.selectedIndex >= 0
          ? selectEl.options[selectEl.selectedIndex]
          : null;
        const label = selected ? String(selected.textContent || '').trim() : '';
        safeSetText(previewEl, label);
      }'''
if old not in s:
    raise SystemExit('CARPETAS_PREVIEW')
s = s.replace(old, new, 1)

old = "        safeSetText($label, origen || '');"
new = "        safeSetText($label, nombre || '');"
if old not in s:
    raise SystemExit('CARPETAS_ORIGIN_LABEL')
s = s.replace(old, new, 1)

old = '''        const res = await fetchNoCache(CFG.urls.moverCarpeta, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: toFormUrlEncoded({ origen, destino })
        });

        const j = await res.json().catch(() => ({}));

        if (!res.ok || !j || !j.ok) {
          throw new Error((j && j.error) ? j.error : 'No se pudo mover la carpeta.');
        }

        const rutaActualizada = String(j.ruta_actual || window.rutaActual || '').trim();

        if (rutaActualizada) {
          window.rutaActual = rutaActualizada;
        }

        safeHideModal(modal);

        await refrescarBloqueCarpetasCompat({
          ruta_actual: rutaActualizada
        });

        await refrescarBloqueArchivosCompat({
          pagina: 1,
          ruta: rutaActualizada || undefined
        });

        if (typeof window.actualizarBloqueFooter === 'function') {
          try {
            await window.actualizarBloqueFooter({
              pagina: 1,
              ruta: rutaActualizada,
              rutaNueva: rutaActualizada
            });
          } catch (_) {}
        }'''
new = '''        if (!window.DriveMoveTasks || typeof window.DriveMoveTasks.start !== 'function') {
          throw new Error('El módulo de tareas de movimiento no está disponible.');
        }

        await window.DriveMoveTasks.start({
          type: 'folder',
          origen,
          destino
        });

        safeHideModal(modal);'''
if old not in s:
    raise SystemExit('CARPETAS_MOVE_REQUEST')
s = s.replace(old, new, 1)
p.write_text(s)

print('MOVE_TASK_PATCH_OK')
