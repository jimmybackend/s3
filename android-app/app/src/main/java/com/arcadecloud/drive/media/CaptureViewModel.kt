package com.arcadecloud.drive.media

import android.Manifest
import android.app.Application
import android.content.pm.PackageManager
import android.media.MediaRecorder
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCaptureException
import androidx.camera.video.*
import androidx.core.content.ContextCompat
import androidx.lifecycle.AndroidViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.io.File
import java.util.UUID

/** Owns native encoders across configuration changes. No browser media APIs. */
data class CaptureState(val recording: Boolean = false, val paused: Boolean = false, val file: File? = null,
    val mime: String = "", val durationMs: Long = 0, val bytes: Long = 0, val error: String? = null, val busy: Boolean = false)
class CaptureViewModel(app: Application) : AndroidViewModel(app) {
    private val mutable = MutableStateFlow(CaptureState())
    val state = mutable.asStateFlow()
    private var video: Recording? = null
    private var audio: MediaRecorder? = null
    private var audioFile: File? = null
    private var audioStart = 0L
    private var audioElapsed = 0L
    private fun newFile(ext: String): File {
        val dir = File(getApplication<Application>().filesDir, "captures").apply { mkdirs() }
        check(dir.usableSpace > 32L * 1024 * 1024) { "No hay espacio suficiente en el teléfono." }
        return File(dir, "ArcadeCloud-${System.currentTimeMillis()}-${UUID.randomUUID()}.$ext")
    }
    fun error(error: Throwable) { mutable.value = mutable.value.copy(busy = false, error = when(error) {
        is SecurityException -> "Android revocó el permiso. Autorízalo en Ajustes y vuelve a intentarlo."
        else -> error.message ?: "La cámara o el codificador no están disponibles. Cierra otras aplicaciones y vuelve a intentar."
    }) }
    fun photo(capture: ImageCapture) {
        if (mutable.value.busy || mutable.value.recording) return
        try {
            val file = newFile("jpg"); mutable.value = CaptureState(busy = true)
            capture.takePicture(ImageCapture.OutputFileOptions.Builder(file).build(), ContextCompat.getMainExecutor(getApplication()), object : ImageCapture.OnImageSavedCallback {
                override fun onImageSaved(result: ImageCapture.OutputFileResults) { mutable.value = CaptureState(file = file, mime = "image/jpeg", bytes = file.length()) }
                override fun onError(exception: ImageCaptureException) { file.delete(); error(exception) }
            })
        } catch(e: Exception) { error(e) }
    }
    fun startVideo(capture: VideoCapture<Recorder>) {
        if (mutable.value.recording) return
        val app = getApplication<Application>()
        if (ContextCompat.checkSelfPermission(app, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) { error(SecurityException()); return }
        try {
            val file = newFile("mp4"); mutable.value = CaptureState(recording = true, mime = "video/mp4")
            video = capture.output.prepareRecording(app, FileOutputOptions.Builder(file).setFileSizeLimit(180L * 1024 * 1024).setDurationLimitMillis(5 * 60 * 1000L).build())
                .withAudioEnabled().start(ContextCompat.getMainExecutor(app)) { event ->
                    when(event) {
                        is VideoRecordEvent.Status -> mutable.value = mutable.value.copy(durationMs = event.recordingStats.recordedDurationNanos / 1_000_000, bytes = event.recordingStats.numBytesRecorded)
                        is VideoRecordEvent.Finalize -> {
                            video?.close(); video = null
                            if (event.hasError() && event.error !in listOf(VideoRecordEvent.Finalize.ERROR_DURATION_LIMIT_REACHED, VideoRecordEvent.Finalize.ERROR_FILE_SIZE_LIMIT_REACHED)) {
                                file.delete(); mutable.value = CaptureState(error = "Video interrumpido (código ${event.error}). Revisa espacio, cámara y micrófono; no se subió ningún archivo.")
                            } else mutable.value = CaptureState(file = file, mime = "video/mp4", bytes = file.length(), durationMs = event.recordingStats.recordedDurationNanos / 1_000_000)
                        }
                    }
                }
        } catch(e: Exception) { video?.close(); video = null; mutable.value = CaptureState(); error(e) }
    }
    @Suppress("DEPRECATION")
    fun startAudio() {
        if (mutable.value.recording) return
        try {
            val app = getApplication<Application>()
            if (ContextCompat.checkSelfPermission(app, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) throw SecurityException()
            val file = newFile("m4a"); audioFile = file
            audio = (if (android.os.Build.VERSION.SDK_INT >= 31) MediaRecorder(app) else MediaRecorder()).apply {
                setAudioSource(MediaRecorder.AudioSource.MIC)
                setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
                setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
                setAudioEncodingBitRate(128000); setAudioSamplingRate(44100)
                setOutputFile(file.absolutePath); setMaxDuration(5 * 60 * 1000); setMaxFileSize(180L * 1024 * 1024)
                setOnInfoListener { _, what, _ -> if (what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_DURATION_REACHED || what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_FILESIZE_REACHED) stop() }
                setOnErrorListener { _, _, _ -> stop(); error(IllegalStateException("Falló el codificador de audio. Repite la grabación.")) }
                prepare(); start()
            }
            audioStart = System.currentTimeMillis(); audioElapsed = 0
            mutable.value = CaptureState(recording = true, mime = "audio/mp4")
        } catch(e: Exception) { audio?.release(); audio = null; audioFile?.delete(); mutable.value = CaptureState(); error(e) }
    }
    fun tick() {
        if (audio != null && mutable.value.recording && !mutable.value.paused) mutable.value = mutable.value.copy(durationMs = audioElapsed + System.currentTimeMillis() - audioStart, bytes = audioFile?.length() ?: 0)
    }
    fun togglePause() {
        try {
            val paused = mutable.value.paused
            if (paused) { video?.resume(); audio?.resume(); audioStart = System.currentTimeMillis() }
            else { video?.pause(); audio?.pause(); if (audio != null) audioElapsed += System.currentTimeMillis() - audioStart }
            mutable.value = mutable.value.copy(paused = !paused)
        } catch(e: Exception) { error(e) }
    }
    fun stop() {
        video?.stop()
        val current = audio ?: return
        try { current.stop(); val f = audioFile; mutable.value = mutable.value.copy(recording = false, paused = false, file = f, bytes = f?.length() ?: 0) }
        catch(e: Exception) { audioFile?.delete(); mutable.value = CaptureState(); error(IllegalStateException("Grabación demasiado corta o interrumpida. Inténtalo de nuevo.")) }
        finally { current.release(); audio = null }
    }
    fun discard() { if (!mutable.value.recording) { mutable.value.file?.delete(); mutable.value = CaptureState() } }
    fun submitted() { mutable.value = CaptureState() } // Upload worker still needs the private source file.
    override fun onCleared() { video?.stop(); video?.close(); audio?.run { runCatching { stop() }; release() } }
}
