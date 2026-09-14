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
 * Cliente HTTP do JARVIS TV.
 *
 * A IA da TV usa somente o gateway sincrono RunPod da Maurinsoft:
 * POST {baseUrl}/api/chat
 *
 * Payload:
 * {
 *   "runpod_api_key": "...",
 *   "runpod_endpoint_id": "...",
 *   "model": "...",
 *   "prompt": "..."
 * }
 *
 * Resposta esperada:
 * {
 *   "success": true,
 *   "output": "..."
 * }
 */
public class JarvisApiClient {
    private static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    public static final String DEFAULT_BASE_URL = "https://maurinsoft.com.br";
    public static final String DEFAULT_MODEL = "meta-llama/Meta-Llama-3-8B-Instruct";

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
        return value.trim().replaceAll("/+$", "");
    }

    private String runpodApiKey() {
        String value = prefs().getString(MainActivity.KEY_RUNPOD_API_KEY, "");
        return value == null ? "" : value.trim();
    }

    private String runpodEndpointId() {
        String value = prefs().getString(MainActivity.KEY_RUNPOD_ENDPOINT_ID, "");
        return value == null ? "" : value.trim();
    }

    private String runpodModel() {
        String value = prefs().getString(MainActivity.KEY_RUNPOD_MODEL, DEFAULT_MODEL);
        if (value == null || value.trim().isEmpty()) value = DEFAULT_MODEL;
        return value.trim();
    }

    /**
     * O token da TV continua reservado para os endpoints CASA autenticados
     * (status, automacao, dispositivos), mas nao participa da chamada /api/chat.
     */
    private String token() {
        String value = prefs().getString(MainActivity.KEY_TOKEN, "");
        return value == null ? "" : value.trim();
    }

    public boolean isConfigured() {
        return baseUrl().startsWith("https://")
                && !runpodApiKey().isEmpty()
                && !runpodEndpointId().isEmpty();
    }

    public boolean isCasaTokenConfigured() {
        return !token().isEmpty();
    }

    /**
     * Testa a API CASA quando houver token individual da TV.
     * Este metodo nao e usado para IA.
     */
    public String status() throws IOException {
        if (!isCasaTokenConfigured()) {
            throw new IllegalStateException("Configure o token individual da TV");
        }

        Request request = new Request.Builder()
                .url("https://maurinsoft.com.br/casa/api/v1/status")
                .header("Authorization", "Bearer " + token())
                .header("X-Device-Token", token())
                .header("Accept", "application/json")
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
     * Envia a pergunta para o gateway RunPod sincrono da Maurinsoft.
     * Nao existe modo local, hibrido ou fallback neste fluxo.
     */
    public String ask(String text) throws Exception {
        if (!isConfigured()) {
            throw new IllegalStateException("Configure a chave e o Endpoint ID do RunPod na TV");
        }

        String prompt = text == null ? "" : text.trim();
        if (prompt.isEmpty()) {
            throw new IllegalArgumentException("Prompt vazio");
        }

        JSONObject body = new JSONObject()
                .put("runpod_api_key", runpodApiKey())
                .put("runpod_endpoint_id", runpodEndpointId())
                .put("model", runpodModel())
                .put("prompt", prompt);

        Request request = new Request.Builder()
                .url(baseUrl() + "/api/chat")
                .header("Accept", "application/json")
                .post(RequestBody.create(body.toString(), JSON))
                .build();

        try (Response response = client.newCall(request).execute()) {
            String raw = response.body() != null ? response.body().string() : "";

            if (!response.isSuccessful()) {
                throw new IOException("HTTP " + response.code() + ": " + raw);
            }

            JSONObject json = new JSONObject(raw);
            if (!json.optBoolean("success", false)) {
                String error = json.optString("error",
                        json.optString("message", "A API RunPod retornou falha"));
                throw new IOException(error);
            }

            String answer = json.optString("output", "").trim();
            if (answer.isEmpty()) {
                throw new IOException("Resposta RunPod sem campo output");
            }

            return answer;
        }
    }
}
