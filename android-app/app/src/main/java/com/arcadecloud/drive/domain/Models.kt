package com.arcadecloud.drive.domain

import java.net.URI

object NodePolicy {
    val nodes = listOf("https://drive.esforzados.com/", "https://fastdrive.esforzados.com/")
    fun requireNode(value: String): String = value.also { require(it in nodes) { "Nodo no autorizado" } }
    fun requireSignedUrl(value: String): String = value.also {
        val u = URI(it)
        require(u.scheme == "https" && u.userInfo == null && u.port in listOf(-1, 443))
        require(u.host != null && (u.host.endsWith(".amazonaws.com") || u.host.endsWith(".amazonaws.com.cn"))) { "Destino S3 inválido" }
    }
}
data class DriveFile(val id: Long, val name: String, val key: String, val route: String, val bytes: Long, val date: String, val locked: Boolean, val metadata: String, val protected: Boolean = locked)
data class Folder(val route: String, val name: String)
data class Catalog(val route: String, val crumbs: List<Folder>, val folders: List<Folder>, val files: List<DriveFile>, val page: Int, val pages: Int, val folderPage: Int, val moreFolders: Boolean)
class SessionExpired : Exception("Tu sesión venció. Vuelve a iniciar sesión.")
class ApiError(val status: Int, message: String) : Exception(message)
object TransferMath {
    const val PART = 8L * 1024 * 1024
    fun parts(size: Long) = ((size + PART - 1) / PART).toInt()
    fun length(size: Long, number: Int) = minOf(PART, size - (number - 1) * PART)
    fun eta(total: Long, done: Long, speed: Long) = if (speed > 0) maxOf(0, (total - done) / speed) else 0
}
