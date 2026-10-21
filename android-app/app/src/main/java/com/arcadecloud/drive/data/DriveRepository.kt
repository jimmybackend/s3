package com.arcadecloud.drive.data

import com.arcadecloud.drive.domain.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.HttpUrl.Companion.toHttpUrl
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit

class DriveRepository(private val vault: SessionVault) {
    val node get() = NodePolicy.requireNode(vault.read().optString("node", NodePolicy.nodes.first()))
    val owner get() = vault.read().optLong("user_id")
    val generation get() = vault.read().optString("generation")
    private val jar = object : CookieJar {
        override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
            if (url.toString().startsWith(node)) vault.update { state ->
                val all = loadCookies().associateBy { it.name + it.domain + it.path }.toMutableMap()
                cookies.forEach { all[it.name + it.domain + it.path] = it }
                state.put("cookies", JSONArray(all.values.filter { it.expiresAt > System.currentTimeMillis() }.map { it.toString() }))
            }
        }
        override fun loadForRequest(url: HttpUrl) = if (url.toString().startsWith(node)) loadCookies().filter { it.matches(url) && it.expiresAt > System.currentTimeMillis() } else emptyList()
    }
    private fun loadCookies(): List<Cookie> {
        val values = vault.read().optJSONArray("cookies") ?: return emptyList()
        return (0 until values.length()).mapNotNull { Cookie.parse(node.toHttpUrl(), values.getString(it)) }
    }
    private val client = OkHttpClient.Builder().cookieJar(jar).followRedirects(false).followSslRedirects(false)
        .connectTimeout(30, TimeUnit.SECONDS).readTimeout(90, TimeUnit.SECONDS).writeTimeout(90, TimeUnit.SECONDS).build()
    suspend fun request(path: String, fields: Map<String,String>? = null): JSONObject = withContext(Dispatchers.IO) {
        if (!path.startsWith("api/native.php")) request("api/native.php?action=session")
        require(!path.substringBefore('?').let { it.startsWith("/") || it.contains(":") || it.contains("..") })
        val request = Request.Builder().url(node + path).header("Accept", "application/json")
            .header("User-Agent", "ArcadeCloudAndroidNative/0.9.0")
        if (fields != null) request.post(FormBody.Builder().apply { fields.forEach { (k,v) -> add(k,v) } }.build())
            .header("X-Drive-CSRF", vault.read().optString("csrf"))
        client.newCall(request.build()).execute().use { response ->
            if (response.code == 401 && path != "api/native.php?action=login") throw SessionExpired()
            val raw = response.body?.string() ?: throw IOException("Respuesta vacía")
            val json = try { JSONObject(raw) } catch (_: Exception) { throw ApiError(response.code, "El servidor no ofrece la API nativa. Requiere desplegar esta versión del backend.") }
            if (!response.isSuccessful || !json.optBoolean("ok", false)) throw ApiError(response.code,
                json.optString("error", json.optString("msg", json.optString("mensaje", "Operación rechazada"))))
            json
        }
    }
    suspend fun login(selectedNode: String, email: String, password: String): JSONObject {
        NodePolicy.requireNode(selectedNode)
        check(owner == 0L) { "Cierra la sesión antes de cambiar de cuenta" }
        vault.clear(); vault.update { it.put("node", selectedNode) }
        val bootstrap = request("api/native.php?action=bootstrap")
        vault.update { it.put("csrf", bootstrap.getString("csrf")) }
        val identity = request("api/native.php?action=login", mapOf("email" to email, "password" to password))
        vault.update { it.put("csrf", identity.getString("csrf")).put("user_id", identity.getLong("user_id"))
            .put("root", identity.getString("root")).put("alias", identity.getString("alias")).put("generation", UUID.randomUUID().toString()) }
        return identity
    }
    suspend fun session(): JSONObject = request("api/native.php?action=session").also { result -> vault.update { it.put("csrf", result.getString("csrf")) } }
    suspend fun logout() { request("api/native.php?action=logout", emptyMap()); vault.clear() }
    fun forget() = vault.clear()
    suspend fun browse(route: String, page: Int = 1, folderPage: Int = 1, search: String = "", type: String = ""): Catalog {
        val url = "api/native.php?action=browse&route=${encode(route)}&page=$page&folder_page=$folderPage&search=${encode(search)}&type=${encode(type)}"
        val j = request(url)
        fun folders(key: String, routeKey: String, nameKey: String) = j.getJSONArray(key).let { arr -> (0 until arr.length()).map { arr.getJSONObject(it).let { f -> Folder(f.getString(routeKey), f.getString(nameKey)) } } }
        val files = j.getJSONArray("files").let { arr -> (0 until arr.length()).map { arr.getJSONObject(it).let { f ->
            DriveFile(f.getLong("id"), f.getString("name"), f.getString("key"), f.getString("route"), f.getLong("bytes"), f.getString("date"), f.getBoolean("locked"), f.optJSONObject("metadata")?.toString(2) ?: "") } } }
        return Catalog(j.getString("route"), folders("breadcrumbs", "route", "label"), folders("folders", "route", "name"), files, j.getInt("page"), j.getInt("pages"), j.getInt("folder_page"), j.getBoolean("more_folders"))
    }
    suspend fun signedDownload(key: String): String = NodePolicy.requireSignedUrl(request("api/native.php?action=download&key=${encode(key)}").getString("url_descarga"))
    suspend fun upload(action: String, mode: String, fields: Map<String,String>) = request("api/upload.php?mode=$mode&action=$action", fields)
    companion object { fun encode(value: String): String = java.net.URLEncoder.encode(value, "UTF-8") }
}
