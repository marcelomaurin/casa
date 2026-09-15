package br.com.maurinsoft.jarvismobile

import android.app.*
import android.content.Context
import android.content.Intent
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

/**
 * Serviço central do JARVIS Mobile.
 *
 * O BLE fica reservado ao cadastro/provisionamento em WatchSetupActivity.
 * Depois de provisionado, o relógio conversa com a CASA por Wi-Fi/HTTPS.
 * Este serviço acompanha o Control Plane da CASA para atender recursos que
 * pertencem ao celular: notificações, GPS, câmera, voz e canal da família.
 */
class JarvisConnectionService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var connectivity: ConnectivityManager? = null
    private var lastWifiState = false
    private var jarvisOnline = false
    private var familyLastId = 0L
    private var watchEventAfter = 0L
    private var watchOnlineCount = 0
    private var networkCallbackRegistered = false

    companion object {
        const val CHANNEL_SERVICE = "jarvis_service"
        const val CHANNEL_ALERTS = "jarvis_alerts"
        const val CHANNEL_WATCH = "jarvis_watch"
        const val NOTIFICATION_ID = 1001
        const val CAMERA_NOTIFICATION_ID = 1401

        private const val PREFS = "jarvis_connection_service"
        private const val KEY_WATCH_EVENT_AFTER = "watch_event_after"
    }

    override fun onCreate() {
        super.onCreate()

        watchEventAfter = getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong(KEY_WATCH_EVENT_AFTER, 0L)

        runCatching { createChannels() }
        runCatching {
            startForeground(
                NOTIFICATION_ID,
                serviceNotification("Inicializando JARVIS...")
            )
        }.onFailure {
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

        startConnectionLoop()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == WatchCameraActivity.ACTION_CAMERA_RESULT) {
            val ok = intent.getBooleanExtra(WatchCameraActivity.EXTRA_CAMERA_OK, false)
            val path = intent.getStringExtra(WatchCameraActivity.EXTRA_CAMERA_PATH).orEmpty()
            val deviceId = intent.getStringExtra(WatchCameraActivity.EXTRA_WATCH_DEVICE_ID).orEmpty()

            if (deviceId.isNotBlank()) {
                scope.launch {
                    runCatching {
                        WatchApi.enqueue(
                            this@JarvisConnectionService,
                            deviceId,
                            "camera_result",
                            JSONObject()
                                .put("type", "camera_result")
                                .put("ok", ok)
                                .put("path", if (ok) path else JSONObject.NULL),
                            priority = "high",
                            ttlSeconds = 120
                        )
                    }
                }
            }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        if (networkCallbackRegistered) {
            runCatching { connectivity?.unregisterNetworkCallback(networkCallback) }
        }
        connectivity = null
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_SERVICE,
                    "Conexão JARVIS",
                    NotificationManager.IMPORTANCE_LOW
                )
            )
            nm.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_ALERTS,
                    "Alertas JARVIS",
                    NotificationManager.IMPORTANCE_HIGH
                )
            )
            nm.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_WATCH,
                    "JARVIS Watch",
                    NotificationManager.IMPORTANCE_HIGH
                )
            )
        }
    }

    private fun serviceNotification(text: String): Notification {
        val openMain = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val setup = PendingIntent.getActivity(
            this,
            1,
            Intent(this, WatchSetupActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
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
        if (watchOnlineCount > 0) "$text • $watchOnlineCount Watch online"
        else "$text • Watch offline"

    private fun currentSsid(): String? = runCatching {
        val wm = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION")
        val s = wm.connectionInfo?.ssid?.trim('"')
        s?.takeUnless { it.isBlank() || it == "<unknown ssid>" }
    }.getOrNull()

    private fun canLocation(): Boolean =
        ActivityCompat.checkSelfPermission(
            this,
            android.Manifest.permission.ACCESS_FINE_LOCATION
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED ||
            ActivityCompat.checkSelfPermission(
                this,
                android.Manifest.permission.ACCESS_COARSE_LOCATION
            ) == android.content.pm.PackageManager.PERMISSION_GRANTED

    private fun gpsPayload(): JSONObject {
        if (!canLocation()) {
            return JSONObject()
                .put("type", "gps_result")
                .put("ok", false)
                .put("error", "permission_required")
        }

        return runCatching {
            val lm = getSystemService(Context.LOCATION_SERVICE) as LocationManager
            var best: Location? = null
            listOf(
                LocationManager.GPS_PROVIDER,
                LocationManager.NETWORK_PROVIDER,
                LocationManager.PASSIVE_PROVIDER
            ).forEach { provider ->
                val loc = runCatching { lm.getLastKnownLocation(provider) }.getOrNull()
                if (loc != null && (best == null || loc.time > best!!.time)) best = loc
            }

            val loc = best ?: return@runCatching JSONObject()
                .put("type", "gps_result")
                .put("ok", false)
                .put("error", "unavailable")

            JSONObject()
                .put("type", "gps_result")
                .put("ok", true)
                .put("lat", loc.latitude)
                .put("lon", loc.longitude)
                .put("accuracy_m", loc.accuracy.toDouble())
                .put("time", loc.time)
        }.getOrElse {
            JSONObject()
                .put("type", "gps_result")
                .put("ok", false)
                .put("error", "gps_exception")
        }
    }

    private suspend fun sendWatchCommand(
        deviceId: String,
        command: String,
        payload: JSONObject,
        priority: String = "normal",
        ttlSeconds: Int = 300
    ) {
        runCatching {
            WatchApi.enqueue(
                this@JarvisConnectionService,
                deviceId,
                command,
                payload,
                priority,
                ttlSeconds
            )
        }
    }

    private suspend fun handleWatchEvent(event: WatchApi.WatchEvent) {
        val declaredType = event.data.optString("type").trim()
        val type = (if (declaredType.isNotBlank()) declaredType else event.type)
            .substringAfterLast('.')
            .lowercase()

        when (type) {
            "sos" -> sendAssist(
                "SOS",
                "critica",
                "SOS acionado no JARVIS Watch",
                event.data
            )

            "inactivity" -> sendAssist(
                "INACTIVITY",
                "alta",
                "Relógio detectou período prolongado sem movimento",
                event.data
            )

            "checkin" -> sendAssist(
                "CHECKIN",
                if (event.data.optBoolean("ok", true)) "info" else "alta",
                "Check-in do JARVIS Watch",
                event.data
            )

            "movement", "movement_heartbeat" -> sendAssist(
                "MOVEMENT_HEARTBEAT",
                "info",
                "Telemetria de movimento do JARVIS Watch",
                event.data
            )

            "family_message" -> {
                FamilyApi.sendMessage(
                    this@JarvisConnectionService,
                    event.data.optString("message"),
                    event.data.optString("message_type", "texto"),
                    event.data
                )
            }

            "family_call" -> {
                val mode = if (event.data.optString("mode") == "audio") "audio" else "video"
                val callId = runCatching {
                    FamilyApi.startCall(
                        this@JarvisConnectionService,
                        mode,
                        "watch"
                    )
                }.getOrDefault(0L)

                sendWatchCommand(
                    event.deviceId,
                    "family_call_result",
                    JSONObject()
                        .put("type", "family_call_result")
                        .put("ok", callId > 0)
                        .put("call_id", callId)
                        .put("mode", mode),
                    "high",
                    300
                )

                if (callId > 0) showFamilyCallNotification(callId, mode)
            }

            "voice_text" -> {
                val text = event.data.optString("text").trim()
                if (text.isNotBlank()) {
                    val result = JarvisApi.sendOrQueue(
                        this@JarvisConnectionService,
                        text
                    )
                    sendWatchCommand(
                        event.deviceId,
                        "jarvis_result",
                        JSONObject()
                            .put("type", "jarvis_result")
                            .put("ok", result.delivered)
                            .put("queued", result.queued)
                            .put("text", result.answer?.text ?: result.message)
                            .put(
                                "audio_url",
                                result.answer?.audioUrl ?: JSONObject.NULL
                            ),
                        "normal",
                        300
                    )
                }
            }

            "gps_request" -> {
                sendWatchCommand(
                    event.deviceId,
                    "gps_result",
                    gpsPayload(),
                    "normal",
                    120
                )
            }

            "camera_capture" -> showCameraRequest(event.deviceId)

            "phone_state", "phone_state_request" -> {
                val caps = runCatching {
                    connectivity?.getNetworkCapabilities(connectivity?.activeNetwork)
                }.getOrNull()
                val wifi = caps?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true
                val internet =
                    caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true

                sendWatchCommand(
                    event.deviceId,
                    "phone_state",
                    JSONObject()
                        .put("type", "phone_state")
                        .put("wifi", wifi)
                        .put("wifi_ssid", if (wifi) currentSsid() else JSONObject.NULL)
                        .put("internet", internet)
                        .put("jarvis_online", jarvisOnline),
                    "low",
                    120
                )
            }

            "ping" -> sendWatchCommand(
                event.deviceId,
                "pong",
                JSONObject()
                    .put("type", "pong")
                    .put("internet", jarvisOnline),
                "low",
                60
            )
        }
    }

    private suspend fun sendAssist(
        type: String,
        severity: String,
        message: String,
        data: JSONObject
    ) {
        runCatching {
            FamilyApi.assistEvent(
                this@JarvisConnectionService,
                type,
                severity,
                message,
                data,
                device = "JARVIS Watch"
            )
        }
    }

    private fun showCameraRequest(deviceId: String) {
        runCatching {
            val intent = Intent(this, WatchCameraActivity::class.java)
                .putExtra(WatchCameraActivity.EXTRA_WATCH_DEVICE_ID, deviceId)

            val pending = PendingIntent.getActivity(
                this,
                CAMERA_NOTIFICATION_ID,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

            val n = NotificationCompat.Builder(this, CHANNEL_WATCH)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle("JARVIS Watch")
                .setContentText("O relógio pediu uma foto. Toque para abrir a câmera.")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(pending)
                .build()

            getSystemService(NotificationManager::class.java)
                .notify(CAMERA_NOTIFICATION_ID, n)

            scope.launch {
                sendWatchCommand(
                    deviceId,
                    "camera_ready",
                    JSONObject()
                        .put("type", "camera_ready")
                        .put("ok", true)
                        .put("requires_user_action", true),
                    "high",
                    120
                )
            }
        }.onFailure {
            scope.launch {
                sendWatchCommand(
                    deviceId,
                    "camera_ready",
                    JSONObject()
                        .put("type", "camera_ready")
                        .put("ok", false)
                        .put("error", "camera_unavailable"),
                    "high",
                    120
                )
            }
        }
    }

    private fun showFamilyCallNotification(callId: Long, mode: String) {
        runCatching {
            val open = PendingIntent.getActivity(
                this,
                callId.toInt(),
                Intent(this, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

            val n = NotificationCompat.Builder(this, CHANNEL_WATCH)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle("Chamada Família CASA")
                .setContentText("Chamada $mode iniciada pelo relógio (#$callId)")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()

            getSystemService(NotificationManager::class.java)
                .notify((3000 + callId % 1000).toInt(), n)
        }
    }

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            runCatching {
                connectivity?.getNetworkCapabilities(network)?.let { evaluateCaps(it) }
            }
        }

        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            runCatching { evaluateCaps(caps) }
        }

        override fun onLost(network: Network) {
            jarvisOnline = false
            lastWifiState = false
            updateServiceNotification(withWatch("Offline — aguardando reconexão"))
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
                        JSONObject()
                            .put("ssid", if (wifi) currentSsid() else JSONObject.NULL)
                    )
                }
            }
        }
    }

    private fun saveWatchEventCursor(value: Long) {
        if (value <= watchEventAfter) return
        watchEventAfter = value
        getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putLong(KEY_WATCH_EVENT_AFTER, value)
            .apply()
    }

    private fun startConnectionLoop() {
        scope.launch {
            var retryDelay = 5_000L

            while (isActive) {
                try {
                    if (!JarvisApi.isConfigured(this@JarvisConnectionService)) {
                        jarvisOnline = false
                        watchOnlineCount = 0
                        updateServiceNotification("Configure URL e token do JARVIS")
                        delay(10_000L)
                        continue
                    }

                    jarvisOnline = runCatching {
                        JarvisApi.isOnline(this@JarvisConnectionService)
                    }.getOrDefault(false)

                    if (jarvisOnline) {
                        retryDelay = 5_000L

                        runCatching {
                            JarvisApi.flushPending(this@JarvisConnectionService)
                        }

                        val watches = runCatching {
                            WatchApi.listWatches(this@JarvisConnectionService)
                        }.getOrDefault(emptyList())
                        watchOnlineCount = watches.count { it.online }

                        runCatching {
                            FamilyApi.presence(
                                this@JarvisConnectionService,
                                "JARVIS Mobile",
                                JSONObject()
                                    .put("watch_online_count", watchOnlineCount)
                                    .put("watch_registered_count", watches.size)
                                    .put("wifi", lastWifiState)
                            )
                        }

                        runCatching {
                            FamilyApi.poll(
                                this@JarvisConnectionService,
                                familyLastId
                            ).forEach { m ->
                                familyLastId = maxOf(familyLastId, m.id)
                                if (m.origin != "watch") {
                                    WatchApi.enqueueToWatches(
                                        this@JarvisConnectionService,
                                        "family_message",
                                        JSONObject()
                                            .put("type", "family_message")
                                            .put("id", m.id)
                                            .put("sender", m.sender)
                                            .put("message_type", m.type)
                                            .put("message", m.message),
                                        priority = "normal",
                                        ttlSeconds = 3600
                                    )
                                }
                            }
                        }

                        runCatching {
                            val page = WatchApi.pollEventPage(
                                this@JarvisConnectionService,
                                watchEventAfter,
                                100
                            )
                            page.events.forEach { handleWatchEvent(it) }
                            saveWatchEventCursor(page.nextAfter)
                        }

                        runCatching {
                            JarvisApi.getNotifications(this@JarvisConnectionService)
                                .forEach { n ->
                                    showJarvisNotification(n)
                                    WatchApi.forwardNotification(
                                        this@JarvisConnectionService,
                                        packageName,
                                        n.title,
                                        n.message,
                                        if (n.priority == "critica") "high" else "normal"
                                    )
                                    JarvisApi.ackNotification(
                                        this@JarvisConnectionService,
                                        n.id
                                    )
                                }
                        }

                        updateServiceNotification(
                            withWatch("Online — conectado ao JARVIS")
                        )
                        delay(8_000L)
                    } else {
                        watchOnlineCount = 0
                        updateServiceNotification(
                            withWatch("Offline — tentando reconectar")
                        )
                        delay(retryDelay)
                        retryDelay = (retryDelay * 2).coerceAtMost(30_000L)
                    }
                } catch (t: Throwable) {
                    updateServiceNotification(
                        "Serviço protegido — ${t.javaClass.simpleName}"
                    )
                    delay(5_000L)
                }
            }
        }
    }

    private fun showJarvisNotification(n: JarvisApi.MobileNotification) {
        runCatching {
            val open = PendingIntent.getActivity(
                this,
                n.id.toInt(),
                Intent(this, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )

            val notification = NotificationCompat.Builder(this, CHANNEL_ALERTS)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle(n.title)
                .setContentText(n.message)
                .setStyle(NotificationCompat.BigTextStyle().bigText(n.message))
                .setPriority(
                    if (n.priority == "critica")
                        NotificationCompat.PRIORITY_MAX
                    else
                        NotificationCompat.PRIORITY_HIGH
                )
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()

            getSystemService(NotificationManager::class.java)
                .notify((2000 + (n.id % 100000)).toInt(), notification)
        }
    }
}
