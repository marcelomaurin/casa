package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.bluetooth.*
import android.bluetooth.le.AdvertiseCallback
import android.bluetooth.le.AdvertiseData
import android.bluetooth.le.AdvertiseSettings
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Build
import android.os.ParcelUuid
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.util.UUID

class BleBridge(private val context: Context) {
    companion object {
        val SERVICE_UUID: UUID = UUID.fromString("7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001")
        val RX_UUID: UUID = UUID.fromString("7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001")
        val TX_UUID: UUID = UUID.fromString("7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001")
        val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")
        const val PROTOCOL_VERSION = "1.1"
        const val ADVERTISED_NAME = "JARVIS-PHONE"

        const val PREFS = "jarvis_watch_bridge"
        const val KEY_CONNECTED = "connected_count"
        const val KEY_LAST_WATCH = "last_watch"
        const val KEY_LAST_SEEN = "last_seen"

        const val ACTION_WATCH_CAMERA = "br.com.maurinsoft.jarvismobile.WATCH_CAMERA"
        const val EXTRA_WATCH_REQUEST = "watch_request"
        private const val CHANNEL_WATCH = "jarvis_watch_actions"
        private const val CAMERA_NOTIFICATION_ID = 1401

        fun connectedCount(context: Context): Int =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getInt(KEY_CONNECTED, 0)

        fun lastWatch(context: Context): String =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_LAST_WATCH, "") ?: ""

