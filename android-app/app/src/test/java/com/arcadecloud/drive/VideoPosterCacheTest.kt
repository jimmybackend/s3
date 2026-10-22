package com.arcadecloud.drive

import com.arcadecloud.drive.data.VideoPosterCache
import com.arcadecloud.drive.domain.DriveFile
import org.junit.Assert.*
import org.junit.Test

class VideoPosterCacheTest {
    private val file = DriveFile(1,"clip.mp4","Data2/a.mp4","Data2/",100,"2026",false,"")
    @Test fun sessionNodeOwnerAndMetadataIsolateBytes() {
        val cache=VideoPosterCache(); val bytes=byteArrayOf(1,2)
        cache.put("node|2|session1",file,bytes)
        assertArrayEquals(bytes,cache.get("node|2|session1",file))
        assertNull(cache.get("node|2|session1",file.copy(date="changed")))
        assertNull(cache.get("node|2|session1",file.copy(key="Data2/b.mp4")))
        assertNull(cache.get("node|2|session2",file))
        cache.put("node|2|session2",file,bytes)
        assertNull(cache.get("other-node|2|session2",file))
        cache.put("node|2|session2",file,bytes)
        assertNull(cache.get("node|3|session2",file))
    }
    @Test fun protectedFilesAreNotCachedAndLogoutClearsMemory() {
        val cache=VideoPosterCache()
        for (f in listOf(file.copy(locked=true),file.copy(protected=true))) {
            cache.put("s",f,byteArrayOf(1));assertNull(cache.get("s",f))
        }
        cache.put("s",file,byteArrayOf(1));cache.clear();assertNull(cache.get("s",file))
    }
    @Test fun leastRecentlyUsedEntriesAreEvictedWithinMemoryBudget() {
        val cache=VideoPosterCache();val bytes=ByteArray(1024*1024)
        repeat(5){cache.put("s",file.copy(id=it.toLong()),bytes)}
        assertNull(cache.get("s",file.copy(id=0)))
        assertNotNull(cache.get("s",file.copy(id=4)))
    }
}
