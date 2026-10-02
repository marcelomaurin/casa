package br.com.maurinsoft.jarvismobile

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class UpdateDownloadReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != DownloadManager.ACTION_DOWNLOAD_COMPLETE) return
        val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L)
        val prefs = context.getSharedPreferences(UpdateManager.PREFS, Context.MODE_PRIVATE)
        if (id <= 0 || id != prefs.getLong("download_id", -2L)) return
        val manager = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        val successful = manager.query(DownloadManager.Query().setFilterById(id))?.use { c ->
            c.moveToFirst() && c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)) == DownloadManager.STATUS_SUCCESSFUL
        } ?: false
        if (!successful) {
            prefs.edit().remove("download_id").apply()
            UpdateManager.setStatus(context, "Falha no download. A próxima consulta tentará novamente.")
            return
        }
        // Não abre Activities do segundo plano: a notificação conduz ao instalador.
        UpdateManager.notifyReady(context)
    }
}
