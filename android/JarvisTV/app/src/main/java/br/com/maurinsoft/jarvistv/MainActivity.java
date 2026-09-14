package br.com.maurinsoft.jarvistv;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

public class MainActivity extends Activity {
    public static final String PREFS = "jarvis_tv";
    public static final String KEY_URL = "api_url";
    public static final String KEY_TOKEN = "api_token";

    private EditText urlEdit;
    private EditText tokenEdit;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        buildUi();
        requestRuntimePermissions();
    }

    private void buildUi() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(48), dp(36), dp(48), dp(36));
        root.setGravity(Gravity.CENTER_VERTICAL);
        root.setBackgroundColor(Color.rgb(16, 24, 32));

        TextView title = text("JARVIS TV", 34);
        root.addView(title);
        root.addView(text("Assistente lateral para Android TV", 20));

        urlEdit = new EditText(this);
        urlEdit.setHint("https://SEU-HOST/api/v1");
        urlEdit.setTextColor(Color.WHITE);
        urlEdit.setHintTextColor(Color.GRAY);
        urlEdit.setSingleLine(true);
        urlEdit.setText(prefs.getString(KEY_URL, ""));
        root.addView(urlEdit, fullWidth());

        tokenEdit = new EditText(this);
        tokenEdit.setHint("Token individual da TV");
        tokenEdit.setTextColor(Color.WHITE);
        tokenEdit.setHintTextColor(Color.GRAY);
        tokenEdit.setSingleLine(true);
        tokenEdit.setText(prefs.getString(KEY_TOKEN, ""));
        root.addView(tokenEdit, fullWidth());

        Button save = new Button(this);
        save.setText("Salvar configuração");
        save.setOnClickListener(v -> saveConfig());
        root.addView(save, fullWidth());

        Button overlay = new Button(this);
        overlay.setText("Autorizar painel lateral");
        overlay.setOnClickListener(v -> requestOverlayPermission());
        root.addView(overlay, fullWidth());

        Button start = new Button(this);
        start.setText("Iniciar JARVIS TV");
        start.setOnClickListener(v -> startAssistant());
        root.addView(start, fullWidth());

        status = text("Configure a API e autorize o painel lateral.", 17);
        root.addView(status, fullWidth());

        setContentView(root);
    }

    private TextView text(String value, int size) {
        TextView t = new TextView(this);
        t.setText(value);
        t.setTextSize(size);
        t.setTextColor(Color.WHITE);
        t.setPadding(0, dp(8), 0, dp(8));
        return t;
    }

    private LinearLayout.LayoutParams fullWidth() {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT);
        p.setMargins(0, dp(8), 0, dp(8));
        return p;
    }

    private void saveConfig() {
        String url = urlEdit.getText().toString().trim().replaceAll("/+$", "");
        String token = tokenEdit.getText().toString().trim();
        getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                .putString(KEY_URL, url)
                .putString(KEY_TOKEN, token)
                .apply();
        status.setText("Configuração salva localmente na TV.");
    }

    private void requestRuntimePermissions() {
        if (Build.VERSION.SDK_INT >= 23) {
            java.util.ArrayList<String> list = new java.util.ArrayList<>();
            if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                list.add(Manifest.permission.RECORD_AUDIO);
            }
            if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                list.add(Manifest.permission.POST_NOTIFICATIONS);
            }
            if (!list.isEmpty()) requestPermissions(list.toArray(new String[0]), 100);
        }
    }

    private void requestOverlayPermission() {
        if (Build.VERSION.SDK_INT >= 23 && !Settings.canDrawOverlays(this)) {
            Intent intent = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                    Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        } else {
            status.setText("Painel lateral autorizado.");
        }
    }

    private void startAssistant() {
        saveConfig();
        if (Build.VERSION.SDK_INT >= 23 && !Settings.canDrawOverlays(this)) {
            status.setText("Autorize primeiro o painel lateral.");
            requestOverlayPermission();
            return;
        }
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            status.setText("Permissão de microfone necessária.");
            requestRuntimePermissions();
            return;
        }
        Intent service = new Intent(this, AssistantOverlayService.class);
        if (Build.VERSION.SDK_INT >= 26) startForegroundService(service); else startService(service);
        status.setText("JARVIS TV iniciado. Diga 'Jarvis'.");
        moveTaskToBack(true);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
