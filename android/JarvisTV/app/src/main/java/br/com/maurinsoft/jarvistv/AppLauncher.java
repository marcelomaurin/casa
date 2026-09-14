package br.com.maurinsoft.jarvistv;

import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;

import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;

public class AppLauncher {
    private final Context context;
    private final Map<String, String> packages = new LinkedHashMap<>();

    public AppLauncher(Context context) {
        this.context = context.getApplicationContext();
        packages.put("netflix", "com.netflix.ninja");
        packages.put("youtube", "com.google.android.youtube.tv");
        packages.put("prime video", "com.amazon.amazonvideo.livingroom");
        packages.put("amazon prime", "com.amazon.amazonvideo.livingroom");
        packages.put("disney", "com.disney.disneyplus");
        packages.put("globoplay", "com.globo.globotv");
    }

    public String detectRequestedApp(String phrase) {
        String normalized = phrase.toLowerCase(Locale.ROOT);
        for (String name : packages.keySet()) {
            if (normalized.contains(name)) return name;
        }
        return null;
    }

    public LaunchResult launch(String appName) {
        String packageName = packages.get(appName);
        if (packageName == null) return new LaunchResult(false, "Aplicativo não mapeado");

        PackageManager pm = context.getPackageManager();
        Intent intent = pm.getLaunchIntentForPackage(packageName);
        if (intent == null) return new LaunchResult(false, appName + " não está instalado");

        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(intent);
        return new LaunchResult(true,
                appName + " aberto. O painel JARVIS permanece como overlay lateral quando permitido pelo sistema.");
    }

    public static class LaunchResult {
        public final boolean success;
        public final String message;

        public LaunchResult(boolean success, String message) {
            this.success = success;
            this.message = message;
        }
    }
}
