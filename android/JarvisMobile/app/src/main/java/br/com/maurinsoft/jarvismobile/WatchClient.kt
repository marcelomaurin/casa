package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.bluetooth.*
import android.bluetooth.le.*
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.ParcelUuid
import androidx.core.app.ActivityCompat
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID
import java.util.ArrayDeque
import java.util.concurrent.CopyOnWriteArrayList

/**
 * Android = BLE central/client.
 * T-Watch = BLE peripheral/GATT server.
 *
 * Protocol v2:
 *  service 1000
 *  control 1001: Phone -> Watch (WRITE)
 *  events  1002: Watch -> Phone (NOTIFY/READ)
 */
class WatchClient(private val context: Context) {
    companion object {
        val SERVICE_UUID: UUID = UUID.fromString("7a9f1000-3a8c-4b62-9e5f-1b0c0e91a001")
        val CONTROL_UUID: UUID = UUID.fromString("7a9f1001-3a8c-4b62-9e5f-1b0c0e91a001")
        val EVENT_UUID: UUID = UUID.fromString("7a9f1002-3a8c-4b62-9e5f-1b0c0e91a001")
        val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")
        const val WATCH_NAME = "JARVIS Watch"
        private const val PREFS = "jarvis_watch_client"
        private const val KEY_ADDRESS = "watch_address"
        private const val KEY_NAME = "watch_name"
        private const val KEY_CONNECTED = "connected"
        private const val KEY_RSSI = "rssi"
        private const val KEY_LAST_SEEN = "last_seen"

        fun savedAddress(context: Context): String = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_ADDRESS, "") ?: ""
        fun savedName(context: Context): String = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_NAME, WATCH_NAME) ?: WATCH_NAME
        fun isConnected(context: Context): Boolean = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_CONNECTED, false)
        fun lastRssi(context: Context): Int = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getInt(KEY_RSSI, -127)
        fun lastSeen(context: Context): Long = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong(KEY_LAST_SEEN, 0L)
    }

    data class FoundWatch(val name: String, val address: String, val rssi: Int)

    interface Listener {
        fun onScanResult(watch: FoundWatch) {}
        fun onConnectionChanged(connected: Boolean, name: String, address: String) {}
        fun onWatchMessage(json: JSONObject) {}
        fun onError(message: String) {}
    }

    private val manager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
    private val adapter get() = manager.adapter
    private var gatt: BluetoothGatt? = null
    private var control: BluetoothGattCharacteristic? = null
    private var events: BluetoothGattCharacteristic? = null
    private val listeners = CopyOnWriteArrayList<Listener>()
    private var scanning = false

    private val writeLock = Any()
    private val writeQueue = ArrayDeque<ByteArray>()
    private var writeInProgress = false
    private var gattReady = false

    fun addListener(listener: Listener) { listeners += listener }
    fun removeListener(listener: Listener) { listeners -= listener }

    private fun canScan(): Boolean = Build.VERSION.SDK_INT < 31 || ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED
    private fun canConnect(): Boolean = Build.VERSION.SDK_INT < 31 || ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

    fun scan() {
        if (!canScan()) { listeners.forEach { it.onError("Permissão Bluetooth Scan necessária") }; return }
        if (adapter == null || !adapter.isEnabled) { listeners.forEach { it.onError("Bluetooth desligado") }; return }
        stopScan()
        val filter = ScanFilter.Builder().setServiceUuid(ParcelUuid(SERVICE_UUID)).build()
        val settings = ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY).build()
        adapter.bluetoothLeScanner?.startScan(listOf(filter), settings, scanCallback)
        scanning = true
    }

    fun stopScan() {
        if (scanning && canScan()) runCatching { adapter.bluetoothLeScanner?.stopScan(scanCallback) }
        scanning = false
    }

    fun connect(address: String) {
        if (!canConnect()) { listeners.forEach { it.onError("Permissão Bluetooth Connect necessária") }; return }
        stopScan()
        val device = runCatching { adapter.getRemoteDevice(address) }.getOrNull() ?: run {
            listeners.forEach { it.onError("Relógio inválido") }; return
        }
        gatt?.close()
        gatt = if (Build.VERSION.SDK_INT >= 23) device.connectGatt(context, false, callback, BluetoothDevice.TRANSPORT_LE)
        else @Suppress("DEPRECATION") device.connectGatt(context, false, callback)
    }

    fun connectSaved(): Boolean {
        val address = savedAddress(context)
        if (address.isBlank()) return false
        connect(address)
        return true
    }

    fun disconnect(forget: Boolean = false) {
        if (canConnect()) runCatching { gatt?.disconnect() }
        runCatching { gatt?.close() }
        gatt = null; control = null; events = null
        synchronized(writeLock) {
            writeQueue.clear()
            writeInProgress = false
            gattReady = false
        }
        val e = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean(KEY_CONNECTED, false)
        if (forget) e.remove(KEY_ADDRESS).remove(KEY_NAME)
        e.apply()
    }

    fun requestRssi() { if (canConnect()) runCatching { gatt?.readRemoteRssi() } }

    fun send(json: JSONObject): Boolean {
        if (!canConnect()) return false
        if (gatt == null || control == null) return false
        val bytes = (json.toString() + "\n").toByteArray(Charsets.UTF_8)
        if (bytes.size > 180) {
            listeners.forEach { it.onError("Mensagem BLE excede 180 bytes") }
            return false
        }
        synchronized(writeLock) { writeQueue.addLast(bytes) }
        drainWriteQueue()
        return true
    }

    private fun drainWriteQueue() {
        val g: BluetoothGatt
        val c: BluetoothGattCharacteristic
        val bytes: ByteArray
        synchronized(writeLock) {
            if (!gattReady || writeInProgress || writeQueue.isEmpty()) return
            g = gatt ?: return
            c = control ?: return
            bytes = writeQueue.peekFirst() ?: return
            writeInProgress = true
        }

        val started = if (Build.VERSION.SDK_INT >= 33) {
            g.writeCharacteristic(c, bytes, BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT) == BluetoothStatusCodes.SUCCESS
        } else {
            @Suppress("DEPRECATION")
            run {
                c.writeType = BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT
                c.value = bytes
                g.writeCharacteristic(c)
            }
        }

        if (!started) {
            synchronized(writeLock) {
                if (writeQueue.isNotEmpty()) writeQueue.removeFirst()
                writeInProgress = false
            }
            listeners.forEach { it.onError("Falha ao iniciar escrita BLE") }
            drainWriteQueue()
        }
    }

    private fun markGattReady(g: BluetoothGatt) {
        synchronized(writeLock) { gattReady = true }
        val name = if (canConnect()) runCatching { g.device.name }.getOrNull() ?: WATCH_NAME else WATCH_NAME
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_ADDRESS, g.device.address)
            .putString(KEY_NAME, name)
            .putBoolean(KEY_CONNECTED, true)
            .putLong(KEY_LAST_SEEN, System.currentTimeMillis())
            .apply()
        listeners.forEach { it.onConnectionChanged(true, name, g.device.address) }
        send(JSONObject().put("type", "hello").put("protocol", "2.0").put("client", "JARVIS Mobile"))
        drainWriteQueue()
    }

    fun sendPhoneState(wifi: Boolean, ssid: String?, internet: Boolean): Boolean = send(JSONObject()
        .put("type", "phone_state")
        .put("bluetooth", true)
        .put("wifi", wifi)
        .put("wifi_ssid", ssid ?: JSONObject.NULL)
        .put("internet", internet)
        .put("jarvis_online", JarvisApi.isConfigured(context)))

    /** Envia uma rede por mensagem para manter cada write BLE pequeno. */
    fun provisionWifi(slot: Int, ssid: String, password: String): Boolean = send(JSONObject()
        .put("type", "wifi_profile")
        .put("slot", slot)
        .put("ssid", ssid)
        .put("password", password))

    fun provisionCasa(baseUrl: String, watchDeviceToken: String): Boolean = send(JSONObject()
        .put("type", "casa_config")
        .put("base_url", baseUrl)
        .put("device_token", watchDeviceToken))

    fun requestStatus(): Boolean = send(JSONObject().put("type", "status"))
    fun findWatch(): Boolean = send(JSONObject().put("type", "find_watch"))

    private val scanCallback = object : ScanCallback() {
        override fun onScanResult(callbackType: Int, result: ScanResult) {
            val device = result.device
            val name = if (canConnect()) runCatching { device.name }.getOrNull() ?: WATCH_NAME else WATCH_NAME
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putLong(KEY_LAST_SEEN, System.currentTimeMillis()).putInt(KEY_RSSI, result.rssi).apply()
            listeners.forEach { it.onScanResult(FoundWatch(name, device.address, result.rssi)) }
        }
        override fun onScanFailed(errorCode: Int) { listeners.forEach { it.onError("Falha no scan BLE: $errorCode") } }
    }

    private val callback = object : BluetoothGattCallback() {
        override fun onConnectionStateChange(g: BluetoothGatt, status: Int, newState: Int) {
            if (newState == BluetoothProfile.STATE_CONNECTED && status == BluetoothGatt.GATT_SUCCESS) {
                if (canConnect()) {
                    g.requestConnectionPriority(BluetoothGatt.CONNECTION_PRIORITY_HIGH)
                    if (!g.requestMtu(185)) g.discoverServices()
                }
            } else {
                control = null; events = null
                synchronized(writeLock) {
                    writeQueue.clear()
                    writeInProgress = false
                    gattReady = false
                }
                context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean(KEY_CONNECTED, false).apply()
                listeners.forEach { it.onConnectionChanged(false, savedName(context), savedAddress(context)) }
            }
        }

        override fun onMtuChanged(g: BluetoothGatt, mtu: Int, status: Int) {
            if (canConnect()) g.discoverServices()
        }

        override fun onServicesDiscovered(g: BluetoothGatt, status: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS) { listeners.forEach { it.onError("Serviço do Watch não encontrado") }; return }
            val service = g.getService(SERVICE_UUID) ?: run { listeners.forEach { it.onError("JARVIS Watch incompatível") }; return }
            control = service.getCharacteristic(CONTROL_UUID)
                ?: run { listeners.forEach { it.onError("Canal de controle ausente") }; return }
            events = service.getCharacteristic(EVENT_UUID)
            val ev = events ?: run { listeners.forEach { it.onError("Canal de eventos ausente") }; return }

            var descriptorStarted = false
            if (canConnect()) {
                g.setCharacteristicNotification(ev, true)
                ev.getDescriptor(CCCD_UUID)?.let { d ->
                    descriptorStarted = if (Build.VERSION.SDK_INT >= 33) {
                        g.writeDescriptor(d, BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE) == BluetoothStatusCodes.SUCCESS
                    } else {
                        @Suppress("DEPRECATION")
                        run {
                            d.value = BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE
                            g.writeDescriptor(d)
                        }
                    }
                }
            }

            if (!descriptorStarted) {
                listeners.forEach { it.onError("Notificações BLE indisponíveis; controle continua ativo") }
                markGattReady(g)
            }
        }

        override fun onDescriptorWrite(
            g: BluetoothGatt,
            descriptor: BluetoothGattDescriptor,
            status: Int
        ) {
            if (descriptor.uuid == CCCD_UUID) {
                if (status != BluetoothGatt.GATT_SUCCESS) {
                    listeners.forEach { it.onError("Falha ao ativar notificações do Watch") }
                }
                markGattReady(g)
            }
        }

        override fun onCharacteristicWrite(
            g: BluetoothGatt,
            characteristic: BluetoothGattCharacteristic,
            status: Int
        ) {
            if (characteristic.uuid != CONTROL_UUID) return
            synchronized(writeLock) {
                if (writeQueue.isNotEmpty()) writeQueue.removeFirst()
                writeInProgress = false
            }
            if (status != BluetoothGatt.GATT_SUCCESS) {
                listeners.forEach { it.onError("Falha na escrita BLE: $status") }
            }
            drainWriteQueue()
        }

        override fun onCharacteristicChanged(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic) {
            @Suppress("DEPRECATION") val data = characteristic.value ?: return
            onBytes(data)
        }

        override fun onCharacteristicChanged(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, value: ByteArray) {
            onBytes(value)
        }

        override fun onReadRemoteRssi(g: BluetoothGatt, rssi: Int, status: Int) {
            if (status == BluetoothGatt.GATT_SUCCESS) context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putInt(KEY_RSSI, rssi).putLong(KEY_LAST_SEEN, System.currentTimeMillis()).apply()
        }
    }

    private val incoming = StringBuilder()
    private fun onBytes(bytes: ByteArray) {
        incoming.append(bytes.toString(Charsets.UTF_8))
        while (true) {
            val p = incoming.indexOf("\n")
            if (p < 0) break
            val line = incoming.substring(0, p).trim()
            incoming.delete(0, p + 1)
            if (line.isEmpty()) continue
            val json = runCatching { JSONObject(line) }.getOrElse { JSONObject().put("type", "text").put("text", line) }
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putLong(KEY_LAST_SEEN, System.currentTimeMillis()).apply()
            listeners.forEach { it.onWatchMessage(json) }
        }
    }
}
