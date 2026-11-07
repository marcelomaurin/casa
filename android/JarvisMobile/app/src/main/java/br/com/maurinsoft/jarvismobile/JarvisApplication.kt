package br.com.maurinsoft.jarvismobile

import android.app.Application

class JarvisApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        MobileUpdateJobService.schedule(this)
        UpdateManager.checkAndDownloadAsync(this)
        DeviceCommandApi.startLoop(this)
    }
}
