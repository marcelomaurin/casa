package br.com.maurinsoft.jarvistv;

import android.app.DownloadManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import org.json.JSONObject;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

/** Consulta o JSON da master, baixa APK autorizado e solicita confirmacao pelo Android TV. */
public final class TvUpdateManager {
    private static final String MANIFEST = "https://raw.githubusercontent.com/marcelomaurin/casa/master/bin/versions.json";
    private static final String PREFS = "tv_updates";
    private static final String CHANNEL = "tv_updates";
    private static final long CHECK_INTERVAL_MS = TimeUnit.HOURS.toMillis(1);
    private static final ExecutorService EXECUTOR = Executors.newSingleThreadExecutor();
    private static final AtomicBoolean CHECKING = new AtomicBoolean(false);
    private static volatile long lastCheck = 0;
    private TvUpdateManager() {}

    public static String status(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getString("status", "Atualizacao ainda nao consultada.");
    }

    private static void setStatus(Context context, String message) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().putString("status", message).apply();
    }

    public static void check(Context context) { check(context, false); }

    public static void check(Context context, boolean force) {
        long now = System.currentTimeMillis();
        if (!force && now - lastCheck < CHECK_INTERVAL_MS) return;
        if (!CHECKING.compareAndSet(false, true)) return;
        lastCheck = now;
        final Context app = context.getApplicationContext();
        EXECUTOR.execute(() -> {
            try {
                checkNow(app);
            } catch (Exception e) {
                setStatus(app, "Nao foi possivel consultar o GitHub. O aplicativo continuara funcionando.");
            } finally {
                CHECKING.set(false);
            }
        });
    }

    private static void clearDownload(Context app, SharedPreferences prefs, DownloadManager dm) {
        long old = prefs.getLong("download_id", -1);
        if (old > 0) dm.remove(old);
        prefs.edit().remove("download_id").remove("pending_code")
                .remove("pending_version").remove("sha256").apply();
    }

    private static void checkNow(Context app) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(MANIFEST).openConnection();
        conn.setConnectTimeout(8000);
        conn.setReadTimeout(15000);
        conn.setRequestProperty("Cache-Control", "no-cache");
        byte[] data;
        try {
            if (conn.getResponseCode() != 200) throw new IllegalStateException("Manifesto indisponivel");
            try (InputStream in = conn.getInputStream()) { data = readLimited(in, 65536); }
        } finally { conn.disconnect(); }

        JSONObject root = new JSONObject(new String(data, StandardCharsets.UTF_8));
        if (root.optInt("schema_version") != 1) throw new IllegalStateException("Versao do manifesto nao suportada");
        JSONObject components = root.optJSONObject("components");
        JSONObject entry = components == null ? null :
            components.optJSONObject(BuildConfig.DEBUG ? "jarvis-tv-debug" : "jarvis-tv");
        SharedPreferences prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        DownloadManager dm = (DownloadManager) app.getSystemService(Context.DOWNLOAD_SERVICE);
        if (entry == null || !entry.optBoolean("enabled", false)) {
            clearDownload(app, prefs, dm);
            setStatus(app, "Nenhuma atualizacao autorizada para esta TV.");
            return;
        }

        long version = entry.getLong("version_code");
        String versionName = entry.getString("version");
        String pkg = entry.getString("package_id");
        String url = entry.getString("apk_url");
        String sha = entry.getString("sha256").toLowerCase(Locale.ROOT);
        if (version <= BuildConfig.VERSION_CODE) {
            clearDownload(app, prefs, dm);
            setStatus(app, "JARVIS TV atualizado (" + BuildConfig.VERSION_NAME + ").");
            return;
        }
        if (!TvUpdatePolicy.allowed(BuildConfig.VERSION_CODE, version, app.getPackageName(), pkg, sha, url))
            throw new SecurityException("Atualizacao nao autorizada para este pacote.");

        long oldId = prefs.getLong("download_id", -1);
        if (oldId > 0 && prefs.getLong("pending_code", 0) == version &&
                sha.equals(prefs.getString("sha256", ""))) {
            try (Cursor c = dm.query(new DownloadManager.Query().setFilterById(oldId))) {
                if (c != null && c.moveToFirst()) {
                    int state = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
                    if (state == DownloadManager.STATUS_SUCCESSFUL) {
                        setStatus(app, "Versao " + versionName + " baixada. Selecione Instalar atualizacao.");
                        return;
                    }
                    if (state == DownloadManager.STATUS_RUNNING || state == DownloadManager.STATUS_PENDING ||
                            state == DownloadManager.STATUS_PAUSED) {
                        setStatus(app, "Baixando versao " + versionName + " do GitHub.");
                        return;
                    }
                }
            }
        }
        clearDownload(app, prefs, dm);
        DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url))
                .setTitle("JARVIS TV " + versionName)
                .setDescription("Atualizacao autorizada por bin/versions.json")
                .setMimeType("application/vnd.android.package-archive")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalFilesDir(app, "updates", "JarvisTV-" + version + ".apk")
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(false);
        long id = dm.enqueue(request);
        prefs.edit().putLong("download_id", id).putLong("pending_code", version)
                .putString("pending_version", versionName).putString("sha256", sha).commit();
        setStatus(app, "Baixando versao " + versionName + " do GitHub.");
    }

    private static byte[] readLimited(InputStream in, int max) throws Exception {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] b = new byte[8192]; int n;
        while ((n = in.read(b)) != -1) {
            if (out.size() + n > max) throw new IllegalStateException("Manifesto grande demais");
            out.write(b, 0, n);
        }
        return out.toByteArray();
    }

    public static void openInstaller(Context context) {
        context.startActivity(new Intent(context, TvUpdateInstallActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
    }

    public static void onDownloadComplete(Context context, long id) {
        SharedPreferences prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if (id <= 0 || id != prefs.getLong("download_id", -1)) return;
        DownloadManager dm = (DownloadManager) context.getSystemService(Context.DOWNLOAD_SERVICE);
        int state = -1;
        try (Cursor c = dm.query(new DownloadManager.Query().setFilterById(id))) {
            if (c != null && c.moveToFirst()) state = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
        }
        if (state != DownloadManager.STATUS_SUCCESSFUL) {
            setStatus(context, "Download falhou. Nova tentativa na proxima consulta ao GitHub.");
            return;
        }
        setStatus(context, "Atualizacao baixada. Selecione Instalar atualizacao na TV.");
        NotificationManager nm = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        nm.createNotificationChannel(new NotificationChannel(CHANNEL, "Atualizacoes JARVIS TV",
                NotificationManager.IMPORTANCE_DEFAULT));
        if (Build.VERSION.SDK_INT >= 33 &&
                context.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED)
            return;
        Intent intent = new Intent(context, TvUpdateInstallActivity.class);
        PendingIntent pi = PendingIntent.getActivity(context, 910, intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        nm.notify(910, new Notification.Builder(context, CHANNEL)
                .setSmallIcon(android.R.drawable.stat_sys_download_done)
                .setContentTitle("Atualizacao JARVIS TV pronta")
                .setContentText("Confirme a instalacao no Android TV")
                .setContentIntent(pi).setAutoCancel(true).build());
    }

    public static final class Receiver extends BroadcastReceiver {
        @Override public void onReceive(Context context, Intent intent) {
            if (DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(intent.getAction()))
                onDownloadComplete(context, intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1));
        }
    }
}
