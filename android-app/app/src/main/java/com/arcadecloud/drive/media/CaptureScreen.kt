package com.arcadecloud.drive.media

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.*
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.*
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import kotlinx.coroutines.delay

@Composable
fun CaptureScreen(destination: String, onSave: (String, String, String) -> Unit, initialMode: String = "Foto", vm: CaptureViewModel = viewModel()) {
    val context = LocalContext.current; val lifecycle = LocalLifecycleOwner.current
    val state by vm.state.collectAsStateWithLifecycle()
    var mode by rememberSaveable { mutableStateOf(initialMode) }; var front by rememberSaveable { mutableStateOf(false) }
    var permissionTick by remember { mutableIntStateOf(0) }
    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
        permissionTick++; if (grants.values.any { !it }) vm.error(SecurityException())
    }
    val granted = remember(mode, permissionTick) {
        val needed = if (mode == "Audio") listOf(Manifest.permission.RECORD_AUDIO) else if (mode == "Video") listOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO) else listOf(Manifest.permission.CAMERA)
        needed.all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }
    }
    val preview = remember { PreviewView(context).apply { implementationMode = PreviewView.ImplementationMode.COMPATIBLE; scaleType = PreviewView.ScaleType.FIT_CENTER } }
    var image by remember { mutableStateOf<ImageCapture?>(null) }
    var video by remember { mutableStateOf<VideoCapture<Recorder>?>(null) }
    DisposableEffect(mode, front, granted, state.file, lifecycle) {
        var provider: ProcessCameraProvider? = null; var disposed = false
        if (granted && mode != "Audio" && state.file == null) {
            val future = ProcessCameraProvider.getInstance(context)
            future.addListener({
                if (!disposed) try {
                    provider = future.get(); provider!!.unbindAll()
                    val selector = if (front) CameraSelector.DEFAULT_FRONT_CAMERA else CameraSelector.DEFAULT_BACK_CAMERA
                    check(provider!!.hasCamera(selector)) { "Esta cámara no está disponible en el dispositivo." }
                    val surface = Preview.Builder().build().also { it.surfaceProvider = preview.surfaceProvider }
                    if (mode == "Foto") {
                        image = ImageCapture.Builder().setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY).build()
                        provider!!.bindToLifecycle(lifecycle, selector, surface, image)
                    } else {
                        video = VideoCapture.withOutput(Recorder.Builder().setQualitySelector(QualitySelector.from(Quality.SD, FallbackStrategy.lowerQualityOrHigherThan(Quality.SD))).build())
                        provider!!.bindToLifecycle(lifecycle, selector, surface, video)
                    }
                } catch(e: Exception) { vm.error(e) }
            }, ContextCompat.getMainExecutor(context))
        }
        onDispose { disposed = true; provider?.unbindAll(); image = null; video = null }
    }
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_STOP) vm.stop()
            if (event == Lifecycle.Event.ON_RESUME) permissionTick++
        }
        lifecycle.lifecycle.addObserver(observer)
        onDispose { lifecycle.lifecycle.removeObserver(observer) }
    }
    LaunchedEffect(state.recording) { while (state.recording) { delay(500); vm.tick() } }
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Capturar en ArcadeCloud", style = MaterialTheme.typography.headlineSmall)
        Text("Destino: $destination", style = MaterialTheme.typography.bodyMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("Foto", "Video", "Audio").forEach { item -> FilterChip(selected = mode == item, onClick = { vm.discard(); mode = item }, label = { Text(item) }, enabled = !state.recording && !state.busy) }
        }
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (state.file != null) {
            if (state.mime.startsWith("image/")) AsyncImage(model = state.file, contentDescription = "Fotografía capturada", modifier = Modifier.weight(1f).fillMaxWidth())
            else MediaPreview(state.file!!.toURI().toString(), Modifier.weight(1f).fillMaxWidth())
            Text("${state.durationMs / 1000} s · ${state.bytes / 1024} KB")
            Button(onClick = { val file = state.file!!; onSave(android.net.Uri.fromFile(file).toString(), file.name, state.mime); vm.submitted() }, modifier = Modifier.fillMaxWidth()) { Text("Guardar en esta carpeta") }
            OutlinedButton(onClick = { vm.discard() }, modifier = Modifier.fillMaxWidth()) { Text("Repetir captura") }
        } else {
            if (mode != "Audio" && granted) AndroidView(factory = { preview }, modifier = Modifier.weight(1f).fillMaxWidth())
            else Spacer(Modifier.weight(1f))
            Text("${state.durationMs / 1000} s · ${state.bytes / 1024} KB${if (state.paused) " · En pausa" else ""}")
            if (!granted) Button(onClick = { permissions.launch(if (mode == "Audio") arrayOf(Manifest.permission.RECORD_AUDIO) else if (mode == "Video") arrayOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO) else arrayOf(Manifest.permission.CAMERA)) }, modifier = Modifier.fillMaxWidth()) { Text("Autorizar ${if (mode == "Audio") "micrófono" else "cámara"}") }
            else if (state.recording) {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    OutlinedButton(onClick = { vm.togglePause() }) { Text(if (state.paused) "Continuar" else "Pausar") }
                    Button(onClick = { vm.stop() }) { Text("Detener") }
                }
            } else {
                if (mode != "Audio") OutlinedButton(onClick = { front = !front }) { Text(if (front) "Usar cámara trasera" else "Usar cámara frontal") }
                Button(onClick = {
                    when (mode) {
                        "Foto" -> image?.let { it.targetRotation = preview.display?.rotation ?: android.view.Surface.ROTATION_0; vm.photo(it) }
                        "Video" -> video?.let { it.targetRotation = preview.display?.rotation ?: android.view.Surface.ROTATION_0; vm.startVideo(it) }
                        else -> vm.startAudio()
                    }
                }, enabled = !state.busy && (mode == "Audio" || (mode == "Foto" && image != null) || (mode == "Video" && video != null)), modifier = Modifier.fillMaxWidth()) { Text(if (mode == "Foto") "Tomar fotografía" else "Iniciar grabación") }
            }
        }
    }
}
