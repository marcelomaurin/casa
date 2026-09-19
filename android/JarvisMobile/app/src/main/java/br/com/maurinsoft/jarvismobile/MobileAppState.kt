package br.com.maurinsoft.jarvismobile

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class MobileAppState(private val context: Context) {
    var splash by mutableStateOf(true)
        private set
    var session by mutableStateOf(MobileAuth.savedSession(context))
        private set
    var online by mutableStateOf(false)
        private set
    var configured by mutableStateOf(JarvisApi.isConfigured(context))
        private set
    var pending by mutableIntStateOf(JarvisApi.pendingCount(context))
        private set

    fun finishSplash() {
        session = MobileAuth.savedSession(context)
        splash = false
    }

    suspend fun validateSession() {
        val refreshed = withContext(Dispatchers.IO) { MobileAuth.validate(context) }
        if (refreshed != null) session = refreshed
    }

    fun onLoggedIn(value: MobileAuth.Session) {
        session = value
    }

    fun clearLocalSession() {
        session = null
    }

    suspend fun refreshConnection() {
        configured = JarvisApi.isConfigured(context)
        online = if (configured) withContext(Dispatchers.IO) { JarvisApi.isOnline(context) } else false
        pending = JarvisApi.pendingCount(context)
    }
}
