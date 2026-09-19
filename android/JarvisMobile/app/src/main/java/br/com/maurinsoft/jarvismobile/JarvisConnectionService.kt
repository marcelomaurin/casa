package br.com.maurinsoft.jarvismobile

import android.app.*
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.IBinder
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*
import org.json.JSONObject

/**
 * Serviço central do JARVIS Mobile.
 *
 * O Watch usa socket TCP local quando celular e relógio estão na mesma LAN.
 * A CASA continua como fallback remoto para comandos e eventos.
 */
class JarvisConnectionService : Service(), WatchClient.Listener, WatchEventProcessor.Actions {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var jarvisOnline = false
    private var familyLastId = 0L
    private var watchEventAfter = 0L
    private var watchOnlineCount = 0
    private lateinit var localWatchClient: WatchClient
    private lateinit var watchCommands: WatchCommandDispatcher
    private lateinit var networkMonitor: JarvisNetworkMonitor
    private lateinit var watchEventProcessor: WatchEventProcessor
    private lateinit var notifier: JarvisServiceNotifier
    private var localWatchConnected = false
    private var reportedWifiState = false
    private val watchInitiatedCalls = linkedSetOf<Long>()

    companion object {
        const val CHANNEL_SERVICE = "jarvis_service"
        const val CHANNEL_ALERTS = "jarvis_alerts"
        const val CHANNEL_WATCH = "jarvis_watch"
        const val CHANNEL_WATCH_ALARM = "jarvis_watch_alarm_v1"
        const val NOTIFICATION_ID = 1001
        const val CAMERA_NOTIFICATION_ID = 1401
        const val VOICE_NOTIFICATION_ID = 1402
        const val ALARM_NOTIFICATION_ID = 1403

        const val ACTION_VOICE_RECOGNIZED = "br.com.maurinsoft.jarvismobile.action.WATCH_VOICE_RECOGNIZED"
        const val EXTRA_VOICE_TEXT = "watch_voice_text"
        const val EXTRA_VOICE_ERROR = "watch_voice_error"
        const val EXTRA_VOICE_DEVICE_ID = "watch_voice_device_id"

        private const val PREFS = "jarvis_connection_service"
        private const val KEY_WATCH_EVENT_AFTER = "watch_event_after"
    }

    override fun onCreate() {
        super.onCreate()

        watchEventAfter = getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong(KEY_WATCH_EVENT_AFTER, 0L)

        notifier = JarvisServiceNotifier(this)
        runCatching { notifier.createChannels() }
        runCatching {
            startForeground(
                NOTIFICATION_ID,
                notifier.serviceNotification("Inicializando JARVIS...")
            )
        }.onFailure {
            stopSelf()
            return
        }

        localWatchClient = WatchClient(this)
        watchCommands = WatchCommandDispatcher(this, localWatchClient) { localWatchConnected }

        networkMonitor = JarvisNetworkMonitor(
            this,
            object : JarvisNetworkMonitor.Listener {
                override fun onNetworkChanged(snapshot: JarvisNetworkMonitor.Snapshot) {
                    if (localWatchConnected) {
                        localWatchClient.sendPhoneState(
                            wifi = snapshot.wifi,
                            ssid = snapshot.ssid,
                            internet = snapshot.internet
                        )
                    }
                    if (snapshot.wifi != reportedWifiState) {
                        reportedWifiState = snapshot.wifi
                        scope.launch {
                            runCatching {
                                JarvisApi.sendNetworkEvent(
                                    this@JarvisConnectionService,
                                    if (snapshot.wifi) "WIFI_CONNECTED" else "WIFI_DISCONNECTED",
                                    if (snapshot.wifi) "Celular conectado por Wi-Fi" else "Celular saiu do Wi-Fi",
                                    JSONObject().put("ssid", snapshot.ssid ?: JSONObject.NULL)
                                )
                            }
                        }
                    }
                }

                override fun onNetworkLost() {
                    reportedWifiState = false
                    jarvisOnline = false
                    updateServiceNotification(withWatch("Offline — aguardando reconexão"))
                }
            }
        )
        watchEventProcessor = WatchEventProcessor(this, this)
        localWatchClient.addListener(this)

        if (!networkMonitor.start()) {
            updateServiceNotification("Rede indisponível — JARVIS continua em modo local")
        }
        localWatchClient.connectLanSaved()
        startConnectionLoop()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_VOICE_RECOGNIZED) {
            val deviceId = intent.getStringExtra(EXTRA_VOICE_DEVICE_ID).orEmpty()
            val text = intent.getStringExtra(EXTRA_VOICE_TEXT).orEmpty().trim()
            val error = intent.getStringExtra(EXTRA_VOICE_ERROR).orEmpty()
            if (deviceId.isNotBlank()) {
                scope.launch {
                    if (text.isNotBlank()) {
                        watchEventProcessor.processVoiceText(deviceId, text)
                    } else {
                        sendWatchCommand(
                            deviceId,
                            "voice_result",
                            JSONObject()
                                .put("type", "voice_result")
                                .put("ok", false)
                                .put("error", if (error.isBlank()) "speech_cancelled" else error),
                            priority = "normal",
                            ttlSeconds = 120
                        )
                    }
                }
            }
        }

