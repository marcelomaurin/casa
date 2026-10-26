package br.com.maurinsoft.jarvistv;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.provider.Settings;

import androidx.core.app.NotificationCompat;
import androidx.core.app.ServiceCompat;
import androidx.core.content.ContextCompat;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Monitoramento continuo da TV junto ao CASA.
 *
 * A TV e uma tela de exibicao: o mais importante e uma emergencia (SOS da
 * familia) aparecer mesmo que a TV esteja em outro aplicativo. Por isso o
 * sinal de vida e a fila de comandos ficam neste servico em primeiro plano, e
 * nao na tela, que so funciona enquanto esta visivel.
 *
 * Em uma emergencia o servico guarda o evento e traz a tela do JARVIS para a
 * frente. O Android so permite abrir uma tela a partir de segundo plano se o
 * app tiver a permissao "Sobrepor a outros apps"; sem ela, fica uma
 * notificacao e o alerta aparece assim que a tela do JARVIS for aberta.
 */
public class TvWatchService extends Service {
    private static final String CANAL_SERVICO = "jarvis_tv_servico";
    private static final String CANAL_EMERGENCIA = "jarvis_tv_emergencia";
    private static final int ID_SERVICO = 4201;
    private static final int ID_EMERGENCIA = 4202;
    private static final long INTERVALO_MS = 10_000L;

    private HandlerThread thread;
    private Handler worker;
    private TvControlPlaneClient control;
    private volatile boolean ativo;

    /** Inicia o servico (tela aberta ou TV ligada). Pode ser chamado varias vezes. */
    public static void iniciar(Context ctx) {
        try {
            ContextCompat.startForegroundService(ctx, new Intent(ctx, TvWatchService.class));
        } catch (Throwable ignored) {
            // Alguns sistemas recusam iniciar a partir de segundo plano; a tela tenta de novo ao abrir.
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        criarCanais();
        int tipo = Build.VERSION.SDK_INT >= 34 ? ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE : 0;
        ServiceCompat.startForeground(this, ID_SERVICO, notificacaoServico("Monitorando alertas da casa"), tipo);
        control = new TvControlPlaneClient(this);
        thread = new HandlerThread("jarvis-tv-monitor");
        thread.start();
        worker = new Handler(thread.getLooper());
        ativo = true;
        worker.post(this::ciclo);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        ativo = false;
        if (worker != null) worker.removeCallbacksAndMessages(null);
        if (thread != null) thread.quitSafely();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void ciclo() {
        if (!ativo) return;
        try {
            if (control.isAuthorized()) {
                int pendentes = control.heartbeat();
                if (pendentes > 0) {
                    JSONArray lista = control.commands();
                    for (int i = 0; i < lista.length(); i++) executar(lista.getJSONObject(i));
                }
                TvBus.status(null);
            }
        } catch (Throwable t) {
            TvBus.status("Conexão com o CASA: " + (t.getMessage() == null ? t.getClass().getSimpleName() : t.getMessage()));
        }
        if (ativo) worker.postDelayed(this::ciclo, INTERVALO_MS);
    }

    private void executar(JSONObject cmd) {
        long id = cmd.optLong("id", 0);
        String nome = cmd.optString("comando", "").trim().toLowerCase();
        if (id <= 0) return;
        try {
            control.ack(id);
            control.start(id);
            JSONObject resultado = new JSONObject();
            switch (nome) {
                case "refresh":
                case "dashboard.refresh":
                case "tv.refresh":
                    TvBus.refresh();
                    resultado.put("action", TvBus.screenVisible() ? "dashboard_refreshed" : "tela_nao_visivel");
                    break;
                case "camera.next":
                case "tv.camera.next":
                    TvBus.nextCamera();
                    resultado.put("action", TvBus.screenVisible() ? "camera_next" : "tela_nao_visivel");
                    break;
                case "emergency.sos":
                case "emergency.location":
                case "emergency.cancelled":
                case "tv.emergency":
                    JSONObject payload = cmd.optJSONObject("payload");
                    if (payload == null) payload = new JSONObject();
                    resultado.put("action", emergencia(nome, payload)).put("event", nome);
                    break;
                default:
                    control.result(id, false, resultado, "Comando nao suportado pela TV: " + nome);
                    return;
            }
            control.result(id, true, resultado, null);
        } catch (Throwable t) {
            try { control.result(id, false, new JSONObject(), t.getMessage()); } catch (Throwable ignored) {}
        }
    }

    /** Guarda a emergencia, avisa a tela e, se preciso, traz a tela para a frente. */
    private String emergencia(String evento, JSONObject payload) throws Exception {
        boolean encerrada = "emergency.cancelled".equals(evento);
        getSharedPreferences(TvConfig.PREFS, MODE_PRIVATE).edit()
                .putString(TvConfig.KEY_EMERGENCY, encerrada ? "" : new JSONObject().put("event", evento).put("payload", payload).toString())
                .apply();
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (encerrada) {
            if (nm != null) nm.cancel(ID_EMERGENCIA);
            TvBus.emergency(evento, payload);
            return "emergency_cleared";
        }
        if (TvBus.screenVisible()) {
            TvBus.emergency(evento, payload);
            return "emergency_displayed";
        }
        Intent abrir = new Intent(this, MainActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
                .putExtra(MainActivity.EXTRA_EMERGENCIA, true);
        if (Settings.canDrawOverlays(this)) {
            try {
                startActivity(abrir);
                return "emergency_brought_to_front";
            } catch (Throwable ignored) {
                // cai para a notificacao
            }
        }
        if (nm != null) {
            PendingIntent pi = PendingIntent.getActivity(this, 1, abrir, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
            String msg = payload.optString("message", payload.optString("mensagem", "Emergência da família"));
            Notification n = new NotificationCompat.Builder(this, CANAL_EMERGENCIA)
                    .setSmallIcon(R.drawable.ic_jarvis_tv)
                    .setContentTitle("JARVIS • EMERGÊNCIA")
                    .setContentText(msg)
                    .setPriority(NotificationCompat.PRIORITY_MAX)
                    .setCategory(NotificationCompat.CATEGORY_ALARM)
                    .setContentIntent(pi)
                    .setFullScreenIntent(pi, true)
                    .setAutoCancel(true)
                    .build();
            nm.notify(ID_EMERGENCIA, n);
        }
        return "emergency_notified";
    }

    private void criarCanais() {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        if (nm == null) return;
        nm.createNotificationChannel(new NotificationChannel(CANAL_SERVICO, "Monitoramento da casa", NotificationManager.IMPORTANCE_MIN));
        NotificationChannel em = new NotificationChannel(CANAL_EMERGENCIA, "Emergências", NotificationManager.IMPORTANCE_HIGH);
        em.setDescription("SOS e localização enviados pela família");
        nm.createNotificationChannel(em);
    }

    private Notification notificacaoServico(String texto) {
        PendingIntent pi = PendingIntent.getActivity(this, 0, new Intent(this, MainActivity.class), PendingIntent.FLAG_IMMUTABLE);
        return new NotificationCompat.Builder(this, CANAL_SERVICO)
                .setSmallIcon(R.drawable.ic_jarvis_tv)
                .setContentTitle("JARVIS TV")
                .setContentText(texto)
                .setOngoing(true)
                .setPriority(NotificationCompat.PRIORITY_MIN)
                .setContentIntent(pi)
                .build();
    }
}
