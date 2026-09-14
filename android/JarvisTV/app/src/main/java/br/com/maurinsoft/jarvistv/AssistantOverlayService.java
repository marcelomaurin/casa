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
import android.graphics.Typeface;
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

    private static final int CREAM = Color.rgb(244, 238, 222);
    private static final int PANEL = Color.rgb(255, 250, 240);
    private static final int INK = Color.rgb(34, 31, 34);
    private static final int ORANGE = Color.rgb(229, 138, 85);
    private static final int SALMON = Color.rgb(217, 111, 120);
    private static final int LAVENDER = Color.rgb(155, 131, 173);
    private static final int BLUE = Color.rgb(111, 140, 168);
    private static final int GREEN = Color.rgb(111, 152, 126);

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
        panel.setPadding(dp(12), dp(10), dp(12), dp(12));
        panel.setBackground(lcarsPanelBackground());

        title = label("JARVIS", 19, INK, true);
        title.setGravity(Gravity.CENTER);
        title.setPadding(dp(8), dp(6), dp(8), dp(6));
        title.setBackground(rounded(ORANGE, 18));

        status = label("OUVINDO", 12, INK, true);
        status.setGravity(Gravity.CENTER);
        status.setPadding(dp(8), dp(5), dp(8), dp(5));
        status.setBackground(rounded(LAVENDER, 16));

        response = label("", 15, INK, false);
        response.setPadding(dp(10), dp(8), dp(10), dp(8));
        response.setBackground(rounded(PANEL, 16));

        panel.addView(title);
        panel.addView(status);
        panel.addView(response);

        int type = Build.VERSION.SDK_INT >= 26
                ? WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
                : WindowManager.LayoutParams.TYPE_PHONE;

        params = new WindowManager.LayoutParams(
                dp(68),
                dp(118),
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

    private GradientDrawable lcarsPanelBackground() {
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.argb(248, 244, 238, 222));
        bg.setCornerRadius(dp(24));
        bg.setStroke(dp(3), SALMON);
        return bg;
    }

    private GradientDrawable rounded(int color, int radiusDp) {
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(color);
        bg.setCornerRadius(dp(radiusDp));
        return bg;
    }

    private TextView label(String textValue, int size, int color, boolean bold) {
        TextView v = new TextView(this);
        v.setText(textValue);
        v.setTextSize(size);
        v.setTextColor(color);
        if (bold) v.setTypeface(Typeface.DEFAULT_BOLD);
        v.setMaxLines(6);
        return v;
    }

    private void createRecognizer() {
        if (!SpeechRecognizer.isRecognitionAvailable(this)) {
            if (status != null) status.setText("VOZ INDISPONÍVEL");
            return;
        }
        recognizer = SpeechRecognizer.createSpeechRecognizer(this);
        recognizer.setRecognitionListener(new RecognitionListener() {
            @Override public void onReadyForSpeech(Bundle params) { updateStatus("OUVINDO"); }
            @Override public void onBeginningOfSpeech() { updateStatus("ESCUTANDO"); }
            @Override public void onRmsChanged(float rmsdB) { }
            @Override public void onBufferReceived(byte[] buffer) { }
            @Override public void onEndOfSpeech() { updateStatus("PROCESSANDO"); }
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
            updateStatus("SEM MICROFONE");
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
            showMenu("OUVINDO", "");
            String command = normalized.substring(wake + "jarvis".length()).trim();
            if (!command.isEmpty()) processCommand(command);
            return;
        }

        if (menuVisible) processCommand(normalized);
    }

    private void processCommand(String command) {
        showMenu("ENTENDI", command);

        String requestedApp = launcher.detectRequestedApp(command);
        if (requestedApp != null && (command.contains("abr") || command.contains("inici") || command.contains("cham"))) {
            AppLauncher.LaunchResult result = launcher.launch(requestedApp);
            showMenu(result.success ? "APLICATIVO ABERTO" : "NÃO CONSEGUI ABRIR", result.message);
            return;
        }

        executor.execute(() -> {
            try {
                String answer = api.ask(command);
                main.post(() -> showMenu("RUNPOD", answer));
            } catch (Exception e) {
                main.post(() -> showMenu("SEM CONEXÃO", e.getMessage() == null ? "Falha ao acessar o serviço" : e.getMessage()));
            }
        });
    }

    private void showMenu(String state, String text) {
        if (panel == null || windowManager == null) return;
        menuVisible = true;
        status.setText(state);
        status.setBackground(rounded(state.contains("SEM") || state.contains("NÃO") ? SALMON : GREEN, 16));
        response.setText(text == null ? "" : text);
        params.width = dp(380);
        params.height = dp(250);
        params.flags = WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL
                | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS;
        try { windowManager.updateViewLayout(panel, params); } catch (Exception ignored) { }
        main.removeCallbacks(hideRunnable);
        main.postDelayed(hideRunnable, MENU_TIMEOUT_MS);
    }

    private void collapsePanel() {
        if (panel == null || windowManager == null) return;
        menuVisible = false;
        status.setText("OUVINDO");
        status.setBackground(rounded(LAVENDER, 16));
        response.setText("");
        params.width = dp(68);
        params.height = dp(118);
        params.flags = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE
                | WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL
                | WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS;
        try { windowManager.updateViewLayout(panel, params); } catch (Exception ignored) { }
    }

    private void updateStatus(String value) {
        if (status != null) main.post(() -> {
            status.setText(value);
            if (value.contains("PROCESSANDO")) status.setBackground(rounded(BLUE, 16));
            else if (value.contains("SEM")) status.setBackground(rounded(SALMON, 16));
            else status.setBackground(rounded(LAVENDER, 16));
        });
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
