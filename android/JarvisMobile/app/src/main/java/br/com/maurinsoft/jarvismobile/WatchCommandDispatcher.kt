package br.com.maurinsoft.jarvismobile

import android.content.Context
import org.json.JSONObject

class WatchCommandDispatcher(
    private val context: Context,
    private val localClient: WatchClient,
    private val isLocalConnected: () -> Boolean
) {
    suspend fun send(
        deviceId: String,
        command: String,
        payload: JSONObject,
        priority: String = "normal",
        ttlSeconds: Int = 300
    ) {
        val localDeviceId = WatchClient.savedDeviceId(context)
        if (isLocalConnected() && localDeviceId.isNotBlank() && localDeviceId == deviceId) {
            val directPayload = JSONObject(payload.toString())
            if (directPayload.optString("type").isBlank()) directPayload.put("type", command)
            if (localClient.send(directPayload)) return
        }

        runCatching {
            WatchApi.enqueue(
                context,
                deviceId,
                command,
                payload,
                priority,
                ttlSeconds
            )
        }
    }
}
