package br.com.maurinsoft.jarvismobile

import android.app.Notification
import android.content.Intent
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import androidx.core.content.ContextCompat

/**
 * Espelha para o JARVIS Watch apenas o conteúdo visível das notificações que o
 * usuário autorizou o Android a entregar ao JARVIS Mobile.
 *
 * Isso evita depender de READ_SMS. SMS, mensageiros e chamadas chegam pela
 * própria notificação publicada pelo aplicativo correspondente.
 */
class JarvisNotificationListener : NotificationListenerService() {

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        val notification = sbn?.notification ?: return
        if (sbn.packageName == packageName) return

        val extras = notification.extras ?: return
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString()?.trim().orEmpty()
        val text = (
            extras.getCharSequence(Notification.EXTRA_BIG_TEXT)
                ?: extras.getCharSequence(Notification.EXTRA_TEXT)
                ?: extras.getCharSequence(Notification.EXTRA_SUB_TEXT)
            )?.toString()?.trim().orEmpty()

        if (title.isBlank() && text.isBlank()) return

        val category = notification.category.orEmpty()
        val isCall = category == Notification.CATEGORY_CALL
        val isMessage = category == Notification.CATEGORY_MESSAGE ||
            category == Notification.CATEGORY_EMAIL ||
            looksLikeMessagingPackage(sbn.packageName)

        // Para não transformar o relógio em um espelho de spam, por padrão só
        // encaminhamos chamadas e mensagens. Outros tipos podem ser habilitados depois.
        if (!isCall && !isMessage) return

        val intent = Intent(this, JarvisConnectionService::class.java).apply {
            action = JarvisConnectionService.ACTION_FORWARD_NOTIFICATION
            putExtra(JarvisConnectionService.EXTRA_NOTIFICATION_TYPE, if (isCall) "incoming_call" else "phone_notification")
            putExtra(JarvisConnectionService.EXTRA_NOTIFICATION_TITLE, title.take(60))
            putExtra(JarvisConnectionService.EXTRA_NOTIFICATION_TEXT, text.take(120))
            putExtra(JarvisConnectionService.EXTRA_NOTIFICATION_PACKAGE, sbn.packageName)
        }

        ContextCompat.startForegroundService(this, intent)
    }

    private fun looksLikeMessagingPackage(packageName: String): Boolean {
        val p = packageName.lowercase()
        return p.contains("messag") ||
            p.contains("sms") ||
            p.contains("whatsapp") ||
            p.contains("telegram") ||
            p.contains("signal")
    }
}
