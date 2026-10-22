package com.arcadecloud.drive.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import androidx.work.WorkManager
import com.arcadecloud.drive.ArcadeApplication
import com.arcadecloud.drive.domain.*
import com.arcadecloud.drive.transfer.*
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.*
import org.json.JSONObject

data class DriveState(val authenticated: Boolean = false, val busy: Boolean = false, val alias: String = "", val root: String = "",
    val catalog: Catalog? = null, val searchCatalog: Catalog? = null, val notice: String? = null, val error: String? = null, val selected: Set<Long> = emptySet(), val serverTasks: List<JSONObject> = emptyList())
class DriveViewModel(app: Application) : AndroidViewModel(app) {
    val graph = app as ArcadeApplication
    val repo = graph.repository
    private val mutable = MutableStateFlow(DriveState(busy = true))
    val state = mutable.asStateFlow()
    private var browseJob: Job? = null
    var clipboard: DriveClipboard? = null
        private set
    fun copyToClipboard(value: DriveClipboard) { clipboard = value; notice(if(value.operation == "copy") "Copiado. Elige Pegar en la carpeta destino." else "Cortado. Elige Pegar en la carpeta destino.") }
    fun clearSelection() { mutable.update { it.copy(selected = emptySet()) } }
    fun notice(message: String?) { mutable.update { it.copy(notice = message) } }
    private var searchJob: Job? = null
    private data class SearchRequest(val route: String, val page: Int, val folderPage: Int, val query: String, val type: String)
    private var lastSearch: SearchRequest? = null
    fun refreshSearch() { lastSearch?.let { search(it.route,it.page,it.folderPage,it.query,it.type) } }
    fun search(route: String, page: Int = 1, folderPage: Int = 1, query: String = "", type: String = "") {
        lastSearch = SearchRequest(route,page,folderPage,query,type)
        searchJob?.cancel(); searchJob = run {
            val result = repo.browse(route, page, folderPage, query, type)
            mutable.update { it.copy(searchCatalog = result, selected = emptySet()) }
        }
    }
    suspend fun paste(destination: String) {
        val item = clipboard ?: error("Copia o corta primero un archivo o carpeta.")
        repo.request("move_task.php", item.fields(destination))
        if(item.operation == "move") clipboard = null
        notice("Operación enviada a Tareas; todavía no ha finalizado."); refreshTasks()
    }
    init { run { if (repo.owner > 0) { val identity = repo.session(); authenticated(identity) } else mutable.value = DriveState() } }
    private fun authenticated(identity: JSONObject) {
        mutable.value = DriveState(authenticated = true, alias = identity.getString("alias"), root = identity.getString("root"))
        browse(identity.getString("root"))
    }
    fun login(node: String, email: String, password: String) = run { authenticated(repo.login(node, email, password)) }
    fun run(block: suspend () -> Unit) = viewModelScope.launch {
        mutable.update { it.copy(busy = true, error = null) }
        try { block() }
        catch(e: CancellationException) { throw e }
        catch(e: SessionExpired) { WorkManager.getInstance(graph).cancelAllWorkByTag("native-transfer"); repo.forget(); clipboard = null; lastSearch = null; mutable.value = DriveState(error = e.message) }
        catch(e: Exception) { mutable.update { it.copy(error = e.message ?: "No se pudo completar la operación") } }
        finally { mutable.update { it.copy(busy = false) } }
    }
    fun browse(route: String = state.value.catalog?.route ?: state.value.root, page: Int = 1, folderPage: Int = 1, search: String = "", type: String = "") {
        browseJob?.cancel(); browseJob = run {
            val catalog = repo.browse(route, page, folderPage, search, type)
            mutable.update { it.copy(catalog = catalog, selected = emptySet()) }
        }
    }
    fun select(id: Long) { mutable.update { it.copy(selected = if (id in it.selected) it.selected - id else it.selected + id) } }
    fun mutate(path: String, fields: Map<String,String>) = run { repo.request(path, fields); browse() }
    private var taskJob: Job? = null
    fun refreshTasks() {
        if(taskJob?.isActive == true) return
        taskJob = run {
        val result = repo.request("background_tasks.php"); val arr = result.getJSONArray("tasks")
        mutable.update { it.copy(serverTasks = (0 until arr.length()).map { index -> arr.getJSONObject(index) }) }
        }
    }
    fun logout() = run { WorkManager.getInstance(graph).cancelAllWorkByTag("native-transfer"); repo.logout(); clipboard = null; lastSearch = null; mutable.value = DriveState() }
    fun upload(uri: String, name: String, mime: String, route: String) = run { TransferQueue.enqueue(graph, uri, name, mime, route) }
    fun download(file: DriveFile) = run {
        check(!file.locked) { "Desbloquea el archivo antes de descargar." }
        TransferQueue.download(graph, file.key, file.name, mimeFor(file.name), file.bytes)
    }
    fun pause(id: String, cancel: Boolean) = run {
        TransferQueue.pause(graph, id, cancel)
        val t = graph.transfers.get(id)
        if (t.optString("direction") != "download" && t.has("upload")) repo.upload("progress", "task", mapOf("task_id" to id,
            "status" to if (cancel) "cancelled" else "queued", "detail" to if(cancel) "Cancelada desde Android" else "Pausada en Android",
            "task_title" to t.getString("name"), "bytes_total" to "${t.optLong("bytes")}", "bytes_uploaded" to "${t.optLong("done")}"))
    }
    fun resume(id: String) = run { TransferQueue.resume(graph, id) }
    companion object {
        fun mimeFor(name: String): String = android.webkit.MimeTypeMap.getSingleton().getMimeTypeFromExtension(name.substringAfterLast('.', "").lowercase()) ?: "application/octet-stream"
    }
}
