package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import androidx.core.app.ActivityCompat
import org.json.JSONObject

object PhoneSensorProvider {
    fun gpsPayload(context: Context): JSONObject {
        val canLocation =
            ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

        if (!canLocation) {
            return JSONObject()
                .put("type", "gps_result")
                .put("ok", false)
                .put("error", "permission_required")
        }

        return runCatching {
            val lm = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
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
}
