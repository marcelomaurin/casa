package br.com.maurinsoft.jarvistv;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.provider.Settings;
import android.view.WindowManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
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

/**
 * Tela de exibicao da casa: cameras, alertas, mensagens da familia e emergencias.
 * Sem conversa e com chave somente de leitura. O sinal de vida e os comandos do
 * CASA ficam no TvWatchService, que funciona tambem com a TV em outro app.
 */
public class MainActivity extends Activity implements TvBus.Listener {
    public static final String PREFS=TvConfig.PREFS, KEY_URL=TvConfig.KEY_URL, KEY_TOKEN=TvConfig.KEY_TOKEN;
    public static final String EXTRA_EMERGENCIA="emergencia";
    private static final int CREAM=Color.rgb(244,238,222), PANEL=Color.rgb(255,250,240), INK=Color.rgb(34,31,34), ORANGE=Color.rgb(229,138,85), SALMON=Color.rgb(217,111,120), LAVENDER=Color.rgb(155,131,173), BLUE=Color.rgb(111,140,168), GOLD=Color.rgb(211,160,77), GREEN=Color.rgb(111,152,126);
    private final Handler handler=new Handler(Looper.getMainLooper());
    private final ExecutorService io=Executors.newSingleThreadExecutor();
    private final List<CameraItem> cameras=new ArrayList<>();
    private EditText urlEdit; private TextView status,alertText,cameraTitle,familyText,pairingText; private Button overlayButton; private LinearLayout alertPanel; private ImageView cameraImage;
    private int selectedCamera=0; private boolean active=false, emergencyActive=false;
    private TvProvisioningClient provisioning; private TvControlPlaneClient control;

    private static class CameraItem { final String name,location,captureUrl,status; CameraItem(String n,String l,String u,String s){name=n;location=l;captureUrl=u;status=s;} }

    @Override protected void onCreate(Bundle b){
        super.onCreate(b);
        // Tela de exibicao: nao deixa a TV apagar nem entrar no descanso de tela.
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        provisioning=new TvProvisioningClient(this); control=new TvControlPlaneClient(this); buildUi();
        TvWatchService.iniciar(this);
        // Android 13+: notificacao de emergencia precisa de permissao em tempo de execucao.
        if(android.os.Build.VERSION.SDK_INT>=33 && checkSelfPermission("android.permission.POST_NOTIFICATIONS")!=android.content.pm.PackageManager.PERMISSION_GRANTED)
            requestPermissions(new String[]{"android.permission.POST_NOTIFICATIONS"},41);
    }
    @Override protected void onNewIntent(Intent intent){ super.onNewIntent(intent); setIntent(intent); }
    @Override protected void onResume(){
        super.onResume(); active=true; TvBus.register(this); updateOverlayButton();
        showStoredEmergency();
        scheduleRefresh(100); scheduleCamera(500); schedulePairing(250);
    }
    @Override protected void onPause(){ active=false; TvBus.unregister(this); handler.removeCallbacksAndMessages(null); super.onPause(); }

    // --- TvBus: comandos repassados pelo servico ---
    @Override public void onRefresh(){ refreshDashboard(); }
    @Override public void onNextCamera(){ nextCamera(); }
    @Override public void onEmergency(String event, JSONObject payload){ showEmergencyCommand(event,payload); }
    @Override public void onServiceStatus(String text){ if(text!=null && !emergencyActive) status.setText(text); }

    /** Emergencia guardada pelo servico enquanto a tela estava fechada. */
    private void showStoredEmergency(){
        String raw=getSharedPreferences(PREFS,MODE_PRIVATE).getString(TvConfig.KEY_EMERGENCY,"");
        if(raw==null||raw.isEmpty()) return;
        try { JSONObject j=new JSONObject(raw); showEmergencyCommand(j.optString("event","emergency.sos"), j.optJSONObject("payload")==null?new JSONObject():j.optJSONObject("payload")); } catch(Exception ignored){}
    }

