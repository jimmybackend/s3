package com.arcadecloud.drive;

import org.junit.Test;
import static org.junit.Assert.*;

public final class UrlPolicyTest {
    @Test public void firstPartyIsExactHttps() {
        assertTrue(UrlPolicy.isFirstParty("https://drive.esforzados.com/so.php"));
        assertTrue(UrlPolicy.isFirstParty("https://fastdrive.esforzados.com/federationcloud/"));
        assertFalse(UrlPolicy.isFirstParty("http://drive.esforzados.com/so.php"));
        assertFalse(UrlPolicy.isFirstParty("https://drive.esforzados.com.evil.example/"));
        assertFalse(UrlPolicy.isFirstParty("https://drive.esforzados.com:8443/so.php"));
        assertFalse(UrlPolicy.isFirstParty("https://user:pass@drive.esforzados.com/so.php"));
        assertFalse(UrlPolicy.isFirstParty("javascript:alert(1)"));
    }

    @Test public void signedS3DownloadsNeverBecomeWebViewOrigins() {
        String s3 = "https://m41717c71ck.s3.us-east-1.amazonaws.com/private?X-Amz-Signature=abc";
        assertTrue(UrlPolicy.isTrustedDownload(s3));
        assertFalse(UrlPolicy.isFirstParty(s3));
        assertFalse(UrlPolicy.isTrustedDownload("https://otherbucket.s3.us-east-1.amazonaws.com/a.zip"));
        assertFalse(UrlPolicy.isTrustedDownload("http://m41717c71ck.s3.amazonaws.com/a.zip"));
        assertFalse(UrlPolicy.isTrustedDownload("https://m41717c71ck.s3.amazonaws.com.evil.example/a.zip"));
        assertTrue(UrlPolicy.isTrustedDownload("https://drive.esforzados.com/descargar.php?archivo=123"));
    }

    @Test public void legacyDriveIsRedirectedOnlyOnTrustedOrigin() {
        assertTrue(UrlPolicy.isLegacyDrive("https://drive.esforzados.com/s3.php"));
        assertFalse(UrlPolicy.isLegacyDrive("https://drive.esforzados.com/so.php"));
        assertFalse(UrlPolicy.isLegacyDrive("https://other.example/s3.php"));
    }
}
