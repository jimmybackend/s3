package com.arcadecloud.drive.data

import com.arcadecloud.drive.domain.SessionExpired
import com.arcadecloud.drive.domain.NodePolicy
import com.arcadecloud.drive.domain.ApiError
import org.json.JSONObject
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withPermit
import okhttp3.*
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/** Uses the repository's authenticated cookie jar, with no redirects or HTTP disk cache. */
internal class ThumbnailClient(client: OkHttpClient) {
    private val sourceCookies = client.cookieJar
    private val client = client.newBuilder().cookieJar(object: CookieJar {
        override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) { /* Thumbnail responses cannot replace the login session. */ }
        override fun loadForRequest(url: HttpUrl) = sourceCookies.loadForRequest(url)
    }).followRedirects(false).followSslRedirects(false).cache(null)
        .callTimeout(25,TimeUnit.SECONDS).readTimeout(20,TimeUnit.SECONDS).build()
    private val slots = Semaphore(4)
    suspend fun load(url: HttpUrl): ByteArray? = slots.withPermit {
        val call=client.newCall(Request.Builder().url(url).header("Accept","image/*").header("Cache-Control","no-cache, no-store").build())
        suspendCancellableCoroutine<ByteArray?> { continuation ->
            continuation.invokeOnCancellation { call.cancel() }
            call.enqueue(object: Callback {
                override fun onFailure(call: Call,e: IOException) { if(!continuation.isCancelled) continuation.resumeWithException(e) }
                override fun onResponse(call: Call,response: Response) {
                    // OkHttp invokes this callback off the UI thread. Keep cancellation active
                    // until the bounded body has been read and the response closed.
                    val bytes = try { response.use {
                        if(it.code==401 || it.header("X-Thumb-Status")=="NO_SESSION") throw SessionExpired()
                        if(it.header("X-Thumb-Status")=="SOURCE_PREVIEW") { call.cancel();return@use null }
                        if(!it.isSuccessful || it.header("X-Thumb-Status") in listOf("FALLBACK","NO_KEY")) return@use null
                        if(it.body?.contentType()?.type != "image") return@use null
                        val data=it.peekBody(MAX_BYTES+1).bytes()
                        data.takeIf { value -> value.isNotEmpty() && value.size<=MAX_BYTES }
                    } } catch(e: Exception) {
                        if(!continuation.isCancelled) continuation.resumeWithException(e)
                        return
                    }
                    if(!continuation.isCancelled) continuation.resume(bytes)
                }
            })
        }
    }

    /** Cancellable authorization request. Session cookies stay on the node, never on S3. */
    suspend fun signedVideoUrl(url: HttpUrl): String = suspendCancellableCoroutine { continuation ->
        val call = client.newCall(Request.Builder().url(url).header("Accept", "application/json").build())
        continuation.invokeOnCancellation { call.cancel() }
        call.enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) { if (!continuation.isCancelled) continuation.resumeWithException(e) }
            override fun onResponse(call: Call, response: Response) {
                val source = try { response.use {
                    if (it.code == 401) throw SessionExpired()
                    if (!it.isSuccessful) throw ApiError(it.code, "No se autorizó la miniatura del video")
                    val bytes = it.peekBody(65537).bytes()
                    if (bytes.size > 65536) throw IOException("Respuesta demasiado grande")
                    val json = JSONObject(String(bytes, Charsets.UTF_8))
                    NodePolicy.requireSignedUrl(json.getString("url_descarga"))
                } } catch (e: Exception) {
                    if (!continuation.isCancelled) continuation.resumeWithException(e)
                    return
                }
                if (!continuation.isCancelled) continuation.resume(source)
            }
        })
    }

    companion object { const val MAX_BYTES=1024L*1024 }
}