    /** Sem "Sobrepor a outros apps", o Android nao deixa o servico trazer esta tela para a frente numa emergencia. */
    private void updateOverlayButton(){ if(overlayButton!=null) overlayButton.setVisibility(Settings.canDrawOverlays(this)?android.view.View.GONE:android.view.View.VISIBLE); }
    private void requestOverlayPermission(){
        try { startActivity(new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:"+getPackageName()))); }
        catch(Throwable t){ status.setText("Esta TV não abre a tela de permissão. Pelo computador: adb shell appops set "+getPackageName()+" SYSTEM_ALERT_WINDOW allow"); }
    }
    @Override protected void onDestroy(){ active=false; io.shutdownNow(); super.onDestroy(); }

    private void buildUi(){
        SharedPreferences prefs=getSharedPreferences(PREFS,MODE_PRIVATE); ScrollView scroll=new ScrollView(this); scroll.setBackgroundColor(CREAM);
        LinearLayout root=new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setPadding(dp(36),dp(24),dp(36),dp(32)); root.setBackgroundColor(CREAM); scroll.addView(root);
        LinearLayout header=new LinearLayout(this); header.setOrientation(LinearLayout.HORIZONTAL); header.setGravity(Gravity.CENTER_VERTICAL); header.setPadding(dp(18),dp(12),dp(18),dp(12)); header.setBackground(rounded(ORANGE,28));
        header.addView(text("JARVIS TV",32,INK,true),new LinearLayout.LayoutParams(0,ViewGroup.LayoutParams.WRAP_CONTENT,1f)); header.addView(chip("DISPLAY CASA",LAVENDER)); root.addView(header,fullWidth(dp(8)));
        TextView subtitle=text("ALERTAS • CÂMERAS • STATUS • FAMÍLIA",15,INK,true); subtitle.setPadding(dp(16),dp(8),dp(16),dp(8)); subtitle.setBackground(rounded(SALMON,18)); root.addView(subtitle,fullWidth(dp(12)));

        LinearLayout config=panel(); config.addView(sectionTitle("CONEXÃO CASA"));
        urlEdit=field("URL CASA",prefs.getString(KEY_URL,TvConfig.DEFAULT_BASE_URL)); config.addView(urlEdit,fullWidth(dp(6)));
        pairingText=text(control.isAuthorized()?"TV autorizada • "+control.deviceId():"TV ainda não autorizada.",15,INK,true); config.addView(pairingText,fullWidth(dp(8)));
        LinearLayout actions=new LinearLayout(this); actions.setOrientation(LinearLayout.HORIZONTAL);
        Button pair=button(control.isAuthorized()?"REAUTORIZAR":"AUTORIZAR TV",ORANGE); pair.setOnClickListener(v->requestPairing());
        Button refresh=button("ATUALIZAR",GREEN); refresh.setOnClickListener(v->{saveBaseUrl(); refreshDashboard();}); actions.addView(pair,actionWeight(1f,dp(8))); actions.addView(refresh,actionWeight(1f,0)); config.addView(actions);
        overlayButton=button("PERMITIR ALERTA SOBRE OUTROS APPS",SALMON); overlayButton.setOnClickListener(v->requestOverlayPermission());
        LinearLayout.LayoutParams op=new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,dp(54)); op.setMargins(0,dp(8),0,0); config.addView(overlayButton,op);
        root.addView(config,fullWidth(dp(12)));

        status=text("TV em modo de exibição.",15,INK,true); status.setPadding(dp(14),dp(10),dp(14),dp(10)); status.setBackground(rounded(BLUE,18)); root.addView(status,fullWidth(dp(12)));
        LinearLayout grid=new LinearLayout(this); grid.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout left=panel(); left.addView(sectionTitle("CÂMERA")); cameraTitle=text("Nenhuma câmera",18,INK,true); left.addView(cameraTitle,fullWidth(dp(8))); cameraImage=new ImageView(this); cameraImage.setBackgroundColor(Color.BLACK); cameraImage.setScaleType(ImageView.ScaleType.FIT_CENTER); left.addView(cameraImage,new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,dp(360))); Button next=button("PRÓXIMA CÂMERA",GOLD); next.setOnClickListener(v->nextCamera()); left.addView(next,fullWidth(0));
        LinearLayout right=panel(); alertPanel=right; right.addView(sectionTitle("ALERTAS")); alertText=text("Sem alertas carregados.",18,INK,false); right.addView(alertText,fullWidth(dp(14))); right.addView(sectionTitle("CANAL FAMÍLIA")); familyText=text("Sem mensagens.",17,INK,false); right.addView(familyText,fullWidth(0));
        grid.addView(left,new LinearLayout.LayoutParams(0,ViewGroup.LayoutParams.WRAP_CONTENT,1.25f)); LinearLayout.LayoutParams rp=new LinearLayout.LayoutParams(0,ViewGroup.LayoutParams.WRAP_CONTENT,.75f); rp.setMargins(dp(12),0,0,0); grid.addView(right,rp); root.addView(grid,fullWidth(dp(8)));
        TextView footer=text("TELA DE EXIBIÇÃO DA CASA • SOMENTE LEITURA",12,Color.rgb(92,80,92),true); footer.setGravity(Gravity.END); root.addView(footer,fullWidth(0)); setContentView(scroll);
    }

