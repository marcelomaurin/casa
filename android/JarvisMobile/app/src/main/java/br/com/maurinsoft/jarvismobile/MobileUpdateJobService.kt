package br.com.maurinsoft.jarvismobile

import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.app.job.JobService
import android.content.ComponentName
import android.content.Context

/** Verifica versões no GitHub em segundo plano, mesmo sem a tela do chat aberta. */
class MobileUpdateJobService : JobService() {
    override fun onStartJob(params: JobParameters): Boolean {
        Thread({
            try {
                UpdateManager.checkAndDownloadBlocking(applicationContext)
            } finally {
                jobFinished(params, false)
            }
        }, "casa-mobile-updater").start()
        return true
    }

    override fun onStopJob(params: JobParameters): Boolean = true

    companion object {
        private const val JOB_ID = 21002

        fun schedule(context: Context) {
            val scheduler = context.getSystemService(Context.JOB_SCHEDULER_SERVICE) as? JobScheduler ?: return
            if (scheduler.getPendingJob(JOB_ID) != null) return
            val job = JobInfo.Builder(JOB_ID, ComponentName(context, MobileUpdateJobService::class.java))
                .setPeriodic(60L * 60L * 1000L)
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setPersisted(true)
                .build()
            scheduler.schedule(job)
        }
    }
}
