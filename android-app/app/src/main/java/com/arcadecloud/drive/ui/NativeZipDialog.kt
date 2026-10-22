package com.arcadecloud.drive.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.arcadecloud.drive.domain.DriveFile
import com.arcadecloud.drive.domain.SessionExpired
import com.arcadecloud.drive.transfer.TransferQueue
import org.json.JSONArray
import org.json.JSONObject

/** Uses the same guarded Lambda broker as the web explorer; never fetches the source ZIP. */
@Composable internal fun NativeZipDialog(vm: DriveViewModel, file: DriveFile, onDismiss: ()->Unit) {
    var dir by remember { mutableStateOf("") };var nested by remember { mutableStateOf(emptyList<String>()) }
    var page by remember { mutableIntStateOf(1) };var data by remember { mutableStateOf<JSONObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) };var notice by remember { mutableStateOf<String?>(null) }
    fun base()="zip_explorer.php?format=json&archivo=${enc(file.key)}&nested=${enc(JSONArray(nested).toString())}"
    LaunchedEffect(dir,page,nested) { data=null;error=null;try { data=vm.repo.request(base()+"&dir=${enc(dir)}&page=$page") }
        catch(e: Exception){if(e is SessionExpired)vm.run{throw e};error=e.message} }
    fun download(item: JSONObject) {
        val folder=item.optString("type")=="folder"
        val path=base()+if(folder)"&folder=${enc(item.getString("path"))}" else "&file=${enc(item.getString("path"))}"
        vm.run {
            TransferQueue.download(vm.graph,file.key,item.getString("name")+if(folder)".zip" else "",if(folder)"application/zip" else DriveViewModel.mimeFor(item.getString("name")),0,path)
            notice="Descarga enviada a Tareas. La extracción se realiza en Lambda y S3."
        }
    }
    AlertDialog(onDismissRequest=onDismiss,title={Text("ZIP · ${file.name}")},text={
        Column(Modifier.fillMaxWidth().heightIn(max=450.dp).verticalScroll(rememberScrollState()),verticalArrangement=Arrangement.spacedBy(8.dp)) {
            Text("/${nested.joinToString(" → ")} $dir")
            if(dir.isNotEmpty()||nested.isNotEmpty()) TextButton(onClick={if(dir.isNotEmpty())dir=dir.trimEnd('/').substringBeforeLast('/',"").let{if(it.isEmpty())"" else "$it/"} else nested=nested.dropLast(1);page=1}){Text("Volver")}
            error?.let{Text(it,color=MaterialTheme.colorScheme.error)};notice?.let{Text(it)}
            if(data==null&&error==null)LinearProgressIndicator(Modifier.fillMaxWidth())
            data?.let { j ->
                val a=j.optJSONArray("items") ?: JSONArray()
                if(a.length()==0)Text("No hay elementos")
                (0 until a.length()).forEach { index -> val item=a.getJSONObject(index);val type=item.optString("type")
                    Column {
                        Text(item.optString("name"),style=MaterialTheme.typography.titleSmall)
                        Row {
                            if(type=="folder"||type=="zip")TextButton(onClick={if(type=="folder")dir=item.getString("path") else {nested=nested+item.getString("path");dir=""};page=1}){Text("Abrir")}
                            TextButton(onClick={download(item)}){Text(if(type=="folder")"Descargar carpeta" else "Descargar")}
                        }
                    }
                }
                Row {TextButton(onClick={page--},enabled=page>1){Text("Anterior")};Text("Página $page")
                    TextButton(onClick={page++},enabled=page*j.optInt("page_size",100)<j.optInt("total")){Text("Siguiente")}}
            }
        }
    },confirmButton={TextButton(onClick=onDismiss){Text("Cerrar")}})
}
