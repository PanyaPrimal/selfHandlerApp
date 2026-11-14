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
 * runs in its own empty task so completing a task never brings SelfHandler to the front.
 */
public class WidgetActionActivity extends Activity {
    static final String EXTRA_ACTION = "widget_action";
    static final String ACTION_COMPLETE = "complete";
    static final String ACTION_OPEN = "open";

    /** Opens Storage in the app, focused on one task when taskId is positive. */
    static PendingIntent open(Context context, long taskId) {
        Intent intent = new Intent(context, WidgetActionActivity.class)
            .putExtra(EXTRA_ACTION, ACTION_OPEN)
            .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, taskId);
        return PendingIntent.getActivity(context, 3, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Intent intent = getIntent();
        String action = intent.getStringExtra(EXTRA_ACTION);
        long taskId = intent.getLongExtra(TasksWidgetProvider.EXTRA_TASK_ID, 0L);

        if (ACTION_COMPLETE.equals(action) && taskId > 0) {
            sendBroadcast(new Intent(this, TasksWidgetProvider.class)
                .setAction(TasksWidgetProvider.ACTION_COMPLETE)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, taskId));
        } else if (ACTION_OPEN.equals(action)) {
            String target = taskId > 0 ? "selfhandler://storage?item=" + taskId : "selfhandler://storage";
            startActivity(new Intent(this, MainActivity.class)
                .setAction(Intent.ACTION_VIEW)
                .setData(Uri.parse(target))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        }
        finish();
    }
}
