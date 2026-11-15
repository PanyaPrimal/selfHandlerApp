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
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import app.selfhandler.mobile.R;
import app.selfhandler.mobile.SessionVault;

/** Home-screen checklist: one chosen list per widget, finished items at the bottom. */
public class TasksWidgetProvider extends AppWidgetProvider {
    static final String ACTION_REFRESH = "app.selfhandler.mobile.widget.REFRESH";
    static final String ACTION_TOGGLE = "app.selfhandler.mobile.widget.TOGGLE";
    static final String EXTRA_TASK_ID = "task_id";
    static final String EXTRA_DONE = "done";
    static final String EXTRA_LIST = "list";

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
    public void onDeleted(Context context, int[] appWidgetIds) {
        for (int id : appWidgetIds) TaskWidgetStore.forget(context, id);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        super.onReceive(context, intent);
        String action = intent.getAction();
        if (ACTION_REFRESH.equals(action)) {
            refresh(context.getApplicationContext(), goAsync());
        } else if (ACTION_TOGGLE.equals(action)) {
            long id = intent.getLongExtra(EXTRA_TASK_ID, 0L);
            String list = intent.getStringExtra(EXTRA_LIST);
            if (id > 0 && list != null) {
                toggle(context.getApplicationContext(), list, id, intent.getBooleanExtra(EXTRA_DONE, true), goAsync());
            }
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

    private static void toggle(Context context, String list, long id, boolean done, PendingResult result) {
        // Show the result at once: the tap must feel instant even on a slow connection.
        List<WidgetTask> before = TaskWidgetStore.tasks(context, list);
        TaskWidgetStore.saveTasks(context, list, WidgetTree.toggle(before, id, done));
        render(context);
        EXECUTOR.execute(() -> {
            try {
                String token = SessionVault.read(context);
                if (token == null) {
                    TaskWidgetStore.clear(context);
                    return;
                }
                try {
                    new TasksApi(token).toggle(id, done);
                    synchronize(context);
                } catch (TasksApi.UnauthorizedException exception) {
                    TaskWidgetStore.clear(context);
                } catch (IOException exception) {
                    TaskWidgetStore.saveTasks(context, list, before);
                    TaskWidgetStore.setState(context, TaskWidgetStore.State.ACTION_FAILED);
                }
            } finally {
                render(context);
                result.finish();
            }
        });
    }

    /** Replaces every shown list with the server's; keeps the cache when the server is unreachable. */
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
        Set<String> lists = TaskWidgetStore.lists(context, widgetIds(context));
        if (lists.isEmpty()) return;
        TasksApi api = new TasksApi(token);
        try {
            Set<String> existing = new HashSet<>();
            existing.add(TaskWidgetStore.GENERAL);
            if (lists.size() > 1 || !lists.contains(TaskWidgetStore.GENERAL)) {
                for (TasksApi.ListChoice choice : api.lists()) existing.add(choice.key);
            }
            for (String list : lists) {
                if (existing.contains(list)) {
                    TaskWidgetStore.saveTasks(context, list, api.listTasks(list));
                } else {
                    TaskWidgetStore.setMissing(context, list);
                }
            }
            TaskWidgetStore.markSynced(context);
        } catch (TasksApi.UnauthorizedException exception) {
            TaskWidgetStore.clear(context);
        } catch (IOException exception) {
            TaskWidgetStore.setState(context, TaskWidgetStore.State.OFFLINE);
        }
    }

    static String today() {
        return new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date());
    }

    static String listName(Context context, int widgetId) {
        String name = TaskWidgetStore.nameFor(context, widgetId);
        return name == null || name.isEmpty() ? context.getString(R.string.widget_general) : name;
    }

    static void render(Context context) {
        AppWidgetManager manager = AppWidgetManager.getInstance(context);
        int[] ids = widgetIds(context);
        if (ids.length == 0) return;
        for (int id : ids) manager.updateAppWidget(id, views(context, id));
        manager.notifyAppWidgetViewDataChanged(ids, R.id.widget_list);
    }

    static void render(Context context, int widgetId) {
        AppWidgetManager manager = AppWidgetManager.getInstance(context);
        manager.updateAppWidget(widgetId, views(context, widgetId));
        manager.notifyAppWidgetViewDataChanged(new int[] {widgetId}, R.id.widget_list);
    }

    private static RemoteViews views(Context context, int id) {
        TaskWidgetStore.State state = TaskWidgetStore.state(context);
        String list = TaskWidgetStore.listFor(context, id);
        boolean missing = TaskWidgetStore.isMissing(context, list);
        int open = WidgetTree.openCount(TaskWidgetStore.tasks(context, list));

        RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_tasks);
        views.setTextViewText(R.id.widget_title, listName(context, id));
        views.setTextViewText(R.id.widget_subtitle, subtitle(context, state, open));
        String status = missing ? context.getString(R.string.widget_list_missing) : status(context, state);
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
        views.setPendingIntentTemplate(R.id.widget_list, actionTemplate(context, id));

        PendingIntent openList = WidgetActionActivity.open(context, id, list, 0L);
        views.setOnClickPendingIntent(R.id.widget_header, openList);
        views.setOnClickPendingIntent(R.id.widget_empty, openList);
        views.setOnClickPendingIntent(R.id.widget_add, QuickAddActivity.pendingIntent(context, id));
        views.setOnClickPendingIntent(R.id.widget_refresh, PendingIntent.getBroadcast(
            context, 1,
            new Intent(context, TasksWidgetProvider.class).setAction(ACTION_REFRESH),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        ));
        return views;
    }

    private static String subtitle(Context context, TaskWidgetStore.State state, int count) {
        if (state == TaskWidgetStore.State.SIGNED_OUT || state == TaskWidgetStore.State.NOT_CONFIGURED) return "";
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

    private static PendingIntent actionTemplate(Context context, int widgetId) {
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        // List rows fill in the action and task id, so the template must stay mutable.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) flags |= PendingIntent.FLAG_MUTABLE;
        Intent intent = new Intent(context, WidgetActionActivity.class)
            .putExtra(EXTRA_LIST, TaskWidgetStore.listFor(context, widgetId));
        // One template per widget: its request code keeps each widget's list apart.
        return PendingIntent.getActivity(context, 1000 + widgetId, intent, flags);
    }

    static int[] widgetIds(Context context) {
        return AppWidgetManager.getInstance(context)
            .getAppWidgetIds(new ComponentName(context, TasksWidgetProvider.class));
    }
}
