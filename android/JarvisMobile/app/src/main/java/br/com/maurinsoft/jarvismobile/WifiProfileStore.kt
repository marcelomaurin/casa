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
 * Perfis Wi-Fi conhecidos pelo usuário e destinados ao JARVIS Watch.
 *
 * O Android não entrega a senha da rede Wi-Fi atual para aplicativos comuns.
 * Por isso o usuário informa a senha uma vez no JARVIS Mobile. Ela fica
 * criptografada com uma chave não exportável do Android Keystore e pode ser
 * reenviada ao relógio quando necessário.
 */
object WifiProfileStore {
    data class Profile(val slot: Int, val ssid: String, val password: String)

    private const val PREFS = "jarvis_wifi_profiles"
    private const val KEY_DATA = "encrypted_profiles"
    private const val KEY_ALIAS = "jarvis_wifi_profiles_aes_v1"
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

    fun load(context: Context): List<Profile> {
        val encoded = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_DATA, null)
            ?: return emptyList()

        return runCatching {
            val array = JSONArray(decrypt(encoded))
            buildList {
                for (i in 0 until array.length()) {
                    val item = array.getJSONObject(i)
                    val slot = item.optInt("slot", -1)
                    val ssid = item.optString("ssid").trim()
                    val password = item.optString("password")
                    if (slot in 0..4 && ssid.isNotBlank()) add(Profile(slot, ssid, password))
                }
            }.sortedBy { it.slot }
        }.getOrDefault(emptyList())
    }

    fun save(context: Context, profile: Profile) {
        require(profile.slot in 0..4)
        require(profile.ssid.isNotBlank())
        val profiles = load(context).filterNot { it.slot == profile.slot }.toMutableList()
        profiles += profile

        val array = JSONArray()
        profiles.sortedBy { it.slot }.forEach {
            array.put(
                JSONObject()
                    .put("slot", it.slot)
                    .put("ssid", it.ssid)
                    .put("password", it.password)
            )
        }

        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_DATA, encrypt(array.toString()))
            .apply()
    }

    fun remove(context: Context, slot: Int) {
        val profiles = load(context).filterNot { it.slot == slot }
        val array = JSONArray()
        profiles.forEach {
            array.put(
                JSONObject()
                    .put("slot", it.slot)
                    .put("ssid", it.ssid)
                    .put("password", it.password)
            )
        }

        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (profiles.isEmpty()) {
            prefs.edit().remove(KEY_DATA).apply()
        } else {
            prefs.edit().putString(KEY_DATA, encrypt(array.toString())).apply()
        }
    }
}
