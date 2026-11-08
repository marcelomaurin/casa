package br.com.maurinsoft.jarvistv;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.TextView;
import java.io.File;
import java.io.InputStream;
import java.security.MessageDigest;

/** Validates downloaded APK and delegates installation to the Android package installer. */
public final class TvUpdateInstallActivity extends Activity {
    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        TextView status = new TextView(this); status.setPadding(30, 30, 30, 30);
        status.setText("Validando atualização Jarvis TV..."); setContentView(status);
        new Thread(() -> {
            try {
                if (!getPackageManager().canRequestPackageInstalls()) {
                    runOnUiThread(() -> {
                        status.setText("Autorize a instalação de apps para Jarvis TV e volte a esta tela.");
                        startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getPackageName())));
                    });
                    return;
                }
                SharedPreferences prefs = getSharedPreferences("tv_updates", MODE_PRIVATE);
                long id = prefs.getLong("download_id", -1);
                if (id < 0) throw new IllegalStateException("Nenhum download pendente");
                DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                boolean completed;
                try (android.database.Cursor c = dm.query(new DownloadManager.Query().setFilterById(id))) {
                    completed = c != null && c.moveToFirst() && c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) == DownloadManager.STATUS_SUCCESSFUL;
                }
                if (!completed) throw new IllegalStateException("Download não concluído");
                Uri uri = dm.getUriForDownloadedFile(id);
                if (uri == null) throw new IllegalStateException("Arquivo indisponível");
                File temp = File.createTempFile("jarvistv-update-", ".apk", getCacheDir());
                try {
                    MessageDigest md = MessageDigest.getInstance("SHA-256");
                    long total = 0;
                    try (InputStream in = getContentResolver().openInputStream(uri); java.io.FileOutputStream out = new java.io.FileOutputStream(temp)) {
                        if (in == null) throw new IllegalStateException("Não foi possível ler APK");
                        byte[] buf = new byte[8192]; int n;
                        while ((n = in.read(buf)) != -1) {
                            total += n; if (total > 200L * 1024 * 1024) throw new IllegalStateException("APK muito grande");
                            md.update(buf, 0, n); out.write(buf, 0, n);
                        }
                    }
                    StringBuilder hex = new StringBuilder();
                    for (byte b1 : md.digest()) hex.append(String.format(java.util.Locale.ROOT, "%02x", b1 & 0xff));
                    if (!hex.toString().equals(prefs.getString("sha256", ""))) throw new SecurityException("SHA-256 inválido");
                    int flags = Build.VERSION.SDK_INT >= 28 ? PackageManager.GET_SIGNING_CERTIFICATES : PackageManager.GET_SIGNATURES;
                    PackageInfo candidate = getPackageManager().getPackageArchiveInfo(temp.getAbsolutePath(), flags);
                    PackageInfo current = getPackageManager().getPackageInfo(getPackageName(), flags);
                    if (candidate == null || !getPackageName().equals(candidate.packageName)) throw new SecurityException("Pacote diferente");
                    long version = Build.VERSION.SDK_INT >= 28 ? candidate.getLongVersionCode() : candidate.versionCode;
                    if (version <= BuildConfig.VERSION_CODE || version != prefs.getLong("pending_code", -1)) throw new SecurityException("Versão não autorizada");
                    android.content.pm.Signature[] sigA = Build.VERSION.SDK_INT >= 28 ? current.signingInfo.getApkContentsSigners() : current.signatures;
                    android.content.pm.Signature[] sigB = Build.VERSION.SDK_INT >= 28 ? candidate.signingInfo.getApkContentsSigners() : candidate.signatures;
                    if (sigA == null || sigB == null || !java.util.Arrays.equals(sigA, sigB)) throw new SecurityException("Assinatura diferente");
                } finally { temp.delete(); }
                runOnUiThread(() -> {
                    status.setText("Confirme a atualização no instalador Android.");
                    startActivity(new Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
                        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION));
                });
            } catch (Exception e) { runOnUiThread(() -> status.setText("Atualização bloqueada: " + e.getMessage())); }
        }).start();
    }
}