    private void saveBaseUrl(){ String base=urlEdit.getText().toString().trim().replaceAll("/+$",""); if(base.isEmpty()) base=TvConfig.DEFAULT_BASE_URL; getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(KEY_URL,base).apply(); }

    private void requestPairing(){ saveBaseUrl(); status.setText("Solicitando autorização ao CASA..."); io.execute(()->{ try { TvProvisioningClient.PairingRequest r=provisioning.requestPairing(); runOnUiThread(()->{ pairingText.setText("Pendente • código "+r.pairingCode+" • autorize no JARVIS Mobile"); status.setText("Aguardando autorização do celular."); }); } catch(Throwable t){ showError("Pareamento",t); } }); }

    private void pollPairing(){ if(control.isAuthorized()) return; SharedPreferences p=getSharedPreferences(PREFS,MODE_PRIVATE); if(p.getString("pairing_request_id","").isEmpty()) return; try { TvProvisioningClient.PairingResult r=provisioning.pollPairing(); if(r.authorized) runOnUiThread(()->{ pairingText.setText("TV autorizada • "+r.deviceId); status.setText("Autorização concluída. CASA conectado."); refreshDashboard(); }); } catch(Throwable t){ showError("Autorização",t); } }

    private void showEmergencyCommand(String name,JSONObject payload){
        String message=payload.optString("message",payload.optString("mensagem",""));
        JSONObject data=payload.optJSONObject("data");
        if(data==null) data=payload;
        String session=data.optString("session_id","");
        if(name.equals("emergency.cancelled")){
            alertText.setText("[EMERGÊNCIA ENCERRADA]"+(message.isEmpty()?"":" "+message));
            status.setText("Emergência encerrada"+(session.isEmpty()?"":" • "+session));
            emergencyActive=false; status.setBackground(rounded(BLUE,18)); status.setTextColor(INK);
            GradientDrawable bg=rounded(PANEL,22); bg.setStroke(dp(2),LAVENDER); alertPanel.setBackground(bg);
            return;
        }
        StringBuilder text=new StringBuilder(name.equals("emergency.location")?"[EMERGÊNCIA • LOCALIZAÇÃO]":"[EMERGÊNCIA • SOS]");
        if(!message.isEmpty()) text.append("\n").append(message);
        JSONObject loc=data.optJSONObject("location");
        if(loc!=null && loc.has("lat") && loc.has("lon")) text.append("\nGPS: ").append(loc.optDouble("lat")).append(", ").append(loc.optDouble("lon"));
        if(!session.isEmpty()) text.append("\nSessão: ").append(session);
        alertText.setText(text.toString());
        emergencyActive=true; status.setText("EMERGÊNCIA ATIVA");
        status.setBackground(rounded(Color.rgb(198,40,40),18)); status.setTextColor(Color.WHITE);
        GradientDrawable bg=rounded(Color.rgb(255,228,228),22); bg.setStroke(dp(4),Color.rgb(198,40,40)); alertPanel.setBackground(bg);
    }

