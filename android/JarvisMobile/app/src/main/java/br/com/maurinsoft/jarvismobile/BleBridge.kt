package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.bluetooth.*
import android.bluetooth.le.AdvertiseCallback
import android.bluetooth.le.AdvertiseData
import android.bluetooth.le.AdvertiseSettings
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.ParcelUuid
import androidx.core.app.ActivityCompat
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
    }

    private val bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager
    private val adapter get() = bluetoothManager.adapter
    private var gattServer: BluetoothGattServer? = null
    private var tx: BluetoothGattCharacteristic? = null
    private val connected = mutableSetOf<BluetoothDevice>()
    private val scope = CoroutineScope(Dispatchers.IO)

    private fun canBluetooth(): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
    }

    private fun canAdvertise(): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
            ActivityCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_ADVERTISE) == PackageManager.PERMISSION_GRANTED
    }

    fun start() {
        if (!canBluetooth() || !canAdvertise() || adapter == null || !adapter.isEnabled) return

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
            .setAdvertiseMode(AdvertiseSettings.ADVERTISE_MODE_LOW_LATENCY)
            .setConnectable(true)
            .setTimeout(0)
            .build()
        val data = AdvertiseData.Builder()
            .setIncludeDeviceName(true)
            .addServiceUuid(ParcelUuid(SERVICE_UUID))
            .build()
        adapter.bluetoothLeAdvertiser?.startAdvertising(settings, data, advertiseCallback)
    }

    fun stop() {
        if (canAdvertise()) adapter.bluetoothLeAdvertiser?.stopAdvertising(advertiseCallback)
        if (canBluetooth()) gattServer?.close()
        gattServer = null
        connected.clear()
    }

    private val advertiseCallback = object : AdvertiseCallback() {}

    private val callback = object : BluetoothGattServerCallback() {
        override fun onConnectionStateChange(device: BluetoothDevice, status: Int, newState: Int) {
            if (newState == BluetoothProfile.STATE_CONNECTED) connected += device else connected -= device
        }

        override fun onCharacteristicReadRequest(
            device: BluetoothDevice,
            requestId: Int,
            offset: Int,
            characteristic: BluetoothGattCharacteristic
        ) {
            if (!canBluetooth()) return
            val value = if (characteristic.uuid == TX_UUID) "{\"ok\":true,\"bridge\":\"jarvis-mobile\"}".toByteArray() else byteArrayOf()
            gattServer?.sendResponse(device, requestId, BluetoothGatt.GATT_SUCCESS, offset, value)
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

            val text = value.toString(Charsets.UTF_8)
            scope.launch {
                val response = try {
                    JarvisApi.executeBleBridgeRequest(context, JSONObject(text))
                } catch (e: Exception) {
                    JSONObject().put("ok", false).put("error", e.message ?: "Falha BLE")
                }
                notify(device, response.toString())
            }
        }
    }

    private fun notify(device: BluetoothDevice, text: String) {
        if (!canBluetooth()) return
        val characteristic = tx ?: return
        val bytes = text.toByteArray(Charsets.UTF_8)

        // Mensagens maiores devem ser tratadas pelo protocolo do relógio em blocos.
        val mtuSafe = 180
        var pos = 0
        while (pos < bytes.size) {
            val end = minOf(pos + mtuSafe, bytes.size)
            characteristic.value = bytes.copyOfRange(pos, end)
            if (Build.VERSION.SDK_INT >= 33) {
                gattServer?.notifyCharacteristicChanged(device, characteristic, false, characteristic.value)
            } else {
                @Suppress("DEPRECATION")
                gattServer?.notifyCharacteristicChanged(device, characteristic, false)
            }
            pos = end
            Thread.sleep(30)
        }
    }
}
