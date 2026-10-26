package br.com.maurinsoft.jarvistv;

import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;

/**
 * Canal entre o servico de monitoramento e a tela.
 *
 * O servico roda o tempo todo (inclusive com a TV em outro aplicativo) e
 * repassa os comandos para a tela quando ela esta visivel. Tudo e entregue
 * na thread principal.
 */
public final class TvBus {
    public interface Listener {
        void onRefresh();
        void onNextCamera();
        void onEmergency(String event, JSONObject payload);
        void onServiceStatus(String text);
    }

    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static volatile Listener listener;

    private TvBus() {}

    public static void register(Listener l) { listener = l; }
    public static void unregister(Listener l) { if (listener == l) listener = null; }
    public static boolean screenVisible() { return listener != null; }

    public static void refresh() { Listener l = listener; if (l != null) MAIN.post(l::onRefresh); }
    public static void nextCamera() { Listener l = listener; if (l != null) MAIN.post(l::onNextCamera); }
    public static void emergency(String event, JSONObject payload) {
        Listener l = listener;
        if (l != null) MAIN.post(() -> l.onEmergency(event, payload));
    }
    public static void status(String text) { Listener l = listener; if (l != null) MAIN.post(() -> l.onServiceStatus(text)); }
}
