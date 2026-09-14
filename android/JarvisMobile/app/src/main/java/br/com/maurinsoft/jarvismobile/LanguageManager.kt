package br.com.maurinsoft.jarvismobile

import android.content.Context
import java.util.Locale

object LanguageManager {
    private const val PREFS = "jarvis"
    private const val KEY_LANGUAGE = "app_language"
    private const val KEY_AUTO = "app_language_auto"

    data class Language(
        val tag: String,
        val label: String
    )

    val supported = listOf(
        Language("pt-BR", "Português (Brasil)"),
        Language("en-US", "English"),
        Language("es-ES", "Español")
    )

    fun detectSystemLanguage(): String {
        val locale = Locale.getDefault()
        return when (locale.language.lowercase(Locale.ROOT)) {
            "pt" -> "pt-BR"
            "es" -> "es-ES"
            "en" -> "en-US"
            else -> "pt-BR"
        }
    }

    fun isAutomatic(context: Context): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getBoolean(KEY_AUTO, true)

    fun currentLanguage(context: Context): String {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val automatic = prefs.getBoolean(KEY_AUTO, true)
        if (automatic) return detectSystemLanguage()
        return prefs.getString(KEY_LANGUAGE, detectSystemLanguage()) ?: detectSystemLanguage()
    }

    fun setAutomatic(context: Context, automatic: Boolean) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_AUTO, automatic)
            .apply()
    }

    fun setLanguage(context: Context, tag: String) {
        val normalized = supported.firstOrNull { it.tag.equals(tag, ignoreCase = true) }?.tag
            ?: detectSystemLanguage()
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_AUTO, false)
            .putString(KEY_LANGUAGE, normalized)
            .apply()
    }

    fun recognitionTag(context: Context): String = currentLanguage(context)

    fun languageLabel(context: Context): String {
        val tag = currentLanguage(context)
        return supported.firstOrNull { it.tag == tag }?.label ?: tag
    }
}
