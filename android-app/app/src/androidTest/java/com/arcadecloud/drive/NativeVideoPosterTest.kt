package com.arcadecloud.drive

import android.graphics.BitmapFactory
import android.graphics.Color
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.arcadecloud.drive.data.*
import com.arcadecloud.drive.domain.SessionExpired
import kotlinx.coroutines.*
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.*
import okhttp3.tls.HandshakeCertificates
import okhttp3.tls.HeldCertificate
import okio.Buffer
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong

@RunWith(AndroidJUnit4::class)
class NativeVideoPosterTest {
    private fun video() = InstrumentationRegistry.getInstrumentation().context.assets.open("black-then-green.mp4").use { it.readBytes() }
    private fun server(block: (MockWebServer,OkHttpClient)->Unit) {
        val cert=HeldCertificate.Builder().addSubjectAlternativeName("localhost").build()
        val server=MockWebServer()
        server.useHttps(HandshakeCertificates.Builder().heldCertificate(cert).build().sslSocketFactory(),false)
        val trust=HandshakeCertificates.Builder().addTrustedCertificate(cert.certificate).build()
        val client=OkHttpClient.Builder().sslSocketFactory(trust.sslSocketFactory(),trust.trustManager)
            .followRedirects(false).build()
        server.start()
        try {block(server,client)} finally {server.shutdown()}
    }
    @Test fun fallbackDecodesRealGreenFrameForSmallAndLargeContainerUsingOnlyRanges() = server { server,client ->
        val video=video();val transferred=AtomicLong()
        var length=video.size.toLong()
        server.dispatcher=object:Dispatcher() {
            override fun dispatch(request:RecordedRequest):MockResponse {
                if(request.path!!.startsWith("/thumb.php")) return MockResponse().setHeader("Content-Type","image/png").setHeader("X-Thumb-Status","FALLBACK").setBody("generic")
                val range=Regex("bytes=(\\d+)-(\\d+)").matchEntire(request.getHeader("Range").orEmpty()) ?: return MockResponse().setResponseCode(400)
                val start=range.groupValues[1].toLong();val end=minOf(range.groupValues[2].toLong(),length-1)
                val data=ByteArray((end-start+1).toInt()) { i -> video.getOrElse((start+i).toInt()){0} }
                transferred.addAndGet(data.size.toLong())
                assertNull(request.getHeader("Cookie"))
                return MockResponse().setResponseCode(206).setHeader("Content-Range","bytes $start-$end/$length").setBody(Buffer().write(data))
            }
        }
        val extractor=VideoFrameExtractor { url,current -> VideoRangeSource(url,current,client) }
        for (size in listOf(video.size.toLong(),256L*1024*1024)) {
            length=size;transferred.set(0)
            runBlocking {
                assertNull(ThumbnailClient(client).load(server.url("/thumb.php")))
                val jpeg=extractor.extract(server.url("/video.mp4").toString()){true}
                assertNotNull("Generic fallback must produce a real frame",jpeg)
                val frame=BitmapFactory.decodeByteArray(jpeg,0,jpeg!!.size)
                assertTrue(frame.width<=160 && frame.height<=120)
                val pixel=frame.getPixel(frame.width/2,frame.height/2)
                assertTrue("Expected the green frame near 1s, not the black opening",Color.green(pixel)>180 && Color.red(pixel)<60)
                frame.recycle()
                assertTrue(transferred.get()<=VideoRangeSource.MAX_BYTES)
                if(size>video.size) assertTrue(transferred.get()<size/10)
            }
        }
    }
    @Test fun fullBodyRedirectAndRevokedAccessAreRejectedWithoutFollowing() = server { server,client ->
        for(response in listOf(MockResponse().setBody("full video"),MockResponse().setResponseCode(302).setHeader("Location",server.url("/other")),MockResponse().setResponseCode(403))) {
            server.enqueue(response)
            val source=VideoRangeSource(server.url("/video").toString(),{true},client)
            try {source.size;fail("Expected partial response only")} catch(_:java.io.IOException) {} finally {source.close()}
        }
        assertEquals(3,server.requestCount)
        val source=VideoRangeSource(server.url("/video").toString(),{false},client)
        try {source.size;fail("Revoked identity accepted")} catch(_:java.io.IOException) {} finally {source.close()}
        assertEquals(3,server.requestCount)
    }
    @Test fun cancelledDecodeStopsActiveRangeAndDoesNotReturnPixels() = server { server,client -> runBlocking {
        server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.NO_RESPONSE))
        val extractor=VideoFrameExtractor { url,current -> VideoRangeSource(url,current,client) }
        val work=async {extractor.extract(server.url("/video").toString()){true}}
        withContext(Dispatchers.IO) {assertNotNull(server.takeRequest(5,TimeUnit.SECONDS))}
        work.cancelAndJoin();assertTrue(work.isCancelled)
    } }
    @Test fun expiredAndProtectedDownloadAuthorizationCannotReturnSignedSource() = server { server,client -> runBlocking {
        server.enqueue(MockResponse().setResponseCode(401))
        try {ThumbnailClient(client).signedVideoUrl(server.url("/api/native.php?action=download"));fail("Expired session accepted")} catch(_:SessionExpired) {}
        server.enqueue(MockResponse().setResponseCode(403))
        try {ThumbnailClient(client).signedVideoUrl(server.url("/api/native.php?action=download"));fail("Protected file accepted")} catch(_:com.arcadecloud.drive.domain.ApiError) {}
        server.enqueue(MockResponse().setHeader("Content-Type","application/json").setBody("{\"url_descarga\":\"https://bucket.s3.amazonaws.com/a?test=temporary\"}"))
        assertTrue(ThumbnailClient(client).signedVideoUrl(server.url("/api/native.php?action=download")).startsWith("https://bucket.s3.amazonaws.com/"))
    } }
    @Test fun rangeBudgetStopsRepeatedSeeksAndMalformedRangesAreRejected() = server { server,client ->
        server.dispatcher=object:Dispatcher() {
            override fun dispatch(request:RecordedRequest):MockResponse {
                val start=request.getHeader("Range")!!.substringAfter("bytes=").substringBefore('-').toLong()
                return MockResponse().setResponseCode(206).setHeader("Content-Range","bytes $start-${start+65535}/67108864")
                    .setBody(Buffer().write(ByteArray(65536)))
            }
        }
        val source=VideoRangeSource(server.url("/video").toString(),{true},client)
        try {
            val buffer=ByteArray(1)
            repeat(128){source.readAt((it%2)*65536L,buffer,0,1)}
            try {source.readAt(0,buffer,0,1);fail("Data budget exceeded")} catch(_:java.io.IOException) {}
            assertEquals(128,server.requestCount)
        } finally {source.close()}
        server.dispatcher=QueueDispatcher()
        server.enqueue(MockResponse().setResponseCode(206).setHeader("Content-Range","bytes 10-20/30").setBody("bad"))
        val invalid=VideoRangeSource(server.url("/invalid").toString(),{true},client)
        try {invalid.size;fail("Invalid range accepted")} catch(_:java.io.IOException) {} finally {invalid.close()}
    }

}
