package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.wifi.WifiManager
import android.os.BatteryManager
import android.os.Build
import org.json.JSONObject
import java.net.Inet4Address
import java.net.NetworkInterface

object MobileTelemetry {
    fun collect(context: Context, watchOnlineCount: Int = 0, watchTotalCount: Int = 0): JSONObject {
        val root = JSONObject()

        // 1. Bateria
        runCatching {
            val intent = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            if (intent != null) {
                val level = intent.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                val scale = intent.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
                val status = intent.getIntExtra(BatteryManager.EXTRA_STATUS, -1)
                if (level >= 0 && scale > 0) {
                    val pct = (level * 100) / scale
                    root.put("battery", pct)
                    root.put("bateria_pct", pct)
                }
                val isCharging = status == BatteryManager.BATTERY_STATUS_CHARGING || status == BatteryManager.BATTERY_STATUS_FULL
                root.put("charging", isCharging)
            }
        }

        // 2. Modelo e Fabricante
        root.put("model", Build.MODEL)
        root.put("modelo", Build.MODEL)
        root.put("manufacturer", Build.MANUFACTURER)
        root.put("fabricante", Build.MANUFACTURER)
        root.put("android_version", Build.VERSION.RELEASE)

        // 3. Rede e Wi-Fi
        runCatching {
            val wm = context.applicationContext.getSystemService(Context.WIFI_SERVICE) as? WifiManager
            val info = wm?.connectionInfo
            @Suppress("DEPRECATION")
            val ssid = info?.ssid?.trim('"')?.takeUnless { it.isBlank() || it == "<unknown ssid>" }
            val rssi = info?.rssi
            val isWifi = ssid != null
            root.put("wifi", isWifi)
            root.put("network_type", if (isWifi) "wifi" else "cellular")
            if (ssid != null) {
                root.put("wifi_ssid", ssid)
                root.put("ssid", ssid)
            }
            if (rssi != null && rssi != -127) {
                root.put("rssi", rssi)
            }
        }

        // 4. IP Local
        runCatching {
            var localIp: String? = null
            val interfaces = NetworkInterface.getNetworkInterfaces()
            while (interfaces.hasMoreElements() && localIp == null) {
                val iface = interfaces.nextElement()
                if (iface.isLoopback || !iface.isUp) continue
                val addrs = iface.inetAddresses
                while (addrs.hasMoreElements()) {
                    val addr = addrs.nextElement()
                    if (addr is Inet4Address && !addr.isLoopbackAddress) {
                        localIp = addr.hostAddress
                        break
                    }
                }
            }
            if (localIp != null) root.put("local_ip", localIp)
        }

        // 5. Relogios conectados
        root.put("watch_online_count", watchOnlineCount)
        root.put("watch_registered_count", watchTotalCount)

        return root
    }
}
