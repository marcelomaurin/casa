package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.*
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.wifi.WifiManager
import android.os.Build
import android.os.IBinder
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*
import org.json.JSONObject

class JarvisConnectionService : Service(), WatchClient.Listener {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var connectivity: ConnectivityManager? = null
    private var watchClient: WatchClient? = null
    private var lastWifiState = false
    private var jarvisOnline = false
    private var familyLastId = 0L
    private var networkCallbackRegistered = false

    companion object {
        const val CHANNEL_SERVICE = "jarvis_service"
        const val CHANNEL_ALERTS = "jarvis_alerts"
        const val CHANNEL_WATCH = "jarvis_watch"
        const val NOTIFICATION_ID = 1001
        const val CAMERA_NOTIFICATION_ID = 1401
    }

    override fun onCreate() {
        super.onCreate()

        runCatching { createChannels() }
        runCatching {
            startForeground(
                NOTIFICATION_ID,
                serviceNotification("Inicializando JARVIS...")
            )
        }.onFailure {
            // Evita derrubar o processo inteiro se o SO recusar a promoção do serviço.
            stopSelf()
            return
        }

        runCatching {
            connectivity = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            connectivity?.registerDefaultNetworkCallback(networkCallback)
            networkCallbackRegistered = true
        }.onFailure {
            networkCallbackRegistered = false
            updateServiceNotification("Rede indisponível — JARVIS continua em modo local")
        }

        runCatching {
            watchClient = WatchClient(this).also { it.addListener(this) }
            startWatchConnectionSafely()
        }.onFailure {
            updateServiceNotification("Bluetooth indisponível — app continua ativo")
        }

        startConnectionLoop()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        runCatching {
            if (intent?.action == WatchCameraActivity.ACTION_CAMERA_RESULT) {
                val ok = intent.getBooleanExtra(WatchCameraActivity.EXTRA_CAMERA_OK, false)
                val path = intent.getStringExtra(WatchCameraActivity.EXTRA_CAMERA_PATH).orEmpty()
                watchClient?.send(
                    JSONObject()
                        .put("type", "camera_result")
                        .put("ok", ok)
                        .put("path", path)
                )
            }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        if (networkCallbackRegistered) {
            runCatching { connectivity?.unregisterNetworkCallback(networkCallback) }
        }
        runCatching { watchClient?.removeListener(this) }
        runCatching { watchClient?.disconnect(false) }
        watchClient = null
        connectivity = null
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun hasBluetoothPermissions(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return ActivityCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED &&
            ActivityCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
    }

    private fun startWatchConnectionSafely() {
        val client = watchClient ?: return
        if (!hasBluetoothPermissions()) {
            updateServiceNotification("Aguardando permissão Bluetooth")
            return
        }
        runCatching {
            if (!client.connectSaved()) client.scan()
        }.onFailure {
            updateServiceNotification("Falha ao iniciar Bluetooth — ${it.javaClass.simpleName}")
        }
    }

    private fun createChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(NotificationChannel(CHANNEL_SERVICE, "Conexão JARVIS", NotificationManager.IMPORTANCE_LOW))
            nm.createNotificationChannel(NotificationChannel(CHANNEL_ALERTS, "Alertas JARVIS", NotificationManager.IMPORTANCE_HIGH))
            nm.createNotificationChannel(NotificationChannel(CHANNEL_WATCH, "JARVIS Watch", NotificationManager.IMPORTANCE_HIGH))
        }
    }

