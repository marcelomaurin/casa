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

/** Responsabilidade unica: canais e notificacoes do servico. */
class JarvisServiceNotifier(private val context: Context) {
    private val manager: NotificationManager get() = context.getSystemService(NotificationManager::class.java)

    fun createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        manager.createNotificationChannel(NotificationChannel(JarvisConnectionService.CHANNEL_SERVICE,"Conexao JARVIS",NotificationManager.IMPORTANCE_LOW))
        manager.createNotificationChannel(NotificationChannel(JarvisConnectionService.CHANNEL_ALERTS,"Alertas JARVIS",NotificationManager.IMPORTANCE_HIGH))
        manager.createNotificationChannel(NotificationChannel(JarvisConnectionService.CHANNEL_WATCH,"JARVIS Watch",NotificationManager.IMPORTANCE_HIGH))
        val alarmSound=RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)?:RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        val attrs=AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build()
        manager.createNotificationChannel(NotificationChannel(JarvisConnectionService.CHANNEL_WATCH_ALARM,"Alarmes do JARVIS Watch",NotificationManager.IMPORTANCE_HIGH).apply{description="Alarmes solicitados pelo relogio JARVIS";enableVibration(true);vibrationPattern=longArrayOf(0,350,180,350,180,700);setSound(alarmSound,attrs);lockscreenVisibility=Notification.VISIBILITY_PUBLIC})
    }

    fun serviceNotification(text:String):Notification {
        val open=PendingIntent.getActivity(context,0,Intent(context,MainActivity::class.java),PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val setup=PendingIntent.getActivity(context,1,Intent(context,WatchSetupActivity::class.java),PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_SERVICE).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle("Casa Mobile").setContentText(text).setOngoing(true).setContentIntent(open).addAction(0,"Relogio",setup).build()
    }
    fun updateService(text:String){manager.notify(JarvisConnectionService.NOTIFICATION_ID,serviceNotification(text))}
    fun canNotify():Boolean=Build.VERSION.SDK_INT<33||ActivityCompat.checkSelfPermission(context,Manifest.permission.POST_NOTIFICATIONS)==PackageManager.PERMISSION_GRANTED

    fun showEmergency(command:String,data:JSONObject):Boolean=runCatching{
        if(!canNotify()) return false
        val source=data.optString("source_device_id",data.optString("device_id","JARVIS Watch"))
        val session=data.optString("session_id")
        val title:String
        val text:String
        when(command){
            "emergency.sos"->{title="SOS JARVIS";text=data.optString("message").ifBlank{"Pedido de ajuda recebido de $source"}}
            "emergency.location"->{val lat=data.optDouble("latitude",data.optDouble("lat",Double.NaN));val lon=data.optDouble("longitude",data.optDouble("lon",Double.NaN));title="Localizacao do SOS";text=if(lat.isFinite()&&lon.isFinite())"$source: %.6f, %.6f".format(lat,lon) else data.optString("message").ifBlank{"Localizacao atualizada por $source"}}
            "emergency.cancelled"->{title="SOS cancelado";text=data.optString("message").ifBlank{"Emergencia cancelada por $source"}}
            else->return false
        }
        val open=PendingIntent.getActivity(context,(session.hashCode() and 0x7fffffff),Intent(context,MainActivity::class.java),PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val alarm=RingtoneManager.getDefaultUri(if(command=="emergency.sos") RingtoneManager.TYPE_ALARM else RingtoneManager.TYPE_NOTIFICATION)
        val n=NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_ALERTS).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle(title).setContentText(text).setStyle(NotificationCompat.BigTextStyle().bigText(text)).setPriority(NotificationCompat.PRIORITY_MAX).setCategory(if(command=="emergency.sos") NotificationCompat.CATEGORY_ALARM else NotificationCompat.CATEGORY_STATUS).setVisibility(NotificationCompat.VISIBILITY_PUBLIC).setAutoCancel(command!="emergency.sos").setContentIntent(open).setSound(alarm).setVibrate(longArrayOf(0,350,180,350,180,700)).build()
        manager.notify(5000+(session.hashCode() and 0x3ff),n);true
    }.getOrDefault(false)

    fun showVoiceRequest(deviceId:String):Boolean=runCatching{if(!canNotify())return false;val intent=Intent(context,WatchVoiceActivity::class.java).putExtra(WatchVoiceActivity.EXTRA_WATCH_DEVICE_ID,deviceId);val pending=PendingIntent.getActivity(context,JarvisConnectionService.VOICE_NOTIFICATION_ID,intent,PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE);manager.notify(JarvisConnectionService.VOICE_NOTIFICATION_ID,NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_WATCH).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle("JARVIS Watch - voz").setContentText("Toque para falar com o JARVIS pelo celular.").setPriority(NotificationCompat.PRIORITY_HIGH).setAutoCancel(true).setContentIntent(pending).build());true}.getOrDefault(false)
    fun showWatchAlarm(data:JSONObject):Boolean=runCatching{val label=data.optString("message").ifBlank{data.optString("tone")}.ifBlank{"Alarme acionado pelo JARVIS Watch"};val open=PendingIntent.getActivity(context,JarvisConnectionService.ALARM_NOTIFICATION_ID,Intent(context,MainActivity::class.java),PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE);val sound=RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)?:RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);manager.notify(JarvisConnectionService.ALARM_NOTIFICATION_ID,NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_WATCH_ALARM).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle("Alarme JARVIS Watch").setContentText(label).setCategory(NotificationCompat.CATEGORY_ALARM).setPriority(NotificationCompat.PRIORITY_MAX).setSound(sound).setVibrate(longArrayOf(0,350,180,350,180,700)).setAutoCancel(true).setContentIntent(open).build());true}.getOrDefault(false)
    fun showCameraRequest(deviceId:String):Boolean=runCatching{val intent=Intent(context,WatchCameraActivity::class.java).putExtra(WatchCameraActivity.EXTRA_WATCH_DEVICE_ID,deviceId);val pending=PendingIntent.getActivity(context,JarvisConnectionService.CAMERA_NOTIFICATION_ID,intent,PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE);manager.notify(JarvisConnectionService.CAMERA_NOTIFICATION_ID,NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_WATCH).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle("JARVIS Watch").setContentText("O relogio pediu uma foto. Toque para abrir a camera.").setPriority(NotificationCompat.PRIORITY_HIGH).setAutoCancel(true).setContentIntent(pending).build());true}.getOrDefault(false)
    fun showFamilyCall(callId:Long,mode:String,initiator:Boolean=false){runCatching{val openIntent=Intent(context,FamilyCallActivity::class.java).putExtra(FamilyCallActivity.EXTRA_CALL_ID,callId).putExtra(FamilyCallActivity.EXTRA_CALL_MODE,mode).putExtra(FamilyCallActivity.EXTRA_AUTO_JOIN,!initiator).putExtra(FamilyCallActivity.EXTRA_INITIATOR,initiator);val open=PendingIntent.getActivity(context,callId.toInt(),openIntent,PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE);manager.notify((3000+callId%1000).toInt(),NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_WATCH).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle("Videochamada Familia CASA").setContentText(if(initiator)"Toque para usar o celular como camera da chamada (#$callId)" else "Toque para atender a videochamada (#$callId)").setPriority(NotificationCompat.PRIORITY_HIGH).setCategory(NotificationCompat.CATEGORY_CALL).setAutoCancel(true).setContentIntent(open).addAction(0,"ATENDER",open).build())}}
    fun showJarvisNotification(n:JarvisApi.MobileNotification){runCatching{val open=PendingIntent.getActivity(context,n.id.toInt(),Intent(context,MainActivity::class.java),PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE);manager.notify((2000+(n.id%100000)).toInt(),NotificationCompat.Builder(context,JarvisConnectionService.CHANNEL_ALERTS).setSmallIcon(R.drawable.ic_jarvis_launcher).setContentTitle(n.title).setContentText(n.message).setStyle(NotificationCompat.BigTextStyle().bigText(n.message)).setPriority(if(n.priority=="critica")NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH).setAutoCancel(true).setContentIntent(open).build())}}
}
