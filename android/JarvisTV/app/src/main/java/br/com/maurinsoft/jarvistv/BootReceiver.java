package br.com.maurinsoft.jarvistv;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Liga o monitoramento quando a TV termina de iniciar, sem precisar abrir o app. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        String a = intent == null ? null : intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(a) || Intent.ACTION_MY_PACKAGE_REPLACED.equals(a)) {
            TvWatchService.iniciar(context);
        }
    }
}
