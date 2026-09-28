package br.com.maurinsoft.jarvismobile

import android.content.Intent
import android.graphics.Typeface
import android.os.Bundle
import android.view.ViewGroup
import android.widget.*
import androidx.activity.ComponentActivity
import kotlinx.coroutines.*

class NewDevicesActivity : ComponentActivity() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private lateinit var status: TextView
    private lateinit var pendingList: LinearLayout
    private lateinit var nameEdit: EditText
    private lateinit var locationEdit: EditText
    private var selected: DeviceProvisionApi.PairingRequest? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        buildUi()
        refreshPending()
    }

    override fun onDestroy() { scope.cancel(); super.onDestroy() }

    private fun buildUi() {
        val scroll = ScrollView(this)
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(18), dp(20), dp(30)) }
        scroll.addView(root)
        root.addView(TextView(this).apply { text = "NOVOS DEVICES"; textSize = 28f; setTypeface(Typeface.DEFAULT_BOLD) })
        root.addView(TextView(this).apply {
            text = "Autorize somente depois de conferir tipo, modelo, endereço físico e funcionalidades solicitadas pelo equipamento."
            textSize = 15f; setPadding(0, dp(4), 0, dp(14))
        })

        root.addView(section("PEDIDOS DE PAREAMENTO"))
        status = TextView(this).apply { text = "Carregando pedidos..."; textSize = 15f; setPadding(dp(12), dp(10), dp(12), dp(10)) }
        root.addView(status, fullWidth())
        pendingList = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        root.addView(pendingList, fullWidth())
        root.addView(Button(this).apply { text = "ATUALIZAR PEDIDOS"; setOnClickListener { refreshPending() } }, fullWidth())

        root.addView(section("IDENTIFICAÇÃO APÓS APROVAÇÃO"))
        nameEdit = edit("Nome do equipamento", "")
        locationEdit = edit("Local", "Residencia")
        root.addView(nameEdit, fullWidth()); root.addView(locationEdit, fullWidth())
        root.addView(Button(this).apply { text = "AUTORIZAR DEVICE SELECIONADO"; setOnClickListener { authorizeSelected() } }, fullWidth())

        root.addView(section("CONFIGURAÇÃO LOCAL"))
        root.addView(Button(this).apply {
            text = "CONFIGURAR WATCH"
            setOnClickListener { startActivity(Intent(this@NewDevicesActivity, WatchSetupActivity::class.java)) }
        }, fullWidth())
        root.addView(TextView(this).apply {
            text = "A identidade e o token são gerados pelo site somente depois da autorização. O Mobile não cria mais uma identidade silenciosamente para o equipamento."
            textSize = 13f; setPadding(0, dp(12), 0, 0)
        })
        setContentView(scroll)
    }

    private fun refreshPending() {
        status.text = "Buscando pedidos de pareamento..."
        scope.launch {
            val result = withContext(Dispatchers.IO) { runCatching { DeviceProvisionApi.pendingPairingRequests(this@NewDevicesActivity) } }
            result.onSuccess { requests ->
                pendingList.removeAllViews()
                if (requests.isEmpty()) status.text = "Nenhum device aguardando autorização."
                else status.text = "${requests.size} device(s) aguardando. Selecione um para conferir antes de autorizar."
                requests.forEach { request ->
                    pendingList.addView(Button(this@NewDevicesActivity).apply {
                        text = deviceDescription(request)
                        isAllCaps = false
                        setOnClickListener {
                            selected = request
                            if (nameEdit.text.isBlank()) nameEdit.setText(request.model.ifBlank { request.type })
                            status.text = "Selecionado: ${request.model}. Confira as funcionalidades abaixo antes de autorizar."
                            Toast.makeText(this@NewDevicesActivity, deviceDescription(request), Toast.LENGTH_LONG).show()
                        }
                    }, fullWidth())
                }
            }.onFailure { status.text = "Falha ao buscar pedidos: ${it.message ?: it.javaClass.simpleName}" }
        }
    }

    private fun authorizeSelected() {
        val request = selected ?: run { status.text = "Selecione primeiro um pedido de pareamento."; return }
        val description = deviceDescription(request)
        AlertDialog.Builder(this)
            .setTitle("Autorizar ${request.type}?")
            .setMessage("Você está autorizando este equipamento:\n\n$description\n\nA autorização cria uma credencial permanente individual para este device.")
            .setNegativeButton("CANCELAR", null)
            .setPositiveButton("AUTORIZAR") { _, _ -> performAuthorization(request) }
            .show()
    }

    private fun performAuthorization(request: DeviceProvisionApi.PairingRequest) {
        status.text = "Autorizando ${request.model}..."
        scope.launch {
            val result = withContext(Dispatchers.IO) {
                runCatching { DeviceProvisionApi.authorizePairing(this@NewDevicesActivity, request, nameEdit.text.toString().trim(), locationEdit.text.toString().trim()) }
            }
            result.onSuccess {
                selected = null
                status.text = "${request.model} autorizado. O equipamento já pode consultar o servidor e receber sua credencial individual."
                refreshPending()
            }.onFailure { status.text = "Falha ao autorizar: ${it.message ?: it.javaClass.simpleName}" }
        }
    }

    private fun deviceDescription(r: DeviceProvisionApi.PairingRequest): String {
        val purpose = if (r.capabilities.isEmpty()) "não informado" else r.capabilities.joinToString(", ")
        return "Tipo: ${r.type}\nModelo: ${r.model}\nMAC: ${r.mac}\nFirmware: ${r.firmwareVersion.ifBlank { "não informado" }}\nFuncionalidades: $purpose\nCódigo: ${r.pairingCode}"
    }

    private fun edit(hint: String, value: String): EditText = EditText(this).apply { this.hint = hint; setText(value); setSingleLine(true); setPadding(dp(12), dp(10), dp(12), dp(10)) }
    private fun section(text: String): TextView = TextView(this).apply { this.text = text; textSize = 18f; setTypeface(Typeface.DEFAULT_BOLD); setPadding(0, dp(18), 0, dp(6)) }
    private fun fullWidth() = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { setMargins(0, 0, 0, dp(8)) }
    private fun dp(v: Int): Int = (v * resources.displayMetrics.density).toInt()
}
