package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkRequest
import android.net.NetworkCapabilities
import android.net.wifi.WifiNetworkSpecifier
import android.os.Build
import org.json.JSONObject
import java.io.BufferedReader
import java.io.BufferedWriter
import java.io.InputStreamReader
import java.io.OutputStreamWriter
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.CopyOnWriteArrayList

/**
 * Transporte local do JARVIS Watch sem Bluetooth.
 *
 * Watch:
 *   SoftAP: JARVIS-WATCH
 *   IP:     192.168.4.1
 *   TCP:    4040
 *
 * Protocolo: JSON UTF-8, uma mensagem por linha.
 */
class WatchClient(private val context: Context) {
    companion object {
        const val WATCH_NAME = "JARVIS Watch"
        const val AP_SSID = "JARVIS-WATCH"
        const val AP_PASSWORD = "JarvisSetup2026"
        const val WATCH_HOST = "192.168.4.1"
        const val WATCH_PORT = 4040

        private const val PREFS = "jarvis_watch_client"
        private const val KEY_ADDRESS = "watch_address"
        private const val KEY_NAME = "watch_name"
        private const val KEY_CONNECTED = "connected"
        private const val KEY_LAST_SEEN = "last_seen"

        fun savedAddress(context: Context): String =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getString(KEY_ADDRESS, WATCH_HOST) ?: WATCH_HOST

        fun savedName(context: Context): String =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getString(KEY_NAME, WATCH_NAME) ?: WATCH_NAME

        fun isConnected(context: Context): Boolean =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getBoolean(KEY_CONNECTED, false)

        fun lastRssi(context: Context): Int = 0

        fun lastSeen(context: Context): Long =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getLong(KEY_LAST_SEEN, 0L)
    }

    data class FoundWatch(val name: String, val address: String, val rssi: Int)

    interface Listener {
        fun onScanResult(watch: FoundWatch) {}
        fun onConnectionChanged(connected: Boolean, name: String, address: String) {}
        fun onWatchMessage(json: JSONObject) {}
        fun onError(message: String) {}
    }

    private val listeners = CopyOnWriteArrayList<Listener>()
    private val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

    @Volatile private var socket: Socket? = null
    @Volatile private var writer: BufferedWriter? = null
    @Volatile private var readerThread: Thread? = null
    @Volatile private var watchNetwork: Network? = null
    @Volatile private var requestingNetwork = false
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private val ioLock = Any()

    fun addListener(listener: Listener) { listeners += listener }
    fun removeListener(listener: Listener) { listeners -= listener }

    /**
     * No Android 10+ abre o seletor do sistema para a rede JARVIS-WATCH.
     * Em versões antigas, o usuário conecta manualmente à rede e o app abre o socket.
     */
    fun scan() {
        if (Build.VERSION.SDK_INT >= 29) {
            requestWatchNetwork()
        } else {
            listeners.forEach { it.onScanResult(FoundWatch(WATCH_NAME, WATCH_HOST, 0)) }
            connect(WATCH_HOST)
        }
    }

    fun stopScan() {
        // A rede solicitada precisa permanecer ativa enquanto o socket estiver em uso.
    }

    private fun requestWatchNetwork() {
        if (requestingNetwork || socket?.isConnected == true) return

        val specifier = WifiNetworkSpecifier.Builder()
            .setSsid(AP_SSID)
            .setWpa2Passphrase(AP_PASSWORD)
            .build()

        val request = NetworkRequest.Builder()
            .addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
            .removeCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .setNetworkSpecifier(specifier)
            .build()

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                requestingNetwork = false
                watchNetwork = network
                listeners.forEach { it.onScanResult(FoundWatch(WATCH_NAME, WATCH_HOST, 0)) }
                connectSocket(network)
            }

            override fun onLost(network: Network) {
                if (watchNetwork == network) {
                    watchNetwork = null
                    closeSocket(true)
                }
            }

