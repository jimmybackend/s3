package com.arcadecloud.drive.transfer

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.pm.ServiceInfo
import android.net.Uri
import androidx.core.app.NotificationCompat
import androidx.work.*
import com.arcadecloud.drive.ArcadeApplication
import com.arcadecloud.drive.domain.*
import kotlinx.coroutines.*
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okio.BufferedSink
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.io.RandomAccessFile
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong
import java.util.concurrent.atomic.AtomicBoolean

private class StalledTransfer : IOException()

class TransferWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    private val app = context.applicationContext as ArcadeApplication
    private val repo = app.repository
    private val store = app.transfers
    private val taskId = inputData.getString("id") ?: ""
    // S3 client NEVER has the session CookieJar, authorization headers, logging, or redirect following.
    private val s3 = OkHttpClient.Builder().followRedirects(false).followSslRedirects(false)
        .connectTimeout(30, TimeUnit.SECONDS).readTimeout(90, TimeUnit.SECONDS).writeTimeout(90, TimeUnit.SECONDS)
        .callTimeout(30, TimeUnit.MINUTES).build()
    @Volatile private var lastPolicyCheck = 0L
    private fun checkActive() {
        if (isStopped) throw CancellationException()
        // Check cancellation on every buffer; limit disk/Keystore ownership checks to twice/sec.
        val now = System.nanoTime()
        if (now - lastPolicyCheck < 500_000_000L) return
        lastPolicyCheck = now
        val task = store.get(taskId)
        if (task.optString("status") in listOf("paused", "cancelled")) throw CancellationException()
        if (task.getLong("owner") != repo.owner || task.getString("node") != repo.node || task.getString("generation") != repo.generation) throw SessionExpired()
    }
    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        try {
            checkActive(); repo.session(); setForeground(notification("Preparando transferencia", 0))
            store.update(taskId) { it.put("status", "running"); if (!it.has("started")) it.put("started", System.currentTimeMillis()) }
            if (store.get(taskId).optString("direction") == "download") download() else upload()
            checkActive(); store.update(taskId) { it.put("status", "completed").put("done", it.optLong("bytes")).put("finished", System.currentTimeMillis()) }
            val source = store.get(taskId).optString("uri")
            if (source.startsWith("file:")) {
                val local = File(Uri.parse(source).path ?: "")
                if (local.canonicalFile.parentFile == File(applicationContext.filesDir, "captures").canonicalFile) local.delete()
            }
            Result.success()
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) {
            val task = store.get(taskId)
            val noSpace = generateSequence<Throwable>(e) { it.cause }.any { (it as? android.system.ErrnoException)?.errno == android.system.OsConstants.ENOSPC || it.message?.contains("ENOSPC", true) == true }
            val retry = !noSpace && e is IOException && runAttemptCount < 4 && task.optString("phase") != "completing"
            val message = if (noSpace) "No hay espacio suficiente en el teléfono. Libera espacio y reintenta." else when (e) { is StalledTransfer -> "Subida bloqueada: 90 segundos sin avance o menos de 32 KB/s. Revisa la conexión."; is SessionExpired -> e.message!!; is ApiError -> e.message ?: "Servidor rechazó la tarea"; is IllegalArgumentException -> e.message ?: "Datos de transferencia inválidos"; is IllegalStateException -> e.message ?: "La transferencia necesita revisión"; else -> "Transferencia interrumpida: ${e.javaClass.simpleName}. Puedes reintentar desde Tareas." }
            store.update(taskId) { if (it.optString("status") !in listOf("paused", "cancelled")) it.put("status", if (retry) "queued" else "failed").put("error", message) }
            if (task.optString("direction") != "download") runCatching { signal(if (retry) "queued" else "failed", task.optLong("done"), task.optLong("bytes")) }
            if (retry) Result.retry() else Result.failure()
        }
    }
    private suspend fun upload() {
        var task = store.get(taskId); val file = store.payload(taskId)
        if (!task.optBoolean("snapshot")) {
            // SAF provider may be a pipe. Snapshot to disk (never memory) so retries use identical bytes.
            applicationContext.contentResolver.openInputStream(Uri.parse(task.getString("uri"))).use { input ->
                requireNotNull(input) { "No se pudo abrir el archivo" }
                file.outputStream().use { output -> val buffer = ByteArray(64 * 1024); while (true) { checkActive(); val n = input.read(buffer); if (n < 0) break; output.write(buffer, 0, n) } }
            }
            require(file.length() > 0) { "Archivo vacío" }
            require(TransferMath.parts(file.length()) <= 10000) { "Archivo demasiado grande" }
            store.update(taskId) { it.put("snapshot", true).put("bytes", file.length()) }
        }
        task = store.get(taskId); val total = file.length(); val mode = if (total >= TransferMath.PART) "chunked" else "local_put"
        // If completion response was lost, verify catalog. Never recreate a multipart session blindly.
        if (task.optString("phase") == "completing") {
            val registered = repo.request("api/native.php?action=file&key=${com.arcadecloud.drive.data.DriveRepository.encode(task.getString("key"))}")
            check(registered.getJSONObject("file").getLong("bytes") == total) { "El registro requiere revisión" }
            file.delete(); return
        }
        var session = task.optJSONObject("upload")
        if (session == null) {
            val fields = mapOf("ruta_objetivo" to task.getString("route"), "filename" to task.getString("name"),
                "nombre" to task.getString("name"), "filesize" to "$total", "mime" to task.getString("mime"),
                "task_id" to taskId, "task_title" to task.getString("name"), "task_bytes" to "$total", "task_source" to "Android Native")
            // Mark before init: a lost response cannot safely be retried automatically with a new object.
            check(!task.optBoolean("init_uncertain")) { "Inicio sin confirmar; revisar la tarea del servidor antes de volver a seleccionar el archivo." }
            store.update(taskId) { it.put("init_uncertain", true) }
            session = repo.upload("init", mode, fields)
            val saved = session
            store.update(taskId) { it.put("upload", saved).put("key", saved.getString("key")).put("init_uncertain", false) }
        }
        val identity = if (mode == "chunked") mapOf("stateId" to session.getString("stateId"), "uploadId" to session.getString("uploadId"), "key" to session.getString("key"), "task_id" to taskId)
            else mapOf("upload_token" to session.getString("upload_token"), "task_id" to taskId)
        if (mode == "chunked") {
            val resume = repo.upload("part", mode, identity + ("step" to "resume"))
            val tags = resume.optJSONObject("etags") ?: JSONObject()
            val parts = TransferMath.parts(total)
            for (part in 1..parts) {
                checkActive(); if (tags.has("$part")) continue
                val length = TransferMath.length(total, part); val offset = (part - 1) * TransferMath.PART
                val signed = repo.upload("part", mode, identity + mapOf("partNumber" to "$part", "contentLength" to "$length"))
                val tag = put(signed.getString("url"), file, offset, length, total, null)
                require(tag.isNotEmpty()) { "S3 no devolvió ETag" }; tags.put("$part", tag)
                store.update(taskId) { it.put("done", offset + length) }
            }
            store.update(taskId) { it.put("phase", "completing") }
            repo.upload("complete", mode, identity + mapOf("etags" to tags.toString(), "task_bytes" to "$total"))
        } else {
            // Refresh/restart of the same signed PUT overwrites only its own pending key; no duplicate catalog entry.
            put(session.getString("url"), file, 0, total, total, task.getString("mime"))
            store.update(taskId) { it.put("phase", "completing") }
            repo.upload("complete", mode, identity + mapOf("tamano" to "$total", "task_bytes" to "$total"))
        }
        file.delete()
    }
    private suspend fun put(url: String, file: File, offset: Long, length: Long, total: Long, mime: String?): String {
        NodePolicy.requireSignedUrl(url)
        val started = System.nanoTime(); var lastReport = 0L
        val lastByte = AtomicLong(System.nanoTime()); val sent = AtomicLong(0); val stalled = AtomicBoolean(false)
        val body = object : RequestBody() {
            override fun contentType() = mime?.toMediaType()
            override fun contentLength() = length
            override fun writeTo(sink: BufferedSink) {
                RandomAccessFile(file, "r").use { input ->
                    input.seek(offset); var remaining = length; val buffer = ByteArray(64 * 1024)
                    while (remaining > 0) {
                        checkActive(); val n = input.read(buffer, 0, minOf(buffer.size.toLong(), remaining).toInt())
                        if (n <= 0) throw IOException("Lectura incompleta")
                        sink.write(buffer, 0, n); sink.flush(); remaining -= n
                        sent.addAndGet(n.toLong()); lastByte.set(System.nanoTime())
                        val now = System.nanoTime()
                        if (now - lastReport > 1_000_000_000L) {
                            val done = offset + sent.get(); val speed = (sent.get() * 1_000_000_000.0 / (now - started)).toLong()
                            store.update(taskId) { it.put("done", done).put("speed", speed).put("eta", TransferMath.eta(total, done, speed)) }
                            lastReport = now
                        }
                    }
                }
            }
        }
        val call = s3.newCall(Request.Builder().url(url).put(body).build())
        val watchdog = Executors.newSingleThreadScheduledExecutor(); var windowBytes = 0L; var windowTime = System.nanoTime()
        val guard = watchdog.scheduleWithFixedDelay({
            try {
                checkActive(); val now = System.nanoTime()
                if (now - lastByte.get() > 90_000_000_000L) { stalled.set(true); call.cancel() }
                if (now - windowTime >= 90_000_000_000L) {
                    if (length >= 1024 * 1024 && sent.get() < length && sent.get() - windowBytes < 90 * 32 * 1024) { stalled.set(true); call.cancel() }
                    windowBytes = sent.get(); windowTime = now
                }
                val task = store.get(taskId)
                runBlocking { setForeground(notification(task.getString("name"), ((offset + sent.get()) * 100 / total).toInt()))
                    signal("running", offset + sent.get(), total) }
            } catch (_: Exception) { call.cancel() }
        }, 5, 5, TimeUnit.SECONDS)
        return try { call.execute().use { response ->
            if (!response.isSuccessful) throw IOException("S3 HTTP ${response.code}")
            response.header("ETag")?.trim('"') ?: ""
        } } catch (e: IOException) {
            if (stalled.get()) throw StalledTransfer() else throw e
        } finally { guard.cancel(true); watchdog.shutdownNow() }
    }
    private suspend fun signal(status: String, done: Long, total: Long) {
        val task = store.get(taskId)
        repo.upload(if (status == "failed") "fail" else "progress", "task", mapOf(
            "task_id" to taskId, "status" to status, "bytes_total" to "$total", "bytes_uploaded" to "$done",
            "progress" to "${if (total > 0) minOf(99, done * 100 / total) else 0}", "task_title" to task.getString("name"),
            "destination" to task.optString("route"), "task_source" to "Android Native", "upload_mode" to if (total >= TransferMath.PART) "chunked" else "local_put",
            "speed_bps" to "${task.optLong("speed")}", "eta_seconds" to "${task.optLong("eta")}", "detail" to (task.optString("error").ifEmpty { "Transferencia Android" })))
    }
    private suspend fun download() {
        val task = store.get(taskId); val url = repo.signedDownload(task.getString("key"))
        val dir = File(applicationContext.filesDir, "downloads").apply { mkdirs() }
        val partial = File(dir, "$taskId.partial"); val target = File(dir, taskId)
        // Restarting GET is safe. Range recovery deferred until ETag validation is implemented.
        s3.newCall(Request.Builder().url(url).build()).execute().use { response ->
            if (!response.isSuccessful) throw IOException("S3 HTTP ${response.code}")
            val body = response.body ?: throw IOException("Sin contenido")
            val total = body.contentLength(); var done = 0L; val start = System.currentTimeMillis(); var tick = 0L
            body.byteStream().use { input -> partial.outputStream().use { output ->
                val buffer = ByteArray(64 * 1024)
                while (true) { checkActive(); val n = input.read(buffer); if (n < 0) break; output.write(buffer, 0, n); done += n
                    val now = System.currentTimeMillis()
                    if (now - tick > 1000) { val speed = done * 1000 / maxOf(1, now - start)
                        store.update(taskId) { it.put("done", done).put("bytes", total).put("speed", speed).put("eta", TransferMath.eta(total, done, speed)) }; tick = now
                        setForeground(notification(task.getString("name"), if (total > 0) (done * 100 / total).toInt() else 0))
                    }
                }
            } }
            if (total >= 0 && done != total) throw IOException("Descarga incompleta")
            check(partial.renameTo(target)); store.update(taskId) { it.put("local", target.absolutePath).put("bytes", done) }
        }
    }
    private fun notification(title: String, percent: Int): ForegroundInfo {
        val manager = applicationContext.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel("transfers", "Transferencias ArcadeCloud", NotificationManager.IMPORTANCE_LOW))
        val notification = NotificationCompat.Builder(applicationContext, "transfers").setSmallIcon(android.R.drawable.stat_sys_upload)
            .setContentTitle(title).setContentText("$percent % · ArcadeCloud").setOngoing(true).setProgress(100, percent, false)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Detener", WorkManager.getInstance(applicationContext).createCancelPendingIntent(id)).build()
        return ForegroundInfo(taskId.hashCode(), notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
    }
}
