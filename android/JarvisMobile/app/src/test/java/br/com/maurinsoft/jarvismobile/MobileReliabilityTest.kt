package br.com.maurinsoft.jarvismobile

import org.junit.Assert.*
import org.junit.Test

class MobileReliabilityTest {
    private val manifest = """{"schema_version":1,"components":{"casa-mobile-debug":{"enabled":true,"version":"2.8.0","version_code":280,"package_id":"br.com.maurinsoft.jarvismobile.debug","apk_url":"https://github.com/marcelomaurin/casa/releases/download/v/test.apk","sha256":"${"a".repeat(64)}"}}}"""
    private fun parse(value: String = manifest) = MobileReleaseManifest.parse(value, "casa-mobile-debug", "br.com.maurinsoft.jarvismobile.debug")
    @Test fun selectsIndicatedVersion() { assertEquals(280L, parse()!!.versionCode) }
    @Test fun unpublishedVersionIgnored() { assertNull(parse(manifest.replace("true", "false"))) }
    @Test fun otherChannelIgnored() { assertNull(MobileReleaseManifest.parse(manifest, "casa-mobile", "br.com.maurinsoft.jarvismobile")) }
    @Test(expected = IllegalArgumentException::class) fun refusesWrongPackage() { parse(manifest.replace("jarvismobile.debug", "other.debug")) }
    @Test(expected = IllegalArgumentException::class) fun refusesMissingHash() { parse(manifest.replace("a".repeat(64), "")) }
    @Test(expected = IllegalArgumentException::class) fun refusesExternalApk() { parse(manifest.replace("https://github.com/marcelomaurin/casa/releases/download/", "https://example.com/")) }
    @Test fun expiresOldCommands() { assertFalse(PendingCommandPolicy.isFresh(1000, 301001)) }
    @Test fun refusesFutureOrLegacyTimestamp() { assertFalse(PendingCommandPolicy.isFresh(0, 1000)); assertFalse(PendingCommandPolicy.isFresh(2000, 1000)) }
    @Test fun allowsFreshCommandAtBoundary() { assertTrue(PendingCommandPolicy.isFresh(1000, 301000)) }
}
