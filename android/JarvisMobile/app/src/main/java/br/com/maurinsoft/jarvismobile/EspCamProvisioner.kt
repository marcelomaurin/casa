package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.annotation.SuppressLint
import android.bluetooth.*
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanResult
import android.bluetooth.le.ScanSettings
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import java.util.UUID

class EspCamProvisioner(private val context: Context) {
    data class FoundCamera(val name: String, val address: String, val rssi: Int)

    interface Listener {
        fun onCameraFound(camera: FoundCamera)
        fun onStatus(message: String)
        fun onProvisioned()
        fun onError(message: String)
    }

    companion object {
        val SERVICE_UUID: UUID = UUID.fromString("7a5b0001-78fc-4b97-9f0f-9e9f5a31b401")
        val SSID_UUID: UUID = UUID.fromString("7a5b0002-78fc-4b97-9f0f-9e9f5a31b401")
        val WIFI_PASS_UUID: UUID = UUID.fromString("7a5b0003-78fc-4b97-9f0f-9e9f5a31b401")
        val APPLY_UUID: UUID = UUID.fromString("7a5b0006-78fc-4b97-9f0f-9e9f5a31b401")
        val STATUS_UUID: UUID = UUID.fromString("7a5b0007-78fc-4b97-9f0f-9e9f5a31b401")
        val CASA_URL_UUID: UUID = UUID.fromString("7a5b0008-78fc-4b97-9f0f-9e9f5a31b401")
        val DEVICE_TOKEN_UUID: UUID = UUID.fromString("7a5b0009-78fc-4b97-9f0f-9e9f5a31b401")
        val DEVICE_NAME_UUID: UUID = UUID.fromString("7a5b000a-78fc-4b97-9f0f-9e9f5a31b401")
        val LOCATION_UUID: UUID = UUID.fromString("7a5b000b-78fc-4b97-9f0f-9e9f5a31b401")
    }

    private val adapter: BluetoothAdapter? =
        (context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter
    private var listener: Listener? = null
    private var gatt: BluetoothGatt? = null
    private val pendingWrites = ArrayDeque<Pair<UUID, ByteArray>>()

    fun setListener(listener: Listener?) { this.listener = listener }

    private fun canScan(): Boolean = Build.VERSION.SDK_INT < 31 ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED

    private fun canConnect(): Boolean = Build.VERSION.SDK_INT < 31 ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

    @SuppressLint("MissingPermission")
    fun scan() {
        if (!canScan()) { listener?.onError("Permissão Bluetooth Scan não concedida"); return }
        val scanner = adapter?.bluetoothLeScanner ?: run { listener?.onError("Bluetooth indisponível"); return }
        listener?.onStatus("Procurando ESP32-CAM em modo de configuração...")
        scanner.startScan(null, ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY).build(), scanCallback)
    }

    @SuppressLint("MissingPermission")
    fun stopScan() {
        if (!canScan()) return
        runCatching { adapter?.bluetoothLeScanner?.stopScan(scanCallback) }
    }

    @SuppressLint("MissingPermission")
    fun connectAndProvision(
        address: String,
        ssid: String,
        wifiPassword: String,
        casaUrl: String,
        deviceToken: String,
        deviceName: String,
        location: String
    ) {
        if (!canConnect()) { listener?.onError("Permissão Bluetooth Connect não concedida"); return }
        if (ssid.isBlank() || casaUrl.isBlank() || deviceToken.isBlank()) { listener?.onError("Configuração incompleta"); return }
        stopScan()
        pendingWrites.clear()
        fun add(uuid: UUID, text: String) { pendingWrites.add(uuid to text.toByteArray(Charsets.UTF_8)) }
        add(SSID_UUID, ssid)
        add(WIFI_PASS_UUID, wifiPassword)
        add(CASA_URL_UUID, casaUrl.trimEnd('/'))
        add(DEVICE_TOKEN_UUID, deviceToken)
        add(DEVICE_NAME_UUID, deviceName)
        add(LOCATION_UUID, location)
        add(APPLY_UUID, "APPLY")
        val device = runCatching { adapter?.getRemoteDevice(address) }.getOrNull()
            ?: run { listener?.onError("Câmera Bluetooth não encontrada"); return }
        listener?.onStatus("Conectando à câmera...")
        gatt?.close()
        gatt = device.connectGatt(context, false, gattCallback, BluetoothDevice.TRANSPORT_LE)
    }

    @SuppressLint("MissingPermission")
    fun close() {
        stopScan()
        runCatching { gatt?.disconnect() }
        runCatching { gatt?.close() }
        gatt = null
        pendingWrites.clear()
    }

    private val scanCallback = object : ScanCallback() {
        @SuppressLint("MissingPermission")
        override fun onScanResult(callbackType: Int, result: ScanResult) {
            val name = runCatching { result.device.name }.getOrNull() ?: result.scanRecord?.deviceName ?: return
            if (!name.startsWith("JARVIS-CAM-", true)) return
            listener?.onCameraFound(FoundCamera(name, result.device.address, result.rssi))
        }
        override fun onScanFailed(errorCode: Int) { listener?.onError("Falha no scan BLE: $errorCode") }
    }

    private val gattCallback = object : BluetoothGattCallback() {
        @SuppressLint("MissingPermission")
        override fun onConnectionStateChange(g: BluetoothGatt, status: Int, newState: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS) { listener?.onError("Falha BLE GATT: $status"); close(); return }
            if (newState == BluetoothProfile.STATE_CONNECTED) {
                listener?.onStatus("Câmera conectada. Descobrindo serviço...")
                g.discoverServices()
            } else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
                listener?.onStatus("Câmera desconectada")
            }
        }

        @SuppressLint("MissingPermission")
        override fun onServicesDiscovered(g: BluetoothGatt, status: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS || g.getService(SERVICE_UUID) == null) {
                listener?.onError("Serviço de provisionamento JARVIS não encontrado")
                return
            }
            listener?.onStatus("Enviando configuração segura...")
            writeNext(g)
        }

        @Deprecated("Deprecated in Java")
        override fun onCharacteristicWrite(g: BluetoothGatt, characteristic: BluetoothGattCharacteristic, status: Int) {
            if (status != BluetoothGatt.GATT_SUCCESS) {
                listener?.onError("Falha ao gravar ${characteristic.uuid}: $status")
                return
            }
            if (characteristic.uuid == APPLY_UUID) {
                listener?.onStatus("Configuração enviada. A câmera vai conectar ao Wi-Fi e reiniciar.")
                listener?.onProvisioned()
                return
            }
            writeNext(g)
        }
    }

    @SuppressLint("MissingPermission")
    private fun writeNext(g: BluetoothGatt) {
        val next = pendingWrites.removeFirstOrNull() ?: run { listener?.onProvisioned(); return }
        val service = g.getService(SERVICE_UUID) ?: run { listener?.onError("Serviço BLE desapareceu"); return }
        val c = service.getCharacteristic(next.first) ?: run { listener?.onError("Característica ${next.first} não disponível"); return }
        c.writeType = BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT
        c.value = next.second
        if (!g.writeCharacteristic(c)) listener?.onError("Bluetooth recusou a escrita ${next.first}")
    }
}
