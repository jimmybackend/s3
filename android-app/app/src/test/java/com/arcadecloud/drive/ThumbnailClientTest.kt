package com.arcadecloud.drive
import com.arcadecloud.drive.data.ThumbnailClient
import com.arcadecloud.drive.domain.SessionExpired
import kotlinx.coroutines.*
import okhttp3.*
import okhttp3.mockwebserver.*
import okio.Buffer
import org.junit.Assert.*
import org.junit.Test
import java.util.concurrent.TimeUnit
class ThumbnailClientTest {
    @Test fun existingSessionIsSentToThumbnailEndpoint() = runBlocking {
        val server=MockWebServer();server.start()
        try {
            val client=OkHttpClient.Builder().cookieJar(object:CookieJar {
                override fun saveFromResponse(url:HttpUrl,cookies:List<Cookie>) {}
                override fun loadForRequest(url:HttpUrl)=listOf(Cookie.Builder().name("PHPSESSID").value("isolated-test-session").domain(url.host).build())
            }).build()
            val bytes=byteArrayOf(1,2,3)
            server.enqueue(MockResponse().setHeader("Content-Type","image/jpeg").setHeader("X-Thumb-Status","S3_THUMB_HIT").setBody(Buffer().write(bytes)))
            assertArrayEquals(bytes,ThumbnailClient(client).load(server.url("/thumb.php?key=Data2%2Fa.jpg&w=160&h=120&fit=cover")))
            val request=server.takeRequest()
            assertEquals("PHPSESSID=isolated-test-session",request.getHeader("Cookie"))
            assertTrue(request.path!!.startsWith("/thumb.php?key="))
        } finally {server.shutdown()}
    }
    @Test fun fallbackHtmlAndRedirectNeverFetchAnOriginal() = runBlocking {
        val server=MockWebServer();server.start()
        try {
            val loader=ThumbnailClient(OkHttpClient())
            listOf(MockResponse().setHeader("Content-Type","image/png").setHeader("X-Thumb-Status","FALLBACK").setBody("placeholder"),
                MockResponse().setHeader("Content-Type","text/html").setBody("login page"),
                MockResponse().setResponseCode(302).setHeader("Location",server.url("/original.jpg"))).forEach { response ->
                server.enqueue(response);assertNull(loader.load(server.url("/thumb.php")))
            }
            assertEquals(3,server.requestCount)
        } finally {server.shutdown()}
    }
    @Test fun oversizedThumbnailIsBounded() = runBlocking {
        val server=MockWebServer();server.start()
        try {
            server.enqueue(MockResponse().setHeader("Content-Type","image/jpeg").setChunkedBody(Buffer().write(ByteArray(ThumbnailClient.MAX_BYTES.toInt()+100)),8192))
            assertNull(ThumbnailClient(OkHttpClient()).load(server.url("/thumb.php")))
        } finally {server.shutdown()}
    }
    @Test fun revokedSessionNeverReturnsThumbnailBytes() = runBlocking {
        val server=MockWebServer();server.start()
        try {
            listOf(MockResponse().setResponseCode(401),MockResponse().setHeader("Content-Type","image/png").setHeader("X-Thumb-Status","NO_SESSION").setBody("placeholder")).forEach { response ->
                server.enqueue(response)
                try { ThumbnailClient(OkHttpClient()).load(server.url("/thumb.php"));fail("Revoked session accepted") } catch(_:SessionExpired) {}
            }
        } finally {server.shutdown()}
    }
    @Test fun removingVisibleRowCancelsTheNetworkCall() = runBlocking {
        val server=MockWebServer();server.start()
        try {
            server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.NO_RESPONSE))
            val request=async(Dispatchers.Default){ThumbnailClient(OkHttpClient()).load(server.url("/thumb.php"))}
            assertNotNull(server.takeRequest(5,TimeUnit.SECONDS))
            request.cancelAndJoin();assertTrue(request.isCancelled)
        } finally {server.shutdown()}
    }
}
