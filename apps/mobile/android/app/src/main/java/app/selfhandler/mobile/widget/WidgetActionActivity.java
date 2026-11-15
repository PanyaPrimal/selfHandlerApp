package app.selfhandler.mobile.widget;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;

import app.selfhandler.mobile.MainActivity;

/**
 * Invisible, task-less hop for widget row taps. A list widget has one click template, and an
 * activity target is the only one Android reliably lets open the app from the home screen; it
 * runs in its own empty task so checking an item never brings SelfHandler to the front.
 */
public class WidgetActionActivity extends Activity {
    static final String EXTRA_ACTION = "widget_action";
    static final String ACTION_TOGGLE = "toggle";
    static final String ACTION_OPEN = "open";

    /** Opens the widget's list in the app, focused on one item when taskId is positive. */
    static PendingIntent open(Context context, int widgetId, String list, long taskId) {
        Intent intent = new Intent(context, WidgetActionActivity.class)
            .putExtra(EXTRA_ACTION, ACTION_OPEN)
            .putExtra(TasksWidgetProvider.EXTRA_LIST, list)
            .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, taskId);
        return PendingIntent.getActivity(context, 2000 + widgetId, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Intent intent = getIntent();
        String action = intent.getStringExtra(EXTRA_ACTION);
        String list = intent.getStringExtra(TasksWidgetProvider.EXTRA_LIST);
        long taskId = intent.getLongExtra(TasksWidgetProvider.EXTRA_TASK_ID, 0L);
        boolean known = TaskWidgetStore.GENERAL.equals(list) || (list != null && list.matches("[1-9]\\d{0,17}"));

        if (ACTION_TOGGLE.equals(action) && taskId > 0 && known) {
            sendBroadcast(new Intent(this, TasksWidgetProvider.class)
                .setAction(TasksWidgetProvider.ACTION_TOGGLE)
                .putExtra(TasksWidgetProvider.EXTRA_LIST, list)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, taskId)
                .putExtra(TasksWidgetProvider.EXTRA_DONE, intent.getBooleanExtra(TasksWidgetProvider.EXTRA_DONE, true)));
        } else if (ACTION_OPEN.equals(action)) {
            String target = "selfhandler://lists/" + (known ? list : TaskWidgetStore.GENERAL)
                + (taskId > 0 ? "?item=" + taskId : "");
            startActivity(new Intent(this, MainActivity.class)
                .setAction(Intent.ACTION_VIEW)
                .setData(Uri.parse(target))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        }
        finish();
    }
}
