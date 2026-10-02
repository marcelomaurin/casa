package br.com.maurinsoft.jarvismobile

import org.json.JSONObject

object MobileReleaseManifest {
    data class Release(val version: String, val versionCode: Long, val apkUrl: String, val sha256: String)
    fun parse(json: String, channel: String, packageId: String): Release? {
        val root = JSONObject(json)
        require(root.optInt("schema_version") == 1)
        val item = root.optJSONObject("components")?.optJSONObject(channel) ?: return null
        if (!item.optBoolean("enabled", false)) return null
        require(item.optString("package_id") == packageId)
        val hash = item.getString("sha256").lowercase()
        require(hash.matches(Regex("[0-9a-f]{64}")))
        val url = item.getString("apk_url")
        require(url.startsWith("https://github.com/marcelomaurin/casa/releases/download/"))
        val version = item.getString("version")
        require(version.matches(Regex("[0-9]+\\.[0-9]+\\.[0-9]+")))
        val code = item.getLong("version_code")
        require(code > 0)
        return Release(version, code, url, hash)
    }
}
