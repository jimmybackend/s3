package com.arcadecloud.drive.ui

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.compose.*
import coil.compose.AsyncImage
import com.arcadecloud.drive.domain.*
import com.arcadecloud.drive.media.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

@OptIn(ExperimentalMaterial3Api::class)
@Composable fun ArcadeApp(dark: Boolean, onTheme: (Boolean) -> Unit, vm: DriveViewModel = viewModel()) {
    val state by vm.state.collectAsStateWithLifecycle()
    val nav = rememberNavController()
    val entry by nav.currentBackStackEntryAsState()
    val section = entry?.destination?.route ?: "files"
    val context = LocalContext.current
    var captureRoute by rememberSaveable { mutableStateOf("") }
    var captureLabel by rememberSaveable { mutableStateOf("") }
    var captureMode by rememberSaveable { mutableStateOf("Foto") }
    val notifications = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }
    LaunchedEffect(state.authenticated) { if (state.authenticated && android.os.Build.VERSION.SDK_INT >= 33) notifications.launch(Manifest.permission.POST_NOTIFICATIONS) }
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        if (!state.authenticated) LoginScreen(state.busy, state.error, vm::login)
        else Scaffold(topBar = {
            TopAppBar(title = { Column { Text("ArcadeCloud", style = MaterialTheme.typography.titleLarge); Text(state.alias, style = MaterialTheme.typography.labelMedium) } },
                actions = { IconButton(onClick = { if(section == "tasks") vm.refreshTasks() else if(section == "search") vm.notice("Usa el botón Buscar para actualizar los resultados con tus filtros.") else vm.browse() }) { Icon(Icons.Default.Refresh, "Actualizar") } })
        }, bottomBar = {
            DriveNavigationBar(section) { route -> vm.clearSelection(); nav.navigate(route) { launchSingleTop = true; popUpTo("files") } }

        }) { padding ->
            Column(Modifier.padding(padding).fillMaxSize()) {
                if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                state.error?.let { Text(it, Modifier.padding(12.dp), color = MaterialTheme.colorScheme.error) }
                state.notice?.let { message -> Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(message, Modifier.weight(1f).padding(horizontal = 12.dp), style = MaterialTheme.typography.bodySmall)
                    IconButton(onClick = { vm.notice(null) }) { Icon(Icons.Default.Close, "Cerrar aviso") }
                } }
                NavHost(nav, startDestination = "files", modifier = Modifier.weight(1f)) {
                    composable("files") { FilesScreen(state, vm, false) { route,label,mode -> captureRoute = route; captureLabel = label; captureMode = mode; nav.navigate("capture") } }
                    composable("search") { FilesScreen(state, vm, true) { route,label,mode -> captureRoute = route; captureLabel = label; captureMode = mode; nav.navigate("capture") } }
                    composable("capture") { CaptureScreen(captureLabel, { uri,name,mime -> vm.upload(uri,name,mime,captureRoute); nav.navigate("tasks") { popUpTo("files") } }, initialMode = captureMode) }
                    composable("tasks") { TasksScreen(vm, state.serverTasks) }
                    composable("settings") {
                        Column(Modifier.padding(20.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(16.dp)) {
                            Text("Tu nube, en Android", style = MaterialTheme.typography.headlineSmall)
                            Text(vm.repo.node); Text("Versión ${com.arcadecloud.drive.BuildConfig.VERSION_NAME}")
                            Row(verticalAlignment = Alignment.CenterVertically) { Text("Tema oscuro", Modifier.weight(1f)); Switch(dark, onCheckedChange = onTheme) }
                            Text("El tamaño del texto respeta los ajustes de Android. Las grabaciones se detienen al salir de la aplicación. Las transferencias dependen de las restricciones de batería y red de Android.")
                            Text("Las sesiones se guardan cifradas con Android Keystore. Tu contraseña no se guarda.")
                            OutlinedButton(onClick = { vm.logout() }, enabled = !state.busy) { Text("Cerrar sesión") }
                        }
                    }
                }
            }
        }
    }
}

