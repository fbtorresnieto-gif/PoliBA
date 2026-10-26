package com.multipro.mobile.data.local;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import com.multipro.mobile.data.model.LoginData;
import com.multipro.mobile.data.model.User;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

public final class SessionManager {
    private static final String STORE_NAME = "multipro_secure_session";
    private static final String KEY_ALIAS = "multipro_session_key";
    private static final String KEY_TOKEN = "token";
    private static final String KEY_NAME = "name";
    private static final String KEY_EMAIL = "email";
    private static final String KEY_ROLE = "role";
    private static final String KEY_EXPIRES_AT = "expires_at";
    private static final int GCM_TAG_LENGTH_BITS = 128;

    private final SharedPreferences preferences;

    public SessionManager(Context context) {
        preferences = context.getApplicationContext()
                .getSharedPreferences(STORE_NAME, Context.MODE_PRIVATE);
    }

    public void save(LoginData loginData) {
        if (loginData == null || loginData.getUsuario() == null) {
            throw new IllegalArgumentException("La respuesta de login no contiene una sesión válida.");
        }
        User user = loginData.getUsuario();
        preferences.edit()
                .putString(KEY_TOKEN, encrypt(loginData.getToken()))
                .putString(KEY_NAME, encrypt(valueOrEmpty(user.getNombre())))
                .putString(KEY_EMAIL, encrypt(valueOrEmpty(user.getEmail())))
                .putString(KEY_ROLE, encrypt(valueOrEmpty(user.getRol())))
                .putString(KEY_EXPIRES_AT, encrypt(valueOrEmpty(loginData.getExpiresAt())))
                .apply();
    }

    public boolean hasSession() {
        String token = getToken();
        return token != null && !token.isBlank();
    }

    public String getToken() { return read(KEY_TOKEN); }
    public String getName() { return read(KEY_NAME); }
    public String getEmail() { return read(KEY_EMAIL); }
    public String getRole() { return read(KEY_ROLE); }
    public String getExpiresAt() { return read(KEY_EXPIRES_AT); }

    public String authorizationHeader() {
        String token = getToken();
        return token == null ? null : "Bearer " + token;
    }

    public void clear() {
        preferences.edit().clear().apply();
    }

    private String read(String key) {
        String encrypted = preferences.getString(key, null);
        if (encrypted == null) {
            return null;
        }
        try {
            return decrypt(encrypted);
        } catch (RuntimeException error) {
            clear();
            return null;
        }
    }

    private String encrypt(String value) {
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
            byte[] encrypted = cipher.doFinal(value.getBytes(StandardCharsets.UTF_8));
            byte[] iv = cipher.getIV();
            byte[] combined = new byte[iv.length + encrypted.length];
            System.arraycopy(iv, 0, combined, 0, iv.length);
            System.arraycopy(encrypted, 0, combined, iv.length, encrypted.length);
            return Base64.encodeToString(combined, Base64.NO_WRAP);
        } catch (Exception error) {
            throw new IllegalStateException("No se pudo proteger la sesión.", error);
        }
    }

    private String decrypt(String value) {
        try {
            byte[] combined = Base64.decode(value, Base64.NO_WRAP);
            byte[] iv = new byte[12];
            byte[] encrypted = new byte[combined.length - iv.length];
            System.arraycopy(combined, 0, iv, 0, iv.length);
            System.arraycopy(combined, iv.length, encrypted, 0, encrypted.length);

            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(),
                    new GCMParameterSpec(GCM_TAG_LENGTH_BITS, iv));
            return new String(cipher.doFinal(encrypted), StandardCharsets.UTF_8);
        } catch (Exception error) {
            throw new IllegalStateException("No se pudo leer la sesión protegida.", error);
        }
    }

    private SecretKey getOrCreateKey() throws Exception {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);
        if (keyStore.containsAlias(KEY_ALIAS)) {
            return (SecretKey) keyStore.getKey(KEY_ALIAS, null);
        }

        KeyGenerator generator = KeyGenerator.getInstance(
                KeyProperties.KEY_ALGORITHM_AES,
                "AndroidKeyStore"
        );
        generator.init(new KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build());
        return generator.generateKey();
    }

    private static String valueOrEmpty(String value) {
        return value == null ? "" : value;
    }
}
