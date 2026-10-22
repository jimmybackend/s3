package com.arcadecloud.drive.ui

import android.content.Intent
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.arcadecloud.drive.domain.*

@Composable fun DriveNavigationBar(section: String, onNavigate: (String) -> Unit) {
    NavigationBar {
        listOf(Triple("files","Archivos",Icons.Default.Folder), Triple("search","Buscar",Icons.Default.Search),
            Triple("tasks","Tareas",Icons.Default.Sync), Triple("settings","Cuenta",Icons.Default.Person)).forEach { (route,label,icon) ->
            NavigationBarItem(selected = section == route, onClick = { onNavigate(route) }, icon = { Icon(icon,null) }, label = { Text(label) })
        }
    }
}

@Composable fun BrowserFooter(page: Int, pages: Int, folderPage: Int, moreFolders: Boolean, busy: Boolean,
    onPage: (Int,Int) -> Unit, onUpload: () -> Unit, onPhotos: () -> Unit, onCapture: () -> Unit) {
    Column {
        if(moreFolders || folderPage > 1) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            TextButton(modifier = Modifier.weight(1f), onClick = { onPage(page,folderPage-1) }, enabled = !busy && folderPage > 1) { Text("Anteriores") }
            Text("Carpetas · $folderPage", style = MaterialTheme.typography.labelSmall)
            TextButton(modifier = Modifier.weight(1f), onClick = { onPage(page,folderPage+1) }, enabled = !busy && moreFolders) { Text("Más carpetas") }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = { onPage(page-1,folderPage) }, enabled = !busy && page > 1) { Text("Anterior") }
            Text("$page / $pages")
            TextButton(onClick = { onPage(page+1,folderPage) }, enabled = !busy && page < pages) { Text("Siguiente") }
        }
        HorizontalDivider()
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
            FilledTonalIconButton(onClick = onUpload, enabled = !busy) { Icon(Icons.Default.UploadFile,"Subir archivos") }
            FilledTonalIconButton(onClick = onPhotos, enabled = !busy) { Icon(Icons.Default.PhotoLibrary,"Elegir fotos o videos") }
            FilledTonalIconButton(onClick = onCapture, enabled = !busy) { Icon(Icons.Default.CameraAlt,"Cámara, video y notas de voz") }
        }
    }
}

