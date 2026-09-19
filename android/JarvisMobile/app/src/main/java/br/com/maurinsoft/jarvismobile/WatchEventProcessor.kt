package br.com.maurinsoft.jarvismobile

import android.content.Context
import org.json.JSONObject

class WatchEventProcessor(
    private val context: Context,
    private val actions: Actions
) {
    interface Actions {
        suspend fun sendWatchCommand(
            deviceId: String,
            command: String,
            payload: JSONObject,
            priority: String = "normal",
            ttlSeconds: Int = 300
        )

        suspend fun sendAssist(
            type: String,
            severity: String,
            message: String,
            data: JSONObject
        )

        fun showFamilyCallNotification(callId: Long, mode: String)
        fun showVoiceRequest(deviceId: String)
        fun showWatchAlarm(deviceId: String, data: JSONObject)
        fun showCameraRequest(deviceId: String)
        fun networkSnapshot(): JarvisNetworkMonitor.Snapshot
        fun jarvisOnline(): Boolean
    }

    suspend fun handle(event: WatchApi.WatchEvent) {
        val declaredType = event.data.optString("type").trim()
        val type = (if (declaredType.isNotBlank()) declaredType else event.type)
            .substringAfterLast('.')
            .lowercase()

        when (type) {
            "sos" -> actions.sendAssist(
                "SOS",
                "critica",
                "SOS acionado no JARVIS Watch",
                event.data
            )

            "inactivity" -> actions.sendAssist(
                "INACTIVITY",
                "alta",
                "Relógio detectou período prolongado sem movimento",
                event.data
            )

            "checkin" -> actions.sendAssist(
                "CHECKIN",
                if (event.data.optBoolean("ok", true)) "info" else "alta",
                "Check-in do JARVIS Watch",
                event.data
            )

            "movement", "movement_heartbeat" -> actions.sendAssist(
                "MOVEMENT_HEARTBEAT",
                "info",
                "Telemetria de movimento do JARVIS Watch",
                event.data
            )

            "family_message" -> FamilyApi.sendMessage(
                context,
                event.data.optString("message"),
                event.data.optString("message_type", "texto"),
                event.data
            )

            "family_call" -> {
                val mode = if (event.data.optString("mode") == "audio") "audio" else "video"
                val callId = runCatching {
                    FamilyApi.startCall(context, mode, "watch")
                }.getOrDefault(0L)

                actions.sendWatchCommand(
                    event.deviceId,
                    "family_call_result",
                    JSONObject()
                        .put("type", "family_call_result")
                        .put("ok", callId > 0)
                        .put("call_id", callId)
                        .put("mode", mode),
                    "high",
                    300
                )

                if (callId > 0) actions.showFamilyCallNotification(callId, mode)
            }

            "voice_capture" -> actions.showVoiceRequest(event.deviceId)

            "voice_text" -> {
                val text = event.data.optString("text").trim()
                if (text.isNotBlank()) processVoiceText(event.deviceId, text)
            }

            "alarm_sound" -> actions.showWatchAlarm(event.deviceId, event.data)

            "gps_request" -> actions.sendWatchCommand(
                event.deviceId,
                "gps_result",
                PhoneSensorProvider.gpsPayload(context),
                "normal",
                120
            )

            "camera_capture" -> actions.showCameraRequest(event.deviceId)

            "phone_state", "phone_state_request" -> {
                val net = actions.networkSnapshot()
                actions.sendWatchCommand(
                    event.deviceId,
                    "phone_state",
                    JSONObject()
                        .put("type", "phone_state")
                        .put("wifi", net.wifi)
                        .put("wifi_ssid", net.ssid ?: JSONObject.NULL)
                        .put("internet", net.internet)
                        .put("jarvis_online", actions.jarvisOnline()),
                    "low",
                    120
                )
            }

            "ping" -> actions.sendWatchCommand(
                event.deviceId,
                "pong",
                JSONObject()
                    .put("type", "pong")
                    .put("internet", actions.jarvisOnline()),
                "low",
                60
            )
        }
    }

    suspend fun processVoiceText(deviceId: String, text: String) {
        val normalized = text.trim()
        if (normalized.isBlank()) {
            actions.sendWatchCommand(
                deviceId,
                "voice_result",
                JSONObject()
                    .put("type", "voice_result")
                    .put("ok", false)
                    .put("error", "empty_voice_text"),
                "normal",
                120
            )
            return
        }

        val result = JarvisApi.sendOrQueue(context, normalized)
        actions.sendWatchCommand(
            deviceId,
            "jarvis_result",
            JSONObject()
                .put("type", "jarvis_result")
                .put("ok", result.delivered)
                .put("queued", result.queued)
                .put("text", result.answer?.text ?: result.message)
                .put("audio_url", result.answer?.audioUrl ?: JSONObject.NULL),
            "normal",
            300
        )
    }
}
