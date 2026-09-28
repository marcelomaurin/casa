package br.com.maurinsoft.jarvistv;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.util.concurrent.TimeUnit;

import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

/** CASA device.php client for the TV after pairing. */
public final class TvControlPlaneClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private final Context context;
    private final OkHttpClient http;

    public TvControlPlaneClient(Context context) {
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
        String v = prefs().getString(MainActivity.KEY_URL, JarvisApiClient.DEFAULT_BASE_URL);
        if (v == null || v.trim().isEmpty()) v = JarvisApiClient.DEFAULT_BASE_URL;
        return v.trim().replaceAll("/+$", "");
    }

    public boolean isAuthorized() {
        return !token().isEmpty() && !deviceId().isEmpty();
    }

    public String deviceId() {
        String v = prefs().getString("device_id", "");
        return v == null ? "" : v.trim();
    }

    private String token() {
        String v = prefs().getString(MainActivity.KEY_TOKEN, "");
        return v == null ? "" : v.trim();
    }

    public int heartbeat() throws Exception {
        JSONObject body = new JSONObject()
                .put("device_id", deviceId())
                .put("transport", "wifi")
                .put("health", "ok")
                .put("protocol_version", "CASA/1.0")
                .put("manufacturer", "Maurinsoft")
                .put("model", "JARVIS TV Android")
                .put("capabilities", new JSONArray()
                        .put("display").put("alerts").put("cameras")
                        .put("family_messages").put("commands"));
        return post("heartbeat", body).optInt("commands_pending", 0);
    }

    public JSONArray commands() throws Exception {
        JSONObject json = request("commands", null, false);
        JSONArray a = json.optJSONArray("commands");
        return a == null ? new JSONArray() : a;
    }

    public void ack(long id) throws Exception {
        post("command_ack", commandBody(id));
    }

    public void start(long id) throws Exception {
        post("command_start", commandBody(id));
    }

    public void result(long id, boolean success, JSONObject result, String error) throws Exception {
        JSONObject body = commandBody(id)
                .put("status", success ? "success" : "error")
                .put("result", result == null ? new JSONObject() : result);
        if (!success && error != null) body.put("error", error);
        post("command_result", body);
    }

    private JSONObject commandBody(long id) throws Exception {
        return new JSONObject().put("device_id", deviceId()).put("id", id);
    }

    private JSONObject post(String action, JSONObject body) throws Exception {
        return request(action, body, true);
    }

    private JSONObject request(String action, JSONObject body, boolean post) throws Exception {
        if (!isAuthorized()) throw new IllegalStateException("TV ainda nao autorizada no CASA");
        String url = baseUrl() + "/api/v1/device.php?acao=" + action;
        if (!post) url += "&device_id=" + java.net.URLEncoder.encode(deviceId(), "UTF-8");
        Request.Builder b = new Request.Builder().url(url)
                .header("Authorization", "Bearer " + token())
                .header("X-Device-Token", token())
                .header("Accept", "application/json");
        if (post) b.post(RequestBody.create(body.toString(), JSON)); else b.get();
        try (Response response = http.newCall(b.build()).execute()) {
            String raw = response.body() != null ? response.body().string() : "";
            if (!response.isSuccessful()) throw new IOException("HTTP " + response.code() + ": " + raw);
            return new JSONObject(raw);
        }
    }
}