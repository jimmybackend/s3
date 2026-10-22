package com.arcadecloud.drive
import com.arcadecloud.drive.domain.*
import org.junit.Assert.*
import org.junit.Test
class FilePresentationTest {
    @Test fun majorFormatsAndUnknownNamesHaveDistinctTypes() {
        mapOf("acta.PDF" to "pdf","doc.docx" to "word","tabla.csv" to "excel","slides.pptx" to "powerpoint",
            "fotos.7z" to "archive","main.kt" to "code","nota.md" to "text","foto.HEIC" to "image",
            "audio.flac" to "audio","video.mp4" to "video","base.sqlite" to "database","libro.epub" to "ebook",
            "correo.eml" to "mail","fuente.woff2" to "font","clave.pem" to "certificate","app.apk" to "package",
            "plano.dwg" to "model","dibujo.psd" to "design","sin_extension" to "generic").forEach { (name,type) -> assertEquals(name,type,FilePresentation.kind(name).category) }
    }
    @Test fun ambiguousExtensionsRespectWebResolverPriority() {
        assertEquals("code",FilePresentation.kind("fuente.ts").category)
        assertEquals("excel",FilePresentation.kind("datos.csv").category)
        assertEquals("RARO",FilePresentation.kind("archivo.raro").label)
        assertEquals("Archivo",FilePresentation.kind("sin_extension").label)
    }
    @Test fun onlyUnlockedImagesMayRequestThumbnails() {
        val photo=DriveFile(1,"foto.jpg","Data2/a.jpg","Data2/",20,"2026",false,"")
        assertTrue(FilePresentation.thumbnailAllowed(photo))
        assertFalse(FilePresentation.thumbnailAllowed(photo.copy(locked=true)))
        assertFalse(FilePresentation.thumbnailAllowed(photo.copy(name="documento.pdf")))
        assertFalse(FilePresentation.thumbnailAllowed(photo.copy(name="image.jpg.exe")))
    }
}