            override fun onUnavailable() {
                requestingNetwork = false
                listeners.forEach {
                    it.onError("Rede $AP_SSID não selecionada/encontrada")
                }
            }
        }

        networkCallback?.let { runCatching { cm.unregisterNetworkCallback(it) } }
        networkCallback = callback
        requestingNetwork = true
        runCatching { cm.requestNetwork(request, callback) }
            .onFailure {
                requestingNetwork = false
                listeners.forEach { l -> l.onError("Falha ao solicitar rede do Watch: ${it.message}") }
            }
    }

    fun connect(address: String) {
        val existing = socket
        if (existing != null && existing.isConnected && !existing.isClosed) {
            markConnected()
            return
        }

        val network = watchNetwork
        if (network != null) {
            connectSocket(network)
        } else if (Build.VERSION.SDK_INT >= 29) {
            requestWatchNetwork()
        } else {
            connectSocket(null)
        }
    }

    fun connectSaved(): Boolean {
        connect(savedAddress(context))
        return true
    }

    private fun connectSocket(network: Network?) {
        Thread {
            try {
                closeSocketInternal()
                val s = if (network != null) {
                    network.socketFactory.createSocket()
                } else {
                    Socket()
                }
                s.connect(InetSocketAddress(WATCH_HOST, WATCH_PORT), 5000)
                s.tcpNoDelay = true
                s.keepAlive = true

                val w = BufferedWriter(OutputStreamWriter(s.getOutputStream(), Charsets.UTF_8))
                synchronized(ioLock) {
                    socket = s
                    writer = w
                }

                markConnected()
                startReader(s)
                send(JSONObject()
                    .put("type", "hello")
                    .put("protocol", "TCP-1.0")
                    .put("client", "JARVIS Mobile"))
                requestStatus()
            } catch (t: Throwable) {
                closeSocket(false)
                listeners.forEach {
                    it.onError("Falha ao conectar ao Watch em $WATCH_HOST:$WATCH_PORT: ${t.message}")
                }
            }
        }.start()
    }

    private fun startReader(s: Socket) {
        readerThread = Thread {
            try {
                val reader = BufferedReader(InputStreamReader(s.getInputStream(), Charsets.UTF_8))
                while (!s.isClosed) {
                    val line = reader.readLine() ?: break
                    if (line.isBlank()) continue
                    val json = runCatching { JSONObject(line) }
                        .getOrElse { JSONObject().put("type", "text").put("text", line) }
                    val hardwareId = json.optString("hardware_id").trim()
                    val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                        .putLong(KEY_LAST_SEEN, System.currentTimeMillis())
                    if (hardwareId.isNotBlank()) prefs.putString(KEY_ADDRESS, hardwareId)
                    prefs.apply()
                    listeners.forEach { it.onWatchMessage(json) }
                }
            } catch (_: Throwable) {
            } finally {
                if (socket === s) closeSocket(true)
            }
        }.apply {
            name = "JarvisWatchTcpReader"
            isDaemon = true
            start()
        }
    }

    private fun markConnected() {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_ADDRESS, WATCH_HOST)
            .putString(KEY_NAME, WATCH_NAME)
            .putBoolean(KEY_CONNECTED, true)
            .putLong(KEY_LAST_SEEN, System.currentTimeMillis())
            .apply()
        listeners.forEach { it.onConnectionChanged(true, WATCH_NAME, WATCH_HOST) }
    }

    fun disconnect(forget: Boolean = false) {
        closeSocket(false)
        networkCallback?.let { runCatching { cm.unregisterNetworkCallback(it) } }
        networkCallback = null
        watchNetwork = null
        requestingNetwork = false

        val e = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putBoolean(KEY_CONNECTED, false)
        if (forget) e.remove(KEY_ADDRESS).remove(KEY_NAME)
        e.apply()
    }

    private fun closeSocket(notify: Boolean) {
        closeSocketInternal()
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putBoolean(KEY_CONNECTED, false).apply()
        if (notify) {
            listeners.forEach {
                it.onConnectionChanged(false, savedName(context), savedAddress(context))
            }
        }
    }

    private fun closeSocketInternal() {
        synchronized(ioLock) {
            runCatching { writer?.flush() }
            runCatching { socket?.close() }
            writer = null
            socket = null
        }
    }

    fun requestRssi() {}

    fun send(json: JSONObject): Boolean {
        synchronized(ioLock) {
            val w = writer ?: return false
            val s = socket ?: return false
            if (!s.isConnected || s.isClosed) return false
            return try {
                w.write(json.toString())
                w.newLine()
                w.flush()
                true
            } catch (t: Throwable) {
                listeners.forEach { it.onError("Falha no socket do Watch: ${t.message}") }
                false
            }
        }
    }

    fun sendPhoneState(wifi: Boolean, ssid: String?, internet: Boolean): Boolean =
        send(JSONObject()
            .put("type", "phone_state")
            .put("bluetooth", false)
            .put("wifi", wifi)
            .put("wifi_ssid", ssid ?: JSONObject.NULL)
            .put("internet", internet)
            .put("jarvis_online", JarvisApi.isConfigured(context)))

    fun provisionWifi(slot: Int, ssid: String, password: String): Boolean =
        send(JSONObject()
            .put("type", "wifi_profile")
            .put("slot", slot)
            .put("ssid", ssid)
            .put("password", password))

    fun provisionDeviceIdentity(deviceId: String): Boolean =
        send(JSONObject()
            .put("type", "device_identity")
            .put("device_id", deviceId))

    fun provisionCasa(baseUrl: String, watchDeviceToken: String): Boolean =
        send(JSONObject()
            .put("type", "casa_config")
            .put("base_url", baseUrl)
            .put("device_token", watchDeviceToken))

    fun connectWifiProfile(slot: Int): Boolean =
        send(JSONObject().put("type", "wifi_connect").put("slot", slot))

    fun requestStatus(): Boolean = send(JSONObject().put("type", "status"))
    fun findWatch(): Boolean = send(JSONObject().put("type", "find_watch"))
}
