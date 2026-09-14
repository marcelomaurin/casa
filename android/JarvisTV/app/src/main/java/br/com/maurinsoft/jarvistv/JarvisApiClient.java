package br.com.maurinsoft.jarvistv;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONObject;

import java.io.IOException;
import java.util.concurrent.TimeUnit;

import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public class JarvisApiClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");
    private static final String DEFAULT_BASE_URL = "https://maurinsoft.com.br/casa";
    private final Context context;
    private final OkHttpClient client;

    public JarvisApiClient(Context context) {
        this.context = context.getApplicationContext();
        this.client = new OkHttpClient.Builder()
                .connectTimeout(8, TimeUnit.SECONDS)
                .readTimeout(45, TimeUnit.SECONDS)
                .writeTimeout(20, TimeUnit.SECONDS)
                .retryOnConnectionFailure(true)
                .build();
    }

    private SharedPreferences prefs() {
        return context.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
    }

    private String baseUrl() {
        String value = prefs().getString(MainActivity.KEY_URL, DEFAULT_BASE_URL);
        if (value == null || value.trim().isEmpty()) value = DEFAULT_BASE_URL;
        value = value.trim().replaceAll("/+$", "");
        if (value.endsWith("/api/v1")) return value.substring(0, value.length() - 7);
        return value;
    }

    private String token() {
        String value = prefs().getString(MainActivity.KEY_TOKEN, "");
        return value == null ? "" : value.trim();
    }

    public boolean isConfigured() {
        return baseUrl().startsWith("https://") && !token().isEmpty();
    }

    private Request.Builder requestBuilder(String path) {
        if (!isConfigured()) throw new IllegalStateException("Configure o token da TV");
        return new Request.Builder()
                .url(baseUrl() + path)
                .header("Authorization", "Bearer " + token())
                .header("X-Device-Token", token())
                .header("Accept", "application/json");
    }

    public String status() throws IOException {
        Request request = requestBuilder("/api/v1/status").get().build();
        try (Response response = client.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";
            if (!response.isSuccessful()) throw new IOException("HTTP " + response.code());
            return raw;
        }
    }

    public String ask(String text) throws Exception {
        JSONObject body = new JSONObject()
                .put("comando", text)
                .put("ia_mode", "auto")
                .put("origem", "ANDROID_TV");

        Request request = requestBuilder("/api/v1/comando")
                .post(RequestBody.create(body.toString(), JSON))
                .build();

        try (Response response = client.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";
            if (!response.isSuccessful()) throw new IOException("HTTP " + response.code() + ": " + raw);
            JSONObject json = new JSONObject(raw);
            String answer = json.optString("resposta", json.optString("mensagem", "Sem resposta do JARVIS"));
            return answer.isEmpty() ? "Sem resposta do JARVIS" : answer;
        }
    }
}
