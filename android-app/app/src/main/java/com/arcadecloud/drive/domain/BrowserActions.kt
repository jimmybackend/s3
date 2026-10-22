package com.arcadecloud.drive.domain

data class BrowserAction(val id: String, val label: String)
object BrowserActions {
    val text = setOf("txt","md","markdown","html","htm","css","js","mjs","php","phtml","py","json","csv","sql","jas","xml","yaml","yml","ini","cfg","conf","log","srt","vtt")
    val images = setOf("jpg","jpeg","png","webp","gif","bmp","heic")
    val audio = setOf("mp3","wav","ogg","opus","m4a","aac","flac","amr")
    val video = setOf("mp4","webm","mov","avi","mkv","m4v","ogv")
    fun files(file: DriveFile): List<BrowserAction> = buildList {
        fun action(id: String, label: String) { add(BrowserAction(id,label)) }
        action("details","Detalles y propiedades"); action("fileTasks","Tareas del archivo")
        if (file.locked) { action("unlock","Desbloquear con contraseña"); return@buildList }
        val ext = file.name.substringAfterLast('.',"").lowercase()
        action("preview","Abrir"); action("openWith","Abrir con otra aplicación")
        if (ext in setOf("doc","docx","xls","xlsx","ppt","pptx","odt","ods","odp")) action("openWithOffice","Abrir con Office de Android")
        if (ext == "zip") action("zip","Explorar contenido ZIP")
        if (ext in text) action("editText","Editar texto")
        action("download","Descargar"); action("rename","Cambiar nombre")
        action("move","Mover a otra carpeta"); action("copy","Copiar a otra carpeta")
        action("clipboardCopy","Copiar"); action("clipboardCut","Cortar")
        action("share","Compartir enlace ArcadeLink")
        if (ext in images) action("wallpaper","Preparar fondo de Android")
        if (ext in setOf("jpg","jpeg","png","pdf","tif","tiff")) action("textract","Textract · Extraer texto")
        if (ext in text || ext in setOf("jpg","jpeg","png","pdf")) action("translate","Translate · Traducir")
        if (ext in setOf("jpg","jpeg","png")) action("rekognition","Rekognition · Analizar imagen")
        if (ext in text) { action("polly","Polly · Crear audio"); action("comprehend","Comprehend · Analizar texto") }
        if (ext in audio || ext in video) action("transcribe","Transcribe · Audio a texto")
        if (ext in video) { action("split_video","Dividir video"); action("extract_mp3","Extraer MP3") }
        if (ext in audio) action("split_audio","Dividir audio")
        if (file.protected) { action("relock","Bloquear de nuevo"); action("unsecure","Quitar protección") }
        else action("lock","Bloquear con contraseña")
        action("delete","Eliminar archivo")
    }
    fun folders(isRoot: Boolean, hasClipboard: Boolean): List<BrowserAction> = buildList {
        add(BrowserAction("openFolder","Abrir carpeta")); add(BrowserAction("folderDetails","Propiedades de carpeta"))
        add(BrowserAction("createText","Nuevo archivo de texto")); add(BrowserAction("pasteText","Crear desde texto pegado"))
        add(BrowserAction("create","Nueva subcarpeta")); add(BrowserAction("photo","Tomar fotografía"))
        add(BrowserAction("video","Grabar video")); add(BrowserAction("audio","Grabar nota de voz"))
        if (hasClipboard) add(BrowserAction("paste","Pegar dentro"))
        add(BrowserAction("folderTextract","Extraer texto de documentos")); add(BrowserAction("sync","Sincronizar desde S3"))
        if (!isRoot) {
            add(BrowserAction("moveFolder","Mover carpeta")); add(BrowserAction("copyFolder","Copiar carpeta a otra ubicación"))
            add(BrowserAction("folderClipboardCopy","Copiar carpeta")); add(BrowserAction("folderClipboardCut","Cortar carpeta"))
            add(BrowserAction("renameFolder","Editar nombre")); add(BrowserAction("deleteFolder","Eliminar carpeta"))
        }
    }
}
object ApiOutcome {
    fun successful(hasOk: Boolean, ok: Boolean, estado: String): Boolean =
        if (hasOk) ok && estado != "error" else estado == "ok"
}

// Only references are held locally; the existing server task validates ownership and destination.
data class DriveClipboard(val operation: String, val keys: List<String> = emptyList(), val folder: String? = null) {
    fun fields(destination: String): Map<String,String> {
        require(operation in listOf("copy","move"))
        return if(folder != null) mapOf("type" to "folder", "operation" to operation, "origen" to folder, "destino" to destination)
        else mapOf("type" to "files", "operation" to operation, "archivos_json" to keys.joinToString(",", "[", "]") { org.json.JSONObject.quote(it) }, "nueva_ruta" to destination)
    }
}
