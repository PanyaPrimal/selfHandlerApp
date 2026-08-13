package app.selfhandler.mobile;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import org.json.JSONObject;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

@CapacitorPlugin(name = "MobileCredentialVault")
public class MobileCredentialVaultPlugin extends Plugin {
    private static final String ANDROID_KEYSTORE = "AndroidKeyStore";
    private static final String KEY_ALIAS = "selfhandler.mobile.session.v1";
    private static final String PREFERENCES = "selfhandler_secure_session";
    private static final String CIPHERTEXT = "ciphertext";
    private static final String INITIALIZATION_VECTOR = "initialization_vector";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";

    @PluginMethod
    public void read(PluginCall call) {
        synchronized (this) {
            SharedPreferences preferences = preferences();
            String ciphertext = preferences.getString(CIPHERTEXT, null);
            String initializationVector = preferences.getString(INITIALIZATION_VECTOR, null);

            if (ciphertext == null || initializationVector == null) {
                resolveEmpty(call);
                return;
            }

            try {
                Cipher cipher = Cipher.getInstance(TRANSFORMATION);
                cipher.init(
                    Cipher.DECRYPT_MODE,
                    getOrCreateKey(),
                    new GCMParameterSpec(128, Base64.decode(initializationVector, Base64.NO_WRAP))
                );
                byte[] plaintext = cipher.doFinal(Base64.decode(ciphertext, Base64.NO_WRAP));
                String token = new String(plaintext, StandardCharsets.UTF_8);
                java.util.Arrays.fill(plaintext, (byte) 0);

                if (token.isEmpty()) {
                    clearStoredCredential();
                    resolveEmpty(call);
                    return;
                }

                JSObject result = new JSObject();
                result.put("token", token);
                call.resolve(result);
            } catch (GeneralSecurityException | IOException | IllegalArgumentException exception) {
                clearStoredCredential();
                deleteKey();
                resolveEmpty(call);
            }
        }
    }

    @PluginMethod
    public void write(PluginCall call) {
        synchronized (this) {
            String token = call.getString("token");
            if (token == null || token.isEmpty()) {
                call.reject("A non-empty session credential is required.");
                return;
            }

            try {
                Cipher cipher = Cipher.getInstance(TRANSFORMATION);
                cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
                byte[] ciphertext = cipher.doFinal(token.getBytes(StandardCharsets.UTF_8));
                boolean stored = preferences().edit()
                    .putString(CIPHERTEXT, Base64.encodeToString(ciphertext, Base64.NO_WRAP))
                    .putString(
                        INITIALIZATION_VECTOR,
                        Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP)
                    )
                    .commit();

                java.util.Arrays.fill(ciphertext, (byte) 0);
                if (!stored) {
                    clearStoredCredential();
                    call.reject("The session credential could not be stored.");
                    return;
                }

                call.resolve();
            } catch (GeneralSecurityException | IOException exception) {
                clearStoredCredential();
                deleteKey();
                call.reject("The Android credential vault is unavailable.");
            }
        }
    }

    @PluginMethod
    public void clear(PluginCall call) {
        synchronized (this) {
            clearStoredCredential();
            deleteKey();
            call.resolve();
        }
    }

    private SharedPreferences preferences() {
        return getContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }

    private SecretKey getOrCreateKey() throws GeneralSecurityException, IOException {
        KeyStore keyStore = KeyStore.getInstance(ANDROID_KEYSTORE);
        keyStore.load(null);
        if (keyStore.containsAlias(KEY_ALIAS)) {
            return (SecretKey) keyStore.getKey(KEY_ALIAS, null);
        }

        KeyGenerator keyGenerator = KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES,
            ANDROID_KEYSTORE
        );
        keyGenerator.init(
            new KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        );
        return keyGenerator.generateKey();
    }

    private void clearStoredCredential() {
        preferences().edit().clear().commit();
    }

    private void deleteKey() {
        try {
            KeyStore keyStore = KeyStore.getInstance(ANDROID_KEYSTORE);
            keyStore.load(null);
            if (keyStore.containsAlias(KEY_ALIAS)) {
                keyStore.deleteEntry(KEY_ALIAS);
            }
        } catch (GeneralSecurityException | IOException exception) {
            // The encrypted preference is already gone and cannot be recovered without the key.
        }
    }

    private void resolveEmpty(PluginCall call) {
        JSObject result = new JSObject();
        result.put("token", JSONObject.NULL);
        call.resolve(result);
    }
}
