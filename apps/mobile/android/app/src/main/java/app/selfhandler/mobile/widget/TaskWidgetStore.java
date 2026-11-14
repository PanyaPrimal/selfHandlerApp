package app.selfhandler.mobile.widget;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

/**
 * The widget's last known list, kept in the app's private, backup-disabled preferences so the
 * home screen still shows something without a connection. Credentials stay in SessionVault only.
 */
final class TaskWidgetStore {
    enum State { READY, SIGNED_OUT, OFFLINE, NOT_CONFIGURED, ACTION_FAILED }

    private static final String PREFERENCES = "selfhandler_tasks_widget";
    private static final String TASKS = "tasks";
    private static final String STATE = "state";
    private static final String UPDATED_AT = "updated_at";
    private static final String HIDDEN = "hidden";
    private static final Object LOCK = new Object();

    private TaskWidgetStore() {
    }

    static List<WidgetTask> visibleTasks(Context context) {
        synchronized (LOCK) {
            SharedPreferences preferences = preferences(context);
            Set<String> hidden = preferences.getStringSet(HIDDEN, new HashSet<>());
            List<WidgetTask> tasks = new ArrayList<>();
            try {
                JSONArray array = new JSONArray(preferences.getString(TASKS, "[]"));
                for (int index = 0; index < array.length(); index++) {
                    WidgetTask task = WidgetTask.fromJson(array.getJSONObject(index));
                    if (!hidden.contains(String.valueOf(task.id))) tasks.add(task);
                }
            } catch (JSONException exception) {
                preferences.edit().remove(TASKS).apply();
            }
            return tasks;
        }
    }

    static void saveTasks(Context context, List<WidgetTask> tasks) {
        synchronized (LOCK) {
            JSONArray array = new JSONArray();
            try {
                for (WidgetTask task : tasks) array.put(task.toJson());
            } catch (JSONException exception) {
                return;
            }
            // A fresh server list is authoritative; hiding still-pending completions keeps a slow
            // PATCH from making the item flash back.
            Set<String> hidden = new HashSet<>(preferences(context).getStringSet(HIDDEN, new HashSet<>()));
            Set<String> present = new HashSet<>();
            for (WidgetTask task : tasks) present.add(String.valueOf(task.id));
            hidden.retainAll(present);
            preferences(context).edit()
                .putString(TASKS, array.toString())
                .putStringSet(HIDDEN, hidden)
                .putString(STATE, State.READY.name())
                .putLong(UPDATED_AT, System.currentTimeMillis())
                .apply();
        }
    }

    static void hide(Context context, long id, boolean hidden) {
        synchronized (LOCK) {
            Set<String> ids = new HashSet<>(preferences(context).getStringSet(HIDDEN, new HashSet<>()));
            if (hidden) ids.add(String.valueOf(id)); else ids.remove(String.valueOf(id));
            preferences(context).edit().putStringSet(HIDDEN, ids).apply();
        }
    }

    static void setState(Context context, State state) {
        synchronized (LOCK) {
            preferences(context).edit().putString(STATE, state.name()).apply();
        }
    }

    static State state(Context context) {
        synchronized (LOCK) {
            try {
                return State.valueOf(preferences(context).getString(STATE, State.READY.name()));
            } catch (IllegalArgumentException exception) {
                return State.READY;
            }
        }
    }

    static long updatedAt(Context context) {
        return preferences(context).getLong(UPDATED_AT, 0L);
    }

    /** Signing out must not leave another account's titles on the home screen. */
    static void clear(Context context) {
        synchronized (LOCK) {
            preferences(context).edit().clear().putString(STATE, State.SIGNED_OUT.name()).apply();
        }
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
