package com.arcadecloud.drive.media

import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.MediaItem
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner

@Composable fun MediaPreview(uri: String, modifier: Modifier = Modifier) {
    val context = LocalContext.current; val owner = LocalLifecycleOwner.current
    val player = remember(uri) { ExoPlayer.Builder(context).build().apply { setMediaItem(MediaItem.fromUri(uri)); prepare(); playWhenReady = false } }
    DisposableEffect(player, owner) {
        val observer = LifecycleEventObserver { _, event -> if (event == Lifecycle.Event.ON_STOP) player.pause() }
        owner.lifecycle.addObserver(observer)
        onDispose { owner.lifecycle.removeObserver(observer); player.release() }
    }
    AndroidView(factory = { PlayerView(it).apply { this.player = player } }, modifier = modifier)
}
