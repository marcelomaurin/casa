package br.com.maurinsoft.jarvistv;

import java.util.Locale;

/** Regras de autorizacao de versao identicas em todas as consultas ao GitHub. */
public final class TvUpdatePolicy {
    private TvUpdatePolicy() {}

    public static boolean allowed(long currentVersion, long nextVersion, String installedPackage,
                                  String manifestPackage, String sha256, String apkUrl) {
        if (nextVersion <= currentVersion || nextVersion <= 0
                || installedPackage == null || !installedPackage.equals(manifestPackage)) return false;
        if (sha256 == null || !sha256.toLowerCase(Locale.ROOT).matches("[a-f0-9]{64}")) return false;
        if (apkUrl == null || !apkUrl.startsWith("https://github.com/marcelomaurin/casa/releases/download/")) return false;
        return apkUrl.endsWith(".apk") && !apkUrl.contains("?") && !apkUrl.contains("#");
    }
}
