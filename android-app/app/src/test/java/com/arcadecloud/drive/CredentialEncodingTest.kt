package com.arcadecloud.drive

import okhttp3.FormBody
import okio.Buffer
import org.junit.Assert.assertEquals
import org.junit.Test
import java.net.URLDecoder

class CredentialEncodingTest {
    @Test fun formBodyPreservesPasswordBytesIncludingPlusSpacesAndUnicode() {
        val password = " aB+&%ñ= 123 \"'()$\\end "
        val body = FormBody.Builder().add("email", "native+test@example.test").add("password", password).build()
        val buffer = Buffer()
        body.writeTo(buffer)
        val fields = buffer.readUtf8().split('&').associate { pair ->
            val parts = pair.split('=', limit = 2)
            URLDecoder.decode(parts[0], "UTF-8") to URLDecoder.decode(parts[1], "UTF-8")
        }
        assertEquals("native+test@example.test", fields["email"])
        assertEquals(password, fields["password"])
    }
}
