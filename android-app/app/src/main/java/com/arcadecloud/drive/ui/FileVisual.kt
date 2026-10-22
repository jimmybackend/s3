package com.arcadecloud.drive.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import coil.request.CachePolicy
import coil.request.ImageRequest
import com.arcadecloud.drive.domain.*
import kotlinx.coroutines.CancellationException

/** Fetches only visible image rows. No private thumbnails survive in a shared image cache. */
@Composable internal fun FileVisual(file: DriveFile, sessionIdentity: String, load: suspend ()->ByteArray?) {
    key(sessionIdentity,file.key,file.name,file.date,file.bytes,file.locked) {
        FileVisualContent(file,load)
    }
}
@Composable private fun FileVisualContent(file: DriveFile, load: suspend ()->ByteArray?) {
    val kind=FilePresentation.kind(file.name)
    val data by produceState<ByteArray?>(null) {
        if(FilePresentation.thumbnailAllowed(file)) {
            try { value=load() } catch(e: CancellationException) { throw e } catch(_: Exception) { value=null }
        }
    }
    var decoded by remember(data) { mutableStateOf(false) }
    val context=LocalContext.current
    val request=remember(data) { data?.let { ImageRequest.Builder(context).data(it).size(160,120)
        .memoryCachePolicy(CachePolicy.DISABLED).diskCachePolicy(CachePolicy.DISABLED).build() } }
    Box(Modifier.size(72.dp,54.dp).clip(RoundedCornerShape(8.dp)).background(MaterialTheme.colorScheme.surfaceVariant),contentAlignment=Alignment.Center) {
        if(request!=null && !file.locked) AsyncImage(request,if(decoded)"Miniatura de ${file.name}" else null,
            modifier=Modifier.fillMaxSize(),contentScale=ContentScale.Crop,onSuccess={decoded=true},onError={decoded=false})
        if(!decoded || file.locked) Icon(if(file.locked) Icons.Default.Lock else fileTypeIcon(kind.category),
            if(file.locked)"Archivo protegido" else "Tipo: ${kind.label}",Modifier.size(30.dp),
            tint=if(kind.category=="pdf"&&!file.locked)MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
    }
}
private fun fileTypeIcon(category: String): ImageVector = when(category) {
    "pdf"->Icons.Default.PictureAsPdf;"word"->Icons.Default.Description;"excel"->Icons.Default.TableChart
    "powerpoint"->Icons.Default.Slideshow;"archive"->Icons.Default.Archive;"text"->Icons.Default.Notes
    "code"->Icons.Default.Code;"image"->Icons.Default.Image;"audio"->Icons.Default.AudioFile;"video"->Icons.Default.VideoFile
    "database"->Icons.Default.Storage;"ebook"->Icons.Default.MenuBook;"mail"->Icons.Default.Email;"font"->Icons.Default.TextFields
    "certificate"->Icons.Default.VpnKey;"package"->Icons.Default.Inventory2;"design"->Icons.Default.DesignServices
    "model"->Icons.Default.ViewInAr;else->Icons.Default.InsertDriveFile
}
