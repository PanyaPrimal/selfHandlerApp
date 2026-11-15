package app.selfhandler.mobile.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.view.inputmethod.EditorInfo;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import java.io.IOException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import app.selfhandler.mobile.R;
import app.selfhandler.mobile.SessionVault;

/** Small dialog over the home screen that adds one item at the end of the widget's list. */
public class QuickAddActivity extends AppCompatActivity {
    private static final int MAX_TITLE_LENGTH = 200;
    private static final ExecutorService EXECUTOR = Executors.newSingleThreadExecutor();

    private EditText input;
    private TextView error;
    private Button add;
    private boolean saving;
    private String list = TaskWidgetStore.GENERAL;

    static PendingIntent pendingIntent(Context context, int widgetId) {
        Intent intent = new Intent(context, QuickAddActivity.class)
            .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId);
        return PendingIntent.getActivity(context, 4000 + widgetId, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_quick_add);
        setFinishOnTouchOutside(true);
        getWindow().setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_VISIBLE);

        int widgetId = getIntent().getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
        list = TaskWidgetStore.listFor(this, widgetId);
        TextView title = findViewById(R.id.quick_add_title);
        title.setText(getString(R.string.widget_add_title, TasksWidgetProvider.listName(this, widgetId)));

        input = findViewById(R.id.quick_add_input);
        error = findViewById(R.id.quick_add_error);
        add = findViewById(R.id.quick_add_submit);
        findViewById(R.id.quick_add_cancel).setOnClickListener(view -> finish());
        add.setOnClickListener(view -> submit());
        input.setOnEditorActionListener((view, actionId, event) -> {
            boolean enter = event != null && event.getKeyCode() == KeyEvent.KEYCODE_ENTER
                && event.getAction() == KeyEvent.ACTION_DOWN;
            if (actionId == EditorInfo.IME_ACTION_DONE || enter) {
                submit();
                return true;
            }
            return false;
        });
        input.requestFocus();
    }

    private void submit() {
        if (saving) return;
        String title = input.getText().toString().trim();
        if (title.isEmpty()) {
            showError(R.string.widget_add_empty);
            return;
        }
        if (title.length() > MAX_TITLE_LENGTH) title = title.substring(0, MAX_TITLE_LENGTH);

        String token = SessionVault.read(this);
        if (token == null) {
            showError(R.string.widget_signed_out);
            return;
        }

        setSaving(true);
        Context application = getApplicationContext();
        String finalTitle = title;
        String finalList = list;
        EXECUTOR.execute(() -> {
            int failure = 0;
            try {
                new TasksApi(token).create(finalTitle, finalList);
            } catch (TasksApi.UnauthorizedException exception) {
                failure = R.string.widget_signed_out;
            } catch (TasksApi.NotConfiguredException exception) {
                failure = R.string.widget_not_configured;
            } catch (IOException exception) {
                failure = R.string.widget_add_failed;
            }
            int result = failure;
            if (result == 0) TasksWidgetProvider.requestRefresh(application);
            runOnUiThread(() -> {
                if (isFinishing() || isDestroyed()) return;
                if (result == 0) {
                    Toast.makeText(application, R.string.widget_added, Toast.LENGTH_SHORT).show();
                    finish();
                } else {
                    setSaving(false);
                    showError(result);
                }
            });
        });
    }

    private void setSaving(boolean value) {
        saving = value;
        add.setEnabled(!value);
        input.setEnabled(!value);
        if (value) error.setVisibility(View.GONE);
    }

    private void showError(int message) {
        error.setText(message);
        error.setVisibility(View.VISIBLE);
    }
}
