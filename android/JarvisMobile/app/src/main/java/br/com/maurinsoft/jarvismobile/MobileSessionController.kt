package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat

/**
 * Orquestra a sessão do aplicativo e o ciclo do serviço de conexão.
 * Não mantém estado visual.
 */
class MobileSessionController(private val context: Context) {
    suspend fun login(user: String, password: String): MobileAuth.Session =
        MobileAuth.login(context, user, password)

    suspend fun validate(): MobileAuth.Session? =
        MobileAuth.validate(context)

    fun startConnectionService() {
        ContextCompat.startForegroundService(
            context,
            Intent(context, JarvisConnectionService::class.java)
        )
    }

    fun stopConnectionService() {
        context.stopService(Intent(context, JarvisConnectionService::class.java))
    }

    fun logout() {
        stopConnectionService()
        MobileAuth.logout(context)
    }
}
