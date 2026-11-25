package br.com.maurinsoft.jarvismobile

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/** Preferencias do compartilhamento de localizacao (desligado por padrao). */
object LocalizacaoPrefs {
    private const val PREFS = "casa_localizacao"
    private const val KEY_ATIVO = "ativo"
    private const val KEY_INTERVALO = "intervalo_min"
    private const val KEY_ULTIMO = "ultimo_envio"
    private const val KEY_STATUS = "status"

    val INTERVALOS_MIN = listOf(1, 5, 15, 30)

    private fun p(c: Context) = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    fun ativo(c: Context) = p(c).getBoolean(KEY_ATIVO, false)
    fun intervaloMin(c: Context) = p(c).getInt(KEY_INTERVALO, 5).takeIf { it in INTERVALOS_MIN } ?: 5
    fun ultimoEnvio(c: Context) = p(c).getLong(KEY_ULTIMO, 0L)
    fun status(c: Context) = p(c).getString(KEY_STATUS, "").orEmpty()
    fun definirAtivo(c: Context, v: Boolean) = p(c).edit().putBoolean(KEY_ATIVO, v).apply()
    fun definirIntervalo(c: Context, min: Int) = p(c).edit().putInt(KEY_INTERVALO, min).apply()
    fun registrarEnvio(c: Context, quando: Long, status: String) =
        p(c).edit().putLong(KEY_ULTIMO, quando).putString(KEY_STATUS, status).apply()
    fun registrarStatus(c: Context, status: String) = p(c).edit().putString(KEY_STATUS, status).apply()

    fun temPermissao(c: Context): Boolean =
        ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

    /** Chamado com o app em primeiro plano: retoma o envio se o usuario deixou ligado. */
    fun retomarSeAtivo(c: Context) {
        if (ativo(c) && temPermissao(c)) runCatching { LocalizacaoService.iniciar(c) }
    }
}

/**
 * Servico em primeiro plano (tipo localizacao) que envia a posicao do celular a CASA
 * no intervalo escolhido. Mostra notificacao permanente enquanto compartilha.
 */
class LocalizacaoService : Service() {
    companion object {
        private const val CANAL = "casa_localizacao"
        private const val NOTIFICACAO_ID = 4710
        private const val ACAO_PARAR = "br.com.maurinsoft.jarvismobile.PARAR_LOCALIZACAO"

        fun iniciar(c: Context) {
            ContextCompat.startForegroundService(c, Intent(c, LocalizacaoService::class.java))
        }

        fun parar(c: Context) {
            c.stopService(Intent(c, LocalizacaoService::class.java))
        }
    }

    private val escopo = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var gerenciador: LocationManager? = null
    @Volatile private var enviando = false

    private val ouvinte = LocationListener { local -> aoReceber(local) }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACAO_PARAR) {
            LocalizacaoPrefs.definirAtivo(this, false)
            LocalizacaoPrefs.registrarStatus(this, "Compartilhamento desligado pela notificação")
            stopSelf()
            return START_NOT_STICKY
        }
        if (!LocalizacaoPrefs.ativo(this) || !LocalizacaoPrefs.temPermissao(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        criarCanal()
        val tipo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        try {
            ServiceCompat.startForeground(this, NOTIFICACAO_ID, notificacao(), tipo)
        } catch (e: Exception) {
            // Android pode recusar iniciar em segundo plano; o app tenta de novo ao ser aberto.
            LocalizacaoPrefs.registrarStatus(this, "Aguardando abrir o app para retomar o envio")
            stopSelf()
            return START_NOT_STICKY
        }
        solicitarAtualizacoes()
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun solicitarAtualizacoes() {
        val lm = getSystemService(LocationManager::class.java) ?: return
        gerenciador = lm
        lm.removeUpdates(ouvinte)
        val intervaloMs = LocalizacaoPrefs.intervaloMin(this) * 60_000L
        val provedores = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .filter { runCatching { lm.isProviderEnabled(it) }.getOrDefault(false) }
        if (provedores.isEmpty()) {
            LocalizacaoPrefs.registrarStatus(this, "Localização do Android desligada")
            return
        }
        for (prov in provedores) {
            runCatching { lm.requestLocationUpdates(prov, intervaloMs, 25f, ouvinte, Looper.getMainLooper()) }
        }
        // Envia logo a ultima posicao conhecida, sem esperar o primeiro intervalo.
        provedores.mapNotNull { runCatching { lm.getLastKnownLocation(it) }.getOrNull() }
            .maxByOrNull { it.time }?.let { aoReceber(it) }
    }

    private fun aoReceber(local: Location) {
        val agora = System.currentTimeMillis()
        val intervaloMs = LocalizacaoPrefs.intervaloMin(this) * 60_000L
        if (enviando || agora - LocalizacaoPrefs.ultimoEnvio(this) < intervaloMs - 5_000) return
        enviando = true
        escopo.launch {
            val r = runCatching {
                LocalizacaoApi.enviar(
                    this@LocalizacaoService, local.latitude, local.longitude,
                    if (local.hasAccuracy()) local.accuracy else null,
                    if (local.hasAltitude()) local.altitude else null,
                    if (local.hasSpeed()) local.speed else null,
                    bateria(), if (local.provider == LocationManager.GPS_PROVIDER) "gps" else "rede", local.time
                )
            }
            val hora = java.text.SimpleDateFormat("HH:mm", java.util.Locale("pt", "BR")).format(java.util.Date(agora))
            r.onSuccess { LocalizacaoPrefs.registrarEnvio(this@LocalizacaoService, agora, "Última posição enviada às $hora") }
                .onFailure { LocalizacaoPrefs.registrarStatus(this@LocalizacaoService, "Falha ao enviar às $hora: ${it.message}") }
            enviando = false
        }
    }

    private fun bateria(): Int? = runCatching {
        val b = getSystemService(BatteryManager::class.java).getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        b.takeIf { it in 0..100 }
    }.getOrNull()

    private fun criarCanal() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        getSystemService(NotificationManager::class.java).createNotificationChannel(
            NotificationChannel(CANAL, "Localização compartilhada com a CASA", NotificationManager.IMPORTANCE_LOW)
        )
    }

    private fun notificacao(): Notification {
        val abrir = PendingIntent.getActivity(this, 0, Intent(this, LocalizacaoActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val parar = PendingIntent.getService(this, 1, Intent(this, LocalizacaoService::class.java).setAction(ACAO_PARAR),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, CANAL)
            .setSmallIcon(R.drawable.ic_jarvis_launcher)
            .setContentTitle("Compartilhando localização com a CASA")
            .setContentText("A cada ${LocalizacaoPrefs.intervaloMin(this)} min. Toque para ajustar.")
            .setOngoing(true)
            .setContentIntent(abrir)
            .addAction(0, "Parar", parar)
            .build()
    }

    override fun onDestroy() {
        runCatching { gerenciador?.removeUpdates(ouvinte) }
        escopo.cancel()
        super.onDestroy()
    }
}
