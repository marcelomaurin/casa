package br.com.maurinsoft.jarvismobile

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Test

class JarvisApiResponseTest {
    @Test fun parsesSuccessfulAnswerAndAudio() {
        val response = JarvisApi.parseCommandResponse(
            """{"status":"sucesso","status_jarvis":"sucesso","resposta":"A luz está ligada.","audio_url":"/api/audio.php?file=a.wav","acao_executada":"consultar"}"""
        )
        assertEquals("A luz está ligada.", response.text)
        assertEquals("/api/audio.php?file=a.wav", response.audioUrl)
        assertEquals("consultar", response.action)
    }

    @Test fun rejectsInnerJarvisErrorDespiteHttpSuccess() {
        val error = assertThrows(JarvisApi.ResponseFailure::class.java) {
            JarvisApi.parseCommandResponse(
                """{"status":"sucesso","status_jarvis":"erro","mensagem":"Modelo indisponível","resposta":""}"""
            )
        }
        assertEquals("Modelo indisponível", error.message)
    }

    @Test fun rejectsBlankResponse() {
        assertThrows(JarvisApi.ResponseFailure::class.java) {
            JarvisApi.parseCommandResponse("""{"status":"sucesso","resposta":""}""")
        }
    }

    @Test fun rejectsHtmlInsteadOfJson() {
        assertThrows(JarvisApi.ResponseFailure::class.java) {
            JarvisApi.parseCommandResponse("<html>erro</html>")
        }
    }

    @Test fun acceptsAnswerWithoutAudio() {
        val answer = JarvisApi.parseCommandResponse(
            """{"status":"sucesso","resposta":"Tarefa aguardando daemon.","audio_url":null}"""
        )
        assertEquals("Tarefa aguardando daemon.", answer.text)
        assertNull(answer.audioUrl)
    }
}
