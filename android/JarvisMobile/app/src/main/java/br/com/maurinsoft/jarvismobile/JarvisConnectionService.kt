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

    companion object {
        const val CHANNEL_SERVICE = "jarvis_service"
        const val CHANNEL_ALERTS = "jarvis_alerts"
        const val NOTIFICATION_ID = 1001
    }

    override fun onCreate() {
        super.onCreate()
        createChannels()
        startForeground(
            NOTIFICATION_ID,
            NotificationCompat.Builder(this, CHANNEL_SERVICE)
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setContentTitle("JARVIS Mobile")
                .setContentText("Conectado ao JARVIS e ao relógio")
                .setOngoing(true)
                .build()
        )

        connectivity = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        connectivity.registerDefaultNetworkCallback(networkCallback)
        bleBridge = BleBridge(this).also { it.start() }
        startNotificationLoop()
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

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) = evaluateNetwork(network)
        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) = evaluateCaps(caps)
        override fun onLost(network: Network) {
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
                        JSONObject().put("validated", caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED))
                    )
                }
            }
        }
    }

    private fun startNotificationLoop() {
        scope.launch {
            while (isActive) {
                runCatching {
                    JarvisApi.getNotifications(this@JarvisConnectionService).forEach { n ->
                        showJarvisNotification(n)
                        JarvisApi.ackNotification(this@JarvisConnectionService, n.id)
                    }
                }
                delay(10_000)
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
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(n.title)
            .setContentText(n.message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(n.message))
            .setPriority(if (n.priority == "critica") NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openIntent)
            .build()
        getSystemService(NotificationManager::class.java).notify((2000 + (n.id % 100000)).toInt(), notification)
    }
}
