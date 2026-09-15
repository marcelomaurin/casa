package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Typeface
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Bundle
import android.text.InputType
import android.view.Gravity
import android.view.ViewGroup
import android.widget.*
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class NewDevicesActivity : ComponentActivity(), EspCamProvisioner.Listener {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private lateinit var provisioner: EspCamProvisioner
    private lateinit var status: TextView
    private lateinit var list: LinearLayout
    private lateinit var nameEdit: EditText
    private lateinit var locationEdit: EditText
    private lateinit var ssidEdit: EditText
    private lateinit var wifiPassEdit: EditText
    private var selectedAddress: String? = null
    private var selectedName: String? = null
    private val found = linkedMapOf<String, EspCamProvisioner.FoundCamera>()

    private val permissions = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.all { it }) provisioner.scan() else setStatus("Permissão Bluetooth necessária para procurar devices.")
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        provisioner = EspCamProvisioner(this).also { it.setListener(this) }
        buildUi()
    }

    override fun onDestroy() {
        provisioner.close()
        scope.cancel()
        super.onDestroy()
    }

    private fun buildUi() {
        val scroll = ScrollView(this)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(20), dp(18), dp(20), dp(30))
        }
        scroll.addView(root)

        root.addView(TextView(this).apply {
            text = "NOVOS DEVICES"
            textSize = 28f
            setTypeface(Typeface.DEFAULT_BOLD)
        })
        root.addView(TextView(this).apply {
            text = "O celular configura os equipamentos. Depois do setup, cada device opera pela rede definida."
            textSize = 15f
            setPadding(0, dp(4), 0, dp(14))
        })

        root.addView(section("TIPO DE DEVICE"))

        val watchButton = Button(this).apply {
            text = "WATCH"
            setOnClickListener {
                startActivity(Intent(this@NewDevicesActivity, WatchSetupActivity::class.java))
            }
        }
        root.addView(watchButton, fullWidth())

        root.addView(section("ESP32-CAM"))
        val scan = Button(this).apply {
            text = "PROCURAR ESP32-CAM POR BLUETOOTH"
            setOnClickListener { ensureBluetoothAndScan() }
        }
        root.addView(scan, fullWidth())

        status = TextView(this).apply {
            text = "Nenhuma câmera selecionada."
            textSize = 15f
            setPadding(dp(12), dp(10), dp(12), dp(10))
        }
        root.addView(status, fullWidth())

        list = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        root.addView(list, fullWidth())

        root.addView(section("CONFIGURAÇÃO DA ESP32-CAM"))
        nameEdit = edit("Nome do equipamento", "Câmera")
        locationEdit = edit("Local", "Residencia")
        ssidEdit = edit("Wi-Fi SSID", currentSsid().orEmpty())
        wifiPassEdit = edit("Senha do Wi-Fi", "").apply {
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        }
        root.addView(nameEdit, fullWidth())
        root.addView(locationEdit, fullWidth())
        root.addView(ssidEdit, fullWidth())
        root.addView(wifiPassEdit, fullWidth())

        val provision = Button(this).apply {
            text = "PROVISIONAR CÂMERA"
            setOnClickListener { provisionSelected() }
        }
        root.addView(provision, fullWidth())

        root.addView(TextView(this).apply {
            text = "Fluxo: celular → BLE → grava Wi-Fi/URL/token → ESP32-CAM reinicia → operação normal por Wi-Fi. A câmera recebe token próprio; não recebe a credencial do celular."
            textSize = 13f
            setPadding(0, dp(12), 0, 0)
        })

        setContentView(scroll)
    }

    private fun ensureBluetoothAndScan() {
        found.clear(); list.removeAllViews(); selectedAddress = null; selectedName = null
        if (Build.VERSION.SDK_INT >= 31) {
            val needed = arrayOf(Manifest.permission.BLUETOOTH_SCAN, Manifest.permission.BLUETOOTH_CONNECT)
            val missing = needed.filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
            if (missing.isNotEmpty()) { permissions.launch(missing.toTypedArray()); return }
        }
        provisioner.scan()
    }

    private fun provisionSelected() {
        val address = selectedAddress ?: run { setStatus("Selecione uma ESP32-CAM encontrada."); return }
        val ssid = ssidEdit.text.toString().trim()
        val wifiPass = wifiPassEdit.text.toString()
        val name = nameEdit.text.toString().trim()
        val location = locationEdit.text.toString().trim()
        if (name.isBlank()) { setStatus("Informe o nome da câmera."); return }
        if (ssid.isBlank()) { setStatus("Informe o SSID do Wi-Fi."); return }
        if (wifiPass.isNotBlank() && wifiPass.length < 8) { setStatus("Senha WPA/WPA2 deve ter pelo menos 8 caracteres."); return }
        val cfg = JarvisApi.loadConfig(this)
        if (!cfg.baseUrl.startsWith("https://") || cfg.token.isBlank()) { setStatus("Configure primeiro URL HTTPS e token do celular."); return }

        setStatus("Criando identidade da câmera na CASA...")
        scope.launch {
            try {
                val device = withContext(Dispatchers.IO) {
                    DeviceProvisionApi.createEspCam(this@NewDevicesActivity, name, location, address)
                }
                setStatus("Identidade criada. Enviando configuração por Bluetooth...")
                provisioner.connectAndProvision(
                    address = address,
                    ssid = ssid,
                    wifiPassword = wifiPass,
                    casaUrl = cfg.baseUrl,
                    deviceToken = device.token,
                    deviceName = device.name,
                    location = device.location
                )
            } catch (t: Throwable) {
                setStatus("Falha no provisionamento: ${t.message ?: t.javaClass.simpleName}")
            }
        }
    }

    override fun onCameraFound(camera: EspCamProvisioner.FoundCamera) {
        runOnUiThread {
            found[camera.address] = camera
            rebuildList()
        }
    }

    override fun onStatus(message: String) = runOnUiThread { setStatus(message) }
    override fun onProvisioned() = runOnUiThread { setStatus("ESP32-CAM provisionada. Aguarde a reinicialização e conexão ao Wi-Fi.") }
    override fun onError(message: String) = runOnUiThread { setStatus("Erro: $message") }

    private fun rebuildList() {
        list.removeAllViews()
        found.values.sortedByDescending { it.rssi }.forEach { cam ->
            list.addView(Button(this).apply {
                text = "${cam.name}  ${cam.rssi} dBm\n${cam.address}"
                gravity = Gravity.START or Gravity.CENTER_VERTICAL
                setOnClickListener {
                    selectedAddress = cam.address
                    selectedName = cam.name
                    if (nameEdit.text.toString().trim() == "Câmera") nameEdit.setText(cam.name)
                    setStatus("Selecionada: ${cam.name}")
                }
            }, fullWidth())
        }
    }

    private fun currentSsid(): String? = runCatching {
        val wm = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION") val s = wm.connectionInfo?.ssid?.trim('"')
        s?.takeUnless { it.isBlank() || it == "<unknown ssid>" }
    }.getOrNull()

    private fun edit(hint: String, value: String): EditText = EditText(this).apply {
        this.hint = hint
        setText(value)
        setSingleLine(true)
        setPadding(dp(12), dp(10), dp(12), dp(10))
    }

    private fun section(text: String): TextView = TextView(this).apply {
        this.text = text
        textSize = 18f
        setTypeface(Typeface.DEFAULT_BOLD)
        setPadding(0, dp(18), 0, dp(6))
    }

    private fun fullWidth(): LinearLayout.LayoutParams = LinearLayout.LayoutParams(
        ViewGroup.LayoutParams.MATCH_PARENT,
        ViewGroup.LayoutParams.WRAP_CONTENT
    ).apply { setMargins(0, 0, 0, dp(8)) }

    private fun setStatus(text: String) { status.text = text }
    private fun dp(v: Int): Int = (v * resources.displayMetrics.density).toInt()
}
