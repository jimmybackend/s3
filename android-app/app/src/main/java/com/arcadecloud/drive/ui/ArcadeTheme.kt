package com.arcadecloud.drive.ui

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
private val Dark = darkColorScheme(primary = Color(0xFF55DDC3), secondary = Color(0xFF91BFF9), background = Color(0xFF071C25), surface = Color(0xFF102B36), onSurface = Color(0xFFE9F5F5))
private val Light = lightColorScheme(primary = Color(0xFF006B59), secondary = Color(0xFF265D9D), background = Color(0xFFE4F7F2), surface = Color(0xFFF4FFFB), onSurface = Color(0xFF102422))
@Composable fun ArcadeTheme(dark: Boolean = true, content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (dark) Dark else Light, content = content)
}
