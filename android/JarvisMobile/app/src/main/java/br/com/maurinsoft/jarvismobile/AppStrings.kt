package br.com.maurinsoft.jarvismobile

import android.content.Context

object AppStrings {
    private val pt = mapOf(
        "operations" to "Operações", "voice" to "Voz", "watch" to "Watch", "devices" to "Devices", "config" to "Configuração",
        "assistant" to "Assistente residencial inteligente", "initializing" to "Inicializando modo local e conexão...",
        "not_configured" to "Não configurado", "online" to "Online", "offline_reconnecting" to "Offline — reconectando",
        "queue" to "Fila", "connection" to "Conexão", "connected_house" to "Online — conectado à casa",
        "offline_detail" to "Offline — o app continua disponível e tenta reconectar automaticamente",
        "waiting" to "comando(s) aguardando envio", "quick_ops" to "Operações rápidas", "turn_on" to "Ligar luz",
        "turn_off" to "Desligar luz", "status" to "Status", "sensors" to "Sensores", "manual_command" to "Comando manual",
        "processing" to "Processando...", "execute" to "Executar", "save_queue" to "Salvar na fila", "result" to "Resultado",
        "manual_ready" to "Pronto para operações manuais.", "executed" to "Comando executado.", "connected_jarvis" to "Conectado ao JARVIS",
        "offline_voice" to "Modo offline — reconexão automática ativa", "you" to "Você", "none" to "Nenhuma frase ainda.",
        "touch_speak" to "Toque em Falar.", "offline_speech" to "Offline. A fala reconhecida será enfileirada para envio quando a conexão voltar.",
        "listening" to "OUVINDO...", "speak" to "FALAR COM O JARVIS", "not_recognized" to "Não consegui reconhecer a fala.",
        "config_loaded" to "Configuração carregada.", "config_help" to "Use a URL HTTPS externa da API da casa. O aplicativo funciona offline e continuará tentando a conexão após salvar.",
        "public_url" to "URL pública da casa", "phone_token" to "Token deste celular", "save" to "Salvar", "test_now" to "Testar agora",
        "testing" to "Testando...", "saved" to "Configuração salva. Reconexão automática ativa.", "language" to "Idioma",
        "automatic" to "Automático (idioma do aparelho)", "language_help" to "A interface, o reconhecimento de voz e as respostas do JARVIS seguem este idioma."
    )

    private val en = mapOf(
        "operations" to "Operations", "voice" to "Voice", "watch" to "Watch", "devices" to "Devices", "config" to "Settings",
        "assistant" to "Smart home assistant", "initializing" to "Starting local mode and connection...",
        "not_configured" to "Not configured", "online" to "Online", "offline_reconnecting" to "Offline — reconnecting",
        "queue" to "Queue", "connection" to "Connection", "connected_house" to "Online — connected to home",
        "offline_detail" to "Offline — the app remains available and keeps reconnecting automatically",
        "waiting" to "command(s) waiting to send", "quick_ops" to "Quick operations", "turn_on" to "Turn light on",
        "turn_off" to "Turn light off", "status" to "Status", "sensors" to "Sensors", "manual_command" to "Manual command",
        "processing" to "Processing...", "execute" to "Execute", "save_queue" to "Save to queue", "result" to "Result",
        "manual_ready" to "Ready for manual operations.", "executed" to "Command executed.", "connected_jarvis" to "Connected to JARVIS",
        "offline_voice" to "Offline mode — automatic reconnection active", "you" to "You", "none" to "No phrase yet.",
        "touch_speak" to "Tap Speak.", "offline_speech" to "Offline. Recognized speech will be queued until the connection returns.",
        "listening" to "LISTENING...", "speak" to "TALK TO JARVIS", "not_recognized" to "I could not recognize the speech.",
        "config_loaded" to "Settings loaded.", "config_help" to "Use the external HTTPS URL for the home API. The app works offline and keeps trying after saving.",
        "public_url" to "Public home URL", "phone_token" to "This phone token", "save" to "Save", "test_now" to "Test now",
        "testing" to "Testing...", "saved" to "Settings saved. Automatic reconnection active.", "language" to "Language",
        "automatic" to "Automatic (device language)", "language_help" to "The interface, speech recognition and JARVIS responses follow this language."
    )

    private val es = mapOf(
        "operations" to "Operaciones", "voice" to "Voz", "watch" to "Watch", "devices" to "Devices", "config" to "Configuración",
        "assistant" to "Asistente residencial inteligente", "initializing" to "Iniciando modo local y conexión...",
        "not_configured" to "No configurado", "online" to "En línea", "offline_reconnecting" to "Sin conexión — reconectando",
        "queue" to "Cola", "connection" to "Conexión", "connected_house" to "En línea — conectado a la casa",
        "offline_detail" to "Sin conexión — la aplicación sigue disponible e intenta reconectarse automáticamente",
        "waiting" to "comando(s) pendientes", "quick_ops" to "Operaciones rápidas", "turn_on" to "Encender luz",
        "turn_off" to "Apagar luz", "status" to "Estado", "sensors" to "Sensores", "manual_command" to "Comando manual",
        "processing" to "Procesando...", "execute" to "Ejecutar", "save_queue" to "Guardar en cola", "result" to "Resultado",
        "manual_ready" to "Listo para operaciones manuales.", "executed" to "Comando ejecutado.", "connected_jarvis" to "Conectado a JARVIS",
        "offline_voice" to "Modo sin conexión — reconexión automática activa", "you" to "Tú", "none" to "Aún no hay frase.",
        "touch_speak" to "Toca Hablar.", "offline_speech" to "Sin conexión. La voz reconocida se guardará hasta que vuelva la conexión.",
        "listening" to "ESCUCHANDO...", "speak" to "HABLAR CON JARVIS", "not_recognized" to "No pude reconocer la voz.",
        "config_loaded" to "Configuración cargada.", "config_help" to "Usa la URL HTTPS externa de la API de la casa. La aplicación funciona sin conexión y seguirá intentando conectarse.",
        "public_url" to "URL pública de la casa", "phone_token" to "Token de este teléfono", "save" to "Guardar", "test_now" to "Probar ahora",
        "testing" to "Probando...", "saved" to "Configuración guardada. Reconexión automática activa.", "language" to "Idioma",
        "automatic" to "Automático (idioma del dispositivo)", "language_help" to "La interfaz, el reconocimiento de voz y las respuestas de JARVIS siguen este idioma."
    )

    fun get(context: Context, key: String): String {
        val table = when (LanguageManager.currentLanguage(context)) {
            "en-US" -> en
            "es-ES" -> es
            else -> pt
        }
        return table[key] ?: pt[key] ?: key
    }
}