    private fun serviceNotification(text: String): Notification {
        val openMain = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val setup = PendingIntent.getActivity(this, 1, Intent(this, WatchSetupActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, CHANNEL_SERVICE)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Mobile")
            .setContentText(text)
            .setOngoing(true)
            .setContentIntent(openMain)
            .addAction(0, "Relógio", setup)
            .build()
    }

    private fun updateServiceNotification(text: String) {
        runCatching {
            getSystemService(NotificationManager::class.java)
                .notify(NOTIFICATION_ID, serviceNotification(text))
        }
    }

    private fun withWatch(text: String): String =
        if (WatchClient.isConnected(this)) "$text • Watch conectado" else "$text • Watch desconectado"

    override fun onScanResult(watch: WatchClient.FoundWatch) {
        runCatching {
            val saved = WatchClient.savedAddress(this)
            if (saved.isNotBlank() && saved.equals(watch.address, true)) {
                watchClient?.connect(watch.address)
            }
        }
    }

    override fun onConnectionChanged(connected: Boolean, name: String, address: String) {
        updateServiceNotification(withWatch(if (jarvisOnline) "Online — JARVIS" else "Conexão local ativa"))
        if (connected) {
            runCatching { sendPhoneState() }
            runCatching { watchClient?.requestStatus() }
        } else {
            scope.launch {
                delay(5000)
                startWatchConnectionSafely()
            }
        }
    }

    override fun onWatchMessage(json: JSONObject) {
        val type = json.optString("type")
        scope.launch {
            runCatching {
                when (type) {
                    "sos" -> sendAssist("SOS", "critica", "SOS acionado no JARVIS Watch", json)
                    "inactivity" -> sendAssist("INACTIVITY", "alta", "Relógio detectou período prolongado sem movimento", json)
                    "checkin" -> sendAssist("CHECKIN", if (json.optBoolean("ok", true)) "info" else "alta", "Check-in do JARVIS Watch", json)
                    "movement" -> sendAssist("MOVEMENT_HEARTBEAT", "info", "Telemetria de movimento do JARVIS Watch", json)
                    "family_message" -> FamilyApi.sendMessage(this@JarvisConnectionService, json.optString("message"), json.optString("message_type", "texto"), json)
                    "family_call" -> {
                        val mode = if (json.optString("mode") == "audio") "audio" else "video"
                        val callId = runCatching { FamilyApi.startCall(this@JarvisConnectionService, mode, "watch") }.getOrDefault(0L)
                        watchClient?.send(JSONObject().put("type", "family_call_result").put("ok", callId > 0).put("call_id", callId).put("mode", mode))
                        if (callId > 0) showFamilyCallNotification(callId, mode)
                    }
                    "voice_text" -> {
                        val text = json.optString("text").trim()
                        if (text.isNotBlank()) {
                            val result = JarvisApi.sendOrQueue(this@JarvisConnectionService, text)
                            watchClient?.send(JSONObject().put("type", "jarvis_result").put("ok", result.delivered).put("queued", result.queued).put("text", result.answer?.text ?: result.message))
                        }
                    }
                    "gps_request" -> watchClient?.send(gpsPayload())
                    "camera_capture" -> showCameraRequest()
                    "phone_state" -> sendPhoneState()
                    "ping" -> watchClient?.send(JSONObject().put("type", "pong").put("internet", jarvisOnline))
                }
            }.onFailure {
                updateServiceNotification("Evento do Watch ignorado: ${it.javaClass.simpleName}")
            }
        }
    }

    override fun onError(message: String) {
        updateServiceNotification(withWatch(message))
    }

    private suspend fun sendAssist(type: String, severity: String, message: String, data: JSONObject) {
        runCatching {
            FamilyApi.assistEvent(this@JarvisConnectionService, type, severity, message, data, device = "JARVIS Watch")
        }
    }

    private fun currentSsid(): String? = runCatching {
        val wm = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION")
        val s = wm.connectionInfo?.ssid?.trim('"')
        s?.takeUnless { it.isBlank() || it == "<unknown ssid>" }
    }.getOrNull()

    private fun sendPhoneState() {
        val cm = connectivity ?: return
        val caps = runCatching { cm.getNetworkCapabilities(cm.activeNetwork) }.getOrNull()
        val wifi = caps?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true
        val internet = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true
        runCatching {
            watchClient?.sendPhoneState(wifi, if (wifi) currentSsid() else null, internet)
        }
    }

    private fun canLocation(): Boolean =
        ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun gpsPayload(): JSONObject {
        if (!canLocation()) return JSONObject().put("type", "gps_result").put("ok", false).put("error", "permission_required")
        return runCatching {
            val lm = getSystemService(Context.LOCATION_SERVICE) as LocationManager
            var best: Location? = null
            listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER, LocationManager.PASSIVE_PROVIDER).forEach { provider ->
                val loc = runCatching { lm.getLastKnownLocation(provider) }.getOrNull()
                if (loc != null && (best == null || loc.time > best!!.time)) best = loc
            }
            val loc = best ?: return@runCatching JSONObject().put("type", "gps_result").put("ok", false).put("error", "unavailable")
            JSONObject().put("type", "gps_result").put("ok", true).put("lat", loc.latitude).put("lon", loc.longitude).put("accuracy_m", loc.accuracy.toDouble()).put("time", loc.time)
        }.getOrElse {
            JSONObject().put("type", "gps_result").put("ok", false).put("error", "gps_exception")
        }
    }

