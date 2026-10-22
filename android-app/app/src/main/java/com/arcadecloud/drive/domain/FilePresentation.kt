package com.arcadecloud.drive.domain

// Extension groups and priority match drive/src/View/FileIconResolver.php.
data class FileKind(val category: String, val label: String)
object FilePresentation {
    private val groups = listOf(
        Triple("pdf", "PDF", setOf("pdf")),
        Triple("word", "Documento", setOf("doc","docx","docm","dot","dotx","odt","rtf")),
        Triple("excel", "Hoja de cálculo", setOf("xls","xlsx","xlsm","xlsb","xlt","xltx","ods","csv")),
        Triple("powerpoint", "Presentación", setOf("ppt","pptx","pptm","pps","ppsx","odp")),
        Triple("archive", "Archivo comprimido", setOf("zip","rar","7z","tar","gz","gzip","tgz","bz","bz2","xz","zst","cab","jar","war")),
        Triple("text", "Texto", setOf("txt","jas","log","md","markdown","ini","cfg","conf","properties","nfo","srt","vtt")),
        Triple("code", "Código", setOf("php","phtml","phar","js","mjs","cjs","ts","tsx","jsx","html","htm","xhtml","css","scss","sass","less","json","jsonl","xml","xsl","yaml","yml","toml","sql","py","pyw","java","class","c","cc","cpp","cxx","h","hpp","cs","go","rs","rb","swift","kt","kts","dart","lua","sh","bash","zsh","fish","ps1","bat","cmd","vue","svelte","graphql","gql","env")),
        Triple("image", "Imagen", setOf("jpg","jpeg","png","gif","webp","bmp","avif","heic","heif","tif","tiff","svg")),
        Triple("audio", "Audio", setOf("mp3","wav","ogg","opus","m4a","aac","flac","wma","aiff","aif","mid","midi")),
        Triple("video", "Video", setOf("mp4","webm","mov","avi","mkv","m4v","mpeg","mpg","wmv","flv","3gp","ts","mts","m2ts")),
        Triple("database", "Base de datos", setOf("db","db3","sqlite","sqlite3","mdb","accdb","bak","dump")),
        Triple("ebook", "Libro electrónico", setOf("epub","mobi","azw","azw3","fb2")),
        Triple("mail", "Correo", setOf("eml","msg","mbox","pst","ost")),
        Triple("font", "Fuente", setOf("ttf","otf","woff","woff2","eot")),
        Triple("certificate", "Certificado / clave", setOf("pem","crt","cer","key","pfx","p12","csr","der")),
        Triple("package", "Paquete / ejecutable", setOf("apk","aab","ipa","exe","msi","dmg","pkg","deb","rpm","appimage","bin","iso")),
        Triple("design", "Diseño", setOf("psd","psb","ai","eps","indd","sketch","fig")),
        Triple("model", "CAD / 3D", setOf("dwg","dxf","stl","obj","fbx","blend","gltf","glb","step","stp","iges","igs")),
    )
    fun kind(name: String): FileKind {
        val ext = name.substringAfterLast('.', "").lowercase(java.util.Locale.ROOT)
        val group = groups.firstOrNull { ext in it.third }
        return if(group != null) FileKind(group.first,group.second) else FileKind("generic",ext.uppercase(java.util.Locale.ROOT).ifBlank { "Archivo" })
    }
    fun thumbnailAllowed(file: DriveFile) = !file.locked && kind(file.name).category == "image"
}
