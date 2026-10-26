package br.com.maurinsoft.jarvistv;

/** Configuracao comum do JARVIS TV (tela de exibicao da casa). */
public final class TvConfig {
    /** Endereco padrao do CASA; pode ser trocado na tela da TV. */
    public static final String DEFAULT_BASE_URL = "https://maurinsoft.com.br/casa";

    public static final String PREFS = "jarvis_tv";
    public static final String KEY_URL = "api_url";
    public static final String KEY_TOKEN = "api_token";
    /** Ultima emergencia recebida (JSON), mostrada quando a tela abrir. */
    public static final String KEY_EMERGENCY = "emergency_last";

    private TvConfig() {}
}