        if (intent?.action == WatchCameraActivity.ACTION_CAMERA_RESULT) {
            val ok = intent.getBooleanExtra(WatchCameraActivity.EXTRA_CAMERA_OK, false)
            val path = intent.getStringExtra(WatchCameraActivity.EXTRA_CAMERA_PATH).orEmpty()
            val deviceId = intent.getStringExtra(WatchCameraActivity.EXTRA_WATCH_DEVICE_ID).orEmpty()

            if (deviceId.isNotBlank()) {
                scope.launch {
                    sendWatchCommand(
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
        return START_STICKY
    }

    override fun onDestroy() {
        if (::localWatchClient.isInitialized) {
            runCatching { localWatchClient.removeListener(this) }
            runCatching { localWatchClient.disconnect(false) }
        }
        if (::networkMonitor.isInitialized) networkMonitor.stop()
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onConnectionChanged(connected: Boolean, name: String, address: String) {
        localWatchConnected = connected
        if (connected) {
            val net = networkMonitor.snapshot
            localWatchClient.sendPhoneState(
                wifi = net.wifi,
                ssid = net.ssid,
                internet = net.internet
            )
        }
        updateServiceNotification(
            if (connected) "JARVIS online • Watch local conectado"
            else withWatch(if (jarvisOnline) "Online — conectado ao JARVIS" else "Watch local desconectado")
        )
    }

    override fun onWatchMessage(json: JSONObject) {
        val type = json.optString("type").trim()
        if (type.isBlank() || type == "hello" || type == "status" ||
            type.endsWith("_result") || type == "pong") return

        val deviceId = WatchClient.savedDeviceId(this)
        if (deviceId.isBlank()) return

        scope.launch {
            // O transporte local continua sendo o caminho mais rápido para a ação,
            // mas o evento também é espelhado no site para histórico/telemetria.
            runCatching {
                JarvisApi.sendWatchEvent(
                    this@JarvisConnectionService,
                    type,
                    json.optString("message", json.optString("text", "Evento do JARVIS Watch")),
                    JSONObject(json.toString())
                )
            }

            watchEventProcessor.handle(
                WatchApi.WatchEvent(
                    id = 0L,
                    deviceId = deviceId,
                    type = type,
                    priority = "normal",
                    correlationId = null,
                    data = json,
                    createdAt = ""
                )
            )
        }
    }

    override fun onError(message: String) {
        localWatchConnected = false
    }

    private fun updateServiceNotification(text: String) {
        runCatching { notifier.updateService(text) }
    }

    private fun withWatch(text: String): String =
        if (localWatchConnected) "$text • Watch local"
        else if (watchOnlineCount > 0) "$text • $watchOnlineCount Watch online"
        else "$text • Watch offline"

    override suspend fun sendWatchCommand(
        deviceId: String,
        command: String,
        payload: JSONObject,
        priority: String,
        ttlSeconds: Int
    ) {
        watchCommands.send(deviceId, command, payload, priority, ttlSeconds)
    }

    override fun showVoiceRequest(deviceId: String) {
        val ok = notifier.showVoiceRequest(deviceId)
        scope.launch {
            sendWatchCommand(
                deviceId,
                "voice_ready",
                JSONObject()
                    .put("type", "voice_ready")
                    .put("ok", ok)
                    .put("gateway", if (ok) "android" else JSONObject.NULL)
                    .put("requires_user_action", ok)
                    .put("accepts", if (ok) "voice_text" else JSONObject.NULL)
                    .put("audio_stream", false)
                    .apply {
                        if (!ok) put("error", if (notifier.canNotify()) "voice_gateway_unavailable" else "notification_permission_required")
                    },
                "normal",
                120
            )
        }
    }

    override fun showWatchAlarm(deviceId: String, data: JSONObject) {
        val ok = notifier.showWatchAlarm(data)
        scope.launch {
            sendWatchCommand(
                deviceId,
                "alarm_sound_result",
                JSONObject()
                    .put("type", "alarm_sound_result")
                    .put("ok", ok)
                    .apply {
                        if (ok) put("target", "phone")
                        else put("error", "phone_alarm_unavailable")
                    },
                "high",
                120
            )
        }
    }

    override suspend fun sendAssist(
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

    override fun showCameraRequest(deviceId: String) {
        val ok = notifier.showCameraRequest(deviceId)
        scope.launch {
            sendWatchCommand(
                deviceId,
                "camera_ready",
                JSONObject()
                    .put("type", "camera_ready")
                    .put("ok", ok)
                    .apply {
                        if (ok) put("requires_user_action", true)
                        else put("error", "camera_unavailable")
                    },
                "high",
                120
            )
        }
    }

    override fun showFamilyCallNotification(callId: Long, mode: String, initiator: Boolean) {
        if (initiator) {
            synchronized(watchInitiatedCalls) {
                watchInitiatedCalls += callId
                while (watchInitiatedCalls.size > 32) {
                    watchInitiatedCalls.remove(watchInitiatedCalls.first())
                }
            }
        }
        notifier.showFamilyCall(callId, mode, initiator)
    }

    override suspend fun acceptFamilyCall(deviceId: String, callId: Long, mode: String) {
        val accepted = runCatching {
            FamilyApi.joinCall(this@JarvisConnectionService, callId, "JARVIS Mobile", "watch")
            true
        }.getOrDefault(false)

        if (accepted) {
            // Android pode bloquear abertura de Activity em background; a notificação
            // permanece como fallback para o usuário abrir a mídia manualmente.
            notifier.showFamilyCall(callId, mode, false)
            val intent = Intent(this@JarvisConnectionService, FamilyCallActivity::class.java)
                .putExtra(FamilyCallActivity.EXTRA_CALL_ID, callId)
                .putExtra(FamilyCallActivity.EXTRA_CALL_MODE, mode)
                .putExtra(FamilyCallActivity.EXTRA_AUTO_JOIN, true)
                .putExtra(FamilyCallActivity.EXTRA_INITIATOR, false)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            runCatching { startActivity(intent) }
        }

        sendWatchCommand(
            deviceId,
            "family_call_control_result",
            JSONObject()
                .put("type", "family_call_control_result")
                .put("action", "accept")
                .put("call_id", callId)
                .put("ok", accepted),
            "high",
            120
        )
    }

    override suspend fun rejectFamilyCall(deviceId: String, callId: Long) {
        val rejected = runCatching {
            FamilyApi.endCall(this@JarvisConnectionService, callId)
            true
        }.getOrDefault(false)

        sendWatchCommand(
            deviceId,
            "family_call_control_result",
            JSONObject()
                .put("type", "family_call_control_result")
                .put("action", "reject")
                .put("call_id", callId)
                .put("ok", rejected),
            "high",
            120
        )
    }

    override fun networkSnapshot(): JarvisNetworkMonitor.Snapshot = networkMonitor.snapshot

    override fun jarvisOnline(): Boolean = jarvisOnline

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
                    if (!localWatchClient.isSocketConnected() && networkMonitor.snapshot.wifi) {
                        localWatchClient.connectLanSaved()
                    }
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
                                    .put("wifi", networkMonitor.snapshot.wifi)
                            )
                        }

                        runCatching {
                            FamilyApi.poll(
                                this@JarvisConnectionService,
                                familyLastId
                            ).forEach { m ->
                                familyLastId = maxOf(familyLastId, m.id)

                                if (m.type == "call") {
                                    val callId = m.data.optLong("call_id")
                                    val mode = m.data.optString("mode", "video")
                                    val targetPlatform = m.data.optString("target_platform")
                                    val localInitiator = synchronized(watchInitiatedCalls) {
                                        watchInitiatedCalls.contains(callId)
                                    }
                                    val directWatchNeedsBridge = m.origin == "watch" && targetPlatform == "web" && !localInitiator
                                    if (callId > 0 && (localInitiator || targetPlatform.isBlank() || targetPlatform == "mobile" || directWatchNeedsBridge)) {
                                        showFamilyCallNotification(callId, mode, localInitiator || directWatchNeedsBridge)
                                    }
                                }

                                if (m.origin != "watch") {
                                    val command = if (m.type == "call") "incoming_call" else "family_message"
                                    val payload = JSONObject()
                                        .put("type", if (m.type == "call") "incoming_call" else "family_message")
                                        .put("id", m.id)
                                        .put("sender", m.sender)
                                        .put("message_type", m.type)
                                        .put("message", m.message)
                                    if (m.type == "call") {
                                        payload
                                            .put("call_id", m.data.optLong("call_id"))
                                            .put("mode", m.data.optString("mode", "video"))
                                            .put("target_platform", m.data.optString("target_platform"))
                                    }
                                    WatchApi.enqueueToWatches(
                                        this@JarvisConnectionService,
                                        command,
                                        payload,
                                        priority = if (m.type == "call") "high" else "normal",
                                        ttlSeconds = if (m.type == "call") 300 else 3600
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
                            page.events.forEach { watchEventProcessor.handle(it) }
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
        notifier.showJarvisNotification(n)
    }

}