    private fun showCameraRequest() {
        runCatching {
            val intent = Intent(this, WatchCameraActivity::class.java)
            val pending = PendingIntent.getActivity(this, CAMERA_NOTIFICATION_ID, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val n = NotificationCompat.Builder(this, CHANNEL_WATCH)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle("JARVIS Watch")
                .setContentText("O relógio pediu uma foto. Toque para abrir a câmera.")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(pending)
                .build()
            getSystemService(NotificationManager::class.java).notify(CAMERA_NOTIFICATION_ID, n)
            watchClient?.send(JSONObject().put("type", "camera_ready").put("ok", true).put("requires_user_action", true))
        }.onFailure {
            watchClient?.send(JSONObject().put("type", "camera_ready").put("ok", false).put("error", "camera_unavailable"))
        }
    }

    private fun showFamilyCallNotification(callId: Long, mode: String) {
        runCatching {
            val open = PendingIntent.getActivity(this, callId.toInt(), Intent(this, MainActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val n = NotificationCompat.Builder(this, CHANNEL_WATCH)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle("Chamada Família CASA")
                .setContentText("Chamada $mode iniciada pelo relógio (#$callId)")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()
            getSystemService(NotificationManager::class.java).notify((3000 + callId % 1000).toInt(), n)
        }
    }

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            runCatching { connectivity?.getNetworkCapabilities(network)?.let { evaluateCaps(it) } }
        }

        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            runCatching { evaluateCaps(caps) }
        }

        override fun onLost(network: Network) {
            jarvisOnline = false
            lastWifiState = false
            updateServiceNotification(withWatch("Offline — aguardando reconexão"))
            runCatching { sendPhoneState() }
        }
    }

    private fun evaluateCaps(caps: NetworkCapabilities) {
        val wifi = caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
        if (wifi != lastWifiState) {
            lastWifiState = wifi
            scope.launch {
                runCatching {
                    JarvisApi.sendNetworkEvent(
                        this@JarvisConnectionService,
                        if (wifi) "WIFI_CONNECTED" else "WIFI_DISCONNECTED",
                        if (wifi) "Celular conectado por Wi-Fi" else "Celular saiu do Wi-Fi",
                        JSONObject().put("ssid", if (wifi) currentSsid() else JSONObject.NULL)
                    )
                }
            }
        }
        sendPhoneState()
    }

    private fun startConnectionLoop() {
        scope.launch {
            var retryDelay = 5_000L
            var watchRetryAt = 0L

            while (isActive) {
                try {
                    if (hasBluetoothPermissions() && !WatchClient.isConnected(this@JarvisConnectionService) && System.currentTimeMillis() >= watchRetryAt) {
                        startWatchConnectionSafely()
                        watchRetryAt = System.currentTimeMillis() + 15_000L
                    }

                    if (!JarvisApi.isConfigured(this@JarvisConnectionService)) {
                        jarvisOnline = false
                        updateServiceNotification(withWatch("Configure URL e token do JARVIS"))
                        delay(10_000L)
                        continue
                    }

                    jarvisOnline = runCatching { JarvisApi.isOnline(this@JarvisConnectionService) }.getOrDefault(false)
                    runCatching { sendPhoneState() }

                    if (jarvisOnline) {
                        retryDelay = 5_000L
                        runCatching { JarvisApi.flushPending(this@JarvisConnectionService) }
                        runCatching {
                            FamilyApi.presence(
                                this@JarvisConnectionService,
                                "JARVIS Mobile",
                                JSONObject()
                                    .put("watch_connected", WatchClient.isConnected(this@JarvisConnectionService))
                                    .put("wifi", lastWifiState)
                            )
                        }
                        runCatching {
                            FamilyApi.poll(this@JarvisConnectionService, familyLastId).forEach { m ->
                                familyLastId = maxOf(familyLastId, m.id)
                                if (m.origin != "watch") {
                                    watchClient?.send(
                                        JSONObject()
                                            .put("type", "family_message")
                                            .put("id", m.id)
                                            .put("sender", m.sender)
                                            .put("message_type", m.type)
                                            .put("message", m.message)
                                    )
                                }
                            }
                        }
                        runCatching {
                            JarvisApi.getNotifications(this@JarvisConnectionService).forEach { n ->
                                showJarvisNotification(n)
                                JarvisApi.ackNotification(this@JarvisConnectionService, n.id)
                            }
                        }
                        updateServiceNotification(withWatch("Online — conectado ao JARVIS"))
                        runCatching { watchClient?.requestRssi() }
                        delay(10_000L)
                    } else {
                        updateServiceNotification(withWatch("Offline — tentando reconectar"))
                        delay(retryDelay)
                        retryDelay = (retryDelay * 2).coerceAtMost(30_000L)
                    }
                } catch (t: Throwable) {
                    updateServiceNotification("Serviço protegido — ${t.javaClass.simpleName}")
                    delay(5_000L)
                }
            }
        }
    }

    private fun showJarvisNotification(n: JarvisApi.MobileNotification) {
        runCatching {
            val open = PendingIntent.getActivity(this, n.id.toInt(), Intent(this, MainActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            val notification = NotificationCompat.Builder(this, CHANNEL_ALERTS)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle(n.title)
                .setContentText(n.message)
                .setStyle(NotificationCompat.BigTextStyle().bigText(n.message))
                .setPriority(if (n.priority == "critica") NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()
            getSystemService(NotificationManager::class.java).notify((2000 + (n.id % 100000)).toInt(), notification)
        }
    }
}
