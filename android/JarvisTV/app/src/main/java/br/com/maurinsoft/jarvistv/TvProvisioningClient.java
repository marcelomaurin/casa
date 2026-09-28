package br.com.maurinsoft.jarvistv;

import android.content.Context;
import android.content.SharedPreferences;
import android.provider.Settings;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.security.MessageDigest;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

/**
 * Pareamento da JARVIS TV com o CASA.
 *
 * Fluxo de confianca:
 *  1. A TV solicita pareamento SEM token.
 *  2. O site registra a TV como pending.
 *  3. O JARVIS Mobile autoriza a solicitacao.
 *  4. O site gera device_id + token permanente.
 *  5. A TV consulta o pedido, recebe a credencial uma unica vez e a persiste.
 *
 * O celular autoriza, mas nao cria nem transporta a credencial da TV.
 */
public final class TvProvisioningClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private final Context context;
    private final OkHttpClient http;

    public TvProvisioningClient(Context context) {
        this.context = context.getApplicationContext();
        this.http = new OkHttpClient.Builder()
                .connectTimeout(8, TimeUnit.SECONDS)
                .readTimeout(15, TimeUnit.SECONDS)
                .writeTimeout(15, TimeUnit.SECONDS)
                .retryOnConnectionFailure(true)
                .build();
    }

    private SharedPreferences prefs() {
        return context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
    }

    private String baseUrl() {
        String value = prefs().getString(MainActivity.KEY_URL, JarvisApiClient.DEFAULT_BASE_URL);
        if (value == null || value.trim().isEmpty()) value = JarvisApiClient.DEFAULT_BASE_URL;
        return value.trim().replaceAll("/+$", "");
    }

    /** Identidade local estavel, convertida para formato MAC apenas para o protocolo de pareamento. */
    public String pairingIdentity() {
        String androidId = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ANDROID_ID);
        if (androidId == null || androidId.trim().isEmpty()) androidId = "jarvis-tv-install";
        try {
            byte[] digest = MessageDigest.getInstance("SHA-256").digest(androidId.getBytes("UTF-8"));
            // 02 = locally administered/unicast. Nao representa o MAC fisico da TV.
            return String.format(Locale.US, "02:%02X:%02X:%02X:%02X:%02X",
                    digest[0] & 0xff, digest[1] & 0xff, digest[2] & 0xff, digest[3] & 0xff, digest[4] & 0xff);
        } catch (Exception e) {
            return "02:4A:41:52:56:49";
        }
    }

    public PairingRequest requestPairing() throws Exception {
        JSONObject body = new JSONObject()
                .put("mac", pairingIdentity())
                .put("type", "tv")
                .put("model", "JARVIS TV Android")
                .put("firmware_version", "1.0")
                .put("capabilities", new JSONArray()
                        .put("display")
                        .put("alerts")
                        .put("cameras")
                        .put("family_messages")
                        .put("commands"));
        JSONObject json = publicPost("solicitar_pareamento", body);
        PairingRequest result = new PairingRequest(
                json.getString("request_id"),
                json.optString("pairing_code"),
                json.optString("status_pareamento", "pending"));
        prefs().edit()
                .putString("pairing_request_id", result.requestId)
                .putString("pairing_code", result.pairingCode)
                .apply();
        return result;
    }

    public PairingResult pollPairing() throws Exception {
        String requestId = prefs().getString("pairing_request_id", "");
        String code = prefs().getString("pairing_code", "");
        if (requestId == null || requestId.trim().isEmpty()) {
            throw new IllegalStateException("A TV ainda nao solicitou autorizacao ao CASA");
        }
        JSONObject body = new JSONObject()
                .put("request_id", requestId)
                .put("mac", pairingIdentity());
        if (code != null && !code.isEmpty()) body.put("pairing_code", code);
        JSONObject json = publicPost("consultar_pareamento", body);
        String state = json.optString("status_pareamento", json.optString("status", "pending"));
        if ("authorized".equalsIgnoreCase(state) || ("ok".equalsIgnoreCase(json.optString("status")) && json.has("device_token"))) {
            String token = json.getString("device_token");
            String deviceId = json.getString("device_id");
            prefs().edit()
                    .putString(MainActivity.KEY_TOKEN, token)
                    .putString("device_id", deviceId)
                    .remove("pairing_request_id")
                    .remove("pairing_code")
                    .apply();
            return new PairingResult("authorized", deviceId, true);
        }
        return new PairingResult(state, json.optString("device_id"), false);
    }

    private JSONObject publicPost(String action, JSONObject body) throws IOException {
        String url = baseUrl() + "/api/v1/provision.php?acao=" + action;
        Request request = new Request.Builder()
                .url(url)
                .header("Accept", "application/json")
                .post(RequestBody.create(body.toString(), JSON))
                .build();
        try (Response response = http.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";
            if (!response.isSuccessful()) throw new IOException("HTTP " + response.code() + ": " + raw);
            try {
                return new JSONObject(raw);
            } catch (Exception e) {
                throw new IOException("Resposta de pareamento invalida", e);
            }
        }
    }

    public static final class PairingRequest {
        public final String requestId;
        public final String pairingCode;
        public final String status;
        PairingRequest(String requestId, String pairingCode, String status) {
            this.requestId = requestId;
            this.pairingCode = pairingCode;
            this.status = status;
        }
    }

    public static final class PairingResult {
        public final String status;
        public final String deviceId;
        public final boolean authorized;
        PairingResult(String status, String deviceId, boolean authorized) {
            this.status = status;
            this.deviceId = deviceId;
            this.authorized = authorized;
        }
    }
}
