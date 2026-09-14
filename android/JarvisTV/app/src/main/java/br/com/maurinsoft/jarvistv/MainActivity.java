package br.com.maurinsoft.jarvistv;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

public class MainActivity extends Activity {
    public static final String PREFS = "jarvis_tv";
    public static final String KEY_URL = "api_url";
    public static final String KEY_TOKEN = "api_token";
    public static final String KEY_RUNPOD_API_KEY = "runpod_api_key";
    public static final String KEY_RUNPOD_ENDPOINT_ID = "runpod_endpoint_id";
    public static final String KEY_RUNPOD_MODEL = "runpod_model";

    private static final int CREAM = Color.rgb(244, 238, 222);
    private static final int PANEL = Color.rgb(255, 250, 240);
    private static final int INK = Color.rgb(34, 31, 34);
    private static final int ORANGE = Color.rgb(229, 138, 85);
    private static final int SALMON = Color.rgb(217, 111, 120);
    private static final int LAVENDER = Color.rgb(155, 131, 173);
    private static final int BLUE = Color.rgb(111, 140, 168);
    private static final int GOLD = Color.rgb(211, 160, 77);
    private static final int GREEN = Color.rgb(111, 152, 126);

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

        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(CREAM);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(42), dp(28), dp(42), dp(32));
        root.setBackgroundColor(CREAM);
        scroll.addView(root, new ScrollView.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT));

        // Cabeçalho LCARS: a moldura também comunica função/estado.
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(dp(18), dp(12), dp(18), dp(12));
        header.setBackground(rounded(ORANGE, 28));

        TextView title = text("JARVIS TV", 32, INK, true);
        header.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));

        TextView runpodTag = chip("RUNPOD", LAVENDER);
        header.addView(runpodTag);
        root.addView(header, fullWidth(dp(4)));

        LinearLayout subBand = new LinearLayout(this);
        subBand.setOrientation(LinearLayout.HORIZONTAL);
        subBand.setGravity(Gravity.CENTER_VERTICAL);
        subBand.setPadding(dp(16), dp(6), dp(16), dp(6));
        subBand.setBackground(rounded(SALMON, 18));
        TextView subtitle = text("ASSISTENTE LATERAL • ANDROID TV", 15, INK, true);
        subBand.addView(subtitle, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        subBand.addView(chip("IA: SOMENTE RUNPOD", GOLD));
        root.addView(subBand, fullWidth(dp(8)));

        LinearLayout configPanel = new LinearLayout(this);
        configPanel.setOrientation(LinearLayout.VERTICAL);
        configPanel.setPadding(dp(22), dp(20), dp(22), dp(20));
        GradientDrawable panelBg = rounded(PANEL, 24);
        panelBg.setStroke(dp(2), LAVENDER);
        configPanel.setBackground(panelBg);

        configPanel.addView(sectionTitle("CONEXÃO"));
        urlEdit = field("URL da Maurinsoft", prefs.getString(KEY_URL, JarvisApiClient.DEFAULT_BASE_URL), false);
        configPanel.addView(urlEdit, fullWidth(dp(5)));

        configPanel.addView(sectionTitle("RUNPOD"));
        runpodKeyEdit = field("RunPod API Key", prefs.getString(KEY_RUNPOD_API_KEY, ""), true);
        configPanel.addView(runpodKeyEdit, fullWidth(dp(5)));

        runpodEndpointEdit = field("RunPod Endpoint ID", prefs.getString(KEY_RUNPOD_ENDPOINT_ID, ""), false);
        configPanel.addView(runpodEndpointEdit, fullWidth(dp(5)));

        runpodModelEdit = field("Modelo", prefs.getString(KEY_RUNPOD_MODEL, JarvisApiClient.DEFAULT_MODEL), false);
        configPanel.addView(runpodModelEdit, fullWidth(dp(5)));

        configPanel.addView(sectionTitle("CASA"));
        tokenEdit = field("Token individual da TV (serviços CASA)", prefs.getString(KEY_TOKEN, ""), true);
        configPanel.addView(tokenEdit, fullWidth(dp(5)));

        root.addView(configPanel, fullWidth(dp(12)));

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        actions.setGravity(Gravity.CENTER_VERTICAL);

        Button save = lcarsButton("SALVAR RUNPOD", ORANGE);
        save.setOnClickListener(v -> saveConfig());
        actions.addView(save, actionWeight(1f, dp(8)));

        Button overlay = lcarsButton("AUTORIZAR PAINEL", LAVENDER);
        overlay.setOnClickListener(v -> requestOverlayPermission());
        actions.addView(overlay, actionWeight(1f, dp(8)));

        Button start = lcarsButton("INICIAR JARVIS", GREEN);
        start.setOnClickListener(v -> startAssistant());
        actions.addView(start, actionWeight(1f, 0));

        root.addView(actions, fullWidth(dp(10)));

        status = text("CONFIGURE RUNPOD E AUTORIZE O PAINEL LATERAL.", 16, INK, true);
        status.setPadding(dp(16), dp(12), dp(16), dp(12));
        GradientDrawable statusBg = rounded(BLUE, 18);
        status.setBackground(statusBg);
        root.addView(status, fullWidth(dp(2)));

        TextView footer = text("CASA • TV DISTRIBUÍDA • LCARS", 12, Color.rgb(92, 80, 92), true);
        footer.setGravity(Gravity.END);
        root.addView(footer, fullWidth(dp(2)));

        setContentView(scroll);
    }

    private TextView sectionTitle(String value) {
        TextView t = text(value, 14, INK, true);
        t.setPadding(0, dp(10), 0, dp(4));
        return t;
    }

    private EditText field(String hint, String value, boolean secret) {
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setText(value == null ? "" : value);
        e.setSingleLine(true);
        e.setTextColor(INK);
        e.setHintTextColor(Color.rgb(120, 108, 112));
        e.setTextSize(16);
        e.setPadding(dp(14), dp(10), dp(14), dp(10));
        if (secret) {
            e.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        }
        GradientDrawable bg = rounded(Color.WHITE, 16);
        bg.setStroke(dp(2), BLUE);
        e.setBackground(bg);
        return e;
    }

    private Button lcarsButton(String label, int color) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextColor(INK);
        b.setTextSize(14);
        b.setTypeface(Typeface.DEFAULT_BOLD);
        b.setAllCaps(false);
        b.setPadding(dp(12), dp(10), dp(12), dp(10));
        b.setBackground(rounded(color, 22));
        return b;
    }

    private TextView chip(String label, int color) {
        TextView chip = text(label, 13, INK, true);
        chip.setGravity(Gravity.CENTER);
        chip.setPadding(dp(16), dp(7), dp(16), dp(7));
        chip.setBackground(rounded(color, 18));
        return chip;
    }

    private TextView text(String value, int size, int color, boolean bold) {
        TextView t = new TextView(this);
        t.setText(value);
        t.setTextSize(size);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.DEFAULT_BOLD);
        return t;
    }

    private GradientDrawable rounded(int color, int radiusDp) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(color);
        g.setCornerRadius(dp(radiusDp));
        return g;
    }

    private LinearLayout.LayoutParams fullWidth(int bottomMargin) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT);
        p.setMargins(0, 0, 0, bottomMargin);
        return p;
    }

    private LinearLayout.LayoutParams actionWeight(float weight, int rightMargin) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(0, dp(58), weight);
        p.setMargins(0, 0, rightMargin, 0);
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
            status.setText("INFORME A RUNPOD API KEY E O ENDPOINT ID.");
            status.setBackground(rounded(SALMON, 18));
        } else {
            status.setText("RUNPOD CONFIGURADO • TV USARÁ SOMENTE /api/chat");
            status.setBackground(rounded(GREEN, 18));
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
            status.setText("PAINEL LATERAL AUTORIZADO.");
            status.setBackground(rounded(GREEN, 18));
        }
    }

    private void startAssistant() {
        saveConfig();

        JarvisApiClient api = new JarvisApiClient(this);
        if (!api.isConfigured()) {
            status.setText("CONFIGURE A CHAVE E O ENDPOINT ID DO RUNPOD.");
            status.setBackground(rounded(SALMON, 18));
            return;
        }

        if (Build.VERSION.SDK_INT >= 23 && !Settings.canDrawOverlays(this)) {
            status.setText("AUTORIZE PRIMEIRO O PAINEL LATERAL.");
            status.setBackground(rounded(GOLD, 18));
            requestOverlayPermission();
            return;
        }
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            status.setText("PERMISSÃO DE MICROFONE NECESSÁRIA.");
            status.setBackground(rounded(GOLD, 18));
            requestRuntimePermissions();
            return;
        }
        Intent service = new Intent(this, AssistantOverlayService.class);
        if (Build.VERSION.SDK_INT >= 26) startForegroundService(service); else startService(service);
        status.setText("JARVIS TV ATIVO • RUNPOD • DIGA 'JARVIS'.");
        status.setBackground(rounded(GREEN, 18));
        moveTaskToBack(true);
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
