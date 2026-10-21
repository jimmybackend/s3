package com.arcadecloud.drive.transfer

import android.content.Context
import android.util.AtomicFile
import org.json.JSONObject
import java.io.File

/** One process, serialized atomic checkpoints. Payloads stay in app-private storage. */
class TransferStore(context: Context) {
    private val vault = com.arcadecloud.drive.data.SessionVault(context)
    private val root = File(context.filesDir, "transfers").apply { mkdirs() }
    @Synchronized fun get(id: String): JSONObject {
        require(id.matches(Regex("[a-f0-9-]{36}")))
        val file = AtomicFile(File(root, "$id.json"))
        return JSONObject(String(vault.open(file.openRead().use { it.readBytes() }), Charsets.UTF_8))
    }
    @Synchronized fun update(id: String, block: (JSONObject) -> Unit): JSONObject {
        val state = runCatching { get(id) }.getOrElse { JSONObject().put("id", id) }; block(state)
        val atomic = AtomicFile(File(root, "$id.json")); val stream = atomic.startWrite()
        try { stream.write(vault.seal(state.toString().toByteArray())); atomic.finishWrite(stream) }
        catch (e: Exception) { atomic.failWrite(stream); throw e }
        return state
    }
    @Synchronized fun list(owner: Long, node: String): List<JSONObject> = root.listFiles()?.filter { it.extension == "json" }?.mapNotNull {
        runCatching { get(it.nameWithoutExtension) }.getOrNull()
    }?.filter { it.optLong("owner") == owner && it.optString("node") == node }?.sortedByDescending { it.optLong("created") } ?: emptyList()
    fun payload(id: String) = File(root, "$id.payload")
}
