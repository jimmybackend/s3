# Formato visual IA de hojas de extracción

`token_texto.php?t=TOKEN&Extraccion=1` (o JSON detectado sin parámetros) formatea únicamente las hojas visibles. No precarga el libro ni programa trabajos de todo el documento. El navegador solicita cada índice visible, secuencialmente; el servidor vuelve a validar el token y carga la hoja original. No admite texto arbitrario ni usuario de facturación desde el cliente. Los enlaces privados requieren su sesión normal; los públicos no necesitan iniciar sesión.

Amazon Bedrock Converse usa `amazon.nova-micro-v1:0`. El prompt está en `src/Book/BookAiLayoutService.php`: clasifica índices de líneas mediante tipos, alineaciones y negrita permitidos. El modelo nunca aporta texto de reemplazo ni HTML. La vista reconstruye las líneas originales y conserva mayúsculas, espacios, puntuación, líneas vacías y orden. Los saltos CRLF se representan como saltos de línea del navegador. Las líneas completamente mayúsculas se marcan en negrita; la animación revela cada línea de izquierda a derecha y respeta reducción de movimiento. El JSON/S3 original no se modifica.

## Consumo y trazabilidad

- Se atribuye `book_ai_layout` en Actividad y costos al usuario **1**, con actor **1**, incluso para visitantes públicos. Se comprueba que ese usuario exista.
- Se registra una reserva obligatoria antes de Bedrock. Si la tabla existente `DriveActivityEvents` no está disponible, no se invoca el modelo. No hay migraciones nuevas.
- Los tokens de entrada y salida reales de `usage` se registran con unidades `bedrock.nova_micro_input_token` y `bedrock.nova_micro_output_token`. Se reutiliza la correlación única del esquema canónico al recuperar caché, evitando duplicación de costos.
- La respuesta de formato y su uso quedan guardados antes de confirmar el registro final. Si falla el registro, una siguiente consulta recupera ese registro pendiente sin invocar de nuevo al modelo. Un fallo/timeout sin `usage` deja estado `failed_usage_unknown`; no inventa tokens ni cargos.
- Caché por hash de texto y versión de prompt, compartida entre enlaces del mismo nodo. Guarda estilos e información de consumo, no el texto, tokens de acceso ni HTML. Regresar a una hoja ya vista no produce otra inferencia.
- Techos por nodo: **200 intentos nuevos diarios UTC**, **40 por enlace/hora**, **60 por IP/hora**. Los fallos consumen cupo. Un bloqueo de archivo serializa reservas y generación. Los procesos ocupados devuelven la vista original sin acumular trabajadores esperando. Límites de hoja: **24 000 bytes y 300 líneas**, salida máxima **3 000 tokens**, timeout de red **30 s**.
- `ARCADECLOUD_BOOK_AI_DAILY_LIMIT` permite bajar el techo de 200, incluso a **0** para desactivar nuevos cargos manteniendo la caché. No se puede superar 200 con esa variable.
- Almacenamiento local privado: `sys_get_temp_dir()/arcadecloud-book-layout-v1` (directorio 0700, archivos 0600). No es una cuota global entre servidores. Limpiar caché o usar otro nodo puede ocasionar una nueva inferencia, siempre sujeta a los techos de ese nodo. La caché temporal no sustituye la facturación AWS.

Si no hay permisos/modelo disponible, el límite está alcanzado o la IA falla, el lector conserva la hoja original y lo indica sin filtrar errores de AWS. Las respuestas del modelo no válidas usan estilos seguros con el consumo registrado. No se ha invocado Bedrock en las pruebas: usan respuestas simuladas. El despliegue y la primera llamada real requieren verificación posterior en producción.

Referencia de atribución añadida al catálogo: entrada USD 0.000035/1K tokens y salida USD 0.00014/1K, AWS oficial: https://aws.amazon.com/blogs/machine-learning/cost-efficient-custom-text-to-sql-using-amazon-nova-micro-and-amazon-bedrock-on-demand-inference/ . Son estimaciones; región, descuentos y factura efectiva pueden diferir.

## Tipografía local

El contenedor aplica estas reglas incluso sin respuesta IA: palabras completamente mayúsculas en negrita (también dentro de frases), alineación justificada de cuerpo y justificación de la última línea visual cuando el salto original continúa el párrafo. Las líneas que terminan en punto reciben separación de párrafo; no se insertan caracteres ni se dividen fechas/abreviaturas en mitad de una línea. Se conservan las líneas vacías y todos los caracteres originales. Los encabezados y las últimas líneas de párrafo mantienen espaciado natural para evitar palabras artificialmente estiradas. Estas reglas no requieren regenerar cachés ni invocar Bedrock.
