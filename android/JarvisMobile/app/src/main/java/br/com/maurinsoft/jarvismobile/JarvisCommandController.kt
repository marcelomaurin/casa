package br.com.maurinsoft.jarvismobile

import android.content.Context

/**
 * Fachada para comandos do usuário. A UI não precisa conhecer fila,
 * transporte HTTP ou detalhes do retorno da API.
 */
class JarvisCommandController(private val context: Context) {
    data class Outcome(
        val delivered: Boolean,
        val queued: Boolean,
        val text: String?,
        val audioUrl: String?,
        val message: String
    )

    fun send(text: String): Outcome {
        val result = JarvisApi.sendOrQueue(context, text)
        return Outcome(
            delivered = result.delivered,
            queued = result.queued,
            text = result.answer?.text,
            audioUrl = result.answer?.audioUrl,
            message = result.message
        )
    }
}
