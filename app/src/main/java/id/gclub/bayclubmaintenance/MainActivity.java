package id.gclub.bayclubmaintenance;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
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
import android.webkit.PermissionRequest;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.graphics.Color;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Matrix;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.view.View;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.view.WindowManager;
import android.widget.Toast;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import androidx.core.content.FileProvider;
import androidx.exifinterface.media.ExifInterface;
import java.io.File;
import java.io.FileOutputStream;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;
import android.window.BackEvent;
import android.window.OnBackAnimationCallback;
import android.window.OnBackInvokedCallback;
import android.window.OnBackInvokedDispatcher;

public class MainActivity extends Activity {
    private static final String HOME_URL = "https://bayclub.infinityfree.io/maintenance/";
    private static final String TRUSTED_HOST = "bayclub.infinityfree.io";
    private static final int FILE_CHOOSER_REQUEST = 4101;
    private static final int LOCATION_REQUEST = 4102;
    private static final int CAMERA_REQUEST = 4103;
    private static final int CAMERA_PERMISSION_REQUEST = 4104;
    private static final int MAX_CAMERA_IMAGE_DIMENSION = 2200;

    private WebView webView;
    private ValueCallback<Uri[]> fileChooserCallback;
    private File pendingCameraFile;
    private File pendingWatermarkedFile;
    private String pendingPetugasName = "Petugas Bayclub";
    private String pendingJobName = "Checklist Maintenance";
    private GeolocationPermissions.Callback pendingGeoCallback;
    private PermissionRequest pendingWebPermissionRequest;
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
            public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> {
                    Uri origin = request.getOrigin();
                    if (origin == null || !"https".equalsIgnoreCase(origin.getScheme())
                            || !TRUSTED_HOST.equalsIgnoreCase(origin.getHost())) {
                        request.deny();
                        return;
                    }
                    boolean asksForCamera = false;
                    for (String resource : request.getResources()) {
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                            asksForCamera = true;
                        }
                    }
                    if (!asksForCamera) {
                        request.deny();
                        return;
                    }
                    if (checkSelfPermissionCompat(Manifest.permission.CAMERA)) {
                        request.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                    } else {
                        if (pendingWebPermissionRequest != null) pendingWebPermissionRequest.deny();
                        pendingWebPermissionRequest = request;
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                            requestPermissions(new String[]{Manifest.permission.CAMERA},
                                    CAMERA_PERMISSION_REQUEST);
                        } else {
                            request.deny();
                            pendingWebPermissionRequest = null;
                        }
                    }
                });
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                    FileChooserParams fileChooserParams) {
                if (fileChooserCallback != null) fileChooserCallback.onReceiveValue(null);
                fileChooserCallback = callback;

                // Photo evidence: resolve the signed-in account name before opening camera.
                if (isImageChooser(fileChooserParams)) {
                    Toast.makeText(MainActivity.this, "Membuka kamera Bayclub...", Toast.LENGTH_SHORT).show();
                    pendingPetugasName = "Petugas Bayclub";
                    if (webView == null) {
                        launchNativeCamera();
                    } else {
                        final String script =
                                "(function(){try{" +
                                "var bad=/^(\\d{4}-\\d{2}-\\d{2}|\\d{1,2}\\s+[A-Za-z]+\\s+\\d{4}|split|shift\\s*\\d*|progres pekerjaan|pekerjaan|foto bukti|bayclub maintenance|maintenance checklist|ambil \\/ tambah foto)$/i;" +
                                "function norm(v){return String(v||'').replace(/[\\r\\n]+/g,' ').replace(/\\s+/g,' ').trim();}" +
                                "function valid(v){v=norm(v);return v.length>=2&&v.length<=36&&!bad.test(v)&&!/^\\d+$/.test(v);}" +
                                "var named=[];function walk(o,k,d){if(d>5||o==null)return;if(typeof o==='string'){if(/name|nama|username|petugas|employee|staff|display/i.test(k||'')&&valid(o))named.push(norm(o));return;}if(typeof o==='object'){Object.keys(o).forEach(function(x){walk(o[x],x,d+1);});}}" +
                                "try{for(var i=0;i<localStorage.length;i++){var k=localStorage.key(i),v=localStorage.getItem(k);try{walk(JSON.parse(v),k,0);}catch(e){if(/name|nama|username|petugas|employee|staff/i.test(k)&&valid(v))named.push(norm(v));}}}catch(e){}" +
                                "try{for(var j=0;j<sessionStorage.length;j++){var sk=sessionStorage.key(j),sv=sessionStorage.getItem(sk);try{walk(JSON.parse(sv),sk,0);}catch(e){if(/name|nama|username|petugas|employee|staff/i.test(sk)&&valid(sv))named.push(norm(sv));}}}catch(e){}" +
                                "var attrs='[data-user-name],[data-username],[data-petugas],[class*=\\"user-name\\"],[class*=\\"username\\"],[class*=\\"profile-name\\"],[class*=\\"user-chip\\"],[class*=\\"petugas\\"]';" +
                                "document.querySelectorAll(attrs).forEach(function(el){var v=norm(el.getAttribute('data-user-name')||el.getAttribute('data-username')||el.getAttribute('data-petugas')||el.innerText||el.textContent);if(valid(v))named.unshift(v);});" +
                                "if(named.length)return JSON.stringify({petugas:named[0]});" +
                                "var chips=[];document.querySelectorAll('body *').forEach(function(el){if(el.children.length>2)return;var v=norm(el.innerText||el.textContent);if(!valid(v))return;var r=el.getBoundingClientRect();if(r.top<0||r.top>300||r.height<22||r.height>72||r.width<28||r.width>210)return;var s=getComputedStyle(el);var radius=parseFloat(s.borderTopLeftRadius)||0;var bg=s.backgroundColor;var bordered=s.borderTopWidth!=='0px'&&s.borderTopStyle!=='none';if(radius<8&&bg==='rgba(0, 0, 0, 0)'&&!bordered)return;chips.push({v:v,x:r.left,y:r.top,w:r.width});});" +
                                "chips.sort(function(a,b){return Math.abs(a.y-b.y)>8?a.y-b.y:a.x-b.x;});" +
                                "var unique=[];chips.forEach(function(q){if(!unique.some(function(z){return z.v===q.v&&Math.abs(z.x-q.x)<4&&Math.abs(z.y-q.y)<4;}))unique.push(q);});" +
                                "var split=unique.findIndex(function(q){return /^(split|shift\\s*\\d*)$/i.test(q.v);});" +
                                "if(split>=0){var rowY=unique[split].y;var next=unique.filter(function(q){return q.x>unique[split].x&&Math.abs(q.y-rowY)<10;});if(next.length)return JSON.stringify({petugas:next[0].v});}" +
                                "var body=norm(document.body&&document.body.innerText);var m=body.match(/(?:Nama Akun|Nama Pengguna|Petugas|Pengguna)\\s*[:：]\\s*([A-Za-z][A-Za-z .'-]{1,34})/i);if(m&&valid(m[1]))return JSON.stringify({petugas:norm(m[1])});" +
                                "return JSON.stringify({petugas:'Petugas Bayclub'});" +
                                "}catch(e){return JSON.stringify({petugas:'Petugas Bayclub'});}})()";
                        webView.evaluateJavascript(script, value -> {
                            try {
                                Object outer = new org.json.JSONTokener(value == null ? "\"\"" : value).nextValue();
                                String payload = outer instanceof String ? (String) outer : "{}";
                                org.json.JSONObject data = new org.json.JSONObject(payload);
                                String name = data.optString("petugas", "Petugas Bayclub").trim();
                                if (!name.isEmpty()) pendingPetugasName = name;
                            } catch (Exception ignored) { }
                            launchNativeCamera();
                        });
                    }
                    return true;
                }

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
        installSystemBarAutoHideListener();
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
        // Re-assert fullscreen flags because some Android builds clear them after
        // dispatching an edge-swipe Back gesture.
        window.setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,
                WindowManager.LayoutParams.FLAG_FULLSCREEN);
        window.setStatusBarColor(Color.TRANSPARENT);
        window.setNavigationBarColor(Color.TRANSPARENT);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(false);
        }
        View decor = window.getDecorView();
        decor.setSystemUiVisibility(immersiveFlags());
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            WindowInsetsController controller = window.getInsetsController();
            if (controller != null) {
                controller.hide(WindowInsets.Type.statusBars() | WindowInsets.Type.navigationBars());
                controller.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_DEFAULT);
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
        View decor = getWindow().getDecorView();

        // Legacy/system-UI visibility callback: some Android builds restore only
        // the status bar during an edge-swipe Back gesture.
        decor.setOnSystemUiVisibilityChangeListener(visibility -> {
            boolean statusBarShown = (visibility & View.SYSTEM_UI_FLAG_FULLSCREEN) == 0;
            boolean navigationBarShown = (visibility & View.SYSTEM_UI_FLAG_HIDE_NAVIGATION) == 0;
            if (statusBarShown || navigationBarShown) {
                decor.post(this::hideSystemBarsNow);
                decor.postDelayed(this::hideSystemBarsNow, 24L);
                decor.postDelayed(this::hideSystemBarsNow, 80L);
                decor.postDelayed(this::hideSystemBarsNow, 180L);
                decor.postDelayed(this::hideSystemBarsNow, 350L);
                decor.postDelayed(this::hideSystemBarsNow, 700L);
            }
        });

        // Modern Android reports transient bars through WindowInsets as well.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            decor.setOnApplyWindowInsetsListener((view, insets) -> {
                boolean statusVisible = insets.isVisible(WindowInsets.Type.statusBars());
                boolean navigationVisible = insets.isVisible(WindowInsets.Type.navigationBars());
                if (statusVisible || navigationVisible) {
                    view.post(this::hideSystemBarsNow);
                    view.postDelayed(this::hideSystemBarsNow, 24L);
                    view.postDelayed(this::hideSystemBarsNow, 80L);
                    view.postDelayed(this::hideSystemBarsNow, 180L);
                    view.postDelayed(this::hideSystemBarsNow, 350L);
                    view.postDelayed(this::hideSystemBarsNow, 700L);
                }
                return insets;
            });
            decor.requestApplyInsets();
        }
    }

    /**
     * Android can reveal transient status/navigation bars during an edge-swipe.
     * Reapply immersive mode for a brief period after Back is processed.
     */
    private void scheduleImmersiveRestore() {
        View decor = getWindow().getDecorView();
        long[] delays = new long[]{0L, 16L, 40L, 80L, 150L, 250L, 400L, 650L, 900L, 1300L, 1800L, 2400L, 3200L, 4200L, 5500L};
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


    private boolean isImageChooser(WebChromeClient.FileChooserParams params) {
        String[] types = params == null ? null : params.getAcceptTypes();
        if (types == null || types.length == 0) return true;
        boolean hasImageType = false;
        for (String type : types) {
            if (type == null || type.trim().isEmpty()) continue;
            String normalized = type.trim().toLowerCase(Locale.ROOT);
            if ("*/*".equals(normalized)) return false;
            if (normalized.startsWith("image/")) {
                hasImageType = true;
            } else {
                return false;
            }
        }
        return hasImageType;
    }

    private void getPetugasNameAndOpenCamera() {
        if (webView == null) {
            launchNativeCamera();
            return;
        }

        // Read the staff label and current task heading from the Bayclub page.
        // Expected staff labels include "Satria · Split" and "Split Satria".
        final String script =
                "(function(){try{var t=(document.body&&document.body.innerText)||'';" +
                "var m=t.match(/(?:Split|Shift\\s*\\d+)\\s+([A-Z][A-Za-z.'-]*(?:\\s+[A-Z][A-Za-z.'-]*)*)/m);" +
                "if(!m)m=t.match(/([A-Z][A-Za-z.'-]*(?:\\s+[A-Z][A-Za-z.'-]*)*)\\s*[·•]\\s*(?:Split|Shift\\s*\\d+)/m);" +
                "var h=document.querySelector('h1,h2');" +
                "var job=h?(h.innerText||'').trim():'Checklist Maintenance';" +
                "return JSON.stringify({petugas:m?m[1]:'Petugas Bayclub',pekerjaan:job||'Checklist Maintenance'});" +
                "}catch(e){return JSON.stringify({petugas:'Petugas Bayclub',pekerjaan:'Checklist Maintenance'});}})()";

        webView.evaluateJavascript(script, value -> {
            try {
                Object outer = new org.json.JSONTokener(value == null ? "\"\"" : value).nextValue();
                String payload = outer instanceof String ? (String) outer : "{}";
                org.json.JSONObject data = new org.json.JSONObject(payload);
                pendingPetugasName = data.optString("petugas", "Petugas Bayclub").trim();
                pendingJobName = data.optString("pekerjaan", "Checklist Maintenance").trim();
            } catch (Exception ignored) {
                pendingPetugasName = "Petugas Bayclub";
                pendingJobName = "Checklist Maintenance";
            }
            if (pendingPetugasName.isEmpty()) pendingPetugasName = "Petugas Bayclub";
            if (pendingJobName.isEmpty()) pendingJobName = "Checklist Maintenance";
            launchNativeCamera();
        });
    }

    private void launchNativeCamera() {
        try {
            File pictures = getExternalFilesDir(android.os.Environment.DIRECTORY_PICTURES);
            if (pictures == null) pictures = getCacheDir();
            if (!pictures.exists() && !pictures.mkdirs()) {
                throw new java.io.IOException("Folder foto tidak dapat dibuat");
            }

            pendingCameraFile = File.createTempFile("bayclub-camera-", ".jpg", pictures);
            Uri outputUri = FileProvider.getUriForFile(
                    this, getPackageName() + ".fileprovider", pendingCameraFile);

            Intent cameraIntent = new Intent(android.provider.MediaStore.ACTION_IMAGE_CAPTURE);
            cameraIntent.putExtra(android.provider.MediaStore.EXTRA_OUTPUT, outputUri);
            cameraIntent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
            cameraIntent.setClipData(android.content.ClipData.newUri(
                    getContentResolver(), "Bayclub camera output", outputUri));

            // Do not pre-check resolveActivity(): Android package-visibility filters can
            // return null even when a camera handler is installed. Let Android resolve it.
            startActivityForResult(cameraIntent, CAMERA_REQUEST);
        } catch (Exception e) {
            if (fileChooserCallback != null) {
                fileChooserCallback.onReceiveValue(null);
                fileChooserCallback = null;
            }
            Toast.makeText(this, "Tidak dapat membuka kamera: " + e.getMessage(),
                    Toast.LENGTH_LONG).show();
        }
    }

    private Bitmap decodeCameraBitmap(File file) throws Exception {
        BitmapFactory.Options bounds = new BitmapFactory.Options();
        bounds.inJustDecodeBounds = true;
        BitmapFactory.decodeFile(file.getAbsolutePath(), bounds);

        int sample = 1;
        while (bounds.outWidth / sample > MAX_CAMERA_IMAGE_DIMENSION
                || bounds.outHeight / sample > MAX_CAMERA_IMAGE_DIMENSION) {
            sample *= 2;
        }

        BitmapFactory.Options options = new BitmapFactory.Options();
        options.inSampleSize = sample;
        Bitmap bitmap = BitmapFactory.decodeFile(file.getAbsolutePath(), options);
        if (bitmap == null) throw new java.io.IOException("Hasil kamera tidak dapat dibaca");

        ExifInterface exif = new ExifInterface(file.getAbsolutePath());
        int orientation = exif.getAttributeInt(
                ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL);

        Matrix matrix = new Matrix();
        switch (orientation) {
            case ExifInterface.ORIENTATION_ROTATE_90: matrix.postRotate(90); break;
            case ExifInterface.ORIENTATION_ROTATE_180: matrix.postRotate(180); break;
            case ExifInterface.ORIENTATION_ROTATE_270: matrix.postRotate(270); break;
            case ExifInterface.ORIENTATION_FLIP_HORIZONTAL: matrix.preScale(-1, 1); break;
            case ExifInterface.ORIENTATION_FLIP_VERTICAL: matrix.preScale(1, -1); break;
            default: break;
        }
        if (!matrix.isIdentity()) {
            Bitmap rotated = Bitmap.createBitmap(
                    bitmap, 0, 0, bitmap.getWidth(), bitmap.getHeight(), matrix, true);
            if (rotated != bitmap) bitmap.recycle();
            bitmap = rotated;
        }
        return bitmap;
    }

    private void drawWatermarkLine(Canvas canvas, String value, float x, float baseline,
            float maxWidth, Paint paint) {
        float originalSize = paint.getTextSize();
        while (paint.measureText(value) > maxWidth && paint.getTextSize() > 16f) {
            paint.setTextSize(paint.getTextSize() * 0.94f);
        }
        canvas.drawText(value, x, baseline, paint);
        paint.setTextSize(originalSize);
    }

    private File createWatermarkedPhoto(File source) throws Exception {
        Bitmap bitmap = decodeCameraBitmap(source);
        try {
            Canvas canvas = new Canvas(bitmap);
            int width = bitmap.getWidth();
            int height = bitmap.getHeight();
            float left = Math.max(24f, width * 0.035f);
            float bandHeight = Math.max(132f, height * 0.17f);

            Paint background = new Paint(Paint.ANTI_ALIAS_FLAG);
            background.setColor(Color.argb(184, 5, 20, 42));
            canvas.drawRect(0, height - bandHeight, width, height, background);

            SimpleDateFormat dateFormat =
                    new SimpleDateFormat("dd MMMM yyyy", new Locale("id", "ID"));
            SimpleDateFormat timeFormat = new SimpleDateFormat("HH:mm:ss", Locale.getDefault());
            TimeZone wib = TimeZone.getTimeZone("Asia/Jakarta");
            dateFormat.setTimeZone(wib);
            timeFormat.setTimeZone(wib);
            Date capturedAt = new Date();
            String date = dateFormat.format(capturedAt);
            String time = timeFormat.format(capturedAt) + " WIB";

            Paint text = new Paint(Paint.ANTI_ALIAS_FLAG);
            text.setColor(Color.WHITE);
            text.setTypeface(Typeface.create("sans-serif", Typeface.BOLD));
            float titleSize = Math.max(22f, width * 0.026f);
            float detailSize = Math.max(18f, width * 0.021f);
            float availableWidth = width - (left * 2f);

            text.setTypeface(Typeface.create("sans-serif-medium", Typeface.NORMAL));
            text.setTextSize(detailSize);
            float lineGap = detailSize + 12f;
            float y = height - bandHeight + detailSize + 20f;
            drawWatermarkLine(canvas, "Petugas: " + pendingPetugasName, left, y, availableWidth, text);
            y += lineGap;
            drawWatermarkLine(canvas, "Tanggal: " + date, left, y, availableWidth, text);
            y += lineGap;
            drawWatermarkLine(canvas, "Jam: " + time, left, y, availableWidth, text);

            File parent = source.getParentFile();
            File output = File.createTempFile("bayclub-bukti-", ".jpg", parent);
            try (FileOutputStream stream = new FileOutputStream(output)) {
                if (!bitmap.compress(Bitmap.CompressFormat.JPEG, 92, stream)) {
                    throw new java.io.IOException("Foto gagal disimpan sebagai JPEG");
                }
                stream.flush();
            }
            return output;
        } finally {
            bitmap.recycle();
        }
    }

    private void showPhotoPreview(File photoFile) {
        BitmapFactory.Options options = new BitmapFactory.Options();
        options.inSampleSize = 1;
        Bitmap previewBitmap = BitmapFactory.decodeFile(photoFile.getAbsolutePath(), options);
        if (previewBitmap == null) {
            if (fileChooserCallback != null) {
                fileChooserCallback.onReceiveValue(null);
                fileChooserCallback = null;
            }
            Toast.makeText(this, "Preview foto tidak dapat dibuka.", Toast.LENGTH_LONG).show();
            return;
        }

        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(24, 18, 24, 8);

        TextView description = new TextView(this);
        description.setText("Periksa foto dan timestamp sebelum dikirim ke checklist.");
        description.setTextSize(14);
        description.setTextColor(Color.rgb(70, 80, 95));
        content.addView(description);

        ImageView image = new ImageView(this);
        image.setImageBitmap(previewBitmap);
        image.setAdjustViewBounds(true);
        image.setScaleType(ImageView.ScaleType.FIT_CENTER);
        LinearLayout.LayoutParams imageParams = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f);
        imageParams.topMargin = 12;
        content.addView(image, imageParams);

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Preview Foto Bukti")
                .setView(content)
                .setNegativeButton("Ambil Ulang", null)
                .setPositiveButton("Gunakan Foto", (d, which) -> {
                    if (fileChooserCallback != null) {
                        Uri finalUri = FileProvider.getUriForFile(
                                MainActivity.this, getPackageName() + ".fileprovider", photoFile);
                        fileChooserCallback.onReceiveValue(new Uri[]{finalUri});
                        fileChooserCallback = null;
                    }
                })
                .setOnCancelListener(d -> {
                    if (fileChooserCallback != null) {
                        fileChooserCallback.onReceiveValue(null);
                        fileChooserCallback = null;
                    }
                })
                .create();

        dialog.setOnShowListener(d -> {
            dialog.getButton(AlertDialog.BUTTON_NEGATIVE).setOnClickListener(v -> {
                dialog.dismiss();
                getWindow().getDecorView().postDelayed(this::launchNativeCamera, 250L);
            });
        });
        dialog.setOnDismissListener(d -> {
            if (previewBitmap != null && !previewBitmap.isRecycled()) previewBitmap.recycle();
        });

        dialog.show();
        if (dialog.getWindow() != null) {
            dialog.getWindow().setLayout(
                    Math.min(getResources().getDisplayMetrics().widthPixels - 40, 1000),
                    (int) (getResources().getDisplayMetrics().heightPixels * 0.78f));
        }
        dialog.getButton(AlertDialog.BUTTON_POSITIVE).setTextColor(Color.rgb(0, 125, 125));
        dialog.getButton(AlertDialog.BUTTON_NEGATIVE).setTextColor(Color.rgb(75, 85, 100));
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode == CAMERA_REQUEST) {
            if (resultCode == RESULT_OK && pendingCameraFile != null
                    && pendingCameraFile.exists() && pendingCameraFile.length() > 0) {
                try {
                    pendingWatermarkedFile = createWatermarkedPhoto(pendingCameraFile);
                    if (pendingCameraFile != null) pendingCameraFile.delete();
                    showPhotoPreview(pendingWatermarkedFile);
                } catch (Exception e) {
                    if (fileChooserCallback != null) {
                        fileChooserCallback.onReceiveValue(null);
                        fileChooserCallback = null;
                    }
                    Toast.makeText(this, "Gagal memproses foto: " + e.getMessage(),
                            Toast.LENGTH_LONG).show();
                }
            } else {
                if (fileChooserCallback != null) {
                    fileChooserCallback.onReceiveValue(null);
                    fileChooserCallback = null;
                }
                Toast.makeText(this, "Pengambilan foto dibatalkan.", Toast.LENGTH_SHORT).show();
            }
            return;
        }

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

        if (requestCode == CAMERA_PERMISSION_REQUEST && pendingWebPermissionRequest != null) {
            boolean granted = grantResults.length > 0
                    && grantResults[0] == PackageManager.PERMISSION_GRANTED;
            if (granted) {
                pendingWebPermissionRequest.grant(
                        new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            } else {
                pendingWebPermissionRequest.deny();
                Toast.makeText(this, "Izin kamera diperlukan untuk mengambil foto bukti.",
                        Toast.LENGTH_LONG).show();
            }
            pendingWebPermissionRequest = null;
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
        if (pendingWebPermissionRequest != null) {
            pendingWebPermissionRequest.deny();
            pendingWebPermissionRequest = null;
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
