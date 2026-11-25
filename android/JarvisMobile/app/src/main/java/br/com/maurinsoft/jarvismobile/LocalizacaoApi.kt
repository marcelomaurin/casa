package br.com.maurinsoft.jarvismobile

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

object LocalizacaoApi {
    data class Posicao(
        val deviceId: String,
        val nome: String,
        val latitude: Double,
        val longitude: Double,
        val precisaoM: Double?,
        val bateria: Int?,
        val segundos: Long,
        val proprio: Boolean
    )

    /** Retorna true quando a CASA gravou a posicao (envios muito proximos sao ignorados). */
    fun enviar(
        context: Context,
        latitude: Double,
        longitude: Double,
        precisao: Float?,
        altitude: Double?,
        velocidade: Float?,
        bateria: Int?,
        origem: String,
        capturadoEmMs: Long
    ): Boolean {
        val body = JSONObject().put("latitude", latitude).put("longitude", longitude)
            .put("origem", origem).put("capturado_em", capturadoEmMs)
        precisao?.let { body.put("precisao", it.toDouble()) }
        altitude?.let { body.put("altitude", it) }
        velocidade?.let { body.put("velocidade", it.toDouble()) }
        bateria?.let { body.put("bateria", it) }
        return CasaV1Http.call(context, "localizacao.php?acao=enviar", body).optBoolean("gravado")
    }

    fun ultimas(context: Context): List<Posicao> {
        val arr = CasaV1Http.call(context, "localizacao.php?acao=ultimas").optJSONArray("aparelhos") ?: JSONArray()
        val out = ArrayList<Posicao>()
        for (i in 0 until arr.length()) {
            val a = arr.optJSONObject(i) ?: continue
            out += Posicao(
                deviceId = a.optString("device_id"),
                nome = a.optString("nome").ifBlank { a.optString("device_id") },
                latitude = a.optString("latitude").toDoubleOrNull() ?: continue,
                longitude = a.optString("longitude").toDoubleOrNull() ?: continue,
                precisaoM = a.optString("precisao_m").toDoubleOrNull(),
                bateria = a.optString("bateria").toIntOrNull(),
                segundos = a.optString("segundos").toLongOrNull() ?: 0L,
                proprio = a.optBoolean("proprio")
            )
        }
        return out
    }

    fun apagarMeuHistorico(context: Context): Int =
        CasaV1Http.call(context, "localizacao.php?acao=apagar", JSONObject()).optInt("removidos")
}