    private void refreshDashboard(){
        final String base=getSharedPreferences(PREFS,MODE_PRIVATE).getString(KEY_URL,TvConfig.DEFAULT_BASE_URL); final String token=getSharedPreferences(PREFS,MODE_PRIVATE).getString(KEY_TOKEN,"");
        if(token==null||token.trim().isEmpty()){ status.setText("Autorize esta TV no JARVIS Mobile."); return; }
        io.execute(()->{ try { HttpURLConnection c=(HttpURLConnection)new URL(base.replaceAll("/+$","")+"/api/v1/tv.php?acao=dashboard").openConnection(); c.setConnectTimeout(5000); c.setReadTimeout(8000); c.setRequestProperty("Authorization","Bearer "+token); c.setRequestProperty("X-Device-Token",token); c.setRequestProperty("Accept","application/json"); int code=c.getResponseCode(); InputStream in=code>=200&&code<300?c.getInputStream():c.getErrorStream(); String raw=readAll(in); c.disconnect(); if(code<200||code>=300) throw new IllegalStateException("HTTP "+code+" "+raw); JSONObject j=new JSONObject(raw); JSONArray cams=j.optJSONArray("cameras"), alerts=j.optJSONArray("alerts"), family=j.optJSONArray("family_messages"); List<CameraItem> nc=new ArrayList<>(); if(cams!=null) for(int i=0;i<cams.length();i++){JSONObject x=cams.optJSONObject(i); if(x!=null) nc.add(new CameraItem(x.optString("nome","Câmera"),x.optString("localizacao",""),x.optString("capture_url",""),x.optString("status","offline")));} String at=formatAlerts(alerts), ft=formatFamily(family); runOnUiThread(()->{cameras.clear();cameras.addAll(nc);if(selectedCamera>=cameras.size())selectedCamera=0;if(!emergencyActive)alertText.setText(at);familyText.setText(ft);updateCameraTitle();if(!emergencyActive)status.setText("CASA ONLINE • "+cameras.size()+" câmera(s)");}); } catch(Throwable t){showError("CASA",t);} });
    }

