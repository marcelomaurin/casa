package br.com.maurinsoft.jarvistv;

import org.junit.Test;
import static org.junit.Assert.*;

public class TvUpdatePolicyTest {
    private final String repo = "https://github.com/marcelomaurin/casa/releases/download/jarvis-tv-v1.0.1-debug/JarvisTV-v1.0.1-debug.apk";
    private final String sha = "a".repeat(64);

    @Test public void acceptsOnlyNewVersionFromTrustedReleaseAndSamePackage() {
        assertTrue(TvUpdatePolicy.allowed(100,101,"br.com.maurinsoft.jarvistv.debug",
                "br.com.maurinsoft.jarvistv.debug",sha,repo));
        assertFalse(TvUpdatePolicy.allowed(101,101,"br.com.maurinsoft.jarvistv.debug",
                "br.com.maurinsoft.jarvistv.debug",sha,repo));
    }
    @Test public void refusesTamperedOrForeignBuilds() {
        assertFalse(TvUpdatePolicy.allowed(100,101,"br.com.maurinsoft.jarvistv.debug",
                "br.com.maurinsoft.jarvistv",sha,repo));
        assertFalse(TvUpdatePolicy.allowed(100,101,"br.com.maurinsoft.jarvistv.debug",
                "br.com.maurinsoft.jarvistv.debug","",repo));
        assertFalse(TvUpdatePolicy.allowed(100,101,"br.com.maurinsoft.jarvistv.debug",
                "br.com.maurinsoft.jarvistv.debug",sha,"https://example.org/app.apk"));
    }
}
