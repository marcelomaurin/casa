package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/** Chamadas autenticadas a API v1 da CASA usadas pelas telas de daemons e localizacao. */
object CasaV1Http {
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(25, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    class ApiException(val code: Int, message: String) : Exception(message)

    fun call(context: Context, path: String, body: JSONObject? = null): JSONObject {
        val cfg = JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")) { "Use uma URL HTTPS da CASA" }
        require(cfg.token.isNotBlank()) { "Pareie o celular com a CASA pelo QR Code para usar este recurso" }
        val builder = Request.Builder().url(cfg.baseUrl + "/api/v1/" + path)
            .header("Authorization", "Bearer ${cfg.token}")
            .header("Accept", "application/json")
        if (body != null) builder.post(body.toString().toRequestBody(jsonType)) else builder.get()
        client.newCall(builder.build()).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(raw) }.getOrNull()
            if (!response.isSuccessful) {
                val msg = json?.optString("mensagem").orEmpty().ifBlank { "HTTP ${response.code}" }
                throw ApiException(response.code, msg)
            }
            return json ?: throw ApiException(response.code, "Resposta invalida da CASA")
        }
    }
}

object DaemonsApi {
    data class Servico(
        val id: String,
        val nome: String,
        val estado: String,
        val ativo: Boolean,
        val protegido: Boolean,
        val finalidade: String,
        val impactoAoParar: String
    )

    data class No(
        val id: String,
        val nome: String,
        val ip: String,
        val papel: String,
        val online: Boolean,
        val status: String,
        val segundosSemSinal: Long?,
        val cpu: String,
        val ram: String,
        val versao: String,
        val servicos: List<Servico>
    )

    data class Painel(
        val podeControlar: Boolean,
        val nosOnline: Int,
        val totalNos: Int,
        val servicosAtivos: Int,
        val servicosParados: Int,
        val nos: List<No>
    )

    fun listar(context: Context): Painel {
        val j = CasaV1Http.call(context, "daemons.php?acao=listar")
        val resumo = j.optJSONObject("resumo") ?: JSONObject()
        val nosJson = j.optJSONArray("nos") ?: JSONArray()
        val nos = ArrayList<No>()
        for (i in 0 until nosJson.length()) {
            val n = nosJson.optJSONObject(i) ?: continue
            val sj = n.optJSONArray("servicos") ?: JSONArray()
            val servicos = ArrayList<Servico>()
            for (k in 0 until sj.length()) {
                val s = sj.optJSONObject(k) ?: continue
                servicos += Servico(
                    id = s.optString("id"), nome = s.optString("nome").ifBlank { s.optString("id") },
                    estado = s.optString("estado"), ativo = s.optBoolean("ativo"),
                    protegido = s.optBoolean("protegido"), finalidade = s.optString("finalidade"),
                    impactoAoParar = s.optString("impacto_ao_parar")
                )
            }
            nos += No(
                id = n.optString("id"), nome = n.optString("nome"), ip = n.optString("ip"),
                papel = n.optString("papel"), online = n.optBoolean("online"), status = n.optString("status"),
                segundosSemSinal = if (n.isNull("segundos_sem_sinal")) null else n.optLong("segundos_sem_sinal"),
                cpu = n.optString("cpu"), ram = n.optString("ram"), versao = n.optString("versao"),
                servicos = servicos
            )
        }
        return Painel(
            podeControlar = j.optBoolean("pode_controlar"),
            nosOnline = resumo.optInt("nos_online"), totalNos = resumo.optInt("nos"),
            servicosAtivos = resumo.optInt("servicos_ativos"), servicosParados = resumo.optInt("servicos_parados"),
            nos = nos
        )
    }

    /** operacao: start, stop ou restart. Retorna a mensagem da CASA. */
    fun controlar(context: Context, noId: String, servico: String, operacao: String): String {
        val j = CasaV1Http.call(context, "daemons.php?acao=controlar", JSONObject()
            .put("no", noId).put("servico", servico).put("operacao", operacao).put("confirmar", true))
        return j.optString("mensagem").ifBlank { "Comando enviado." }
    }
}
