package com.arcadecloud.drive.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.location.LocationManager
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner

/** Preserves the legacy local prerequisite. Never reads or transmits coordinates. */
object StartupPolicy {
    fun allowed(coarse: Boolean, fine: Boolean, services: Boolean) = (coarse || fine) && services
}
@Composable fun RequireLocation(content: @Composable () -> Unit) {
    val context = LocalContext.current; val owner = LocalLifecycleOwner.current
    fun allowed(): Boolean = StartupPolicy.allowed(
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED,
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED,
        context.getSystemService(LocationManager::class.java)?.let { LocationManagerCompat.isLocationEnabled(it) } == true)
    var ready by remember { mutableStateOf(allowed()) }
    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { ready = allowed() }
    DisposableEffect(owner) {
        val observer = LifecycleEventObserver { _, event -> if(event == Lifecycle.Event.ON_RESUME) ready = allowed() }
        owner.lifecycle.addObserver(observer)
        onDispose { owner.lifecycle.removeObserver(observer) }
    }
    if(ready) content() else Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(Modifier.widthIn(max = 520.dp).padding(24.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
                Text("Ubicación necesaria", style = MaterialTheme.typography.headlineMedium)
                Text("ArcadeCloud conserva el requisito de acceso de la APK anterior: autoriza ubicación aproximada o precisa y activa el servicio de ubicación de Android. Esta aplicación no obtiene ni guarda coordenadas.")
                Button(onClick = { permissions.launch(arrayOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION)) }, modifier = Modifier.fillMaxWidth()) { Text("Autorizar ubicación") }
                OutlinedButton(onClick = { context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS)) }, modifier = Modifier.fillMaxWidth()) { Text("Abrir ajustes de ubicación") }
                TextButton(onClick = { context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, android.net.Uri.parse("package:${context.packageName}"))) }) { Text("Permisos de la aplicación") }
            }
        }
    }
}
