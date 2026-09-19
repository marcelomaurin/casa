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

/**
 * Cliente HTTP unificado do JARVIS TV.
 *
 * A TV nunca conhece provedores de IA, chaves RunPod ou enderecos de nos.
 * Toda solicitacao cognitiva passa pela CASA API v1:
 *
 *   POST {baseUrl}/api/v1/comando
 *
 * O servidor CASA decide se a execucao sera local, RunPod ou outro provedor.
 */
public class JarvisApiClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    public static final String DEFAULT_BASE_URL = "https://maurinsoft.com.br/casa";

    private final Context context;
    private final OkHttpClient client;

    public JarvisApiClient(Context context) {
        this.context = context.getApplicationContext();
        this.client = new OkHttpClient.Builder()
                .connectTimeout(10, TimeUnit.SECONDS)
                .readTimeout(90, TimeUnit.SECONDS)
                .writeTimeout(30, TimeUnit.SECONDS)
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

        // Compatibilidade com instalacoes antigas.
        if ("https://maurinsoft.com.br/casa".equalsIgnoreCase(value)) {
            value = DEFAULT_BASE_URL;
        }
        return value;
    }

    private String token() {
        String value = prefs().getString(MainActivity.KEY_TOKEN, "");
        return value == null ? "" : value.trim();
    }

    public boolean isConfigured() {
        return baseUrl().startsWith("https://") && !token().isEmpty();
    }

    private Request.Builder authenticatedRequest(String path) {
        if (!isConfigured()) {
            throw new IllegalStateException("Configure a URL CASA e o token individual da TV");
        }

        return new Request.Builder()
                .url(baseUrl() + path)
                .header("Authorization", "Bearer " + token())
                .header("X-Device-Token", token())
                .header("Accept", "application/json");
    }

    public String status() throws IOException {
        Request request = authenticatedRequest("/api/v1/status")
                .get()
                .build();

        try (Response response = client.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";
            if (!response.isSuccessful()) {
                throw new IOException("HTTP " + response.code() + ": " + raw);
            }
            return raw;
        }
    }

    /**
     * Envia linguagem natural ao nucleo JARVIS pelo ponto unico de entrada.
     * Nenhuma credencial de provedor de IA fica armazenada na TV.
     */
    public String ask(String text) throws Exception {
        String prompt = text == null ? "" : text.trim();
        if (prompt.isEmpty()) {
            throw new IllegalArgumentException("Prompt vazio");
        }

        JSONObject body = new JSONObject()
                .put("comando", prompt)
                .put("ia_mode", "auto")
                .put("origem", "ANDROID_TV");

        Request request = authenticatedRequest("/api/v1/comando")
                .post(RequestBody.create(body.toString(), JSON))
                .build();

        try (Response response = client.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";

            if (!response.isSuccessful()) {
                throw new IOException("HTTP " + response.code() + ": " + raw);
            }

            JSONObject json = new JSONObject(raw);
            String answer = json.optString(
                    "resposta",
                    json.optString("mensagem", "")
            ).trim();

            if (answer.isEmpty()) {
                throw new IOException("Resposta CASA sem campo resposta");
            }
            return answer;
        }
    }
}
