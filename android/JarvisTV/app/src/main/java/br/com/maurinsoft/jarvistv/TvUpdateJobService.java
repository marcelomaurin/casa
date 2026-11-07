package br.com.maurinsoft.jarvistv;

import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;

/** Android JobScheduler reconsulta o manifesto mesmo com a TV fora da tela principal. */
public final class TvUpdateJobService extends JobService {
    private static final int JOB_ID = 21001;

    public static void schedule(Context context) {
        JobScheduler scheduler = (JobScheduler) context.getSystemService(Context.JOB_SCHEDULER_SERVICE);
        if (scheduler == null) return;
        if (scheduler.getPendingJob(JOB_ID) != null) return;
        JobInfo job = new JobInfo.Builder(JOB_ID, new ComponentName(context, TvUpdateJobService.class))
                .setPeriodic(60L * 60L * 1000L)
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setPersisted(true)
                .build();
        scheduler.schedule(job);
    }

    @Override public boolean onStartJob(JobParameters params) {
        new Thread(() -> {
            try {
                TvUpdateManager.checkBlocking(getApplicationContext());
            } finally {
                jobFinished(params, false);
            }
        }, "casa-tv-update").start();
        return true;
    }

    @Override public boolean onStopJob(JobParameters params) {
        return true;
    }
}
