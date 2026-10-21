package com.arcadecloud.drive.transfer

import android.content.Context
import androidx.work.*
import com.arcadecloud.drive.ArcadeApplication
import java.util.UUID
import java.util.concurrent.TimeUnit

object TransferQueue {
    fun enqueue(context: Context, uri: String, name: String, mime: String, route: String): String {
        val app = context.applicationContext as ArcadeApplication
        check(app.repository.owner > 0)
        val id = UUID.randomUUID().toString()
        app.transfers.update(id) { it.put("uri", uri).put("name", name).put("mime", mime).put("route", route)
            .put("node", app.repository.node).put("owner", app.repository.owner).put("generation", app.repository.generation)
            .put("status", "queued").put("created", System.currentTimeMillis()).put("bytes", 0).put("done", 0) }
        resume(context, id); return id
    }
    fun download(context: Context, key: String, name: String, mime: String, size: Long): String {
        val app = context.applicationContext as ArcadeApplication; val id = UUID.randomUUID().toString()
        app.transfers.update(id) { it.put("direction", "download").put("key", key).put("name", name).put("mime", mime)
            .put("bytes", size).put("done", 0).put("owner", app.repository.owner).put("node", app.repository.node)
            .put("generation", app.repository.generation).put("status", "queued").put("created", System.currentTimeMillis()) }
        resume(context, id); return id
    }
    fun resume(context: Context, id: String) {
        val app = context.applicationContext as ArcadeApplication; val task = app.transfers.get(id)
        check(task.getLong("owner") == app.repository.owner && task.getString("generation") == app.repository.generation) { "La tarea pertenece a otra sesión; conserva el archivo y vuelve a seleccionarlo." }
        check(task.optString("status") !in listOf("completed", "cancelled"))
        app.transfers.update(id) { it.put("status", "queued").remove("error") }
        val work = OneTimeWorkRequestBuilder<TransferWorker>().setInputData(workDataOf("id" to id))
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).addTag("native-transfer").build()
        WorkManager.getInstance(context).enqueueUniqueWork(id, ExistingWorkPolicy.KEEP, work)
    }
    fun pause(context: Context, id: String, cancel: Boolean = false) {
        val app = context.applicationContext as ArcadeApplication
        app.transfers.update(id) { it.put("status", if (cancel) "cancelled" else "paused") }
        WorkManager.getInstance(context).cancelUniqueWork(id)
        // Incomplete multipart objects are left to the EXISTING server cleanup policy, never deleted here.
    }
}
