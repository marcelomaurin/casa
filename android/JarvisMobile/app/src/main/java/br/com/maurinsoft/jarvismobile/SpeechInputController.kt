package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer

class SpeechInputController(private val activity: Activity) {
    private var recognizer: SpeechRecognizer? = null
    var lastError: String? = null
        private set
    private var callback: ((String) -> Unit)? = null

    fun initialize() {
        if (!SpeechRecognizer.isRecognitionAvailable(activity)) return
        recognizer = SpeechRecognizer.createSpeechRecognizer(activity).apply {
            setRecognitionListener(object : RecognitionListener {
                override fun onReadyForSpeech(params: Bundle?) = Unit
                override fun onBeginningOfSpeech() = Unit
                override fun onRmsChanged(rmsdB: Float) = Unit
                override fun onBufferReceived(buffer: ByteArray?) = Unit
                override fun onEndOfSpeech() = Unit
                override fun onError(error: Int) {
                    lastError = when (error) {
                        SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> "Autorize o microfone nos ajustes do Android."
                        SpeechRecognizer.ERROR_NETWORK, SpeechRecognizer.ERROR_NETWORK_TIMEOUT -> "Falha de rede no reconhecimento de voz."
                        SpeechRecognizer.ERROR_RECOGNIZER_BUSY -> "Reconhecimento ocupado. Tente novamente."
                        SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> "Nenhuma fala reconhecida. Tente novamente."
                        else -> "Falha no reconhecimento de voz ($error)."
                    }
                    callback?.invoke("")
                }
                override fun onResults(results: Bundle?) {
                    callback?.invoke(results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull().orEmpty())
                }
                override fun onPartialResults(partialResults: Bundle?) = Unit
                override fun onEvent(eventType: Int, params: Bundle?) = Unit
            })
        }
    }

    fun listen(onResult: (String) -> Unit) {
        lastError = null
        if (recognizer == null) {
            lastError = "Reconhecimento de voz indisponível neste aparelho. Use a digitação."
            onResult("")
            return
        }
        if (activity.checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            lastError = "Autorize o microfone para falar com o JARVIS."
            onResult("")
            return
        }
        callback = onResult
        try { recognizer?.startListening(Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, LanguageManager.recognitionTag(activity))
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, false)
            putExtra(RecognizerIntent.EXTRA_PROMPT, "JARVIS")
        }) } catch (e: Exception) {
            lastError = "Não foi possível iniciar o microfone. Tente novamente."
            onResult("")
        }
    }

    fun cancel() { recognizer?.cancel(); callback = null }

    fun destroy() {
        recognizer?.destroy()
        recognizer = null
        callback = null
    }
}
