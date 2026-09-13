# Amazon Polly TTS en segundo plano

## Objetivo

La generación de voz desde archivos TXT/MD ya no debe bloquear el modal ni depender exclusivamente de `SynthesizeSpeech`.

El flujo usa dos modos:

1. **Guardar en S3 activado**: `StartSpeechSynthesisTask` genera el audio de forma asíncrona.
2. **Guardar en S3 desactivado**: se conserva `SynthesizeSpeech` para texto corto y reproducción/descarga inmediata.

## Problemas corregidos

- `TextLengthExceededException` al enviar textos mayores al límite de `SynthesizeSpeech`.
- selección incompatible entre voz y motor `standard`/`neural`.
- modal bloqueado mientras se genera el audio.
- falta de aviso al terminar la generación.
- salida de Polly con sufijo de `TaskId` en lugar del nombre visible esperado por Drive.

## Flujo asíncrono

```text
TXT/MD
  -> polly_tts.php
  -> AwsFileController
  -> PollyFileService::synthesize()
  -> StartSpeechSynthesisTask
  -> S3: carpeta/.polly/base.<task-id>.<ext>

Navegador
  -> guarda task_id + from_key en localStorage
  -> cierra el modal
  -> usuario sigue trabajando
  -> polly_task_status.php
  -> PollyTaskController
  -> PollyFileService::taskStatus()
  -> GetSpeechSynthesisTask

cuando completed:
  -> COPY objeto temporal a carpeta/base.<ext>
  -> DELETE temporal
  -> UPSERT FileS3
  -> refrescar bloque de archivos
  -> notificación "Audio listo"
```

No se agregó ninguna tabla nueva. `FileS3` sigue siendo la fuente de verdad del catálogo local y S3 sigue siendo el almacenamiento físico.

## Nombre y carpeta

El audio final usa:

- la **misma carpeta** del archivo origen;
- el **mismo nombre visible sin la extensión de texto**;
- la extensión elegida: `.mp3`, `.ogg` o `.pcm`;
- el mismo basename físico del objeto origen para conservar el modelo de claves actual.

Ejemplo visible:

```text
DEL VERBO HECHO CARNE AL CORDERO GLORIFICADO.txt
DEL VERBO HECHO CARNE AL CORDERO GLORIFICADO.mp3
```

## Persistencia del trabajo en el navegador

`drive/js/polly-background.js` conserva únicamente datos de seguimiento en `localStorage`:

- `task_id`;
- `from_key`;
- nombre/ruta esperados;
- fecha de creación.

No almacena el texto completo.

Esto permite cerrar el modal, continuar usando Drive y volver a consultar tareas pendientes después de recargar la página. Las referencias locales se conservan como máximo siete días.

## Voz y motor

La lista de voces usa `SupportedEngines` devuelto por Polly.

El cliente intenta mantener una combinación válida:

- al cambiar motor, busca una voz compatible;
- al cambiar voz, selecciona `neural` o `standard` si corresponde;
- el backend vuelve a validar la combinación antes de llamar a Polly.

No existe fallback silencioso de Neural a Standard en el backend. La voz que se genera corresponde al motor mostrado al usuario.

## Límites aplicados

- modo inmediato (`SynthesizeSpeech`): hasta 3,000 caracteres facturables;
- modo asíncrono (`StartSpeechSynthesisTask`): hasta 100,000 caracteres facturables.

Para texto largo, el cliente activa automáticamente **Guardar en S3** cuando estaba desactivado, de modo que la generación pase a segundo plano.

## Costos

La solicitud inicial sigue registrando caracteres de Polly con el motor realmente utilizado.

Al finalizar se registran las operaciones S3 realizadas por ArcadeCloud Drive para normalizar el archivo:

- HEAD;
- COPY;
- DELETE;
- almacenamiento del audio final.

La escritura que Amazon Polly hace directamente en el bucket forma parte del trabajo asíncrono del servicio y no se duplica como un PUT manual de Drive.

## Permisos AWS necesarios

La identidad usada por la aplicación debe conservar los permisos existentes y permitir, como mínimo:

```text
polly:DescribeVoices
polly:SynthesizeSpeech
polly:StartSpeechSynthesisTask
polly:GetSpeechSynthesisTask
```

Además necesita acceso al bucket para las operaciones usadas por la aplicación, entre ellas lectura de metadatos, copia y eliminación del objeto temporal/finalización. El bucket de salida debe estar en una región compatible con el cliente Polly configurado.

## Archivos del cambio

```text
drive/src/Aws/PollyFileService.php
drive/src/Http/Controller/PollyTaskController.php
drive/polly_task_status.php
drive/js/polly-background.js
drive/js/move-tasks.js
.github/workflows/activity-costs.yml
```

## Prueba manual recomendada

1. Abrir un TXT/MD pequeño, seleccionar una voz Neural compatible y generar con **Guardar en S3** activo.
2. Confirmar que el modal se cierra y se muestra el aviso de trabajo en segundo plano.
3. Seguir navegando en Drive.
4. Confirmar el aviso final y que aparece el audio en la misma carpeta con el mismo nombre visible.
5. Repetir con Standard y verificar que se selecciona una voz compatible.
6. Repetir con un texto mayor a 3,000 caracteres y comprobar que no aparece `TextLengthExceededException`.
7. Recargar la página mientras un audio sigue en proceso y comprobar que el seguimiento se reanuda.
