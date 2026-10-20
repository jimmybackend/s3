package com.arcadecloud.drive;

import android.Manifest;
import android.content.pm.PackageManager;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.ContentValues;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.view.Gravity;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.net.http.SslError;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.WebSettings;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URI;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Thin Android client for ArcadeCloud Web OS.
 * All authorization and data remain on the existing PHP backend.
 */
public final class MainActivity extends Activity {
    private static final int FILE_PICKER_REQUEST = 101;
    private static final int MEDIA_PERMISSION_REQUEST = 102;
    private static final int INITIAL_MEDIA_PERMISSION_REQUEST = 103;
    private static final String PERMISSION_PREFERENCES = "arcadecloud_android_permissions";
    private static final String INITIAL_MEDIA_PROMPTED = "capture_permissions_requested_on_first_launch";
    private PermissionRequest pendingMediaPermission;
    private final ExecutorService downloads = Executors.newSingleThreadExecutor();
    private WebView webView;
    private ProgressBar progress;
    private TextView connectionError;
    private ValueCallback<Uri[]> uploadCallback;

    @Override
    @SuppressLint("SetJavaScriptEnabled") // JavaScript is required by the first-party Web OS.
    protected void onCreate(Bundle state) {
        super.onCreate(state);

        FrameLayout root = new FrameLayout(this);
        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(-1, -1));

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        FrameLayout.LayoutParams progressParams = new FrameLayout.LayoutParams(-1, dp(3), Gravity.TOP);
        root.addView(progress, progressParams);

        connectionError = new TextView(this);
        connectionError.setText(R.string.offline_message);
        connectionError.setTextSize(17);
        connectionError.setTextColor(Color.WHITE);
        connectionError.setGravity(Gravity.CENTER);
        connectionError.setPadding(dp(22), dp(20), dp(22), dp(20));
        connectionError.setBackgroundColor(Color.rgb(11, 29, 44));
        connectionError.setVisibility(View.GONE);
        connectionError.setOnClickListener(v -> webView.reload());
        root.addView(connectionError, new FrameLayout.LayoutParams(-1, -1));
        setContentView(root);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setAllowFileAccessFromFileURLs(false);
        settings.setAllowUniversalAccessFromFileURLs(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setSafeBrowsingEnabled(true);
        settings.setSupportMultipleWindows(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setMediaPlaybackRequiresUserGesture(true);
        webView.setOverScrollMode(View.OVER_SCROLL_NEVER);

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(webView, false);
        webView.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) return false;
                String url = request.getUrl().toString();
                if (UrlPolicy.isLegacyDrive(url)) {
                    view.loadUrl(UrlPolicy.START_URL);
                    return true;
                }
                if (UrlPolicy.isFirstParty(url)) return false;
                if (UrlPolicy.isTrustedDownload(url)) {
                    downloadFile(url, null, null);
                    return true;
                }
                launchExternal(url);
                return true;
            }

