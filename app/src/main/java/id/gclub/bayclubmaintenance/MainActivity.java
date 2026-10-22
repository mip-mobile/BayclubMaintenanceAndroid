package id.gclub.bayclubmaintenance;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.graphics.Color;
import android.view.View;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.view.WindowManager;
import android.widget.Toast;
import android.window.BackEvent;
import android.window.OnBackAnimationCallback;
import android.window.OnBackInvokedCallback;
import android.window.OnBackInvokedDispatcher;

public class MainActivity extends Activity {
    private static final String HOME_URL = "https://bayclub.infinityfree.io/maintenance/";
    private static final String TRUSTED_HOST = "bayclub.infinityfree.io";
    private static final int FILE_CHOOSER_REQUEST = 4101;
    private static final int LOCATION_REQUEST = 4102;

    private WebView webView;
    private ValueCallback<Uri[]> fileChooserCallback;
    private GeolocationPermissions.Callback pendingGeoCallback;
    private String pendingGeoOrigin;
    private long lastBackPressAt = 0L;
    private OnBackInvokedCallback backInvokedCallback;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Window window = getWindow();
        window.setStatusBarColor(Color.TRANSPARENT);
        window.setNavigationBarColor(Color.TRANSPARENT);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            window.setStatusBarContrastEnforced(false);
            window.setNavigationBarContrastEnforced(false);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            window.setNavigationBarDividerColor(Color.TRANSPARENT);
        }
        window.setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,
                WindowManager.LayoutParams.FLAG_FULLSCREEN);

        // Allow the app window to extend into the display cutout on short edges.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            WindowManager.LayoutParams attributes = window.getAttributes();
            attributes.layoutInDisplayCutoutMode =
                    WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
            window.setAttributes(attributes);
        }

        // Android 11+ uses WindowInsetsController for reliable immersive mode.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(false);
        }
        applyImmersiveMode();
        installSystemBarAutoHideListener();

        // Intercept predictive Back at gesture start on Android 14+ so transient
        // system bars can be hidden during the gesture instead of lingering onscreen.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            backInvokedCallback = new OnBackAnimationCallback() {
                @Override
                public void onBackStarted(BackEvent backEvent) {
                    applyImmersiveMode();
                }

                @Override
                public void onBackProgressed(BackEvent backEvent) {
                    hideSystemBarsNow();
                }

                @Override
                public void onBackCancelled() {
                    scheduleImmersiveRestore();
                }

                @Override
                public void onBackInvoked() {
                    handleBackPress();
                    scheduleImmersiveRestore();
                }
            };
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                    OnBackInvokedDispatcher.PRIORITY_DEFAULT, backInvokedCallback);
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            backInvokedCallback = this::handleBackPress;
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(
                    OnBackInvokedDispatcher.PRIORITY_DEFAULT, backInvokedCallback);
        }

        webView = new WebView(this);
        webView.setBackgroundColor(Color.rgb(242, 245, 249));
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setSupportMultipleWindows(false);
        settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookies.setAcceptThirdPartyCookies(webView, false);
        }

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return routeUrl(request.getUrl());
            }

            @SuppressWarnings("deprecation")
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return routeUrl(Uri.parse(url));
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                    FileChooserParams fileChooserParams) {
                if (fileChooserCallback != null) fileChooserCallback.onReceiveValue(null);
                fileChooserCallback = callback;
                Intent intent;
                try {
                    intent = fileChooserParams.createIntent();
                } catch (Exception e) {
                    intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
                    intent.addCategory(Intent.CATEGORY_OPENABLE);
                    intent.setType("*/*");
                }
                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST);
                    return true;
                } catch (ActivityNotFoundException e) {
                    fileChooserCallback = null;
                    Toast.makeText(MainActivity.this, "Tidak ada aplikasi untuk memilih file.", Toast.LENGTH_LONG).show();
                    return false;
                }
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                Uri uri = Uri.parse(origin);
                if (!"https".equalsIgnoreCase(uri.getScheme()) || !TRUSTED_HOST.equalsIgnoreCase(uri.getHost())) {
                    callback.invoke(origin, false, false);
                    return;
                }
                boolean fine = checkSelfPermissionCompat(Manifest.permission.ACCESS_FINE_LOCATION);
                boolean coarse = checkSelfPermissionCompat(Manifest.permission.ACCESS_COARSE_LOCATION);
                if (fine || coarse) {
                    callback.invoke(origin, true, false);
                    return;
                }
                pendingGeoOrigin = origin;
                pendingGeoCallback = callback;
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION}, LOCATION_REQUEST);
                } else {
                    callback.invoke(origin, false, false);
                }
            }
        });

        setContentView(webView);
        applyImmersiveMode();
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState);
            if (webView.getUrl() == null) webView.loadUrl(HOME_URL);
        } else {
            webView.loadUrl(HOME_URL);
        }
    }

    /**
     * Immersive fullscreen: hide the Android status/notification bar and
     * navigation bar. With IMMERSIVE_STICKY, a swipe can reveal system UI
     * temporarily and Android hides it again.
     */
    private int immersiveFlags() {
        return View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN;
    }

    private void hideSystemBarsNow() {
        Window window = getWindow();
        window.getDecorView().setSystemUiVisibility(immersiveFlags());
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            WindowInsetsController controller = window.getInsetsController();
            if (controller != null) {
                controller.hide(WindowInsets.Type.statusBars() | WindowInsets.Type.navigationBars());
                controller.setSystemBarsBehavior(
                        WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            }
        }
    }

    private void applyImmersiveMode() {
        hideSystemBarsNow();
    }

    /**
     * Android can dispatch fresh insets when transient bars are revealed by an
     * edge-swipe gesture. Re-hide them as soon as that visibility change reaches
     * the window, without interfering with the system Back callback itself.
     */
    private void installSystemBarAutoHideListener() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return;
        View decor = getWindow().getDecorView();
        decor.setOnApplyWindowInsetsListener((view, insets) -> {
            boolean barsVisible = insets.isVisible(WindowInsets.Type.statusBars())
                    || insets.isVisible(WindowInsets.Type.navigationBars());
            if (barsVisible) {
                view.postDelayed(this::hideSystemBarsNow, 80L);
                view.postDelayed(this::hideSystemBarsNow, 220L);
            }
            return insets;
        });
        decor.requestApplyInsets();
    }

    /**
     * Android can reveal transient status/navigation bars during an edge-swipe.
     * Reapply immersive mode for a brief period after Back is processed.
     */
    private void scheduleImmersiveRestore() {
        View decor = getWindow().getDecorView();
        long[] delays = new long[]{0L, 45L, 140L, 300L, 550L};
        for (long delay : delays) {
            decor.postDelayed(this::applyImmersiveMode, delay);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        applyImmersiveMode();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            applyImmersiveMode();
        }
    }

    private boolean checkSelfPermissionCompat(String permission) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return true;
        return checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean routeUrl(Uri uri) {
        String scheme = uri.getScheme();
        String host = uri.getHost();
        if (scheme == null) return true;
        if ("https".equalsIgnoreCase(scheme) && host != null &&
                (TRUSTED_HOST.equalsIgnoreCase(host) || host.toLowerCase().endsWith(".infinityfreeapp.com"))) {
            return false;
        }
        if ("http".equalsIgnoreCase(scheme) && host != null && TRUSTED_HOST.equalsIgnoreCase(host)) {
            // Redirect any HTTP entry back to the trusted HTTPS origin.
            webView.post(() -> webView.loadUrl(HOME_URL));
            return true;
        }
        if ("mailto".equalsIgnoreCase(scheme) || "tel".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)) {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
            } catch (ActivityNotFoundException ignored) {
                Toast.makeText(this, "Tidak ada aplikasi untuk membuka tautan ini.", Toast.LENGTH_SHORT).show();
            }
            return true;
        }
        return true;
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == FILE_CHOOSER_REQUEST && fileChooserCallback != null) {
            Uri[] result = null;
            if (resultCode == RESULT_OK && data != null) {
                Uri single = data.getData();
                if (single != null) result = new Uri[]{single};
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN && data.getClipData() != null) {
                    int count = data.getClipData().getItemCount();
                    result = new Uri[count];
                    for (int i = 0; i < count; i++) result[i] = data.getClipData().getItemAt(i).getUri();
                }
            }
            fileChooserCallback.onReceiveValue(result);
            fileChooserCallback = null;
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == LOCATION_REQUEST && pendingGeoCallback != null) {
            boolean granted = false;
            for (int i = 0; i < permissions.length; i++) {
                if ((Manifest.permission.ACCESS_FINE_LOCATION.equals(permissions[i]) ||
                        Manifest.permission.ACCESS_COARSE_LOCATION.equals(permissions[i])) &&
                        grantResults[i] == PackageManager.PERMISSION_GRANTED) {
                    granted = true;
                }
            }
            pendingGeoCallback.invoke(pendingGeoOrigin, granted, false);
            pendingGeoCallback = null;
            pendingGeoOrigin = null;
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        if (webView != null) webView.saveState(outState);
        super.onSaveInstanceState(outState);
    }

    /**
     * Back navigates through WebView history first. At the home page, the user
     * must press/gesture Back twice within two seconds to exit the app.
     */
    private void handleBackPress() {
        hideSystemBarsNow();
        scheduleImmersiveRestore();
        if (webView == null) {
            requestExitOnDoubleBack();
            return;
        }

        // Bayclub is a single-page web app: opening a checklist task is a
        // JavaScript dialog/view, not a new WebView URL. Ask the website to
        // unwind its own navigation stack before considering app exit.
        final String backScript =
                "(function(){try{" +
                "if(typeof window.navigateBack==='function')return window.navigateBack();" +
                "var d=document.getElementById('modal');" +
                "if(d&&d.open&&typeof window.closeModal==='function')return window.closeModal()?'modal':'stay';" +
                "return 'no-spa';" +
                "}catch(e){return 'no-spa';}})()";

        webView.evaluateJavascript(backScript, value -> {
            String result = value == null ? "" : value.replace(String.valueOf((char) 34), "").trim();

            if ("modal".equals(result) || "subpage".equals(result)
                    || "page".equals(result) || "home".equals(result)) {
                lastBackPressAt = 0L;
                applyImmersiveMode();
                return;
            }

            if ("busy".equals(result) || "stay".equals(result)) {
                applyImmersiveMode();
                return;
            }

            if ("no-spa".equals(result)) {
                // Fallback for an unexpected page without Bayclub's SPA handler.
                if (webView != null && webView.canGoBack()) {
                    lastBackPressAt = 0L;
                    webView.goBack();
                    applyImmersiveMode();
                    return;
                }
            }

            // 'exit' means Bayclub's own page stack is already at its home page.
            // Never exit on the first Back press.
            requestExitOnDoubleBack();
        });
    }

    private void requestExitOnDoubleBack() {
        long now = System.currentTimeMillis();
        if (lastBackPressAt > 0L && now - lastBackPressAt <= 2000L) {
            lastBackPressAt = 0L;
            finish();
            return;
        }

        lastBackPressAt = now;
        Toast.makeText(this,
                "Tekan sekali lagi untuk keluar dari Bayclub Mobile.",
                Toast.LENGTH_SHORT).show();
        applyImmersiveMode();
    }

    @Override
    public void onBackPressed() {
        handleBackPress();
    }

    @Override
    protected void onDestroy() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && backInvokedCallback != null) {
            getOnBackInvokedDispatcher().unregisterOnBackInvokedCallback(backInvokedCallback);
            backInvokedCallback = null;
        }
        if (fileChooserCallback != null) {
            fileChooserCallback.onReceiveValue(null);
            fileChooserCallback = null;
        }
        if (webView != null) {
            webView.stopLoading();
            webView.setWebChromeClient(null);
            webView.setWebViewClient(null);
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }
}
