package app.selfhandler.mobile.widget;

import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProviderInfo;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.ListView;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import app.selfhandler.mobile.R;
import app.selfhandler.mobile.SessionVault;

/**
 * Widget configuration: which list this widget shows. The launcher opens it when the widget is
 * placed and again from "reconfigure". It only ever assigns a list to one of this app's widgets.
 */
public class ListPickerActivity extends AppCompatActivity {
    private static final ExecutorService EXECUTOR = Executors.newSingleThreadExecutor();

    private int widgetId = AppWidgetManager.INVALID_APPWIDGET_ID;
    private final List<TasksApi.ListChoice> choices = new ArrayList<>();
    private ArrayAdapter<String> adapter;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Backing out must not leave a half-configured widget behind.
        setResult(RESULT_CANCELED);
        widgetId = getIntent().getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
        if (!isOwnWidget(this, widgetId)) {
            finish();
            return;
        }

        setContentView(R.layout.activity_list_picker);
        status = findViewById(R.id.list_picker_status);
        findViewById(R.id.list_picker_cancel).setOnClickListener(view -> finish());
        ListView options = findViewById(R.id.list_picker_options);
        adapter = new ArrayAdapter<>(this, android.R.layout.simple_list_item_1, new ArrayList<>());
        options.setAdapter(adapter);
        options.setOnItemClickListener((parent, view, position, id) -> choose(choices.get(position)));
        show(new ArrayList<>());

        String token = SessionVault.read(this);
        if (token == null) {
            status.setText(R.string.widget_signed_out);
            return;
        }
        EXECUTOR.execute(() -> {
            List<TasksApi.ListChoice> named = null;
            try {
                named = new TasksApi(token).lists();
            } catch (IOException exception) {
                named = null;
            }
            List<TasksApi.ListChoice> result = named;
            runOnUiThread(() -> {
                if (isFinishing() || isDestroyed()) return;
                if (result == null) {
                    status.setText(R.string.widget_pick_failed);
                } else {
                    status.setVisibility(View.GONE);
                    show(result);
                }
            });
        });
    }

    private void show(List<TasksApi.ListChoice> named) {
        choices.clear();
        choices.add(new TasksApi.ListChoice(TaskWidgetStore.GENERAL, getString(R.string.widget_general)));
        choices.addAll(named);
        adapter.clear();
        for (TasksApi.ListChoice choice : choices) adapter.add(choice.name);
        adapter.notifyDataSetChanged();
    }

    private void choose(TasksApi.ListChoice choice) {
        Context application = getApplicationContext();
        TaskWidgetStore.assign(application, widgetId, choice.key, choice.name);
        TasksWidgetProvider.render(application, widgetId);
        TasksWidgetProvider.requestRefresh(application);
        setResult(RESULT_OK, new Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId));
        finish();
    }

    private static boolean isOwnWidget(Context context, int widgetId) {
        if (widgetId == AppWidgetManager.INVALID_APPWIDGET_ID) return false;
        AppWidgetProviderInfo info = AppWidgetManager.getInstance(context).getAppWidgetInfo(widgetId);
        return info != null && new ComponentName(context, TasksWidgetProvider.class).equals(info.provider);
    }
}
