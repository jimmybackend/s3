package com.arcadecloud.drive.data

import com.arcadecloud.drive.domain.DriveFile

/** JPEG bytes only, private in-memory LRU. Never stores signed URLs or credentials. */
internal class VideoPosterCache {
    private var session = ""
    private var bytes = 0
    private val entries = LinkedHashMap<DriveFile, ByteArray>(16, 0.75f, true)
    @Synchronized fun clear() { entries.clear(); bytes = 0; session = "" }
    @Synchronized fun get(identity: String, file: DriveFile): ByteArray? {
        select(identity)
        return entries[file]
    }
    @Synchronized fun put(identity: String, file: DriveFile, value: ByteArray) {
        select(identity)
        if (file.locked || file.protected || value.size > LIMIT) return
        entries.remove(file)?.let { bytes -= it.size }
        entries[file] = value; bytes += value.size
        while (bytes > LIMIT) { val first = entries.entries.iterator(); bytes -= first.next().value.size; first.remove() }
    }
    private fun select(identity: String) { if (identity != session) { clear(); session = identity } }
    companion object { const val LIMIT = 4 * 1024 * 1024 }
}
