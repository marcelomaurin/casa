package br.com.maurinsoft.jarvismobile

import android.content.Context
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

/** Cliente/consumidor do Control Plane para o proprio Casa Mobile. */
object DeviceCommandApi {
    data class Command(val id:Long,val name:String,val payload:JSONObject,val priority:String,val correlationId:String?)
    private val jsonType="application/json; charset=utf-8".toMediaType()
    private val client=OkHttpClient.Builder().connectTimeout(8,TimeUnit.SECONDS).readTimeout(20,TimeUnit.SECONDS).writeTimeout(15,TimeUnit.SECONDS).retryOnConnectionFailure(true).build()
    private val running=AtomicBoolean(false)
    private val executor=Executors.newSingleThreadExecutor()

    private fun request(context:Context,action:String,body:JSONObject?=null):JSONObject{
        val cfg=JarvisApi.loadConfig(context)
        require(cfg.baseUrl.startsWith("https://")){"Use uma URL HTTPS do JARVIS"}
        require(cfg.token.isNotBlank()){"Token do Casa Mobile nao configurado"}
        val b=Request.Builder().url(cfg.baseUrl.trimEnd('/')+"/api/v1/device.php?acao=$action").header("Authorization","Bearer ${cfg.token}").header("X-Device-Token",cfg.token).header("Accept","application/json")
        if(body==null)b.get() else b.post(body.toString().toRequestBody(jsonType))
        client.newCall(b.build()).execute().use{r->val raw=r.body?.string().orEmpty();if(!r.isSuccessful)throw IllegalStateException("HTTP ${r.code}: ${raw.take(300)}");return JSONObject(raw)}
    }

    fun poll(context:Context,limit:Int=10):List<Command>{
        val arr=request(context,"commands&limit=${limit.coerceIn(1,20)}").optJSONArray("commands")?:JSONArray();val out=ArrayList<Command>()
        for(i in 0 until arr.length()){val j=arr.optJSONObject(i)?:continue;val id=j.optLong("id");val name=j.optString("comando").trim();if(id<=0||name.isBlank())continue;val payload=when(val raw=j.opt("payload")){is JSONObject->raw;is String->runCatching{JSONObject(raw)}.getOrElse{JSONObject()};else->JSONObject()};out+=Command(id,name,payload,j.optString("prioridade","normal"),j.optString("correlation_id").takeIf{it.isNotBlank()&&it!="null"})}
        return out
    }
    fun ack(context:Context,id:Long){request(context,"command_ack",JSONObject().put("id",id))}
    fun start(context:Context,id:Long){request(context,"command_start",JSONObject().put("id",id))}
    fun result(context:Context,id:Long,ok:Boolean,result:JSONObject=JSONObject(),error:String?=null){request(context,"command_result",JSONObject().put("id",id).put("status",if(ok)"success" else "error").put("result",result).apply{if(!ok)put("error",error?:"Falha no Casa Mobile")})}

    fun startLoop(context:Context){
        if(!running.compareAndSet(false,true))return
        val app=context.applicationContext
        executor.execute{
            val notifier=JarvisServiceNotifier(app)
            while(running.get()){
                try{
                    if(JarvisApi.isConfigured(app)){
                        poll(app).forEach{cmd->
                            runCatching{ack(app,cmd.id)}
                            runCatching{start(app,cmd.id)}
                            val supported=cmd.name in setOf("emergency.sos","emergency.location","emergency.cancelled")
                            if(supported){
                                val shown=notifier.showEmergency(cmd.name,cmd.payload)
                                result(app,cmd.id,shown,JSONObject().put("handled",shown).put("command",cmd.name).put("surface","android_notification"),if(shown)null else "notification_unavailable")
                            }else{
                                result(app,cmd.id,false,JSONObject().put("handled",false).put("command",cmd.name),"unsupported_mobile_command")
                            }
                        }
                    }
                }catch(_:Throwable){}
                try{Thread.sleep(5000)}catch(_:InterruptedException){Thread.currentThread().interrupt();running.set(false)}
            }
        }
    }
}
