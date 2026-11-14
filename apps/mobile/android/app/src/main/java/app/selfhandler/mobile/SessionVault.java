package app.selfhandler.mobile;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * The single owner of the mobile session credential. The WebView plugin and the home-screen widget
 * both read it here, so the Keystore key, ciphertext layout and failure handling cannot diverge.
 */
public final class SessionVault {
    private static final String ANDROID_KEYSTORE = "AndroidKeyStore";
    private static final String KEY_ALIAS = "selfhandler.mobile.session.v1";
    private static final String PREFERENCES = "selfhandler_secure_session";
    private static final String CIPHERTEXT = "ciphertext";
    private static final String INITIALIZATION_VECTOR = "initialization_vector";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";
    private static final Object LOCK = new Object();

    private SessionVault() {
    }

    /** Returns the stored token, or null when none is stored or it can no longer be decrypted. */
    public static String read(Context context) {
        synchronized (LOCK) {
            SharedPreferences preferences = preferences(context);
            String ciphertext = preferences.getString(CIPHERTEXT, null);
            String initializationVector = preferences.getString(INITIALIZATION_VECTOR, null);
            if (ciphertext == null || initializationVector == null) return null;

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
                    clearStoredCredential(context);
                    return null;
                }
                return token;
            } catch (GeneralSecurityException | IOException | IllegalArgumentException exception) {
                clearStoredCredential(context);
                deleteKey();
                return null;
            }
        }
    }

    /** Stores a non-empty token; returns false (with nothing stored) when the vault is unavailable. */
    public static boolean write(Context context, String token) {
        synchronized (LOCK) {
            try {
                Cipher cipher = Cipher.getInstance(TRANSFORMATION);
                cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
                byte[] ciphertext = cipher.doFinal(token.getBytes(StandardCharsets.UTF_8));
                boolean stored = preferences(context).edit()
                    .putString(CIPHERTEXT, Base64.encodeToString(ciphertext, Base64.NO_WRAP))
                    .putString(INITIALIZATION_VECTOR, Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP))
                    .commit();
                java.util.Arrays.fill(ciphertext, (byte) 0);
                if (!stored) clearStoredCredential(context);
                return stored;
            } catch (GeneralSecurityException | IOException exception) {
                clearStoredCredential(context);
                deleteKey();
                return false;
            }
        }
    }

    public static void clear(Context context) {
        synchronized (LOCK) {
            clearStoredCredential(context);
            deleteKey();
        }
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }

    private static SecretKey getOrCreateKey() throws GeneralSecurityException, IOException {
        KeyStore keyStore = KeyStore.getInstance(ANDROID_KEYSTORE);
        keyStore.load(null);
        if (keyStore.containsAlias(KEY_ALIAS)) {
            return (SecretKey) keyStore.getKey(KEY_ALIAS, null);
        }

        KeyGenerator keyGenerator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE);
        keyGenerator.init(
            new KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        );
        return keyGenerator.generateKey();
    }

    private static void clearStoredCredential(Context context) {
        preferences(context).edit().clear().commit();
    }

    private static void deleteKey() {
        try {
            KeyStore keyStore = KeyStore.getInstance(ANDROID_KEYSTORE);
            keyStore.load(null);
            if (keyStore.containsAlias(KEY_ALIAS)) keyStore.deleteEntry(KEY_ALIAS);
        } catch (GeneralSecurityException | IOException exception) {
            // The encrypted preference is already gone and cannot be recovered without the key.
        }
    }
}
