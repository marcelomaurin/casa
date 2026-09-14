package br.com.maurinsoft.jarvismobile

import android.app.*
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*
import org.json.JSONObject

class JarvisConnectionService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var connectivity: ConnectivityManager
    private var bleBridge: BleBridge? = null
    private var lastWifiState = false
    @Volatile private var jarvisOnline = false

    companion object {
        const val CHANNEL_SERVICE = "jarvis_service"
        const val CHANNEL_ALERTS = "jarvis_alerts"
        const val NOTIFICATION_ID = 1001
    }

    override fun onCreate() {
        super.onCreate()
        createChannels()
        startForeground(NOTIFICATION_ID, serviceNotification("Inicializando conexão..."))

        connectivity = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        connectivity.registerDefaultNetworkCallback(networkCallback)
        bleBridge = BleBridge(this).also { runCatching { it.start() } }
        startConnectionLoop()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == WatchCameraActivity.ACTION_CAMERA_RESULT) {
            val ok = intent.getBooleanExtra(WatchCameraActivity.EXTRA_CAMERA_OK, false)
            val path = intent.getStringExtra(WatchCameraActivity.EXTRA_CAMERA_PATH).orEmpty()
            runCatching { bleBridge?.broadcastCameraResult(ok, path) }
            updateServiceNotification(withWatch(if (ok) "Foto capturada para o relógio" else "Captura de foto cancelada"))
        }
        return START_STICKY
    }

    override fun onDestroy() {
        try { connectivity.unregisterNetworkCallback(networkCallback) } catch (_: Exception) {}
        bleBridge?.stop()
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL_SERVICE, "Conexão JARVIS", NotificationManager.IMPORTANCE_LOW)
            )
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL_ALERTS, "Alertas JARVIS", NotificationManager.IMPORTANCE_HIGH)
            )
        }
    }

    private fun serviceNotification(text: String): Notification {
        val openIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, CHANNEL_SERVICE)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Mobile")
            .setContentText(text)
            .setOngoing(true)
            .setContentIntent(openIntent)
            .build()
    }

    private fun updateServiceNotification(text: String) {
        getSystemService(NotificationManager::class.java)
            .notify(NOTIFICATION_ID, serviceNotification(text))
    }

    private fun withWatch(text: String): String {
        val count = bleBridge?.connectedCount() ?: 0
        return if (count > 0) "$text • Watch conectado" else "$text • aguardando Watch"
    }

    private fun notifyWatchState() {
        runCatching { bleBridge?.broadcastStatus() }
    }

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            evaluateNetwork(network)
        }

        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            evaluateCaps(caps)
        }

        override fun onLost(network: Network) {
            jarvisOnline = false
            updateServiceNotification(withWatch("Offline — aguardando reconexão"))
            notifyWatchState()
            if (lastWifiState) {
                lastWifiState = false
                scope.launch {
                    runCatching {
                        JarvisApi.sendNetworkEvent(
                            this@JarvisConnectionService,
                            "WIFI_DISCONNECTED",
                            "Celular desconectou da rede Wi-Fi"
                        )
                    }
                }
            }
        }
    }

    private fun evaluateNetwork(network: Network) {
        connectivity.getNetworkCapabilities(network)?.let { evaluateCaps(it) }
    }

    private fun evaluateCaps(caps: NetworkCapabilities) {
        val wifi = caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
        if (wifi && !lastWifiState) {
            lastWifiState = true
            scope.launch {
                runCatching {
                    JarvisApi.sendNetworkEvent(
                        this@JarvisConnectionService,
                        "WIFI_CONNECTED",
                        "Celular conectado por Wi-Fi",
                        JSONObject().put(
                            "validated",
                            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
                        )
                    )
                }
            }
        }
    }

    private fun startConnectionLoop() {
        scope.launch {
            var retryDelay = 5_000L
            var lastPublishedState: Boolean? = null

            while (isActive) {
                if (!JarvisApi.isConfigured(this@JarvisConnectionService)) {
                    jarvisOnline = false
                    updateServiceNotification(withWatch("Configure a URL e o token do JARVIS"))
                    if (lastPublishedState != false) {
                        notifyWatchState()
                        lastPublishedState = false
                    }
                    delay(10_000L)
                    continue
                }

                val online = JarvisApi.isOnline(this@JarvisConnectionService)
                jarvisOnline = online

                if (lastPublishedState != online) {
                    notifyWatchState()
                    lastPublishedState = online
                }

                if (online) {
                    retryDelay = 5_000L
                    val sent = runCatching {
                        JarvisApi.flushPending(this@JarvisConnectionService)
                    }.getOrDefault(0)

                    val pending = JarvisApi.pendingCount(this@JarvisConnectionService)
                    val msg = when {
                        sent > 0 -> "Online — $sent comando(s) pendente(s) enviado(s)"
                        pending > 0 -> "Online — $pending comando(s) aguardando envio"
                        else -> "Online — conectado ao JARVIS"
                    }
                    updateServiceNotification(withWatch(msg))
                    notifyWatchState()

                    runCatching {
                        JarvisApi.getNotifications(this@JarvisConnectionService).forEach { n ->
                            showJarvisNotification(n)
                            JarvisApi.ackNotification(this@JarvisConnectionService, n.id)
                        }
                    }
                    delay(10_000L)
                } else {
                    val pending = JarvisApi.pendingCount(this@JarvisConnectionService)
                    updateServiceNotification(
                        withWatch(
                            if (pending > 0) "Offline — $pending comando(s) na fila; tentando reconectar"
                            else "Offline — tentando reconectar"
                        )
                    )
                    notifyWatchState()
                    delay(retryDelay)
                    retryDelay = (retryDelay * 2).coerceAtMost(30_000L)
                }
            }
        }
    }

    private fun showJarvisNotification(n: JarvisApi.MobileNotification) {
        val openIntent = PendingIntent.getActivity(
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
            .setPriority(if (n.priority == "critica") NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openIntent)
            .build()
        getSystemService(NotificationManager::class.java)
            .notify((2000 + (n.id % 100000)).toInt(), notification)
    }
}
