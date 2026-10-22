package com.arcadecloud.drive
import android.graphics.Bitmap
import android.graphics.Color
import androidx.compose.foundation.layout.Column
import androidx.compose.runtime.*
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.arcadecloud.drive.domain.DriveFile
import com.arcadecloud.drive.ui.ArcadeTheme
import com.arcadecloud.drive.ui.FileVisual
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import java.io.ByteArrayOutputStream
@RunWith(AndroidJUnit4::class)
class NativeFileVisualTest {
    @get:Rule val compose=createComposeRule()
    private fun file(name:String,locked:Boolean=false)=DriveFile(1,name,"Data2/$name","Data2/",100,"2026",locked,"")
    @Test fun documentIconsAndLockedImageNeverLoadThumbnails() {
        var calls=0
        compose.setContent { ArcadeTheme { Column {
            FileVisual(file("acta.pdf"),"test"){calls++;null}
            FileVisual(file("fotos.zip"),"test"){calls++;null}
            FileVisual(file("privada.jpg",true),"test"){calls++;null}
            FileVisual(file("privado.mp4",true),"test"){calls++;null}
        } } }
        compose.onNodeWithContentDescription("Tipo: PDF").assertIsDisplayed()
        compose.onNodeWithContentDescription("Tipo: Archivo comprimido").assertIsDisplayed()
        compose.onAllNodesWithContentDescription("Archivo protegido").assertCountEquals(2)
        compose.runOnIdle {assertEquals(0,calls)}
    }
    @Test fun imageShowsRealDecodedThumbnailAndDisappearsWhenRelocked() {
        val bitmap=Bitmap.createBitmap(16,16,Bitmap.Config.ARGB_8888).apply{eraseColor(Color.GREEN)}
        val bytes=ByteArrayOutputStream().use{bitmap.compress(Bitmap.CompressFormat.PNG,100,it);it.toByteArray()};bitmap.recycle()
        var locked by mutableStateOf(false);var calls=0
        compose.setContent {ArcadeTheme {FileVisual(file("foto.jpg",locked),"test"){calls++;bytes}}}
        compose.waitUntil(10000){compose.onAllNodesWithContentDescription("Miniatura de foto.jpg").fetchSemanticsNodes().isNotEmpty()}
        compose.onNodeWithContentDescription("Miniatura de foto.jpg").assertIsDisplayed()
        compose.runOnIdle{locked=true}
        compose.onNodeWithContentDescription("Archivo protegido").assertIsDisplayed()
        compose.onNodeWithContentDescription("Miniatura de foto.jpg").assertDoesNotExist()
        compose.runOnIdle{assertEquals(1,calls)}
    }
    @Test fun videoPosterShowsCameraAndIsDiscardedOnSessionChange() {
        val bitmap=Bitmap.createBitmap(16,16,Bitmap.Config.ARGB_8888).apply{eraseColor(Color.GREEN)}
        val bytes=ByteArrayOutputStream().use{bitmap.compress(Bitmap.CompressFormat.JPEG,80,it);it.toByteArray()};bitmap.recycle()
        var identity by mutableStateOf("user2-session1")
        compose.setContent {ArcadeTheme {FileVisual(file("clip.mp4"),identity){if(identity=="user2-session1") bytes else null}}}
        compose.waitUntil(10000){compose.onAllNodesWithContentDescription("Miniatura de clip.mp4").fetchSemanticsNodes().isNotEmpty()}
        compose.onNodeWithContentDescription("Video").assertIsDisplayed()
        compose.runOnIdle{identity="user3-session2"}
        compose.onNodeWithContentDescription("Miniatura de clip.mp4").assertDoesNotExist()
        compose.onNodeWithContentDescription("Video").assertDoesNotExist()
        compose.onNodeWithContentDescription("Tipo: Video").assertIsDisplayed()
    }

}
