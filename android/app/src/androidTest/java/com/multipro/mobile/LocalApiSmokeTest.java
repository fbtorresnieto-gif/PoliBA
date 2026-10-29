package com.multipro.mobile;

import androidx.test.ext.junit.runners.AndroidJUnit4;
import androidx.test.platform.app.InstrumentationRegistry;
import org.junit.Assume;
import org.junit.Test;
import org.junit.runner.RunWith;
import com.google.gson.JsonParser;
import java.net.HttpURLConnection;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import static org.junit.Assert.*;

/** Opt-in read-only smoke test against real local PHP via adb reverse. */
@RunWith(AndroidJUnit4.class)
public class LocalApiSmokeTest {
    @Test public void localEntitiesEndpointResponds() throws Exception {
        Assume.assumeTrue("local".equals(BuildConfig.BUILD_TYPE));
        Assume.assumeTrue("true".equals(InstrumentationRegistry.getArguments().getString("localApiSmoke")));
        assertEquals("http://127.0.0.1:8080/api/v1/",BuildConfig.API_BASE_URL);
        HttpURLConnection connection=(HttpURLConnection)URI.create(BuildConfig.API_BASE_URL+"entidades.php").toURL().openConnection();
        connection.setConnectTimeout(10000);connection.setReadTimeout(10000);connection.setInstanceFollowRedirects(false);
        try {
            assertEquals(200,connection.getResponseCode());
            String body=new String(connection.getInputStream().readAllBytes(),StandardCharsets.UTF_8);
            assertTrue(JsonParser.parseString(body).getAsJsonObject().getAsJsonArray("data").size()>0);
        } finally {connection.disconnect();}
    }
}
