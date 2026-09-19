package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import org.json.JSONObject

/**
 * Responsabilidade única: canais e notificações do serviço.
 * O JarvisConnectionService permanece focado em conectividade e orquestração.
 */
class JarvisServiceNotifier(private val context: Context) {
    private val manager: NotificationManager
        get() = context.getSystemService(NotificationManager::class.java)

    fun createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        manager.createNotificationChannel(
            NotificationChannel(
                JarvisConnectionService.CHANNEL_SERVICE,
                "Conexão JARVIS",
                NotificationManager.IMPORTANCE_LOW
            )
        )
        manager.createNotificationChannel(
            NotificationChannel(
                JarvisConnectionService.CHANNEL_ALERTS,
                "Alertas JARVIS",
                NotificationManager.IMPORTANCE_HIGH
            )
        )
        manager.createNotificationChannel(
            NotificationChannel(
                JarvisConnectionService.CHANNEL_WATCH,
                "JARVIS Watch",
                NotificationManager.IMPORTANCE_HIGH
            )
        )

        val alarmSound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        val alarmAttributes = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_ALARM)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()

        manager.createNotificationChannel(
            NotificationChannel(
                JarvisConnectionService.CHANNEL_WATCH_ALARM,
                "Alarmes do JARVIS Watch",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Alarmes solicitados pelo relógio JARVIS"
                enableVibration(true)
                vibrationPattern = longArrayOf(0, 350, 180, 350, 180, 700)
                setSound(alarmSound, alarmAttributes)
                lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            }
        )
    }

    fun serviceNotification(text: String): Notification {
        val openMain = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val setup = PendingIntent.getActivity(
            context,
            1,
            Intent(context, WatchSetupActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_SERVICE)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Mobile")
            .setContentText(text)
            .setOngoing(true)
            .setContentIntent(openMain)
            .addAction(0, "Relógio", setup)
            .build()
    }

    fun updateService(text: String) {
        manager.notify(
            JarvisConnectionService.NOTIFICATION_ID,
            serviceNotification(text)
        )
    }

    fun canNotify(): Boolean =
        Build.VERSION.SDK_INT < 33 ||
            ActivityCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED

    fun showVoiceRequest(deviceId: String): Boolean = runCatching {
        if (!canNotify()) return false
        val intent = Intent(context, WatchVoiceActivity::class.java)
            .putExtra(WatchVoiceActivity.EXTRA_WATCH_DEVICE_ID, deviceId)
        val pending = PendingIntent.getActivity(
            context,
            JarvisConnectionService.VOICE_NOTIFICATION_ID,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_WATCH)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Watch — voz")
            .setContentText("Toque para falar com o JARVIS pelo celular.")
            .setStyle(
                NotificationCompat.BigTextStyle().bigText(
                    "O relógio detectou sua voz. Toque aqui e fale o comando; o celular reconhecerá a fala e enviará a resposta de volta ao relógio."
                )
            )
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        manager.notify(JarvisConnectionService.VOICE_NOTIFICATION_ID, notification)
        true
    }.getOrDefault(false)

    fun showWatchAlarm(data: JSONObject): Boolean = runCatching {
        val label = data.optString("message")
            .ifBlank { data.optString("tone") }
            .ifBlank { "Alarme acionado pelo JARVIS Watch" }
        val open = PendingIntent.getActivity(
            context,
            JarvisConnectionService.ALARM_NOTIFICATION_ID,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val alarmSound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        val notification = NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_WATCH_ALARM)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("Alarme JARVIS Watch")
            .setContentText(label)
            .setStyle(NotificationCompat.BigTextStyle().bigText(label))
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setSound(alarmSound)
            .setVibrate(longArrayOf(0, 350, 180, 350, 180, 700))
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()
        manager.notify(JarvisConnectionService.ALARM_NOTIFICATION_ID, notification)
        true
    }.getOrDefault(false)

    fun showCameraRequest(deviceId: String): Boolean = runCatching {
        val intent = Intent(context, WatchCameraActivity::class.java)
            .putExtra(WatchCameraActivity.EXTRA_WATCH_DEVICE_ID, deviceId)
        val pending = PendingIntent.getActivity(
            context,
            JarvisConnectionService.CAMERA_NOTIFICATION_ID,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_WATCH)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("JARVIS Watch")
            .setContentText("O relógio pediu uma foto. Toque para abrir a câmera.")
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        manager.notify(JarvisConnectionService.CAMERA_NOTIFICATION_ID, notification)
        true
    }.getOrDefault(false)

    fun showFamilyCall(callId: Long, mode: String) {
        runCatching {
            val open = PendingIntent.getActivity(
                context,
                callId.toInt(),
                Intent(context, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            val notification = NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_WATCH)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle("Chamada Família CASA")
                .setContentText("Chamada $mode iniciada pelo relógio (#$callId)")
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()
            manager.notify((3000 + callId % 1000).toInt(), notification)
        }
    }

    fun showJarvisNotification(n: JarvisApi.MobileNotification) {
        runCatching {
            val open = PendingIntent.getActivity(
                context,
                n.id.toInt(),
                Intent(context, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            val notification = NotificationCompat.Builder(context, JarvisConnectionService.CHANNEL_ALERTS)
                .setSmallIcon(R.drawable.ic_jarvis_launcher)
                .setContentTitle(n.title)
                .setContentText(n.message)
                .setStyle(NotificationCompat.BigTextStyle().bigText(n.message))
                .setPriority(
                    if (n.priority == "critica") NotificationCompat.PRIORITY_MAX
                    else NotificationCompat.PRIORITY_HIGH
                )
                .setAutoCancel(true)
                .setContentIntent(open)
                .build()
            manager.notify((2000 + (n.id % 100000)).toInt(), notification)
        }
    }
}
