package br.com.maurinsoft.jarvistv;

import android.app.*;
import android.content.*;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import org.json.JSONObject;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import java.util.concurrent.Executors;

/** Downloads only an explicitly approved, SHA-256 pinned APK; installation needs Android approval. */
public final class TvUpdateManager {
    private static final String MANIFEST = "https://raw.githubusercontent.com/marcelomaurin/casa/master/bin/versions.json";
    private static final String PREFS = "tv_updates";
    private static final String CHANNEL = "tv_updates";
    private TvUpdateManager() {}

    public static void check(Activity activity) {
        Executors.newSingleThreadExecutor().execute(() -> {
            try {
                HttpURLConnection conn = (HttpURLConnection) new URL(MANIFEST).openConnection();
                conn.setConnectTimeout(8000); conn.setReadTimeout(12000);
                if (conn.getResponseCode() != 200) throw new IllegalStateException("Manifest unavailable");
                byte[] data;
                try (InputStream in = conn.getInputStream()) { data = readLimited(in, 65536); }
                JSONObject root = new JSONObject(new String(data, java.nio.charset.StandardCharsets.UTF_8));
                JSONObject entry = root.getJSONObject("components").optJSONObject(BuildConfig.DEBUG ? "jarvis-tv-debug" : "jarvis-tv");
                if (entry == null || !entry.optBoolean("enabled", false)) return;
                long version = entry.getLong("version_code");
                String packageId = entry.getString("package_id");
                String url = entry.getString("apk_url");
                String sha = entry.getString("sha256").toLowerCase(java.util.Locale.ROOT);
                if (version <= BuildConfig.VERSION_CODE || !packageId.equals(activity.getPackageName())
                    || !sha.matches("[a-f0-9]{64}") || !url.startsWith("https://github.com/marcelomaurin/casa/releases/download/")) return;
                SharedPreferences prefs = activity.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
                if (prefs.getLong("pending_code", 0) == version && prefs.getLong("download_id", -1) > 0) return;
                DownloadManager dm = (DownloadManager) activity.getSystemService(Context.DOWNLOAD_SERVICE);
                long old = prefs.getLong("download_id", -1); if (old > 0) dm.remove(old);
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url))
                    .setTitle("Atualização Jarvis TV").setMimeType("application/vnd.android.package-archive")
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalFilesDir(activity, "updates", "JarvisTV-" + version + ".apk");
                long id = dm.enqueue(request);
                prefs.edit().putLong("download_id", id).putLong("pending_code", version).putString("sha256", sha).apply();
            } catch (Exception ignored) { /* Retry on next app launch. */ }
        });
    }

    private static byte[] readLimited(InputStream in, int max) throws Exception {
        java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream();
        byte[] b = new byte[8192]; int n;
        while ((n = in.read(b)) != -1) { if (out.size() + n > max) throw new IllegalStateException("Too large"); out.write(b, 0, n); }
        return out.toByteArray();
    }

    public static void onDownloadComplete(Context context, long id) {
        SharedPreferences prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if (id != prefs.getLong("download_id", -1)) return;
        NotificationManager nm = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        nm.createNotificationChannel(new NotificationChannel(CHANNEL, "Atualizações Jarvis TV", NotificationManager.IMPORTANCE_DEFAULT));
        if (Build.VERSION.SDK_INT >= 33 && context.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return;
        Intent intent = new Intent(context, TvUpdateInstallActivity.class);
        PendingIntent pi = PendingIntent.getActivity(context, 910, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        nm.notify(910, new Notification.Builder(context, CHANNEL).setSmallIcon(android.R.drawable.stat_sys_download_done)
            .setContentTitle("Atualização Jarvis TV pronta").setContentText("Confirme a instalação").setContentIntent(pi).setAutoCancel(true).build());
    }

    public static final class Receiver extends android.content.BroadcastReceiver {
        @Override public void onReceive(Context context, Intent intent) {
            if (DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(intent.getAction()))
                onDownloadComplete(context, intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1));
        }
    }
}
