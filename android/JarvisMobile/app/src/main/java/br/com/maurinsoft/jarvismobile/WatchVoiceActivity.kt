package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.speech.RecognizerIntent
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import java.util.Locale

/**
 * Ponte de voz iniciada pelo JARVIS Watch.
 *
 * O relógio atual apenas detecta atividade de voz; o reconhecimento ocorre no
 * celular. O texto reconhecido é devolvido ao JarvisConnectionService, que
 * consulta o JARVIS e envia a resposta ao Watch.
 */
class WatchVoiceActivity : ComponentActivity() {
    companion object {
        const val EXTRA_WATCH_DEVICE_ID = "watch_device_id"
    }

    private var watchDeviceId: String = ""

    private val speechLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val text = if (result.resultCode == Activity.RESULT_OK) {
            result.data
                ?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)
                ?.firstOrNull()
                ?.trim()
                .orEmpty()
        } else {
            ""
        }

        if (text.isNotBlank()) {
            deliver(text = text)
        } else {
            deliver(error = "speech_cancelled")
        }
        finish()
    }

    private val audioPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) {
            launchSpeechRecognizer()
        } else {
            deliver(error = "record_audio_permission_required")
            finish()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        watchDeviceId = intent.getStringExtra(EXTRA_WATCH_DEVICE_ID).orEmpty()
        if (watchDeviceId.isBlank()) {
            finish()
            return
        }

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) ==
            PackageManager.PERMISSION_GRANTED
        ) {
            launchSpeechRecognizer()
        } else {
            audioPermissionLauncher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    private fun launchSpeechRecognizer() {
        val locale = when (LanguageManager.currentLanguage(this)) {
            "en-US" -> Locale.US.toLanguageTag()
            "es-ES" -> "es-ES"
            else -> "pt-BR"
        }

        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
            .putExtra(
                RecognizerIntent.EXTRA_LANGUAGE_MODEL,
                RecognizerIntent.LANGUAGE_MODEL_FREE_FORM
            )
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE, locale)
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, locale)
            .putExtra(RecognizerIntent.EXTRA_PROMPT, "Fale com o JARVIS")
            .putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)

        try {
            speechLauncher.launch(intent)
        } catch (_: ActivityNotFoundException) {
            deliver(error = "speech_recognizer_unavailable")
            finish()
        }
    }

    private fun deliver(text: String = "", error: String = "") {
        val serviceIntent = Intent(this, JarvisConnectionService::class.java)
            .setAction(JarvisConnectionService.ACTION_VOICE_RECOGNIZED)
            .putExtra(JarvisConnectionService.EXTRA_VOICE_DEVICE_ID, watchDeviceId)
            .putExtra(JarvisConnectionService.EXTRA_VOICE_TEXT, text)
            .putExtra(JarvisConnectionService.EXTRA_VOICE_ERROR, error)

        ContextCompat.startForegroundService(this, serviceIntent)
    }
}
