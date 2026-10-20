package com.arcadecloud.drive;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;

/** Strict HTTPS host allowlist. No third-party URLs run in the signed-in WebView. */
public final class UrlPolicy {
    private UrlPolicy() {}

    public static final String START_URL = "https://drive.esforzados.com/so.php";

    public static URI parse(String raw) {
        if (raw == null || raw.isEmpty()) return null;
        try {
            return new URI(raw);
        } catch (URISyntaxException | IllegalArgumentException error) {
            return null;
        }
    }

    public static boolean isFirstParty(String raw) {
        return isFirstParty(parse(raw));
    }

    private static boolean isFirstParty(URI uri) {
        if (!validHttps(uri)) return false;
        String host = uri.getHost().toLowerCase(Locale.ROOT);
        return "drive.esforzados.com".equals(host) || "fastdrive.esforzados.com".equals(host);
    }

    public static boolean isTrustedDownload(String raw) {
        URI uri = parse(raw);
        if (!validHttps(uri)) return false;
        if (isFirstParty(uri)) return true;
        String host = uri.getHost().toLowerCase(Locale.ROOT);
        return "m41717c71ck.s3.amazonaws.com".equals(host)
            || "m41717c71ck.s3.us-east-1.amazonaws.com".equals(host);
    }

    public static boolean isLegacyDrive(String raw) {
        URI uri = parse(raw);
        if (!isFirstParty(uri)) return false;
        String path = uri.getPath();
        return path != null && (path.equals("/s3.php") || path.endsWith("/s3.php"));
    }

    private static boolean validHttps(URI uri) {
        return uri != null
            && "https".equalsIgnoreCase(uri.getScheme())
            && uri.getHost() != null
            && uri.getRawUserInfo() == null
            && (uri.getPort() == -1 || uri.getPort() == 443);
    }
}