@Composable fun LoginScreen(busy: Boolean, error: String?, onLogin: (String,String,String) -> Unit) {
    var email by rememberSaveable { mutableStateOf("") }; var password by remember { mutableStateOf("") }
    var node by rememberSaveable { mutableStateOf(NodePolicy.nodes.first()) }
    var passwordVisible by remember { mutableStateOf(false) }
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(Modifier.widthIn(max = 520.dp).fillMaxWidth().padding(24.dp).imePadding().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Icon(Icons.Default.Cloud, null, Modifier.size(56.dp), tint = MaterialTheme.colorScheme.primary)
            Text("ArcadeCloud", style = MaterialTheme.typography.headlineLarge)
            Text("Tus archivos. Tu espacio.", style = MaterialTheme.typography.titleMedium)
            NodePolicy.nodes.forEach { candidate -> Row(Modifier.fillMaxWidth().clickable(enabled = !busy) { node = candidate }, verticalAlignment = Alignment.CenterVertically) {
                RadioButton(node == candidate, onClick = { node = candidate }, enabled = !busy); Text(Uri.parse(candidate).host ?: candidate)
            } }
            OutlinedTextField(email, { email = it }, label = { Text("Correo o identificador de acceso") },
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false, keyboardType = KeyboardType.Email),
                singleLine = true, modifier = Modifier.fillMaxWidth(), enabled = !busy)
            OutlinedTextField(password, { password = it }, label = { Text("Contraseña") },
                visualTransformation = if (passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Password),
                trailingIcon = { IconButton(onClick = { passwordVisible = !passwordVisible }, enabled = !busy) {
                    Icon(if (passwordVisible) Icons.Default.VisibilityOff else Icons.Default.Visibility,
                        if (passwordVisible) "Ocultar contraseña" else "Mostrar contraseña")
                } }, singleLine = true, modifier = Modifier.fillMaxWidth(), enabled = !busy)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(onClick = { passwordVisible = false; onLogin(node, email.trim(), password) }, enabled = !busy && email.isNotBlank() && password.isNotEmpty(), modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text(if (busy) "Conectando…" else "Iniciar sesión") }
            Text("Conexión HTTPS con tu nodo ArcadeCloud", style = MaterialTheme.typography.bodySmall)
            Text("Versión ${com.arcadecloud.drive.BuildConfig.VERSION_NAME}", style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable internal fun DestinationDialog(vm: DriveViewModel, root: String, onSelect: (String) -> Unit, onDismiss: () -> Unit, confirmLabel: String = "Elegir esta carpeta") {
    var route by remember { mutableStateOf(root) }; var page by remember { mutableIntStateOf(1) }
    var result by remember { mutableStateOf<Catalog?>(null) }; var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(route,page) { result = null; error = null; try { result = vm.repo.browse(route, folderPage = page) } catch(e: Exception) { error = e.message } }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Carpeta destino") }, text = {
        Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
            error?.let { Text(it) }
            result?.let { catalog ->
                Text(catalog.crumbs.joinToString(" / ") { it.name })
                catalog.crumbs.dropLast(1).lastOrNull()?.let { parent -> TextButton(onClick = { route = parent.route; page = 1 }) { Text("Subir una carpeta") } }
                catalog.folders.forEach { folder -> TextButton(onClick = { route = folder.route; page = 1 }) { Text(folder.name) } }
                if(page > 1) TextButton(onClick = { page-- }) { Text("Anteriores") }
                if(catalog.moreFolders) TextButton(onClick = { page++ }) { Text("Más carpetas") }
            }
        }
    }, confirmButton = { TextButton(onClick = { onSelect(route) }, enabled = result != null) { Text(confirmLabel) } }, dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } })
}