    private void refreshCameraSnapshot(){ if(cameras.isEmpty())return; CameraItem cam=cameras.get(Math.max(0,Math.min(selectedCamera,cameras.size()-1))); if(cam.captureUrl==null||cam.captureUrl.isEmpty()||!"online".equalsIgnoreCase(cam.status))return; io.execute(()->{HttpURLConnection c=null;try{c=(HttpURLConnection)new URL(cam.captureUrl).openConnection();c.setConnectTimeout(2500);c.setReadTimeout(4000);c.setUseCaches(false);Bitmap b=BitmapFactory.decodeStream(c.getInputStream());if(b!=null)runOnUiThread(()->cameraImage.setImageBitmap(b));}catch(Throwable ignored){}finally{if(c!=null)c.disconnect();}}); }
    private void nextCamera(){if(cameras.isEmpty())return;selectedCamera=(selectedCamera+1)%cameras.size();cameraImage.setImageDrawable(null);updateCameraTitle();refreshCameraSnapshot();}
    private void updateCameraTitle(){if(cameras.isEmpty()){cameraTitle.setText("Nenhuma câmera disponível");return;}CameraItem c=cameras.get(selectedCamera);cameraTitle.setText(c.name+(c.location.isEmpty()?"":" • "+c.location)+" • "+c.status.toUpperCase());}
    private String formatAlerts(JSONArray a){if(a==null||a.length()==0)return "Nenhum alerta pendente.";StringBuilder b=new StringBuilder();for(int i=0;i<Math.min(a.length(),8);i++){JSONObject x=a.optJSONObject(i);if(x!=null)b.append("[").append(x.optString("severidade","info").toUpperCase()).append("] ").append(x.optString("mensagem","Alerta")).append("\n\n");}return b.toString().trim();}
    private String formatFamily(JSONArray a){if(a==null||a.length()==0)return "Nenhuma mensagem recente.";StringBuilder b=new StringBuilder();for(int i=0;i<Math.min(a.length(),6);i++){JSONObject x=a.optJSONObject(i);if(x!=null){String msg=x.optString("mensagem","").trim(); if(msg.isEmpty()) msg="["+x.optString("tipo","mensagem")+"]"; b.append(x.optString("remetente","CASA")).append(": ").append(msg).append("\n\n");}}return b.toString().trim();}
    private String readAll(InputStream in)throws Exception{if(in==null)return"";java.io.ByteArrayOutputStream out=new java.io.ByteArrayOutputStream();byte[]buf=new byte[4096];int n;while((n=in.read(buf))>0)out.write(buf,0,n);in.close();return out.toString("UTF-8");}
    private void showError(String where,Throwable t){runOnUiThread(()->{ if(!emergencyActive) status.setText(where+": "+(t.getMessage()==null?t.getClass().getSimpleName():t.getMessage())); });}
    private void scheduleRefresh(long d){handler.postDelayed(new Runnable(){public void run(){if(!active)return;refreshDashboard();handler.postDelayed(this,5000);}},d);}
    private void scheduleCamera(long d){handler.postDelayed(new Runnable(){public void run(){if(!active)return;refreshCameraSnapshot();handler.postDelayed(this,2000);}},d);}
    private void schedulePairing(long d){handler.postDelayed(new Runnable(){public void run(){if(!active)return;if(!control.isAuthorized())io.execute(MainActivity.this::pollPairing);handler.postDelayed(this,5000);}},d);}
    private LinearLayout panel(){LinearLayout p=new LinearLayout(this);p.setOrientation(LinearLayout.VERTICAL);p.setPadding(dp(20),dp(18),dp(20),dp(18));GradientDrawable bg=rounded(PANEL,22);bg.setStroke(dp(2),LAVENDER);p.setBackground(bg);return p;}
    private TextView sectionTitle(String v){TextView t=text(v,15,INK,true);t.setPadding(0,dp(8),0,dp(6));return t;}
    private EditText field(String hint,String value){EditText e=new EditText(this);e.setHint(hint);e.setText(value==null?"":value);e.setSingleLine(true);e.setTextColor(INK);e.setTextSize(16);e.setPadding(dp(12),dp(9),dp(12),dp(9));GradientDrawable bg=rounded(Color.WHITE,14);bg.setStroke(dp(2),BLUE);e.setBackground(bg);return e;}
    private Button button(String label,int color){Button b=new Button(this);b.setText(label);b.setTextColor(INK);b.setTypeface(Typeface.DEFAULT_BOLD);b.setBackground(rounded(color,20));return b;}
    private TextView chip(String label,int color){TextView t=text(label,13,INK,true);t.setPadding(dp(14),dp(7),dp(14),dp(7));t.setGravity(Gravity.CENTER);t.setBackground(rounded(color,18));return t;}
    private TextView text(String v,int size,int color,boolean bold){TextView t=new TextView(this);t.setText(v);t.setTextSize(size);t.setTextColor(color);if(bold)t.setTypeface(Typeface.DEFAULT_BOLD);return t;}
    private GradientDrawable rounded(int color,int radius){GradientDrawable g=new GradientDrawable();g.setColor(color);g.setCornerRadius(dp(radius));return g;}
    private LinearLayout.LayoutParams fullWidth(int bottom){LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,ViewGroup.LayoutParams.WRAP_CONTENT);p.setMargins(0,0,0,bottom);return p;}
    private LinearLayout.LayoutParams actionWeight(float w,int r){LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(0,dp(54),w);p.setMargins(0,0,r,0);return p;}
    private int dp(int v){return Math.round(v*getResources().getDisplayMetrics().density);}
}
