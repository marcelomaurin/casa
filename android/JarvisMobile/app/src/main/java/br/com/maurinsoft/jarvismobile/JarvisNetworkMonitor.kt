package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.wifi.WifiManager

class JarvisNetworkMonitor(
    private val context: Context,
    private val listener: Listener
) {
    data class Snapshot(
        val wifi: Boolean,
        val internet: Boolean,
        val ssid: String?
    )

    interface Listener {
        fun onNetworkChanged(snapshot: Snapshot)
        fun onNetworkLost()
    }

    private val connectivity =
        context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
    private var registered = false
    private var lastSnapshot = Snapshot(false, false, null)

    val snapshot: Snapshot
        get() = lastSnapshot

    fun start(): Boolean = runCatching {
        connectivity.registerDefaultNetworkCallback(callback)
        registered = true
        connectivity.activeNetwork?.let { network ->
            connectivity.getNetworkCapabilities(network)?.let { publish(it) }
        }
        true
    }.getOrDefault(false)

    fun stop() {
        if (registered) runCatching { connectivity.unregisterNetworkCallback(callback) }
        registered = false
    }

    private val callback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            connectivity.getNetworkCapabilities(network)?.let { publish(it) }
        }

        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            publish(caps)
        }

        override fun onLost(network: Network) {
            lastSnapshot = Snapshot(false, false, null)
            listener.onNetworkLost()
        }
    }

    private fun publish(caps: NetworkCapabilities) {
        val wifi = caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
        val internet = caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
        val next = Snapshot(wifi, internet, if (wifi) currentSsid() else null)
        if (next != lastSnapshot) {
            lastSnapshot = next
            listener.onNetworkChanged(next)
        }
    }

    private fun currentSsid(): String? = runCatching {
        val wm = context.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        @Suppress("DEPRECATION")
        val ssid = wm.connectionInfo?.ssid?.trim('"')
        ssid?.takeUnless { it.isBlank() || it == "<unknown ssid>" }
    }.getOrNull()
}
