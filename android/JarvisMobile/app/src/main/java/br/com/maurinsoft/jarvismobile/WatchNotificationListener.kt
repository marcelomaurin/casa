package br.com.maurinsoft.jarvismobile

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

class WatchNotificationListener : NotificationListenerService() {
    companion object {
        private const val PREFS = "jarvis_watch_notifications"
        private const val KEY_ENABLED = "enabled"

        fun isEnabled(context: Context): Boolean =
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getBoolean(KEY_ENABLED, false)

        fun setEnabled(context: Context, enabled: Boolean) {
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().putBoolean(KEY_ENABLED, enabled).apply()
        }

        fun hasSystemAccess(context: Context): Boolean {
            val flat = Settings.Secure.getString(
                context.contentResolver,
                "enabled_notification_listeners"
            ).orEmpty()
            val component = ComponentName(context, WatchNotificationListener::class.java)
                .flattenToString()
            return flat.split(':').any { it.equals(component, true) }
        }
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (!isEnabled(this)) return
        if (sbn.packageName == packageName) return

        val n = sbn.notification ?: return
        if ((n.flags and Notification.FLAG_ONGOING_EVENT) != 0) return
        if ((n.flags and Notification.FLAG_GROUP_SUMMARY) != 0) return

        val extras = n.extras ?: return
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString().orEmpty().trim()
        val text = (
            extras.getCharSequence(Notification.EXTRA_BIG_TEXT)
                ?: extras.getCharSequence(Notification.EXTRA_TEXT)
                ?: extras.getCharSequence(Notification.EXTRA_SUB_TEXT)
            )?.toString().orEmpty().trim()

        if (title.isBlank() && text.isBlank()) return

        val priority = when {
            n.category == Notification.CATEGORY_CALL -> "high"
            n.category == Notification.CATEGORY_ALARM -> "high"
            else -> "normal"
        }

        scope.launch {
            runCatching {
                WatchApi.forwardNotification(
                    this@WatchNotificationListener,
                    sbn.packageName,
                    title,
                    text,
                    priority
                )
            }
        }
    }
}