            @Override public void onPageStarted(WebView view, String url, Bitmap favicon) {
                connectionError.setVisibility(View.GONE);
                progress.setVisibility(View.VISIBLE);
            }

            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) connectionError.setVisibility(View.VISIBLE);
            }

            @Override public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.cancel(); // Never bypass certificate validation.
                connectionError.setVisibility(View.VISIBLE);
            }
        });
        webView.setWebChromeClient(new WebChromeClient() {
            @Override public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> {
                    if (!UrlPolicy.isFirstParty(request.getOrigin().toString())) {
                        request.deny();
                        return;
                    }
                    if (pendingMediaPermission != null) {
                        request.deny();
                        return;
                    }
                    java.util.List<String> required = new java.util.ArrayList<>();
                    for (String resource : request.getResources()) {
                        if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resource)
                                && checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                            required.add(Manifest.permission.RECORD_AUDIO);
                        }
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)
                                && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                            required.add(Manifest.permission.CAMERA);
                        }
                    }
                    if (required.isEmpty()) {
                        grantKnownMedia(request);
                    } else {
                        pendingMediaPermission = request;
                        requestPermissions(required.toArray(new String[0]), MEDIA_PERMISSION_REQUEST);
                    }
                });
            }

            @Override public void onPermissionRequestCanceled(PermissionRequest request) {
                if (pendingMediaPermission == request) pendingMediaPermission = null;
            }

            @Override public void onProgressChanged(WebView view, int newProgress) {
                progress.setProgress(newProgress);
                progress.setVisibility(newProgress == 100 ? View.GONE : View.VISIBLE);
            }

            @Override public boolean onShowFileChooser(WebView view,
                    ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (uploadCallback != null) uploadCallback.onReceiveValue(null);
                uploadCallback = callback;
                Intent choose = new Intent(Intent.ACTION_GET_CONTENT);
                choose.addCategory(Intent.CATEGORY_OPENABLE);
                choose.setType("*/*");
                String[] accepted = params.getAcceptTypes();
                if (accepted != null && accepted.length > 0) {
                    java.util.ArrayList<String> mime = new java.util.ArrayList<>();
                    for (String type : accepted) {
                        if (type != null && type.contains("/") && !type.contains(",")) mime.add(type.trim());
                    }
                    if (mime.size() == 1) choose.setType(mime.get(0));
                    else if (mime.size() > 1) choose.putExtra(Intent.EXTRA_MIME_TYPES,
                            mime.toArray(new String[0]));
                }
                choose.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,
                        params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE);
                try {
                    startActivityForResult(Intent.createChooser(choose, "Seleccionar archivos"), FILE_PICKER_REQUEST);
                    return true;
                } catch (ActivityNotFoundException e) {
                    uploadCallback = null;
                    callback.onReceiveValue(null);
                    return false;
                }
            }
        });

        webView.setDownloadListener((url, agent, disposition, mime, size) ->
                downloadFile(url, disposition, mime));

        if (state == null || webView.restoreState(state) == null) {
            webView.loadUrl(UrlPolicy.START_URL);
        }
        requestInitialCapturePermissions();

    }

    @Override
    @SuppressWarnings("deprecation")
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_PICKER_REQUEST || uploadCallback == null) return;
        ValueCallback<Uri[]> callback = uploadCallback;
        uploadCallback = null;
        callback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data));
    }

    /**
     * Android grants camera and microphone only through its own runtime dialogs.
     * Ask once on the app's first launch. Later, only a user-initiated recording
     * can ask again for a permission the user previously declined.
     */
    private void requestInitialCapturePermissions() {
        SharedPreferences preferences = getSharedPreferences(PERMISSION_PREFERENCES, MODE_PRIVATE);
        boolean previouslyPrompted = preferences.getBoolean(INITIAL_MEDIA_PROMPTED, false);
        boolean cameraGranted = checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED;
        boolean micGranted = checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED;
        if (!MediaPermissionPolicy.shouldPromptAtLaunch(previouslyPrompted, cameraGranted, micGranted)) {
            return;
        }

        java.util.List<String> missing = new java.util.ArrayList<>();
        if (!cameraGranted) missing.add(Manifest.permission.CAMERA);
        if (!micGranted) missing.add(Manifest.permission.RECORD_AUDIO);
        // Persist before showing the OS dialog to avoid duplicate prompts after rotation.
        preferences.edit().putBoolean(INITIAL_MEDIA_PROMPTED, true).apply();
        requestPermissions(missing.toArray(new String[0]), INITIAL_MEDIA_PERMISSION_REQUEST);
    }

    private void grantKnownMedia(PermissionRequest request) {
        if (!UrlPolicy.isFirstParty(request.getOrigin().toString())) {
            request.deny();
            return;
        }
        java.util.List<String> approved = new java.util.ArrayList<>();
        for (String resource : request.getResources()) {
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resource)
                    && checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                approved.add(resource);
            }
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)
                    && checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                approved.add(resource);
            }
        }
        if (approved.isEmpty()) request.deny();
        else request.grant(approved.toArray(new String[0]));
    }

    @Override public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == INITIAL_MEDIA_PERMISSION_REQUEST) {
            // Android remembers the grant/deny state. The user can retry later
            // from a voice/video/photo button inside the trusted Drive origin.
            return;
        }
        if (requestCode == MEDIA_PERMISSION_REQUEST && pendingMediaPermission != null) {
            PermissionRequest request = pendingMediaPermission;
            pendingMediaPermission = null;
            grantKnownMedia(request);
        }
    }

    @Override protected void onPause() {
        // Persist existing first-party cookies without inventing a long-lived login token.
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override public void onBackPressed() {
        if (webView != null && webView.canGoBack()) webView.goBack();
        else super.onBackPressed();
    }

    @Override protected void onSaveInstanceState(Bundle state) {
        if (webView != null) webView.saveState(state);
        super.onSaveInstanceState(state);
    }

    @Override protected void onDestroy() {
        if (uploadCallback != null) {
            uploadCallback.onReceiveValue(null);
            uploadCallback = null;
        }
        if (pendingMediaPermission != null) {
            pendingMediaPermission.deny();
            pendingMediaPermission = null;
        }
        downloads.shutdownNow();
        if (webView != null) {
            webView.stopLoading();
            webView.destroy();
        }
        super.onDestroy();
    }

    private void launchExternal(String raw) {
        URI parsed = UrlPolicy.parse(raw);
        if (parsed == null) { toast(R.string.unsupported_link); return; }
        String scheme = parsed.getScheme();
        if (scheme == null || !(scheme.equalsIgnoreCase("https") ||
                scheme.equalsIgnoreCase("http") ||
                scheme.equalsIgnoreCase("mailto") ||
                scheme.equalsIgnoreCase("tel"))) {
            toast(R.string.unsupported_link);
            return;
        }
        try {
            Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(raw));
            intent.addCategory(Intent.CATEGORY_BROWSABLE);
            startActivity(intent);
        } catch (ActivityNotFoundException error) {
            toast(R.string.unsupported_link);
        }
    }

    /**
     * Downloads stream into the phone's MediaStore, never onto EC2.
     * A first-party cookie is applied ONLY to that specific Drive/FastDrive
     * request. Following a signed S3 redirect never forwards that cookie.
     */
    private void downloadFile(String raw, String disposition, String declaredMime) {
        if (!UrlPolicy.isTrustedDownload(raw)) {
            toast(R.string.unsupported_link);
            return;
        }
        downloads.execute(() -> {
            Uri pending = null;
            try {
                URL current = new URL(raw);
                for (int redirects = 0; redirects <= 5; redirects++) {
                    if (!UrlPolicy.isTrustedDownload(current.toString())) {
                        throw new IOException("Untrusted download redirect");
                    }
                    HttpURLConnection connection = (HttpURLConnection) current.openConnection();
                    connection.setInstanceFollowRedirects(false);
                    connection.setConnectTimeout(15000);
                    connection.setReadTimeout(60000);
                    try {
                        connection.setRequestMethod("GET");
                        if (UrlPolicy.isFirstParty(current.toString())) {
                            String cookie = CookieManager.getInstance().getCookie(current.toString());
                            if (cookie != null && !cookie.isEmpty()) {
                                connection.setRequestProperty("Cookie", cookie);
                            }
                        }
                        int status = connection.getResponseCode();
                        if (status == 301 || status == 302 || status == 303
                                || status == 307 || status == 308) {
                            String redirect = connection.getHeaderField("Location");
                            if (redirect == null) throw new IOException("No redirect location");
                            current = new URL(current, redirect);
                            continue;
                        }
                        if (status != 200) throw new IOException("Download HTTP " + status);
                        String contentType = connection.getContentType();
                        if (contentType != null && contentType.toLowerCase(java.util.Locale.ROOT)
                                .startsWith("text/html")) {
                            throw new IOException("HTML response instead of downloadable file");
                        }
                        String mime = contentType != null && !contentType.isEmpty()
                                ? contentType.split(";")[0].trim()
                                : (declaredMime == null ? "application/octet-stream" : declaredMime);
                        String serverDisposition = connection.getHeaderField("Content-Disposition");
                        String name = URLUtil.guessFileName(current.toString(),
                                serverDisposition == null ? disposition : serverDisposition, mime);
                        name = safeFileName(name);

                        ContentValues values = new ContentValues();
                        values.put(MediaStore.Downloads.DISPLAY_NAME, name);
                        values.put(MediaStore.Downloads.MIME_TYPE, mime);
                        values.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/ArcadeCloud/");
                        values.put(MediaStore.Downloads.IS_PENDING, 1);
                        pending = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                        if (pending == null) throw new IOException("Cannot create download destination");

                        try (InputStream in = connection.getInputStream();
                             OutputStream out = getContentResolver().openOutputStream(pending)) {
                            if (out == null) throw new IOException("Cannot open download destination");
                            byte[] buffer = new byte[64 * 1024];
                            int n;
                            while ((n = in.read(buffer)) != -1) out.write(buffer, 0, n);
                        }
                        ContentValues complete = new ContentValues();
                        complete.put(MediaStore.Downloads.IS_PENDING, 0);
                        getContentResolver().update(pending, complete, null, null);
                        pending = null;
                        toast(R.string.download_complete);
                        return;
                    } finally {
                        connection.disconnect();
                    }
                }
                throw new IOException("Too many redirects");
            } catch (Exception error) {
                if (pending != null) getContentResolver().delete(pending, null, null);
                toast(R.string.download_error);
            }
        });
    }

    private static String safeFileName(String name) {
        if (name == null || name.isEmpty()) return "archivo-descargado";
        StringBuilder cleaned = new StringBuilder(name.length());
        for (int i = 0; i < name.length(); i++) {
            char c = name.charAt(i);
            cleaned.append(c < 32 || c == 127 || c == '/' || c == 92 ? '_' : c);
        }
        String safe = cleaned.toString().trim();
        if (safe.equals(".") || safe.equals("..") || safe.isEmpty()) safe = "archivo-descargado";
        return safe.length() > 160 ? safe.substring(safe.length() - 160) : safe;
    }

    private int dp(int value) {
        return (int)(value * getResources().getDisplayMetrics().density + 0.5f);
    }

    private void toast(int message) {
        runOnUiThread(() -> Toast.makeText(this, message, Toast.LENGTH_LONG).show());
    }
}
