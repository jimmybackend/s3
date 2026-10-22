package com.arcadecloud.drive
import com.arcadecloud.drive.domain.*
import org.junit.Assert.*
import org.junit.Test
class BrowserActionsTest {
    private fun file(name: String, locked: Boolean=false, protected: Boolean=locked)=DriveFile(1,name,"Data/a", "Data/",10,"2026",locked,"",protected)
    @Test fun lockedFilesOnlyExposeDetailsTasksAndUnlock() {
        assertEquals(setOf("details","fileTasks","unlock"),BrowserActions.files(file("a.mp4",true)).map{it.id}.toSet())
    }
    @Test fun fileActionsMatchTypeAndProtection() {
        val video=BrowserActions.files(file("a.mp4")).map{it.id}
        assertTrue(video.containsAll(listOf("transcribe","split_video","extract_mp3","copy","move","share","lock")))
        assertFalse(video.contains("editText"));assertFalse(video.contains("rekognition"))
        val unlocked=BrowserActions.files(file("a.txt",protected=true)).map{it.id}
        assertTrue(unlocked.containsAll(listOf("editText","polly","comprehend","relock","unsecure")))
        assertFalse(unlocked.contains("lock"))
        assertTrue(BrowserActions.files(file("a.zip")).any{it.id=="zip"})
    }
    @Test fun rootCannotBeDeletedMovedRenamedOrCut() {
        val root=BrowserActions.folders(true,false).map{it.id}
        assertFalse(root.any{it in listOf("deleteFolder","moveFolder","renameFolder","folderClipboardCut")})
        assertFalse(root.contains("paste"))
        assertTrue(BrowserActions.folders(false,true).map{it.id}.containsAll(listOf("copyFolder","moveFolder","deleteFolder","renameFolder","paste","folderTextract","sync")))
    }
    @Test fun existingLegacySuccessCannotOverrideExplicitFailure() {
        assertTrue(ApiOutcome.successful(false,false,"ok"))
        assertTrue(ApiOutcome.successful(true,true,"queued"))
        assertFalse(ApiOutcome.successful(true,false,"ok"))
        assertFalse(ApiOutcome.successful(true,true,"error"))
        assertFalse(ApiOutcome.successful(false,false,""))
    }
    @Test fun folderCopyUsesExistingTaskFields() {
        val fields=DriveClipboard("copy",folder="Data/Origen/").fields("Data/Destino/")
        assertEquals(mapOf("type" to "folder","operation" to "copy","origen" to "Data/Origen/","destino" to "Data/Destino/"),fields)
    }
}
