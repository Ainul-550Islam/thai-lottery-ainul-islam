package com.ainul550.thailottery;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.Gravity;
import android.view.ViewGroup;
import android.view.Window;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import java.util.Locale;

/**
 * Thin, origin-locked Android client for the server-hosted Thai Lottery site.
 *
 * Business rules, authentication, payments, and account data remain on the
 * Laravel server. This Activity does not expose a JavaScript bridge, accept
 * cleartext traffic, or permit untrusted origins inside the application WebView.
 */
public final class MainActivity extends Activity {
    private static final int FILE_CHOOSER_REQUEST_CODE = 7310;
    private static final int DARK_BACKGROUND = Color.rgb(2, 6, 23);
    private static final String OFFLINE_HTML = "<!doctype html>"
            + "<html lang=\"en\"><head><meta charset=\"utf-8\">"
            + "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            + "<title>Thai Lottery</title><style>"
            + "html,body{height:100%;margin:0;background:#020617;color:#e2e8f0;"
            + "font:16px sans-serif}main{min-height:100%;display:grid;place-content:center;"
            + "padding:24px;box-sizing:border-box;text-align:center}h1{color:#f8fafc}"
            + "p{line-height:1.6;color:#cbd5e1}button{padding:12px 20px;border:0;"
            + "border-radius:8px;background:#d4af37;color:#020617;font-weight:700}"
            + "</style></head><body><main><h1>Connection unavailable</h1>"
            + "<p>Connect to the internet and try again.</p>"
            + "<button type=\"button\" onclick=\"location.reload()\">Retry</button>"
            + "</main></body></html>";

    private WebView webView;
    private Uri trustedStartUri;
    private ValueCallback<Uri[]> pendingFileChooser;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        trustedStartUri = Uri.parse(BuildConfig.WEB_APP_URL);
        if (!isValidConfiguredStartUrl(trustedStartUri)) {
            showConfigurationError();
            return;
        }

        Window window = getWindow();
        window.setStatusBarColor(DARK_BACKGROUND);
        window.setNavigationBarColor(DARK_BACKGROUND);

