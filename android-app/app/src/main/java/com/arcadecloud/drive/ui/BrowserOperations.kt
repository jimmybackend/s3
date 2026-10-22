package com.arcadecloud.drive.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.arcadecloud.drive.domain.*
import com.arcadecloud.drive.media.MediaPreview
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun BrowserOperationDialog(vm: DriveViewModel, operation: String, file: DriveFile?, folder: Folder,
    selected: List<DriveFile>, onRefresh: ()->Unit, onDismiss: ()->Unit) {
    val scope = rememberCoroutineScope(); val context = LocalContext.current
    var busy by remember { mutableStateOf(false) }; var error by remember { mutableStateOf<String?>(null) }
    var result by remember { mutableStateOf<String?>(null) }; var link by remember { mutableStateOf("") }
    var name by remember { mutableStateOf(if(operation=="rename") file?.name ?: "" else if(operation=="renameFolder") folder.name else "") }
    var content by remember { mutableStateOf("") }; var format by remember { mutableStateOf("txt") }
    var password by remember { mutableStateOf("") }; var confirm by remember { mutableStateOf("") }; var hint by remember { mutableStateOf("") }
    var language by remember { mutableStateOf(if(operation=="transcribe") "es-ES" else "es") }
    var parts by remember { mutableStateOf("2") }; var voices by remember { mutableStateOf(emptyList<JSONObject>()) }
    var voice by remember { mutableStateOf<JSONObject?>(null) }; var previewUrl by remember { mutableStateOf("") }
    var ready by remember { mutableStateOf(operation !in listOf("editText","polly","preview","fileTasks")) }
    fun perform(block: suspend ()->Unit) {
        if(busy) return
        busy=true;error=null
        scope.launch { try { block() } catch(e: Exception) {
            if(e is SessionExpired) vm.run { throw e }
            error=e.message ?: "No se pudo completar la operación"
        } finally { busy=false } }
    }
    suspend fun post(path: String, fields: Map<String,String>): JSONObject = vm.repo.request(path,fields)
    fun completed(json: JSONObject, fallback: String) {
        result = readableResult(json).ifBlank { fallback }
        onRefresh(); vm.refreshTasks()
    }
    LaunchedEffect(operation) {
        when(operation) {
            "editText","polly" -> perform {
                check((file?.bytes ?: 0) <= 2*1024*1024) { "El editor móvil admite hasta 2 MB. Descarga este archivo para abrirlo en otra aplicación." }
                val j=vm.repo.request(if(operation=="polly") "polly_cargar_texto.php" else "leer_texto.php?archivo=${enc(file!!.key)}",
                    if(operation=="polly") mapOf("archivo" to file!!.key) else null)
                content=if(operation=="polly") j.optString("texto") else j.getJSONObject("data").getString("contenido")
                if(operation=="polly") {
                    val a=vm.repo.request("polly_list_voices.php").getJSONArray("voices")
                    voices=(0 until a.length()).map{a.getJSONObject(it)};voice=voices.firstOrNull { it.optString("LanguageCode").startsWith("es") } ?: voices.firstOrNull()
                }
                ready=true
            }
            "preview" -> perform {
                val ext=file!!.name.substringAfterLast('.',"").lowercase()
                if(ext in BrowserActions.images || ext in BrowserActions.audio || ext in BrowserActions.video) previewUrl=vm.repo.signedDownload(file.key)
                else { vm.download(file); result="Descarga iniciada. En Tareas podrás abrir el documento con una aplicación de Android." }
                ready=true
            }
            "fileTasks" -> perform {
                val a=vm.repo.request("background_tasks.php").getJSONArray("tasks")
                val rows=(0 until a.length()).map{a.getJSONObject(it)}.filter { it.toString().contains(file!!.key) || it.optString("title").contains(file.name) }
                result=if(rows.isEmpty()) "No se encontraron tareas asociadas en el listado del servidor." else rows.joinToString("\n\n") { "${it.optString("title")}\n${it.optString("status")} · ${it.optString("detail")}" };ready=true
            }
        }
    }
    val moving=operation in listOf("move","copy","moveFolder","copyFolder","moveMany","copyMany")
    if(moving && result==null) {
        DestinationDialog(vm,vm.state.value.root,{ destination -> perform {
            val item=if(operation.endsWith("Folder")) DriveClipboard(if(operation=="copyFolder")"copy" else "move",folder=folder.route)
                else DriveClipboard(if(operation.startsWith("copy"))"copy" else "move",keys=if(operation.endsWith("Many"))selected.map{it.key} else listOf(file!!.key))
            val j=post("move_task.php",item.fields(destination));vm.notice(j.optString("mensaje","Operación enviada a Tareas; todavía no ha finalizado."));vm.refreshTasks();onDismiss()
        } },onDismiss,if(operation.startsWith("copy"))"Copiar aquí" else "Mover aquí")
        if(error!=null) AlertDialog(onDismissRequest={error=null},title={Text("No se pudo iniciar")},text={Text(error!!)},confirmButton={TextButton(onClick={error=null}){Text("Aceptar")}})
        return
    }
    if(operation=="zip") { NativeZipDialog(vm,file!!,onDismiss);return }
    val label=when(operation) {
        "create"->"Nueva subcarpeta";"createText"->"Nuevo archivo de texto";"pasteText"->"Crear desde texto pegado"
        "rename","renameFolder"->"Cambiar nombre";"delete","deleteFolder","deleteMany"->"Confirmar eliminación"
        "editText"->"Editar texto";"details"->"Detalles del archivo";"folderDetails"->"Propiedades de carpeta"
        "lock"->"Proteger archivo";"unlock"->"Desbloquear archivo";"unsecure"->"Quitar protección";"relock"->"Bloquear de nuevo"
        "share"->"Compartir enlace ArcadeLink";"preview"->"Abrir archivo";"fileTasks"->"Tareas del archivo"
        else->(BrowserActions.files(file ?: DriveFile(0,"", "","",0,"",false,"")).find{it.id==operation}?.label
            ?: BrowserActions.folders(false,false).find{it.id==operation}?.label ?: "Acción del Drive")
    }
    val immediate = operation in listOf("details","folderDetails","preview","fileTasks")
    AlertDialog(onDismissRequest={if(!busy)onDismiss()},title={Text(label)},text={
        Column(Modifier.fillMaxWidth().heightIn(max=450.dp).verticalScroll(rememberScrollState()),verticalArrangement=Arrangement.spacedBy(10.dp)) {
            Text(if(operation in listOf("create","createText","pasteText","sync","folderTextract","folderDetails","deleteFolder","renameFolder"))folder.name else file?.name ?: "${selected.size} archivos")
            if(busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            error?.let {Text(it,color=MaterialTheme.colorScheme.error)}
            when {
                result!=null -> { Text(result!!); if(link.isNotEmpty()) {
                    OutlinedTextField(link,{},readOnly=true,label={Text("Enlace compartido")})
                    TextButton(onClick={context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT,link),"Compartir enlace"))}){Text("Compartir con Android")}
                } }
                operation=="details" -> {
                    Text("Nombre: ${file!!.name}\nTamaño: ${bytes(file.bytes)}\nFecha: ${file.date}\nRuta: ${file.route}\nClave de almacenamiento: ${file.key}\nProtegido: ${if(file.protected)"Sí" else "No"}")
                    if(file.metadata.isNotBlank()) Text(file.metadata,style=MaterialTheme.typography.bodySmall)
                }
                operation=="folderDetails" -> Text("Nombre: ${folder.name}\nRuta: ${folder.route}\nEl tamaño total no está disponible sin consultar el contenido completo.")
                operation=="preview" && previewUrl.isNotEmpty() -> {
                    if(file!!.name.substringAfterLast('.',"").lowercase() in BrowserActions.images) AsyncImage(previewUrl,file.name,Modifier.fillMaxWidth().heightIn(max=330.dp))
                    else MediaPreview(previewUrl,Modifier.fillMaxWidth().height(260.dp))
                }
                else -> {
                    if(operation in listOf("create","rename","renameFolder","createText","pasteText")) OutlinedTextField(name,{name=it},label={Text("Nombre")},enabled=!busy,singleLine=true)
                    if(operation in listOf("createText","pasteText")) Row {
                        listOf("txt","md","html").forEach { item -> FilterChip(selected=format==item,onClick={format=item},label={Text(item)},enabled=!busy) }
                    }
                    if(operation in listOf("editText","pasteText","polly")) OutlinedTextField(content,{content=it},label={Text("Contenido")},modifier=Modifier.fillMaxWidth().heightIn(min=140.dp,max=280.dp),enabled=ready&&!busy)
                    if(operation in listOf("lock","unlock")) OutlinedTextField(password,{password=it},label={Text("Contraseña")},visualTransformation=PasswordVisualTransformation(),keyboardOptions=KeyboardOptions(autoCorrectEnabled=false,keyboardType=KeyboardType.Password),enabled=!busy)
                    if(operation=="lock") {
                        OutlinedTextField(confirm,{confirm=it},label={Text("Confirmar contraseña")},visualTransformation=PasswordVisualTransformation(),keyboardOptions=KeyboardOptions(autoCorrectEnabled=false,keyboardType=KeyboardType.Password),enabled=!busy)
                        OutlinedTextField(hint,{hint=it},label={Text("Pista (opcional)")},enabled=!busy)
                    }
                    if(operation in listOf("translate","transcribe")) OutlinedTextField(language,{language=it},label={Text(if(operation=="translate")"Idioma destino (es, en…)" else "Idioma del audio (es-ES…)")},singleLine=true,enabled=!busy)
                    if(operation=="share") { Text("Cualquier persona con el enlace podrá acceder durante su vigencia.");OutlinedTextField(parts,{parts=it},label={Text("Días de vigencia")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),enabled=!busy) }
                    if(operation in listOf("split_audio","split_video")) OutlinedTextField(parts,{parts=it},label={Text("Número de partes (2 a 50)")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),enabled=!busy)
                    if(operation=="polly") {
                        Text("Voz seleccionada: ${voice?.optString("Name") ?: "Cargando voces…"}")
                        Column(Modifier.heightIn(max=130.dp).verticalScroll(rememberScrollState())) { voices.forEach { item ->
                            TextButton(onClick={voice=item},enabled=!busy) {Text("${if(voice==item)"✓ " else ""}${item.optString("Name")} · ${item.optString("LanguageCode")}")}
                        } }
                    }
                    if(operation.startsWith("delete")) Text("Se eliminará del Drive y de S3 ${if(operation=="deleteFolder")"esta carpeta y todo su contenido" else if(operation=="deleteMany")"la selección" else "este archivo"}. Esta acción no se puede deshacer.")
                    if(operation in listOf("textract","translate","transcribe","rekognition","comprehend","polly","folderTextract")) Text("Esta operación utiliza el servicio existente de AWS y registra actividad y costos.")
                    if(operation in listOf("split_audio","split_video","extract_mp3")) Text("Se usará el nodo multimedia si está disponible. Esta solicitud no autoriza encender una EC2 detenida.")
                    if(operation in listOf("openWith","openWithOffice","download","wallpaper")) Text(if(operation=="wallpaper")"Se descargará la imagen. Después elige Fondo de Android desde Tareas." else "Se descargará el archivo. Desde Tareas podrás abrirlo o elegir una aplicación de Android.")
                    if(operation=="sync") Text("Se enviará la sincronización existente de esta carpeta a Tareas.")
                    if(operation=="relock") Text("El archivo volverá a solicitar su contraseña para abrirse.")
                    if(operation=="unsecure") Text("El archivo dejará de requerir contraseña. Debe estar desbloqueado en esta sesión.")
                }
            }
        }
    },confirmButton={
        if(result!=null || immediate) TextButton(onClick=onDismiss,enabled=!busy){Text("Cerrar")}
        else TextButton(enabled=!busy&&ready,onClick={ perform {
            val j=when(operation) {
                "create" -> post("crear_carpeta.php",mapOf("ruta" to folder.route,"nueva" to name))
                "rename" -> post("renombrar_archivo.php",mapOf("key" to file!!.key,"nombre_nuevo" to name))
                "renameFolder" -> post("renombrar_carpeta.php",mapOf("ruta" to folder.route,"nuevo" to name))
                "delete" -> post("eliminar_archivo.php",mapOf("file_id" to "${file!!.id}"))
                "deleteMany" -> post("delete_multiple.php",mapOf("archivos_json" to JSONArray(selected.map{it.key}).toString()))
                "deleteFolder" -> post("eliminar_carpeta.php",mapOf("ruta" to folder.route))
                "createText","pasteText" -> post("create_folder_document.php",mapOf("route" to folder.route,"name" to name,"format" to format,"create_empty" to if(operation=="createText")"1" else "0","plain_text" to content,"html_content" to if(format=="html")content else ""))
                "editText" -> post("guardar_texto.php",mapOf("archivo" to file!!.key,"contenido" to content))
                "lock" -> { check(password.isNotEmpty() && password==confirm){"Las contraseñas deben coincidir y no estar vacías."};post("set_file_security.php",mapOf("key" to file!!.key,"mode" to "secure","password" to password,"secure_hint" to hint)) }
                "unlock" -> post("unlock_file.php",mapOf("key" to file!!.key,"password" to password))
                "relock" -> post("relock_file.php",mapOf("key" to file!!.key))
                "unsecure" -> post("set_file_security.php",mapOf("key" to file!!.key,"mode" to "normal"))
                "share" -> { val days=parts.toIntOrNull() ?: error("Indica días válidos");check(days in 1..3650);post("generar_token.php",mapOf("archivo" to file!!.key,"tipo" to "otro","dias" to "$days")).also { link=it.getString("url");check(Uri.parse(link).scheme=="https"){"El servidor devolvió un enlace sin HTTPS."} } }
                "sync" -> post("sync_s3_to_db.php",mapOf("prefix" to folder.route))
                "folderTextract" -> post("procesar_textract_carpeta.php",mapOf("ruta" to folder.route,"nombre" to folder.name))
                "textract" -> post("procesar_textract.php",mapOf("archivo" to file!!.key))
                "translate" -> post("traducir_archivo.php",mapOf("archivo" to file!!.key,"source" to "auto","target" to language))
                "transcribe" -> post("transcribir_iniciar.php",mapOf("archivo" to file!!.key,"languageMode" to "specific","languageCode" to language))
                "rekognition" -> post("rekognition_labels.php",mapOf("archivo" to file!!.key))
                "comprehend" -> post("comprehend_archivo.php",mapOf("key" to file!!.key))
                "polly" -> {val v=voice ?: error("Elige una voz"); val engines=v.optJSONArray("SupportedEngines");val engine=if(engines!=null && engines.length()>0)engines.getString(0) else "standard";post("polly_tts.php",mapOf("texto" to content,"from_key" to file!!.key,"voiceId" to v.getString("Id"),"engine" to engine,"to_s3" to "1","format" to "mp3"))}
                "split_audio","split_video","extract_mp3" -> {val n=if(operation=="extract_mp3")1 else parts.toIntOrNull() ?: error("Indica un número de partes");check(operation=="extract_mp3"||n in 2..50);post("media_processing.php",mapOf("archivo" to file!!.key,"operation" to operation,"parts" to "$n","overlap_before" to "3","overlap_after" to "3","authorize_node_start" to "0"))}
                "openWith","openWithOffice","download","wallpaper" -> {vm.download(file!!);JSONObject().put("ok",true).put("message","Descarga solicitada. Consulta Tareas para ver el progreso y abrir el archivo cuando termine.")}
                else -> error("Acción no reconocida")
            }
            password="";confirm="";completed(j,"Operación realizada.")
        } }){Text(if(operation.startsWith("delete"))"Eliminar" else if(operation in listOf("textract","translate","transcribe","rekognition","comprehend","polly","folderTextract"))"Ejecutar" else "Aceptar")}
    },dismissButton={if(result==null&&!immediate)TextButton(onClick=onDismiss,enabled=!busy){Text("Cancelar")}})
}
internal fun enc(value: String)=java.net.URLEncoder.encode(value,"UTF-8")
internal fun readableResult(j: JSONObject): String = buildList {
    listOf("message","mensaje","msg","traduccion","texto","text").forEach { key ->
        if(j.has(key)) { val v=j.opt(key);add(if(v is JSONArray)(0 until v.length()).joinToString("\n"){v.optString(it)} else v.toString()) }
    }
    if(j.has("job_id") || j.has("task_id") || j.has("jobName") || j.has("job")) add("Solicitud registrada. Consulta Tareas para seguir su estado; puede continuar en cola o en proceso.")
    listOf("labels" to "Etiquetas", "moderation" to "Moderación", "key_phrases" to "Frases clave", "entities" to "Entidades").forEach { (key,title) ->
        j.optJSONArray(key)?.let { a -> if(a.length()>0)add("$title: "+(0 until a.length()).joinToString(", "){i->a.optJSONObject(i)?.let{it.optString("Name",it.optString("Text",it.optString("text")))} ?: a.optString(i)}) }
    }
    if(j.has("sentiment")) add("Sentimiento: ${j.optString("sentiment")}")
    if(j.has("language")) add("Idioma: ${j.optString("language")}")
}.filter{it.isNotBlank()}.distinct().joinToString("\n\n")
