package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.location.LocationListener
import android.os.Bundle
import android.os.Looper
import android.os.SystemClock
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume
import androidx.core.app.ActivityCompat
import org.json.JSONObject

object PhoneSensorProvider {
    // Ask for a new fix, but never hold the event processor indefinitely.
    suspend fun freshGpsPayload(context: Context): JSONObject {
        val fallback = gpsPayload(context)
        if (fallback.optString("error") == "permission_required") return fallback
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        val fresh = withTimeoutOrNull(20000L) {
            suspendCancellableCoroutine<Location?> { continuation ->
                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        runCatching { lm.removeUpdates(this) }
                        if (continuation.isActive) continuation.resume(location)
                    }
                    override fun onProviderEnabled(provider: String) {}
                    override fun onProviderDisabled(provider: String) {}
                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                }
                continuation.invokeOnCancellation { runCatching { lm.removeUpdates(listener) } }
                val provider = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
                    .firstOrNull { runCatching { lm.isProviderEnabled(it) }.getOrDefault(false) }
                if (provider == null) continuation.resume(null)
                else runCatching { lm.requestSingleUpdate(provider, listener, Looper.getMainLooper()) }
                    .onFailure { if (continuation.isActive) continuation.resume(null) }
            }
        }
        val result = fresh?.let { locationPayload(it) } ?: gpsPayload(context)
        if (result.optBoolean("ok") && result.optLong("age_ms", Long.MAX_VALUE) > 120000L)
            return JSONObject().put("type", "gps_result").put("ok", false).put("error", "stale_location")
        return result
    }

    private fun locationPayload(loc: Location): JSONObject = JSONObject()
        .put("type", "gps_result").put("ok", true)
        .put("lat", loc.latitude).put("lon", loc.longitude)
        .put("accuracy_m", loc.accuracy.toDouble()).put("time", loc.time)
        .put("age_ms", ((SystemClock.elapsedRealtimeNanos() - loc.elapsedRealtimeNanos) / 1000000L).coerceAtLeast(0L))

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

            locationPayload(loc)
        }.getOrElse {
            JSONObject()
                .put("type", "gps_result")
                .put("ok", false)
                .put("error", "gps_exception")
        }
    }
}
