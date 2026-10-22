package com.arcadecloud.drive.data

import android.media.MediaDataSource
import okhttp3.*
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Random access only: never accepts a full HTTP 200 video response. No cookies or redirects. */
internal class VideoRangeSource(url: String, private val authorized: () -> Boolean, private val client: OkHttpClient = CLIENT) : MediaDataSource() {
    private val url = url
    @Volatile private var closed = false
    @Volatile private var active: Call? = null
    private val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(25)
    private var total = -1L
    private var consumed = 0L
    private var blockStart = -1L
    private var block = ByteArray(0)

    private fun checkOpen() {
        if (closed || !authorized() || System.nanoTime() > deadline) throw IOException("Miniatura cancelada o vencida")
    }
    @Synchronized override fun getSize(): Long {
        checkOpen()
        if (total < 0) fetch(0)
        return total
    }
    @Synchronized override fun readAt(position: Long, buffer: ByteArray, offset: Int, size: Int): Int {
        checkOpen()
        if (position < 0 || offset < 0 || size < 0 || offset > buffer.size - size) throw IOException("Rango inválido")
        if (size == 0) return 0
        if (total >= 0 && position >= total) return -1
        if (position < blockStart || position >= blockStart + block.size) fetch(position)
        val count = minOf(size, (blockStart + block.size - position).toInt())
        if (count <= 0) return -1
        block.copyInto(buffer, offset, (position - blockStart).toInt(), (position - blockStart).toInt() + count)
        return count
    }
    private fun fetch(position: Long) {
        checkOpen()
        if (consumed >= MAX_BYTES) throw IOException("Límite de datos de miniatura")
        val count = minOf(BLOCK_BYTES.toLong(), MAX_BYTES - consumed, if (total >= 0) total - position else Long.MAX_VALUE)
        if (count <= 0) throw IOException("Rango fuera del archivo")
        val end = position + count - 1
        val call = client.newCall(Request.Builder().url(url).header("Range", "bytes=$position-$end")
            .header("Accept-Encoding", "identity").build())
        active = call
        try {
            checkOpen()
            call.execute().use { response ->
                try {
                    checkOpen()
                    // Refuse ignored ranges before consuming any body bytes.
                    if (response.code != 206) throw IOException("El origen no permite lectura parcial")
                    val range = Regex("bytes (\\d+)-(\\d+)/(\\d+)").matchEntire(response.header("Content-Range").orEmpty())
                        ?: throw IOException("Respuesta parcial inválida")
                    val (start, last, length) = range.destructured.toList().map { it.toLong() }
                    if (start != position || last < start || last > end || length <= last || (total >= 0 && total != length))
                        throw IOException("Rango inesperado")
                    val expected = (last - start + 1).toInt()
                    val body = response.body ?: throw IOException("Respuesta vacía")
                    if (body.contentLength() != expected.toLong()) throw IOException("Longitud parcial inválida")
                    val data = body.source().readByteArray(expected.toLong())
                    consumed += data.size
                    checkOpen()
                    total = length
                    blockStart = position
                    block = data
                } catch (e: Exception) {
                    // Cancel before Response.close(): OkHttp must not drain an ignored/oversized body.
                    call.cancel()
                    throw e
                }
            }
        } finally { active = null }
    }
    // Must not acquire the read lock: cancellation interrupts a blocked HTTP request.
    override fun close() { closed = true; active?.cancel() }
    companion object {
        private val CLIENT = OkHttpClient.Builder().followRedirects(false).followSslRedirects(false)
            .callTimeout(8, TimeUnit.SECONDS).readTimeout(8, TimeUnit.SECONDS).build()
        const val BLOCK_BYTES = 64 * 1024
        const val MAX_BYTES = 8L * 1024 * 1024
    }
}
