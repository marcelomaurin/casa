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
import android.text.InputType;
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
    public static final String KEY_RUNPOD_API_KEY = "runpod_api_key";
    public static final String KEY_RUNPOD_ENDPOINT_ID = "runpod_endpoint_id";
    public static final String KEY_RUNPOD_MODEL = "runpod_model";

    private EditText urlEdit;
    private EditText tokenEdit;
    private EditText runpodKeyEdit;
    private EditText runpodEndpointEdit;
    private EditText runpodModelEdit;
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
        root.setPadding(dp(48), dp(30), dp(48), dp(30));
        root.setGravity(Gravity.CENTER_VERTICAL);
        root.setBackgroundColor(Color.rgb(16, 24, 32));

        TextView title = text("JARVIS TV", 34);
        root.addView(title);
        root.addView(text("Assistente lateral para Android TV — IA somente RunPod", 20));

        TextView provider = text("PROVEDOR DE IA: RUNPOD", 16);
        provider.setTextColor(Color.rgb(100, 210, 255));
        root.addView(provider);

        urlEdit = new EditText(this);
        urlEdit.setHint("https://maurinsoft.com.br");
        urlEdit.setTextColor(Color.WHITE);
        urlEdit.setHintTextColor(Color.GRAY);
        urlEdit.setSingleLine(true);
        urlEdit.setText(prefs.getString(KEY_URL, JarvisApiClient.DEFAULT_BASE_URL));
        root.addView(urlEdit, fullWidth());

        runpodKeyEdit = new EditText(this);
        runpodKeyEdit.setHint("RunPod API Key");
        runpodKeyEdit.setTextColor(Color.WHITE);
        runpodKeyEdit.setHintTextColor(Color.GRAY);
        runpodKeyEdit.setSingleLine(true);
        runpodKeyEdit.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        runpodKeyEdit.setText(prefs.getString(KEY_RUNPOD_API_KEY, ""));
        root.addView(runpodKeyEdit, fullWidth());

        runpodEndpointEdit = new EditText(this);
        runpodEndpointEdit.setHint("RunPod Endpoint ID");
        runpodEndpointEdit.setTextColor(Color.WHITE);
        runpodEndpointEdit.setHintTextColor(Color.GRAY);
        runpodEndpointEdit.setSingleLine(true);
        runpodEndpointEdit.setText(prefs.getString(KEY_RUNPOD_ENDPOINT_ID, ""));
        root.addView(runpodEndpointEdit, fullWidth());

        runpodModelEdit = new EditText(this);
        runpodModelEdit.setHint(JarvisApiClient.DEFAULT_MODEL);
        runpodModelEdit.setTextColor(Color.WHITE);
        runpodModelEdit.setHintTextColor(Color.GRAY);
        runpodModelEdit.setSingleLine(true);
        runpodModelEdit.setText(prefs.getString(KEY_RUNPOD_MODEL, JarvisApiClient.DEFAULT_MODEL));
        root.addView(runpodModelEdit, fullWidth());

        tokenEdit = new EditText(this);
        tokenEdit.setHint("Token individual da TV (serviços CASA)");
        tokenEdit.setTextColor(Color.WHITE);
        tokenEdit.setHintTextColor(Color.GRAY);
        tokenEdit.setSingleLine(true);
        tokenEdit.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        tokenEdit.setText(prefs.getString(KEY_TOKEN, ""));
        root.addView(tokenEdit, fullWidth());

        Button save = new Button(this);
        save.setText("Salvar configuração RunPod");
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

        status = text("Configure RunPod e autorize o painel lateral.", 17);
        root.addView(status, fullWidth());

        setContentView(root);
    }

    private TextView text(String value, int size) {
        TextView t = new TextView(this);
        t.setText(value);
        t.setTextSize(size);
        t.setTextColor(Color.WHITE);
        t.setPadding(0, dp(6), 0, dp(6));
        return t;
    }

    private LinearLayout.LayoutParams fullWidth() {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT);
        p.setMargins(0, dp(6), 0, dp(6));
        return p;
    }

    private void saveConfig() {
        String url = urlEdit.getText().toString().trim().replaceAll("/+$", "");
        String token = tokenEdit.getText().toString().trim();
        String runpodKey = runpodKeyEdit.getText().toString().trim();
        String runpodEndpoint = runpodEndpointEdit.getText().toString().trim();
        String runpodModel = runpodModelEdit.getText().toString().trim();

        if (url.isEmpty()) url = JarvisApiClient.DEFAULT_BASE_URL;
        if (runpodModel.isEmpty()) runpodModel = JarvisApiClient.DEFAULT_MODEL;

        getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                .putString(KEY_URL, url)
                .putString(KEY_TOKEN, token)
                .putString(KEY_RUNPOD_API_KEY, runpodKey)
                .putString(KEY_RUNPOD_ENDPOINT_ID, runpodEndpoint)
                .putString(KEY_RUNPOD_MODEL, runpodModel)
                .apply();

        if (runpodKey.isEmpty() || runpodEndpoint.isEmpty()) {
            status.setText("Informe a RunPod API Key e o Endpoint ID.");
        } else {
            status.setText("RunPod configurado. A TV usará somente /api/chat.");
        }
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

        JarvisApiClient api = new JarvisApiClient(this);
        if (!api.isConfigured()) {
            status.setText("Configure a chave e o Endpoint ID do RunPod antes de iniciar.");
            return;
        }

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
        status.setText("JARVIS TV iniciado com RunPod. Diga 'Jarvis'.");
        moveTaskToBack(true);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
