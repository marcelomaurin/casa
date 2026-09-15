package br.com.maurinsoft.jarvismobile

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Armazena o token do próprio JARVIS Mobile protegido pelo Android Keystore.
 * O app é o ponto focal de provisionamento dos hardwares e, por isso, a
 * credencial do celular não deve permanecer em SharedPreferences em texto claro.
 */
object AppTokenStore {
    private const val PREFS = "jarvis"
    private const val KEY_ENCRYPTED = "device_token_encrypted"
    private const val LEGACY_KEY = "device_token"
    private const val KEY_ALIAS = "jarvis_mobile_token_aes_v1"
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

    private fun encrypt(value: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
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

    fun load(context: Context): String {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val encrypted = prefs.getString(KEY_ENCRYPTED, null)
        if (!encrypted.isNullOrBlank()) {
            return runCatching { decrypt(encrypted) }.getOrDefault("")
        }

        // Migração transparente da versão anterior.
        val legacy = prefs.getString(LEGACY_KEY, "")?.trim().orEmpty()
        if (legacy.isNotBlank()) {
            save(context, legacy)
            prefs.edit().remove(LEGACY_KEY).apply()
        }
        return legacy
    }

    fun save(context: Context, token: String) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val value = token.trim()
        if (value.isBlank()) {
            prefs.edit().remove(KEY_ENCRYPTED).remove(LEGACY_KEY).apply()
        } else {
            prefs.edit()
                .putString(KEY_ENCRYPTED, encrypt(value))
                .remove(LEGACY_KEY)
                .apply()
        }
    }
}
