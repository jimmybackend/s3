package com.arcadecloud.drive
import com.arcadecloud.drive.domain.*
import org.junit.Assert.*
import org.junit.Test
class TransferPolicyTest {
    @Test fun multipartBoundaries() {
        assertEquals(1, TransferMath.parts(TransferMath.PART))
        assertEquals(2, TransferMath.parts(TransferMath.PART + 1))
        assertEquals(1L, TransferMath.length(TransferMath.PART + 1, 2))
        assertEquals(TransferMath.PART, TransferMath.length(2 * TransferMath.PART, 2))
    }
    @Test fun progressNeverHasNegativeEta() {
        assertEquals(0L, TransferMath.eta(10,20,2))
        assertEquals(0L, TransferMath.eta(10,2,0))
        assertEquals(4L, TransferMath.eta(10,2,2))
    }
    @Test fun signedHostsCannotLeakSessionOrUploadToArbitraryServers() {
        listOf("http://bucket.s3.amazonaws.com/a", "https://evil.com/a", "https://s3.amazonaws.com.evil.com/a", "https://u:p@bucket.s3.amazonaws.com/a", "https://bucket.s3.amazonaws.com:444/a").forEach { url ->
            assertThrows(IllegalArgumentException::class.java) { NodePolicy.requireSignedUrl(url) }
        }
        assertEquals("https://bucket.s3.us-east-1.amazonaws.com/key?signature=x", NodePolicy.requireSignedUrl("https://bucket.s3.us-east-1.amazonaws.com/key?signature=x"))
    }
    @Test fun onlyExplicitNodesAreAccepted() {
        NodePolicy.nodes.forEach { assertEquals(it, NodePolicy.requireNode(it)) }
        assertThrows(IllegalArgumentException::class.java) { NodePolicy.requireNode("https://drive.esforzados.com.evil.com/") }
    }
}