@Composable private fun TasksScreen(vm: DriveViewModel, remote: List<JSONObject>) {
    val context = LocalContext.current
    var tasks by remember { mutableStateOf(emptyList<JSONObject>()) }; var local by remember { mutableStateOf<JSONObject?>(null) }
    LaunchedEffect(Unit) { var ticks = 0; vm.refreshTasks(); while(true) { if(ticks++ % 5 == 0) vm.refreshTasks(); tasks = withContext(Dispatchers.IO) {
        val rows = vm.graph.transfers.list(vm.repo.owner, vm.repo.node)
        rows.forEach { t ->
            if(t.optString("status") in listOf("running", "queued")) {
                val work = androidx.work.WorkManager.getInstance(context).getWorkInfosForUniqueWork(t.getString("id")).get().lastOrNull()
                if(work?.state == androidx.work.WorkInfo.State.CANCELLED) vm.graph.transfers.update(t.getString("id")) { it.put("status", "paused").put("speed", 0) }
                if(work?.state in listOf(androidx.work.WorkInfo.State.ENQUEUED, androidx.work.WorkInfo.State.BLOCKED) && t.optString("status") == "running")
                    vm.graph.transfers.update(t.getString("id")) { it.put("status", "queued").put("speed", 0) }
                if(work == null && System.currentTimeMillis() - t.optLong("created") > 10000)
                    vm.graph.transfers.update(t.getString("id")) { it.put("status", "paused").put("error", "Trabajo Android no programado. Revisa y reintenta la tarea.") }
            }
        }
        vm.graph.transfers.list(vm.repo.owner, vm.repo.node)
    }; delay(1000) } }
    LazyColumn(Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item { Text("Transferencias del teléfono", style = MaterialTheme.typography.titleLarge) }
        if(tasks.isEmpty()) item { Text("No hay transferencias locales") }
        items(tasks, key = { it.getString("id") }) { task ->
            ElevatedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(task.getString("name"), style = MaterialTheme.typography.titleMedium)
                val total = task.optLong("bytes"); val done = task.optLong("done")
                Text("${task.getString("status")} · ${bytes(done)} / ${bytes(total)}")
                LinearProgressIndicator(progress = { if(total > 0) (done.toFloat() / total).coerceIn(0f,1f) else 0f }, modifier = Modifier.fillMaxWidth())
                val elapsed = (task.optLong("finished",System.currentTimeMillis()) - task.optLong("started",System.currentTimeMillis())) / 1000
                Text("${bytes(task.optLong("speed"))}/s · ${maxOf(0,elapsed)} s · Restan aprox. ${task.optLong("eta")} s", style = MaterialTheme.typography.bodySmall)
                if(task.has("error")) Text(task.getString("error"), color = MaterialTheme.colorScheme.error)
                Row {
                    val status = task.getString("status"); val id = task.getString("id")
                    if(status in listOf("running","queued")) TextButton(onClick = { vm.pause(id,false) }) { Text("Pausar") }
                    if(status in listOf("paused","failed")) TextButton(onClick = { vm.resume(id) }) { Text("Reintentar") }
                    if(status !in listOf("completed","cancelled")) TextButton(onClick = { vm.pause(id,true) }) { Text("Cancelar") }
                    if(status == "completed" && task.has("local")) TextButton(onClick = { local = task }) { Text("Abrir") }
                }
            } }
        }
        item { Text("Tareas del servidor", style = MaterialTheme.typography.titleLarge) }
        items(remote, key = { "server:${it.optString("control_id",it.optString("id"))}" }) { task ->
            ListItem(headlineContent = { Text(task.optString("title")) }, supportingContent = { Text("${task.optString("status")} · ${task.optString("detail")}") })
        }
    }
    local?.let { task ->
        val file = File(task.getString("local")); val mime = task.getString("mime")
        AlertDialog(onDismissRequest = { local = null }, title = { Text(task.getString("name")) }, text = {
            if(mime.startsWith("image/")) AsyncImage(file, "Archivo descargado", Modifier.fillMaxWidth().heightIn(max = 400.dp))
            else if(mime.startsWith("audio/") || mime.startsWith("video/")) MediaPreview(file.toURI().toString(), Modifier.fillMaxWidth().height(300.dp))
            else Text("El documento está listo para abrir con una aplicación de Android.")
        }, confirmButton = { TextButton(onClick = { local = null }) { Text("Cerrar") } }, dismissButton = {
            TextButton(onClick = { runCatching {
                val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
                context.startActivity(Intent.createChooser(Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), "Abrir documento"))
            }.onFailure { vm.run { throw IllegalStateException("Instala una aplicación compatible para abrir este tipo de archivo.") } } }) { Text("Abrir con…") }
        })
        if(mime.startsWith("image/")) TextButton(onClick = { runCatching {
            val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
            context.startActivity(Intent.createChooser(Intent(Intent.ACTION_ATTACH_DATA).setDataAndType(uri,mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), "Usar imagen como fondo"))
        }.onFailure { vm.notice("No hay una aplicación disponible para configurar este fondo.") } }) { Text("Fondo de Android") }
    }
}
fun bytes(value: Long): String = if(value < 1024) "$value B" else if(value < 1024 * 1024) "${value / 1024} KB" else "%.1f MB".format(value / 1048576.0)