        fun lastSeen(context: Context): Long =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong(KEY_LAST_SEEN, 0L)
    }

    private val bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
    private val adapter get() = bluetoothManager.adapter
    private var gattServer: BluetoothGattServer? = null
    private var tx: BluetoothGattCharacteristic? = null
    private val connected = mutableSetOf<BluetoothDevice>()
    private val scope = CoroutineScope(Dispatchers.IO)

    private fun canBluetooth(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

    private fun canAdvertise(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_ADVERTISE) == PackageManager.PERMISSION_GRANTED

    private fun canLocation(): Boolean =
        ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ActivityCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun updateWatchState(device: BluetoothDevice? = null) {
        val count = synchronized(connected) { connected.size }
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putInt(KEY_CONNECTED, count)
            .putLong(KEY_LAST_SEEN, System.currentTimeMillis())
        if (device != null && canBluetooth()) {
            val name = runCatching { device.name }.getOrNull().orEmpty()
            if (name.isNotBlank()) p.putString(KEY_LAST_WATCH, name)
        }
        p.apply()
    }

    fun start() {
        if (!canBluetooth() || !canAdvertise() || adapter == null || !adapter.isEnabled) {
            updateWatchState()
            return
        }

        stop()

        val rx = BluetoothGattCharacteristic(
            RX_UUID,
            BluetoothGattCharacteristic.PROPERTY_WRITE or BluetoothGattCharacteristic.PROPERTY_WRITE_NO_RESPONSE,
            BluetoothGattCharacteristic.PERMISSION_WRITE
        )
        tx = BluetoothGattCharacteristic(
            TX_UUID,
            BluetoothGattCharacteristic.PROPERTY_NOTIFY or BluetoothGattCharacteristic.PROPERTY_READ,
            BluetoothGattCharacteristic.PERMISSION_READ
        ).apply {
            addDescriptor(
                BluetoothGattDescriptor(
                    CCCD_UUID,
                    BluetoothGattDescriptor.PERMISSION_READ or BluetoothGattDescriptor.PERMISSION_WRITE
                )
            )
        }

        val service = BluetoothGattService(SERVICE_UUID, BluetoothGattService.SERVICE_TYPE_PRIMARY)
        service.addCharacteristic(rx)
        service.addCharacteristic(tx)

        gattServer = bluetoothManager.openGattServer(context, callback)
        gattServer?.addService(service)

        val settings = AdvertiseSettings.Builder()
            .setAdvertiseMode(AdvertiseSettings.ADVERTISE_MODE_LOW_POWER)
            .setTxPowerLevel(AdvertiseSettings.ADVERTISE_TX_POWER_MEDIUM)
            .setConnectable(true)
            .setTimeout(0)
            .build()
        val data = AdvertiseData.Builder()
            .setIncludeDeviceName(false)
            .addServiceUuid(ParcelUuid(SERVICE_UUID))
            .build()
        val scanResponse = AdvertiseData.Builder().setIncludeDeviceName(true).build()

        runCatching { if (canBluetooth()) adapter.name = ADVERTISED_NAME }
        adapter.bluetoothLeAdvertiser?.startAdvertising(settings, data, scanResponse, advertiseCallback)
        updateWatchState()
    }

    fun stop() {
        if (canAdvertise()) runCatching { adapter.bluetoothLeAdvertiser?.stopAdvertising(advertiseCallback) }
        if (canBluetooth()) runCatching { gattServer?.close() }
        gattServer = null
        tx = null
        synchronized(connected) { connected.clear() }
        updateWatchState()
    }

    fun connectedCount(): Int = synchronized(connected) { connected.size }

    fun broadcastStatus() {
        val payload = statusPayload().toString()
        synchronized(connected) { connected.toList() }.forEach { notify(it, payload) }
    }

    private val advertiseCallback = object : AdvertiseCallback() {}

    private val callback = object : BluetoothGattServerCallback() {
        override fun onConnectionStateChange(device: BluetoothDevice, status: Int, newState: Int) {
            synchronized(connected) {
                if (newState == BluetoothProfile.STATE_CONNECTED) connected += device else connected -= device
            }
            updateWatchState(device)
            if (newState == BluetoothProfile.STATE_CONNECTED) {
                scope.launch { notify(device, helloPayload().toString()) }
            }
        }

        override fun onCharacteristicReadRequest(
            device: BluetoothDevice,
            requestId: Int,
            offset: Int,
            characteristic: BluetoothGattCharacteristic
        ) {
            if (!canBluetooth()) return
            updateWatchState(device)
            val value = if (characteristic.uuid == TX_UUID) {
                (helloPayload().toString() + "\n").toByteArray()
            } else byteArrayOf()
            gattServer?.sendResponse(device, requestId, BluetoothGatt.GATT_SUCCESS, offset, value)
        }

        override fun onDescriptorWriteRequest(
            device: BluetoothDevice,
            requestId: Int,
            descriptor: BluetoothGattDescriptor,
            preparedWrite: Boolean,
            responseNeeded: Boolean,
            offset: Int,
            value: ByteArray
        ) {
            if (descriptor.uuid == CCCD_UUID) descriptor.value = value
            updateWatchState(device)
            if (responseNeeded && canBluetooth()) {
                gattServer?.sendResponse(device, requestId, BluetoothGatt.GATT_SUCCESS, offset, value)
            }
        }

        override fun onCharacteristicWriteRequest(
            device: BluetoothDevice,
            requestId: Int,
            characteristic: BluetoothGattCharacteristic,
            preparedWrite: Boolean,
            responseNeeded: Boolean,
            offset: Int,
            value: ByteArray
        ) {
            if (characteristic.uuid != RX_UUID) return
            if (responseNeeded && canBluetooth()) {
                gattServer?.sendResponse(device, requestId, BluetoothGatt.GATT_SUCCESS, 0, null)
            }

            updateWatchState(device)
            val text = value.toString(Charsets.UTF_8).trim()
            scope.launch {
                val response = try {
                    val input = JSONObject(text)
                    handleWatchRequest(input)
                } catch (e: Exception) {
                    JSONObject()
                        .put("ok", false)
                        .put("type", "error")
                        .put("error", e.message ?: "Falha BLE")
                }.put("protocol", PROTOCOL_VERSION)
                notify(device, response.toString())
            }
        }
    }

    private fun handleWatchRequest(input: JSONObject): JSONObject = when (input.optString("type")) {
        "hello", "phone_info" -> helloPayload()
        "ping" -> JSONObject().put("ok", true).put("type", "pong").put("phone", ADVERTISED_NAME)
        "status" -> statusPayload()
        "gps_request" -> gpsPayload()
        "camera_capture" -> cameraPayload()
        "voice_capture" -> voiceReadyPayload()
        "voice_text" -> {
            val text = input.optString("text").trim()
            if (text.isBlank()) {
                JSONObject().put("ok", false).put("type", "voice_result").put("error", "empty_voice_text")
            } else {
                JarvisApi.executeBleBridgeRequest(context, JSONObject().put("type", "jarvis").put("text", text))
                    .put("source", "watch_voice")
            }
        }
        else -> JarvisApi.executeBleBridgeRequest(context, input)
    }

    private fun helloPayload(): JSONObject = JSONObject()
        .put("ok", true)
        .put("type", "hello")
        .put("bridge", "jarvis-mobile")
        .put("phone", ADVERTISED_NAME)
        .put("protocol", PROTOCOL_VERSION)
        .put("internet", JarvisApi.isOnline(context))
        .put("pending", JarvisApi.pendingCount(context))
        .put("capabilities", JSONObject()
            .put("jarvis", true)
            .put("voice_gateway", true)
            .put("camera_remote", true)
            .put("gps", true)
            .put("notifications", true))

    private fun statusPayload(): JSONObject = JSONObject()
        .put("ok", true)
        .put("type", "status")
        .put("internet", JarvisApi.isOnline(context))
        .put("configured", JarvisApi.isConfigured(context))
        .put("pending", JarvisApi.pendingCount(context))
        .put("watch_connected", connectedCount() > 0)
        .put("watch_count", connectedCount())
        .put("language", LanguageManager.currentLanguage(context))

    private fun gpsPayload(): JSONObject {
        if (!canLocation()) {
            return JSONObject()
                .put("ok", false)
                .put("type", "gps_result")
                .put("error", "location_permission_required")
        }

        val lm = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        val providers = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER, LocationManager.PASSIVE_PROVIDER)
        var best: Location? = null
        providers.forEach { provider ->
            val loc = runCatching { lm.getLastKnownLocation(provider) }.getOrNull()
            if (loc != null && (best == null || loc.time > best!!.time)) best = loc
        }

        val loc = best ?: return JSONObject()
            .put("ok", false)
            .put("type", "gps_result")
            .put("error", "location_unavailable")

        return JSONObject()
            .put("ok", true)
            .put("type", "gps_result")
            .put("lat", loc.latitude)
            .put("lon", loc.longitude)
            .put("accuracy_m", loc.accuracy.toDouble())
            .put("provider", loc.provider ?: "")
            .put("time", loc.time)
    }

    private fun voiceReadyPayload(): JSONObject = JSONObject()
        .put("ok", true)
        .put("type", "voice_ready")
        .put("gateway", "android")
        .put("message", "Gateway de voz pronto")
        .put("accepts", "voice_text")
        .put("audio_stream", false)
        .put("note", "Streaming de audio do Watch ainda precisa do transporte de chunks")

    private fun cameraPayload(): JSONObject {
        createWatchChannel()
        val intent = Intent(context, MainActivity::class.java).apply {
            action = ACTION_WATCH_CAMERA
            putExtra(EXTRA_WATCH_REQUEST, true)
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val pending = PendingIntent.getActivity(
            context,
            CAMERA_NOTIFICATION_ID,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_WATCH)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Watch")
            .setContentText("O relógio solicitou a câmera. Toque para abrir.")
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(CAMERA_NOTIFICATION_ID, notification)

        return JSONObject()
            .put("ok", true)
            .put("type", "camera_ready")
            .put("message", "Solicitacao de camera enviada ao celular")
            .put("requires_user_action", true)
    }

    private fun createWatchChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = context.getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL_WATCH, "Ações do JARVIS Watch", NotificationManager.IMPORTANCE_HIGH)
            )
        }
    }

    private fun notify(device: BluetoothDevice, text: String) {
        if (!canBluetooth()) return
        val characteristic = tx ?: return
        val bytes = (text + "\n").toByteArray(Charsets.UTF_8)
        val mtuSafe = 180
        var pos = 0
        while (pos < bytes.size) {
            val end = minOf(pos + mtuSafe, bytes.size)
            val part = bytes.copyOfRange(pos, end)
            characteristic.value = part
            if (Build.VERSION.SDK_INT >= 33) {
                gattServer?.notifyCharacteristicChanged(device, characteristic, false, part)
            } else {
                @Suppress("DEPRECATION")
                gattServer?.notifyCharacteristicChanged(device, characteristic, false)
            }
            pos = end
            Thread.sleep(25)
        }
    }
}
