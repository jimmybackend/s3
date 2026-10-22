package com.arcadecloud.drive.data

import android.graphics.Bitmap
import android.graphics.Color
import android.media.MediaMetadataRetriever
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.Semaphore
import java.io.ByteArrayOutputStream
import java.util.concurrent.Executors
import kotlin.coroutines.resume

/** Two decoders maximum, bounded range reads and scaled frames; no player, audio or video file. */
internal class VideoFrameExtractor(private val sourceFactory: (String, () -> Boolean) -> VideoRangeSource = { url, current -> VideoRangeSource(url, current) }) {
    private val slots = Semaphore(2)
    private val workers = Executors.newFixedThreadPool(2) { runnable -> Thread(runnable, "video-poster").apply { isDaemon = true } }
    suspend fun extract(url: String, authorized: () -> Boolean): ByteArray? {
        slots.acquire()
        return suspendCancellableCoroutine { continuation ->
            val source = sourceFactory(url, authorized)
            continuation.invokeOnCancellation { source.close() }
            workers.execute {
                var retriever: MediaMetadataRetriever? = null
                val result = try {
                    if (!continuation.isActive || !authorized()) null else {
                        val decoder = MediaMetadataRetriever()
                        retriever = decoder
                        decoder.setDataSource(source)
                        var best: Bitmap? = null
                        try {
                            // Prefer the actual frame at 1s, rather than a black opening keyframe.
                            for (time in longArrayOf(1_000_000, 2_000_000, 0)) {
                                if (!continuation.isActive || !authorized()) break
                                val frame = decoder.getScaledFrameAtTime(time, MediaMetadataRetriever.OPTION_CLOSEST, 160, 120) ?: continue
                                if (best == null || !isDark(frame)) {
                                    best?.recycle(); best = frame
                                } else frame.recycle()
                                if (best != null && !isDark(best)) break
                            }
                            best?.let { image -> ByteArrayOutputStream().use { output ->
                                if (image.compress(Bitmap.CompressFormat.JPEG, 78, output)) output.toByteArray() else null
                            } }
                        } finally { best?.recycle() }
                    }
                } catch (_: Exception) { null }
                finally {
                    source.close()
                    try { retriever?.release() } catch (_: Exception) { /* Decoder already stopped. */ }
                    slots.release()
                }
                if (continuation.isActive) continuation.resume(result.takeIf { authorized() })
            }
        }
    }
    private fun isDark(bitmap: Bitmap): Boolean {
        var light = 0
        for (y in 1..4) for (x in 1..4) {
            val pixel = bitmap.getPixel(x * (bitmap.width - 1) / 5, y * (bitmap.height - 1) / 5)
            light += maxOf(Color.red(pixel), Color.green(pixel), Color.blue(pixel))
        }
        return light < 16 * 18
    }
}
