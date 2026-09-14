package br.com.maurinsoft.jarvistv;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.PixelFormat;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.provider.Settings;
import android.speech.RecognitionListener;
import android.speech.RecognizerIntent;
import android.speech.SpeechRecognizer;
import android.view.Gravity;
import android.view.WindowManager;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.core.app.NotificationCompat;

import java.util.ArrayList;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class AssistantOverlayService extends Service {
    private static final String CHANNEL_ID = "jarvis_tv_assistant";
    private static final int NOTIFICATION_ID = 4101;
    private static final long MENU_TIMEOUT_MS = 8000L;

    private final Handler main = new Handler(Looper.getMainLooper());
    private final ExecutorService executor = Executors.newSingleThreadExecutor();

    private WindowManager windowManager;
    private WindowManager.LayoutParams params;
    private LinearLayout panel;
    private TextView title;
    private TextView status;
    private TextView response;
    private SpeechRecognizer recognizer;
    private boolean menuVisible = false;
    private boolean destroyed = false;

    private JarvisApiClient api;
    private AppLauncher launcher;

    private final Runnable hideRunnable = this::collapsePanel;
    private final Runnable restartListeningRunnable = this::startListening;

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        startForeground(NOTIFICATION_ID, buildNotification("Ouvindo por 'Jarvis'"));
        api = new JarvisApiClient(this);
        launcher = new AppLauncher(this);
        createOverlay();
        createRecognizer();
        startListening();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onDestroy() {
        destroyed = true;
        main.removeCallbacksAndMessages(null);
        if (recognizer != null) {
            recognizer.cancel();
            recognizer.destroy();
            recognizer = null;
        }
        if (panel != null && windowManager != null) {
            try { windowManager.removeView(panel); } catch (Exception ignored) { }
        }
        executor.shutdownNow();
        super.onDestroy();
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationManager nm = getSystemService(NotificationManager.class);
            nm.createNotificationChannel(new NotificationChannel(
                    CHANNEL_ID,
                    "JARVIS TV",
                    NotificationManager.IMPORTANCE_LOW));
        }
    }

    private Notification buildNotification(String text) {
        Intent open = new Intent(this, MainActivity.class);
        PendingIntent pi = PendingIntent.getActivity(this, 0, open,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_jarvis_tv)
                .setContentTitle("JARVIS TV")
                .setContentText(text)
                .setOngoing(true)
                .setContentIntent(pi)
                .build();
    }

    private void createOverlay() {
        if (Build.VERSION.SDK_INT >= 23 && !Settings.canDrawOverlays(this)) return;

        windowManager = (WindowManager) getSystemService(WINDOW_SERVICE);
        panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setPadding(dp(14), dp(14), dp(14), dp(14));

        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.argb(235, 16, 42, 67));
        bg.setCornerRadius(dp(18));
        bg.setStroke(dp(2), Color.rgb(79, 195, 247));
        panel.setBackground(bg);

        title = label("JARVIS", 20, Color.WHITE);
        status = label("ouvindo", 14, Color.rgb(79, 195, 247));
        response = label("", 15, Color.WHITE);
        panel.addView(title);
        panel.addView(status);
        panel.addView(response);

        int type = Build.VERSION.SDK_INT >= 26
                ? WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
                : WindowManager.LayoutParams.TYPE_PHONE;

        params = new WindowManager.LayoutParams(
                dp(62),
                dp(112),
                type,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE
                        | WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL
                        | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
                PixelFormat.TRANSLUCENT);
        params.gravity = Gravity.START | Gravity.CENTER_VERTICAL;
        params.x = dp(8);
        params.y = 0;

        windowManager.addView(panel, params);
        collapsePanel();
    }

    private TextView label(String textValue, int size, int color) {
        TextView v = new TextView(this);
        v.setText(textValue);
        v.setTextSize(size);
        v.setTextColor(color);
        v.setMaxLines(6);
        return v;
    }

    private void createRecognizer() {
        if (!SpeechRecognizer.isRecognitionAvailable(this)) {
            if (status != null) status.setText("reconhecimento de voz indisponível");
            return;
        }
        recognizer = SpeechRecognizer.createSpeechRecognizer(this);
        recognizer.setRecognitionListener(new RecognitionListener() {
            @Override public void onReadyForSpeech(Bundle params) { updateStatus("ouvindo"); }
            @Override public void onBeginningOfSpeech() { updateStatus("escutando..."); }
            @Override public void onRmsChanged(float rmsdB) { }
            @Override public void onBufferReceived(byte[] buffer) { }
            @Override public void onEndOfSpeech() { updateStatus("processando..."); }
            @Override public void onError(int error) { scheduleRestart(error == SpeechRecognizer.ERROR_RECOGNIZER_BUSY ? 1800 : 900); }
            @Override public void onResults(Bundle results) {
                ArrayList<String> list = results == null ? null : results.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION);
                String phrase = list == null || list.isEmpty() ? "" : list.get(0);
                handleRecognizedPhrase(phrase);
                scheduleRestart(700);
            }
            @Override public void onPartialResults(Bundle partialResults) { }
            @Override public void onEvent(int eventType, Bundle params) { }
        });
    }

    private void startListening() {
        if (destroyed || recognizer == null) return;
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            updateStatus("microfone sem permissão");
            return;
        }
        try {
            Intent intent = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
            intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
            intent.putExtra(RecognizerIntent.EXTRA_LANGUAGE, "pt-BR");
            intent.putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false);
            intent.putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, true);
            recognizer.startListening(intent);
        } catch (Exception e) {
            scheduleRestart(1500);
        }
    }

    private void scheduleRestart(long delayMs) {
        main.removeCallbacks(restartListeningRunnable);
        main.postDelayed(restartListeningRunnable, delayMs);
    }

    private void handleRecognizedPhrase(String phrase) {
        if (phrase == null || phrase.trim().isEmpty()) return;
        String normalized = phrase.toLowerCase(Locale.ROOT).trim();
        int wake = normalized.indexOf("jarvis");

        if (wake >= 0) {
            showMenu("Estou ouvindo", "");
            String command = normalized.substring(wake + "jarvis".length()).trim();
            if (!command.isEmpty()) processCommand(command);
            return;
        }

        if (menuVisible) processCommand(normalized);
    }

    private void processCommand(String command) {
        showMenu("Entendi", command);

        String requestedApp = launcher.detectRequestedApp(command);
        if (requestedApp != null && (command.contains("abr") || command.contains("inici") || command.contains("cham"))) {
            AppLauncher.LaunchResult result = launcher.launch(requestedApp);
            showMenu(result.success ? "Aplicativo aberto" : "Não consegui abrir", result.message);
            return;
        }

        executor.execute(() -> {
            try {
                String answer = api.ask(command);
                main.post(() -> showMenu("JARVIS", answer));
            } catch (Exception e) {
                main.post(() -> showMenu("Sem conexão", e.getMessage() == null ? "Falha ao acessar a casa" : e.getMessage()));
            }
        });
    }

    private void showMenu(String state, String text) {
        if (panel == null || windowManager == null) return;
        menuVisible = true;
        status.setText(state);
        response.setText(text == null ? "" : text);
        params.width = dp(360);
        params.height = dp(260);
        params.flags = WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL
                | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS;
        try { windowManager.updateViewLayout(panel, params); } catch (Exception ignored) { }
        main.removeCallbacks(hideRunnable);
        main.postDelayed(hideRunnable, MENU_TIMEOUT_MS);
    }

    private void collapsePanel() {
        if (panel == null || windowManager == null) return;
        menuVisible = false;
        status.setText("ouvindo");
        response.setText("");
        params.width = dp(62);
        params.height = dp(112);
        params.flags = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE
                | WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL
                | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS;
        try { windowManager.updateViewLayout(panel, params); } catch (Exception ignored) { }
    }

    private void updateStatus(String value) {
        if (status != null) main.post(() -> status.setText(value));
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
