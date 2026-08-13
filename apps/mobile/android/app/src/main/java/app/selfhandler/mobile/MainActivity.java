package app.selfhandler.mobile;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(MobileCredentialVaultPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