        webView = new WebView(this);
        webView.setBackgroundColor(DARK_BACKGROUND);
        setContentView(webView, new ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        configureWebView();

        if (savedInstanceState != null && webView.restoreState(savedInstanceState) != null) {
            return;
        }

        webView.loadUrl(BuildConfig.WEB_APP_URL);
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setLoadsImagesAutomatically(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setSupportMultipleWindows(false);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setGeolocationEnabled(false);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setUserAgentString(settings.getUserAgentString() + " ThaiLotteryAndroid/1.0.0");
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            settings.setSafeBrowsingEnabled(true);
        }

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, false);

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        webView.setWebViewClient(new OriginLockedWebViewClient());
        webView.setWebChromeClient(new FileUploadWebChromeClient());
        webView.setDownloadListener(createDownloadListener());
    }

    private boolean isValidConfiguredStartUrl(Uri uri) {
        return uri != null
                && "https".equalsIgnoreCase(uri.getScheme())
                && uri.getHost() != null
                && !uri.getHost().trim().isEmpty()
                && uri.getUserInfo() == null;
    }

    private boolean isTrustedOrigin(Uri uri) {
        if (!isValidConfiguredStartUrl(uri) || trustedStartUri == null) {
            return false;
        }

        String expectedHost = trustedStartUri.getHost().toLowerCase(Locale.ROOT);
        String actualHost = uri.getHost().toLowerCase(Locale.ROOT);
        int expectedPort = trustedStartUri.getPort() < 0 ? 443 : trustedStartUri.getPort();
        int actualPort = uri.getPort() < 0 ? 443 : uri.getPort();

        return expectedHost.equals(actualHost) && expectedPort == actualPort;
    }

    private boolean handleNavigation(Uri uri) {
        if (isTrustedOrigin(uri)) {
            return false;
        }

        if (uri == null || uri.getScheme() == null) {
            return true;
        }

        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);
        if ("https".equals(scheme) || "mailto".equals(scheme) || "tel".equals(scheme)) {
            openExternalUri(uri);
        }

        return true;
    }

    private void openExternalUri(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (ActivityNotFoundException exception) {
            Toast.makeText(this, R.string.external_link_failed, Toast.LENGTH_SHORT).show();
        }
    }

    private DownloadListener createDownloadListener() {
        return (url, userAgent, contentDisposition, mimeType, contentLength) -> {
            Uri uri = Uri.parse(url);
            if (!isTrustedOrigin(uri)) {
                Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
                return;
            }

            String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);
            fileName = fileName.replaceAll("[^A-Za-z0-9._-]", "_");
            if (fileName.isEmpty() || ".".equals(fileName) || "..".equals(fileName)) {
                fileName = "thai-lottery-download";
            }

            try {
                DownloadManager downloadManager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                if (downloadManager == null) {
                    Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
                    return;
                }

                DownloadManager.Request request = new DownloadManager.Request(uri);
                request.setTitle(fileName);
                request.setDescription(getString(R.string.app_name));
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setAllowedOverRoaming(false);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                    request.setAllowedOverMetered(true);
                }
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);

                String cookie = CookieManager.getInstance().getCookie(url);
                if (cookie != null && !cookie.isEmpty()) {
                    request.addRequestHeader("Cookie", cookie);
                }
                if (userAgent != null && !userAgent.isEmpty()) {
                    request.addRequestHeader("User-Agent", userAgent);
                }

                downloadManager.enqueue(request);
                Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show();
            } catch (IllegalArgumentException | SecurityException exception) {
                Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
            }
        };
    }

    private void showConfigurationError() {
        LinearLayout container = new LinearLayout(this);
        container.setOrientation(LinearLayout.VERTICAL);
        container.setGravity(Gravity.CENTER);
        container.setPadding(32, 32, 32, 32);
        container.setBackgroundColor(DARK_BACKGROUND);

        TextView message = new TextView(this);
        message.setText("The official HTTPS site address is not configured for this build.");
        message.setTextColor(Color.WHITE);
        message.setTextSize(18);
        message.setGravity(Gravity.CENTER);
        container.addView(message, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        setContentView(container);
    }

    private void showOfflinePage(WebView view) {
        view.loadDataWithBaseURL(
                BuildConfig.WEB_APP_URL,
                OFFLINE_HTML,
                "text/html",
                "UTF-8",
                null
        );
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        if (webView != null) {
            webView.saveState(outState);
        }
        super.onSaveInstanceState(outState);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_CHOOSER_REQUEST_CODE || pendingFileChooser == null) {
            return;
        }

        Uri[] results = WebChromeClient.FileChooserParams.parseResult(resultCode, data);
        pendingFileChooser.onReceiveValue(results);
        pendingFileChooser = null;
    }

    @Override
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onDestroy() {
        if (pendingFileChooser != null) {
            pendingFileChooser.onReceiveValue(null);
            pendingFileChooser = null;
        }
        if (webView != null) {
            webView.stopLoading();
            webView.setWebChromeClient(null);
            webView.setWebViewClient(new WebViewClient());
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    private final class OriginLockedWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            return handleNavigation(request.getUrl());
        }

        @Override
        @SuppressWarnings("deprecation")
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            return handleNavigation(Uri.parse(url));
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (request.isForMainFrame()) {
                showOfflinePage(view);
            }
        }
    }

    private final class FileUploadWebChromeClient extends WebChromeClient {
        @Override
        public boolean onShowFileChooser(
                WebView view,
                ValueCallback<Uri[]> filePathCallback,
                FileChooserParams fileChooserParams
        ) {
            if (pendingFileChooser != null) {
                pendingFileChooser.onReceiveValue(null);
            }
            pendingFileChooser = filePathCallback;

            final Intent chooserIntent;
            try {
                chooserIntent = fileChooserParams.createIntent();
            } catch (RuntimeException exception) {
                pendingFileChooser = null;
                return false;
            }

            try {
                startActivityForResult(chooserIntent, FILE_CHOOSER_REQUEST_CODE);
                return true;
            } catch (ActivityNotFoundException exception) {
                pendingFileChooser = null;
                return false;
            }
        }
    }
}
