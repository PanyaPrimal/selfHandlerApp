package app.selfhandler.mobile.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.text.format.DateUtils;
import android.view.View;
import android.widget.RemoteViews;

import java.io.IOException;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import app.selfhandler.mobile.R;
import app.selfhandler.mobile.SessionVault;

/** Home-screen list of open tasks due today or earlier. */
public class TasksWidgetProvider extends AppWidgetProvider {
    static final String ACTION_REFRESH = "app.selfhandler.mobile.widget.REFRESH";
    static final String ACTION_COMPLETE = "app.selfhandler.mobile.widget.COMPLETE";
    static final String EXTRA_TASK_ID = "task_id";

    /** Network work runs here, one request chain at a time, never on the main thread. */
    private static final ExecutorService EXECUTOR = Executors.newSingleThreadExecutor();

    /** Asks placed widgets to reload, e.g. after the app changed tasks or the session. */
    public static void requestRefresh(Context context) {
        if (widgetIds(context).length == 0) return;
        context.sendBroadcast(new Intent(context, TasksWidgetProvider.class).setAction(ACTION_REFRESH));
    }

    @Override
    public void onUpdate(Context context, AppWidgetManager manager, int[] appWidgetIds) {
        render(context);
        refresh(context.getApplicationContext(), goAsync());
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        super.onReceive(context, intent);
        String action = intent.getAction();
        if (ACTION_REFRESH.equals(action)) {
            refresh(context.getApplicationContext(), goAsync());
        } else if (ACTION_COMPLETE.equals(action)) {
            long id = intent.getLongExtra(EXTRA_TASK_ID, 0L);
            if (id > 0) complete(context.getApplicationContext(), id, goAsync());
        }
    }

    private static void refresh(Context context, PendingResult result) {
        EXECUTOR.execute(() -> {
            try {
                synchronize(context);
            } finally {
                render(context);
                result.finish();
            }
        });
    }

    private static void complete(Context context, long id, PendingResult result) {
        // Hide immediately: the tap must feel instant even on a slow connection.
        TaskWidgetStore.hide(context, id, true);
        render(context);
        EXECUTOR.execute(() -> {
            try {
                String token = SessionVault.read(context);
                if (token == null) {
                    TaskWidgetStore.clear(context);
                    return;
                }
                try {
                    new TasksApi(token).complete(id);
                    synchronize(context);
                } catch (TasksApi.UnauthorizedException exception) {
                    TaskWidgetStore.clear(context);
                } catch (IOException exception) {
                    TaskWidgetStore.hide(context, id, false);
                    TaskWidgetStore.setState(context, TaskWidgetStore.State.ACTION_FAILED);
                }
            } finally {
                render(context);
                result.finish();
            }
        });
    }

    /** Replaces the cached list with the server's; keeps the cache when the server is unreachable. */
    static void synchronize(Context context) {
        if (!TasksApi.isConfigured()) {
            TaskWidgetStore.setState(context, TaskWidgetStore.State.NOT_CONFIGURED);
            return;
        }
        String token = SessionVault.read(context);
        if (token == null) {
            TaskWidgetStore.clear(context);
            return;
        }
        try {
            List<WidgetTask> tasks = new TasksApi(token).dueTasks(today());
            TaskWidgetStore.saveTasks(context, tasks);
        } catch (TasksApi.UnauthorizedException exception) {
            TaskWidgetStore.clear(context);
        } catch (IOException exception) {
            TaskWidgetStore.setState(context, TaskWidgetStore.State.OFFLINE);
        }
    }

    static String today() {
        return new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date());
    }

    static void render(Context context) {
        AppWidgetManager manager = AppWidgetManager.getInstance(context);
        int[] ids = widgetIds(context);
        if (ids.length == 0) return;

        TaskWidgetStore.State state = TaskWidgetStore.state(context);
        int count = TaskWidgetStore.visibleTasks(context).size();

        for (int id : ids) {
            RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_tasks);

            views.setTextViewText(R.id.widget_subtitle, subtitle(context, state, count));
            String status = status(context, state);
            views.setTextViewText(R.id.widget_status, status);
            views.setViewVisibility(R.id.widget_status, status == null ? View.GONE : View.VISIBLE);
            views.setTextViewText(R.id.widget_empty, context.getString(
                state == TaskWidgetStore.State.SIGNED_OUT ? R.string.widget_signed_out : R.string.widget_empty
            ));

            Intent service = new Intent(context, TasksWidgetService.class)
                .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, id);
            // A unique data URI keeps Android from reusing one factory for several widgets.
            service.setData(Uri.parse(service.toUri(Intent.URI_INTENT_SCHEME)));
            views.setRemoteAdapter(R.id.widget_list, service);
            views.setEmptyView(R.id.widget_list, R.id.widget_empty);
            views.setPendingIntentTemplate(R.id.widget_list, actionTemplate(context));

            PendingIntent openStorage = WidgetActionActivity.open(context, 0L);
            views.setOnClickPendingIntent(R.id.widget_header, openStorage);
            views.setOnClickPendingIntent(R.id.widget_empty, openStorage);
            views.setOnClickPendingIntent(R.id.widget_add, QuickAddActivity.pendingIntent(context));
            views.setOnClickPendingIntent(R.id.widget_refresh, PendingIntent.getBroadcast(
                context, 1,
                new Intent(context, TasksWidgetProvider.class).setAction(ACTION_REFRESH),
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
            ));

            manager.updateAppWidget(id, views);
        }
        manager.notifyAppWidgetViewDataChanged(ids, R.id.widget_list);
    }

    private static String subtitle(Context context, TaskWidgetStore.State state, int count) {
        if (state == TaskWidgetStore.State.SIGNED_OUT || state == TaskWidgetStore.State.NOT_CONFIGURED) {
            return context.getString(R.string.widget_today);
        }
        return context.getResources().getQuantityString(R.plurals.widget_task_count, count, count);
    }

    private static String status(Context context, TaskWidgetStore.State state) {
        switch (state) {
            case OFFLINE: {
                long updatedAt = TaskWidgetStore.updatedAt(context);
                if (updatedAt == 0L) return context.getString(R.string.widget_offline);
                CharSequence time = DateUtils.getRelativeTimeSpanString(
                    updatedAt, System.currentTimeMillis(), DateUtils.MINUTE_IN_MILLIS
                );
                return context.getString(R.string.widget_offline_since, time);
            }
            case ACTION_FAILED:
                return context.getString(R.string.widget_action_failed);
            case NOT_CONFIGURED:
                return context.getString(R.string.widget_not_configured);
            default:
                return null;
        }
    }

    private static PendingIntent actionTemplate(Context context) {
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        // List rows fill in the action and task id, so the template must stay mutable.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) flags |= PendingIntent.FLAG_MUTABLE;
        return PendingIntent.getActivity(context, 2, new Intent(context, WidgetActionActivity.class), flags);
    }

    private static int[] widgetIds(Context context) {
        return AppWidgetManager.getInstance(context)
            .getAppWidgetIds(new ComponentName(context, TasksWidgetProvider.class));
    }
}
