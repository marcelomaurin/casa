package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONArray
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Guarda, de forma criptografada, as identidades de Watch criadas pelo app.
 *
 * O servidor exibe o device_token somente no momento do provisionamento.
 * Manter uma copia protegida no celular permite reenviar a credencial ao
 * relogio caso a configuracao BLE seja interrompida.
 */
object WatchProvisionStore {
    data class Entry(
        val address: String,
        val deviceId: String,
        val name: String,
        val location: String,
        val baseUrl: String,
        val token: String,
        val createdAt: Long
    )

    private const val PREFS = "jarvis_watch_provision"
    private const val KEY_DATA = "encrypted_watch_devices"
    private const val KEY_ALIAS = "jarvis_watch_provision_aes_v1"
    private const val TRANSFORMATION = "AES/GCM/NoPadding"

    private fun secretKey(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES,
            "AndroidKeyStore"
        )
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return generator.generateKey()
    }

    private fun encrypt(plain: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val encrypted = cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        val blob = ByteArray(1 + cipher.iv.size + encrypted.size)
        blob[0] = cipher.iv.size.toByte()
        System.arraycopy(cipher.iv, 0, blob, 1, cipher.iv.size)
        System.arraycopy(encrypted, 0, blob, 1 + cipher.iv.size, encrypted.size)
        return Base64.encodeToString(blob, Base64.NO_WRAP)
    }

    private fun decrypt(encoded: String): String {
        val blob = Base64.decode(encoded, Base64.NO_WRAP)
        require(blob.isNotEmpty())
        val ivLen = blob[0].toInt() and 0xff
        require(ivLen in 12..32 && blob.size > 1 + ivLen)
        val iv = blob.copyOfRange(1, 1 + ivLen)
        val encrypted = blob.copyOfRange(1 + ivLen, blob.size)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, iv))
        return cipher.doFinal(encrypted).toString(Charsets.UTF_8)
    }

    fun load(context: Context): List<Entry> {
        val encoded = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_DATA, null) ?: return emptyList()
        return runCatching {
            val arr = JSONArray(decrypt(encoded))
            buildList {
                for (i in 0 until arr.length()) {
                    val o = arr.optJSONObject(i) ?: continue
                    val address = o.optString("address")
                    val deviceId = o.optString("device_id")
                    val token = o.optString("token")
                    if (address.isBlank() || deviceId.isBlank() || token.isBlank()) continue
                    add(
                        Entry(
                            address = address,
                            deviceId = deviceId,
                            name = o.optString("name", "JARVIS Watch"),
                            location = o.optString("location", "Residencia"),
                            baseUrl = o.optString("base_url", "https://casa.maurinsoft.com.br").let { if (it == "https://maurinsoft.com.br/casa") "https://casa.maurinsoft.com.br" else it },
                            token = token,
                            createdAt = o.optLong("created_at", 0L)
                        )
                    )
                }
            }
        }.getOrDefault(emptyList())
    }

    fun findByAddress(context: Context, address: String): Entry? =
        load(context).firstOrNull { it.address.equals(address, true) }

    fun save(context: Context, entry: Entry) {
        val items = load(context)
            .filterNot { it.address.equals(entry.address, true) || it.deviceId == entry.deviceId }
            .toMutableList()
        items += entry

        val arr = JSONArray()
        items.forEach {
            arr.put(
                JSONObject()
                    .put("address", it.address)
                    .put("device_id", it.deviceId)
                    .put("name", it.name)
                    .put("location", it.location)
                    .put("base_url", it.baseUrl)
                    .put("token", it.token)
                    .put("created_at", it.createdAt)
            )
        }

        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putString(KEY_DATA, encrypt(arr.toString())).apply()
    }

    fun remove(context: Context, address: String) {
        val items = load(context).filterNot { it.address.equals(address, true) }
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (items.isEmpty()) {
            prefs.edit().remove(KEY_DATA).apply()
            return
        }
        val arr = JSONArray()
        items.forEach {
            arr.put(
                JSONObject()
                    .put("address", it.address)
                    .put("device_id", it.deviceId)
                    .put("name", it.name)
                    .put("location", it.location)
                    .put("base_url", it.baseUrl)
                    .put("token", it.token)
                    .put("created_at", it.createdAt)
            )
        }
        prefs.edit().putString(KEY_DATA, encrypt(arr.toString())).apply()
    }
}
