package br.com.maurinsoft.jarvistv;

import android.app.Activity;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    public static final String PREFS = "jarvis_tv";
    public static final String KEY_URL = "api_url";
    public static final String KEY_TOKEN = "api_token";

    private static final int CREAM = Color.rgb(244, 238, 222);
    private static final int PANEL = Color.rgb(255, 250, 240);
    private static final int INK = Color.rgb(34, 31, 34);
    private static final int ORANGE = Color.rgb(229, 138, 85);
    private static final int SALMON = Color.rgb(217, 111, 120);
    private static final int LAVENDER = Color.rgb(155, 131, 173);
    private static final int BLUE = Color.rgb(111, 140, 168);
    private static final int GOLD = Color.rgb(211, 160, 77);
    private static final int GREEN = Color.rgb(111, 152, 126);

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final List<CameraItem> cameras = new ArrayList<>();

    private EditText urlEdit;
    private EditText tokenEdit;
    private TextView status;
    private TextView alertText;
    private TextView cameraTitle;
    private TextView familyText;
    private ImageView cameraImage;
    private int selectedCamera = 0;
    private boolean active = false;

    private static class CameraItem {
        final String name;
        final String location;
        final String captureUrl;
        final String status;
        CameraItem(String name, String location, String captureUrl, String status) {
            this.name = name;
            this.location = location;
            this.captureUrl = captureUrl;
            this.status = status;
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        buildUi();
    }

    @Override
    protected void onResume() {
        super.onResume();
        active = true;
        scheduleRefresh(100);
        scheduleCamera(500);
    }

    @Override
    protected void onPause() {
        active = false;
        handler.removeCallbacksAndMessages(null);
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        active = false;
        io.shutdownNow();
        super.onDestroy();
    }

    private void buildUi() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(CREAM);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(36), dp(24), dp(36), dp(32));
        root.setBackgroundColor(CREAM);
        scroll.addView(root);

        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(dp(18), dp(12), dp(18), dp(12));
        header.setBackground(rounded(ORANGE, 28));
        TextView title = text("JARVIS TV", 32, INK, true);
        header.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        header.addView(chip("DISPLAY CASA", LAVENDER));
        root.addView(header, fullWidth(dp(8)));

        TextView subtitle = text("ALERTAS • CÂMERAS • STATUS • FAMÍLIA", 15, INK, true);
        subtitle.setPadding(dp(16), dp(8), dp(16), dp(8));
        subtitle.setBackground(rounded(SALMON, 18));
        root.addView(subtitle, fullWidth(dp(12)));

        LinearLayout config = panel();
        config.addView(sectionTitle("CONEXÃO CASA"));
        urlEdit = field("URL CASA", prefs.getString(KEY_URL, "https://casa.maurinsoft.com.br"), false);
        tokenEdit = field("Token individual da TV", prefs.getString(KEY_TOKEN, ""), true);
        config.addView(urlEdit, fullWidth(dp(6)));
        config.addView(tokenEdit, fullWidth(dp(8)));
        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        Button save = button("SALVAR", ORANGE);
        save.setOnClickListener(v -> { saveConfig(); refreshDashboard(); });
        Button refresh = button("ATUALIZAR", GREEN);
        refresh.setOnClickListener(v -> refreshDashboard());
        actions.addView(save, actionWeight(1f, dp(8)));
        actions.addView(refresh, actionWeight(1f, 0));
        config.addView(actions);
        root.addView(config, fullWidth(dp(12)));

        status = text("TV em modo de exibição. Não usa conversa nem microfone.", 15, INK, true);
        status.setPadding(dp(14), dp(10), dp(14), dp(10));
        status.setBackground(rounded(BLUE, 18));
        root.addView(status, fullWidth(dp(12)));

        LinearLayout grid = new LinearLayout(this);
        grid.setOrientation(LinearLayout.HORIZONTAL);

        LinearLayout left = panel();
        left.addView(sectionTitle("CÂMERA"));
        cameraTitle = text("Nenhuma câmera", 18, INK, true);
        left.addView(cameraTitle, fullWidth(dp(8)));
        cameraImage = new ImageView(this);
        cameraImage.setBackgroundColor(Color.BLACK);
        cameraImage.setScaleType(ImageView.ScaleType.FIT_CENTER);
        left.addView(cameraImage, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(360)));
        Button next = button("PRÓXIMA CÂMERA", GOLD);
        next.setOnClickListener(v -> nextCamera());
        left.addView(next, fullWidth(0));

        LinearLayout right = panel();
        right.addView(sectionTitle("ALERTAS"));
        alertText = text("Sem alertas carregados.", 18, INK, false);
        right.addView(alertText, fullWidth(dp(14)));
        right.addView(sectionTitle("CANAL FAMÍLIA"));
        familyText = text("Sem mensagens.", 17, INK, false);
        right.addView(familyText, fullWidth(0));

        grid.addView(left, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1.25f));
        LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 0.75f);
        rp.setMargins(dp(12), 0, 0, 0);
        grid.addView(right, rp);
        root.addView(grid, fullWidth(dp(8)));

        TextView footer = text("CASA DISTRIBUÍDA • TV PASSIVA • SEM CONVERSA", 12, Color.rgb(92,80,92), true);
        footer.setGravity(Gravity.END);
        root.addView(footer, fullWidth(0));
        setContentView(scroll);
    }

    private void saveConfig() {
        String base = urlEdit.getText().toString().trim().replaceAll("/+$", "");
        String token = tokenEdit.getText().toString().trim();
        if (base.isEmpty()) base = "https://casa.maurinsoft.com.br";
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putString(KEY_URL, base).putString(KEY_TOKEN, token).apply();
        status.setText(token.isEmpty() ? "Informe o token individual da TV." : "Configuração salva.");
    }

    private void refreshDashboard() {
        final String base = getSharedPreferences(PREFS, MODE_PRIVATE).getString(KEY_URL, "https://casa.maurinsoft.com.br");
        final String token = getSharedPreferences(PREFS, MODE_PRIVATE).getString(KEY_TOKEN, "");
        if (token == null || token.trim().isEmpty()) { status.setText("Configure o token individual da TV."); return; }
        status.setText("Atualizando CASA...");
        io.execute(() -> {
            try {
                HttpURLConnection c = (HttpURLConnection)new URL(base.replaceAll("/+$", "") + "/api/v1/tv.php?acao=dashboard").openConnection();
                c.setConnectTimeout(5000); c.setReadTimeout(8000);
                c.setRequestProperty("Authorization", "Bearer " + token);
                c.setRequestProperty("X-Device-Token", token);
                c.setRequestProperty("Accept", "application/json");
                int code = c.getResponseCode();
                InputStream in = code >= 200 && code < 300 ? c.getInputStream() : c.getErrorStream();
                String raw = readAll(in);
                c.disconnect();
                if (code < 200 || code >= 300) throw new IllegalStateException("HTTP " + code + " " + raw);
                JSONObject j = new JSONObject(raw);
                JSONArray cams = j.optJSONArray("cameras");
                JSONArray alerts = j.optJSONArray("alerts");
                JSONArray family = j.optJSONArray("family_messages");
                List<CameraItem> newCameras = new ArrayList<>();
                if (cams != null) for (int i=0;i<cams.length();i++) {
                    JSONObject x = cams.optJSONObject(i); if (x == null) continue;
                    newCameras.add(new CameraItem(x.optString("nome","Câmera"), x.optString("localizacao",""), x.optString("capture_url",""), x.optString("status","offline")));
                }
                String alertsText = formatAlerts(alerts);
                String familyTextValue = formatFamily(family);
                runOnUiThread(() -> {
                    cameras.clear(); cameras.addAll(newCameras);
                    if (selectedCamera >= cameras.size()) selectedCamera = 0;
                    alertText.setText(alertsText);
                    familyText.setText(familyTextValue);
                    updateCameraTitle();
                    status.setText("CASA ONLINE • " + cameras.size() + " câmera(s)");
                });
            } catch (Throwable t) {
                runOnUiThread(() -> status.setText("Falha CASA: " + (t.getMessage() == null ? t.getClass().getSimpleName() : t.getMessage())));
            }
        });
    }

    private void refreshCameraSnapshot() {
        if (cameras.isEmpty()) return;
        CameraItem cam = cameras.get(Math.max(0, Math.min(selectedCamera, cameras.size()-1)));
        if (cam.captureUrl == null || cam.captureUrl.isEmpty() || !"online".equalsIgnoreCase(cam.status)) return;
        io.execute(() -> {
            HttpURLConnection c = null;
            try {
                c = (HttpURLConnection)new URL(cam.captureUrl).openConnection();
                c.setConnectTimeout(2500); c.setReadTimeout(4000); c.setUseCaches(false);
                Bitmap bitmap = BitmapFactory.decodeStream(c.getInputStream());
                if (bitmap != null) runOnUiThread(() -> cameraImage.setImageBitmap(bitmap));
            } catch (Throwable ignored) {
            } finally { if (c != null) c.disconnect(); }
        });
    }

    private void nextCamera() {
        if (cameras.isEmpty()) return;
        selectedCamera = (selectedCamera + 1) % cameras.size();
        cameraImage.setImageDrawable(null);
        updateCameraTitle();
        refreshCameraSnapshot();
    }

    private void updateCameraTitle() {
        if (cameras.isEmpty()) { cameraTitle.setText("Nenhuma câmera disponível"); return; }
        CameraItem c = cameras.get(selectedCamera);
        cameraTitle.setText(c.name + (c.location.isEmpty() ? "" : " • " + c.location) + " • " + c.status.toUpperCase());
    }

    private String formatAlerts(JSONArray arr) {
        if (arr == null || arr.length() == 0) return "Nenhum alerta pendente.";
        StringBuilder b = new StringBuilder();
        int max = Math.min(arr.length(), 8);
        for (int i=0;i<max;i++) {
            JSONObject x = arr.optJSONObject(i); if (x == null) continue;
            String sev = x.optString("severidade","info").toUpperCase();
            b.append("[").append(sev).append("] ").append(x.optString("mensagem","Alerta")).append("\n\n");
        }
        return b.toString().trim();
    }

    private String formatFamily(JSONArray arr) {
        if (arr == null || arr.length() == 0) return "Nenhuma mensagem recente.";
        StringBuilder b = new StringBuilder();
        int max = Math.min(arr.length(), 6);
        for (int i=0;i<max;i++) {
            JSONObject x = arr.optJSONObject(i); if (x == null) continue;
            b.append(x.optString("remetente","CASA")).append(": ").append(x.optString("mensagem","[").concat(x.optString("tipo","")).concat("]")).append("\n\n");
        }
        return b.toString().trim();
    }

    private String readAll(InputStream in) throws Exception {
        if (in == null) return "";
        java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream();
        byte[] buf = new byte[4096]; int n;
        while ((n = in.read(buf)) > 0) out.write(buf,0,n);
        in.close();
        return out.toString("UTF-8");
    }

    private void scheduleRefresh(long delay) {
        handler.postDelayed(new Runnable() {
            @Override public void run() {
                if (!active) return;
                refreshDashboard();
                handler.postDelayed(this, 5000);
            }
        }, delay);
    }

    private void scheduleCamera(long delay) {
        handler.postDelayed(new Runnable() {
            @Override public void run() {
                if (!active) return;
                refreshCameraSnapshot();
                handler.postDelayed(this, 2000);
            }
        }, delay);
    }

    private LinearLayout panel() {
        LinearLayout p = new LinearLayout(this);
        p.setOrientation(LinearLayout.VERTICAL);
        p.setPadding(dp(20),dp(18),dp(20),dp(18));
        GradientDrawable bg = rounded(PANEL,22); bg.setStroke(dp(2),LAVENDER); p.setBackground(bg);
        return p;
    }
    private TextView sectionTitle(String v) { TextView t=text(v,15,INK,true); t.setPadding(0,dp(8),0,dp(6)); return t; }
    private EditText field(String hint,String value,boolean secret) {
        EditText e=new EditText(this); e.setHint(hint); e.setText(value==null?"":value); e.setSingleLine(true); e.setTextColor(INK); e.setTextSize(16); e.setPadding(dp(12),dp(9),dp(12),dp(9));
        if(secret)e.setInputType(InputType.TYPE_CLASS_TEXT|InputType.TYPE_TEXT_VARIATION_PASSWORD);
        GradientDrawable bg=rounded(Color.WHITE,14); bg.setStroke(dp(2),BLUE); e.setBackground(bg); return e;
    }
    private Button button(String label,int color) { Button b=new Button(this); b.setText(label); b.setTextColor(INK); b.setTypeface(Typeface.DEFAULT_BOLD); b.setBackground(rounded(color,20)); return b; }
    private TextView chip(String label,int color) { TextView t=text(label,13,INK,true); t.setPadding(dp(14),dp(7),dp(14),dp(7)); t.setGravity(Gravity.CENTER); t.setBackground(rounded(color,18)); return t; }
    private TextView text(String v,int size,int color,boolean bold) { TextView t=new TextView(this); t.setText(v); t.setTextSize(size); t.setTextColor(color); if(bold)t.setTypeface(Typeface.DEFAULT_BOLD); return t; }
    private GradientDrawable rounded(int color,int radius) { GradientDrawable g=new GradientDrawable(); g.setColor(color); g.setCornerRadius(dp(radius)); return g; }
    private LinearLayout.LayoutParams fullWidth(int bottom) { LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,ViewGroup.LayoutParams.WRAP_CONTENT); p.setMargins(0,0,0,bottom); return p; }
    private LinearLayout.LayoutParams actionWeight(float weight,int right) { LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(0,dp(54),weight); p.setMargins(0,0,right,0); return p; }
    private int dp(int v) { return Math.round(v*getResources().getDisplayMetrics().density); }
}