@Composable internal fun FilesScreen(state: DriveState, vm: DriveViewModel, searchMode: Boolean, onCapture: (String,String,String) -> Unit) {
    val context = LocalContext.current
    val catalog = if(searchMode) state.searchCatalog else state.catalog
    var query by rememberSaveable { mutableStateOf("") }; var type by rememberSaveable { mutableStateOf("") }
    var descending by rememberSaveable { mutableStateOf(false) }
    var file by remember { mutableStateOf<DriveFile?>(null) }; var folder by remember { mutableStateOf<Folder?>(null) }
    var menu by remember { mutableStateOf("") }; var operation by remember { mutableStateOf("") }
    var targetRoute by rememberSaveable { mutableStateOf("") }
    val load: (String,Int,Int) -> Unit = { route,page,fp ->
        if(searchMode) vm.search(route,page,fp,query,type) else vm.browse(route,page,fp)
    }
    LaunchedEffect(searchMode) { if(searchMode && catalog == null) vm.search(state.catalog?.route ?: state.root) }
    fun enqueue(uris: List<Uri>, fallback: String) {
        uris.forEach { uri ->
            runCatching { context.contentResolver.takePersistableUriPermission(uri,Intent.FLAG_GRANT_READ_URI_PERMISSION) }
            val name = context.contentResolver.query(uri,arrayOf(OpenableColumns.DISPLAY_NAME),null,null,null)?.use { c -> if(c.moveToFirst()) c.getString(0) else null } ?: fallback
            vm.upload(uri.toString(),name,context.contentResolver.getType(uri) ?: "application/octet-stream",targetRoute)
        }
    }
    val docs = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { enqueue(it,"archivo") }
    val photos = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia()) { enqueue(it,"imagen-${System.currentTimeMillis()}.jpg") }
    if(catalog == null) { Box(Modifier.fillMaxSize(),contentAlignment=Alignment.Center) { Text("Abriendo tu Drive…") }; return }
    val current = Folder(catalog.route,catalog.crumbs.lastOrNull()?.name ?: "Mi Drive")
    val parent = catalog.crumbs.dropLast(1).lastOrNull()
    BackHandler(parent != null) { parent?.let { load(it.route,1,1) } }
    Column(Modifier.fillMaxSize().padding(horizontal=12.dp),verticalArrangement=Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment=Alignment.CenterVertically) {
            IconButton(onClick={ parent?.let { load(it.route,1,1) } },enabled=parent!=null && !state.busy) { Icon(Icons.AutoMirrored.Filled.ArrowBack,"Carpeta anterior") }
            Text(catalog.crumbs.joinToString(" / ") { it.name },Modifier.weight(1f),style=MaterialTheme.typography.titleMedium,maxLines=2,overflow=TextOverflow.Ellipsis)
            IconButton(onClick={ descending=!descending }) { Icon(Icons.Default.SortByAlpha,"Ordenar nombres en esta página") }
            IconButton(onClick={ folder=current; menu="folder" },enabled=!state.busy) { Icon(Icons.Default.MoreVert,"Acciones de la carpeta actual") }
        }
        if(searchMode) SearchControls(query,type,{ query=it },{ type=it },{ load(catalog.route,1,1) },{ query="";type="";vm.search(catalog.route) },state.busy)
        if(state.selected.isNotEmpty()) Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically) {
            Text("${state.selected.size} seleccionados",Modifier.weight(1f))
            TextButton(onClick={ menu="selection" }) { Text("Acciones") }
        }
        LazyColumn(Modifier.weight(1f),verticalArrangement=Arrangement.spacedBy(6.dp)) {
            items(if(descending) catalog.folders.sortedByDescending { it.name.lowercase() } else catalog.folders,key={"folder:${it.route}"}) { item ->
                ElevatedCard(Modifier.fillMaxWidth()) { Row(Modifier.fillMaxWidth().clickable(enabled=!state.busy) { load(item.route,1,1) }.padding(10.dp),verticalAlignment=Alignment.CenterVertically) {
                    Icon(Icons.Default.Folder,null,tint=MaterialTheme.colorScheme.primary);Spacer(Modifier.width(12.dp))
                    Text(item.name,Modifier.weight(1f),maxLines=2,overflow=TextOverflow.Ellipsis)
                    IconButton(onClick={ folder=item; menu="folder" },enabled=!state.busy) { Icon(Icons.Default.MoreVert,"Acciones de carpeta ${item.name}") }
                } }
            }
            items(if(descending) catalog.files.sortedByDescending { it.name.lowercase() } else catalog.files,key={"file:${it.id}"}) { item ->
                ElevatedCard(Modifier.fillMaxWidth()) { Row(Modifier.padding(8.dp),verticalAlignment=Alignment.CenterVertically) {
                    Checkbox(item.id in state.selected,onCheckedChange={ vm.select(item.id) },enabled=!state.busy)
                    Icon(if(item.locked) Icons.Default.Lock else Icons.Default.InsertDriveFile,null,tint=MaterialTheme.colorScheme.secondary)
                    Column(Modifier.weight(1f).clickable { file=item;menu="file" }.padding(10.dp)) {
                        Text(item.name,maxLines=2,overflow=TextOverflow.Ellipsis)
                        Text("${bytes(item.bytes)} · ${item.date}",style=MaterialTheme.typography.bodySmall)
                    }
                    IconButton(onClick={ file=item;menu="file" },enabled=!state.busy) { Icon(Icons.Default.MoreVert,"Acciones del archivo ${item.name}") }
                } }
            }
            if(catalog.files.isEmpty() && catalog.folders.isEmpty()) item { Text("No hay elementos que mostrar",Modifier.padding(20.dp)) }
        }
        BrowserFooter(catalog.page,catalog.pages,catalog.folderPage,catalog.moreFolders,state.busy,
            { page,fp -> load(catalog.route,page,fp) },
            { targetRoute=catalog.route;docs.launch(arrayOf("*/*")) },
            { targetRoute=catalog.route;photos.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
            { onCapture(catalog.route,current.name,"Foto") })
    }
    val refresh = { load(catalog.route,catalog.page,catalog.folderPage) }
    if(menu.isNotEmpty()) {
        val actions = when(menu) {
            "folder" -> BrowserActions.folders(folder?.route==state.root,vm.clipboard!=null)
            "file" -> file?.let { BrowserActions.files(it) } ?: emptyList()
            else -> listOf(BrowserAction("downloadMany","Descargar seleccionados"),BrowserAction("copyMany","Copiar seleccionados"),BrowserAction("moveMany","Mover seleccionados"),BrowserAction("deleteMany","Eliminar seleccionados"))
        }
        AlertDialog(onDismissRequest={ menu="" },title={Text(when(menu){"folder"->folder?.name ?: "Carpeta";"file"->file?.name ?: "Archivo";else->"Selección"})},text={
            Column(Modifier.fillMaxWidth().heightIn(max=440.dp).verticalScroll(rememberScrollState())) {
                actions.forEach { action -> TextButton(onClick={
                    when(action.id) {
                        "openFolder" -> folder?.let { load(it.route,1,1) }
                        "photo","video","audio" -> folder?.let { onCapture(it.route,it.name,when(action.id){"audio"->"Audio";"video"->"Video";else->"Foto"}) }
                        "clipboardCopy","clipboardCut" -> file?.let { vm.copyToClipboard(DriveClipboard(if(action.id=="clipboardCopy")"copy" else "move",keys=listOf(it.key))) }
                        "folderClipboardCopy","folderClipboardCut" -> folder?.let { vm.copyToClipboard(DriveClipboard(if(action.id=="folderClipboardCopy")"copy" else "move",folder=it.route)) }
                        "paste" -> vm.run { vm.paste(folder!!.route);refresh() }
                        "downloadMany" -> catalog.files.filter { it.id in state.selected }.forEach { vm.download(it) }
                        else -> operation=action.id
                    }; menu=""
                },modifier=Modifier.fillMaxWidth(),enabled=!state.busy) { Text(action.label,Modifier.fillMaxWidth()) } }
            }
        },confirmButton={ TextButton(onClick={menu=""}) {Text("Cerrar")} })
    }
    if(operation.isNotEmpty()) BrowserOperationDialog(vm,operation,file,folder ?: current,catalog.files.filter{it.id in state.selected},refresh,{operation=""})
}

@Composable fun SearchControls(query: String, type: String, onQuery: (String)->Unit, onType: (String)->Unit, onSearch: ()->Unit, onClear: ()->Unit, busy: Boolean) {
    Column(verticalArrangement=Arrangement.spacedBy(6.dp)) {
        OutlinedTextField(query,onQuery,label={Text("Buscar en esta carpeta")},singleLine=true,modifier=Modifier.fillMaxWidth(),enabled=!busy)
        Row(verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(6.dp)) {
            OutlinedTextField(type,onType,label={Text("Extensión: pdf, jpg…")},singleLine=true,modifier=Modifier.weight(1f),enabled=!busy)
            IconButton(onClick=onSearch,enabled=!busy) {Icon(Icons.Default.Search,"Ejecutar búsqueda")}
            TextButton(onClick=onClear,enabled=!busy) {Text("Limpiar")}
        }
    }
}
