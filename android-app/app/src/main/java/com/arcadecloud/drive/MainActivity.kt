package com.arcadecloud.drive

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import com.arcadecloud.drive.ui.ArcadeApp
import com.arcadecloud.drive.ui.ArcadeTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            val prefs = LocalContext.current.getSharedPreferences("native_ui", MODE_PRIVATE)
            var dark by remember { mutableStateOf(prefs.getBoolean("dark", true)) }
            ArcadeTheme(dark) { ArcadeApp(dark, { dark = it; prefs.edit().putBoolean("dark", it).apply() }) }
        }
    }
}
