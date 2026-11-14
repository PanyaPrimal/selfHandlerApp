package app.selfhandler.mobile;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

import app.selfhandler.mobile.widget.TasksWidgetProvider;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(MobileCredentialVaultPlugin.class);
        registerPlugin(DictationPlugin.class);
        super.onCreate(savedInstanceState);
    }

    @Override
    public void onPause() {
        super.onPause();
        // Tasks edited in the app should be reflected on the home screen when the user leaves it.
        TasksWidgetProvider.requestRefresh(this);
    }
}
