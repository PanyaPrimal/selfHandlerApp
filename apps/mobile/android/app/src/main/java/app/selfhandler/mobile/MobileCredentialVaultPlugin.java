package app.selfhandler.mobile;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import org.json.JSONObject;

import app.selfhandler.mobile.widget.TasksWidgetProvider;

/** WebView access to the Keystore-backed session vault; the cryptography lives in SessionVault. */
@CapacitorPlugin(name = "MobileCredentialVault")
public class MobileCredentialVaultPlugin extends Plugin {
    @PluginMethod
    public void read(PluginCall call) {
        String token = SessionVault.read(getContext());
        JSObject result = new JSObject();
        result.put("token", token == null ? JSONObject.NULL : token);
        call.resolve(result);
    }

    @PluginMethod
    public void write(PluginCall call) {
        String token = call.getString("token");
        if (token == null || token.isEmpty()) {
            call.reject("A non-empty session credential is required.");
            return;
        }
        if (!SessionVault.write(getContext(), token)) {
            call.reject("The Android credential vault is unavailable.");
            return;
        }
        TasksWidgetProvider.requestRefresh(getContext());
        call.resolve();
    }

    @PluginMethod
    public void clear(PluginCall call) {
        SessionVault.clear(getContext());
        TasksWidgetProvider.requestRefresh(getContext());
        call.resolve();
    }
}
