package app.selfhandler.mobile.widget;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Each widget's chosen list and the last known items of every list, kept in the app's private,
 * backup-disabled preferences so the home screen still shows something without a connection.
 * Credentials stay in SessionVault only.
 */
final class TaskWidgetStore {
    enum State { READY, SIGNED_OUT, OFFLINE, NOT_CONFIGURED, ACTION_FAILED }

    /** The list of tasks without a project. */
    static final String GENERAL = "general";

    private static final String PREFERENCES = "selfhandler_tasks_widget";
    private static final String ITEMS = "items:";
    private static final String MISSING = "missing:";
    private static final String WIDGET_LIST = "widget_list:";
    private static final String WIDGET_NAME = "widget_name:";
    private static final String STATE = "state";
    private static final String UPDATED_AT = "updated_at";
    private static final Object LOCK = new Object();

    private TaskWidgetStore() {
    }

    static String listFor(Context context, int widgetId) {
        String list = preferences(context).getString(WIDGET_LIST + widgetId, GENERAL);
        return list == null ? GENERAL : list;
    }

    /** The list name chosen in the picker; null shows the localized name of the general list. */
    static String nameFor(Context context, int widgetId) {
        return preferences(context).getString(WIDGET_NAME + widgetId, null);
    }

    static void assign(Context context, int widgetId, String list, String name) {
        synchronized (LOCK) {
            preferences(context).edit()
                .putString(WIDGET_LIST + widgetId, list)
                .putString(WIDGET_NAME + widgetId, GENERAL.equals(list) ? null : name)
                .apply();
        }
    }

    static void forget(Context context, int widgetId) {
        synchronized (LOCK) {
            preferences(context).edit().remove(WIDGET_LIST + widgetId).remove(WIDGET_NAME + widgetId).apply();
        }
    }

    /** Lists shown by at least one of these widgets. */
    static Set<String> lists(Context context, int[] widgetIds) {
        Set<String> lists = new HashSet<>();
        for (int id : widgetIds) lists.add(listFor(context, id));
        return lists;
    }

    static List<WidgetTask> tasks(Context context, String list) {
        synchronized (LOCK) {
            List<WidgetTask> tasks = new ArrayList<>();
            try {
                JSONArray array = new JSONArray(preferences(context).getString(ITEMS + list, "[]"));
                for (int index = 0; index < array.length(); index++) {
                    tasks.add(WidgetTask.fromJson(array.getJSONObject(index)));
                }
            } catch (JSONException exception) {
                preferences(context).edit().remove(ITEMS + list).apply();
            }
            return tasks;
        }
    }

    static void saveTasks(Context context, String list, List<WidgetTask> tasks) {
        synchronized (LOCK) {
            JSONArray array = new JSONArray();
            try {
                for (WidgetTask task : tasks) array.put(task.toJson());
            } catch (JSONException exception) {
                return;
            }
            preferences(context).edit()
                .putString(ITEMS + list, array.toString())
                .putBoolean(MISSING + list, false)
                .apply();
        }
    }

    static void markSynced(Context context) {
        synchronized (LOCK) {
            preferences(context).edit()
                .putString(STATE, State.READY.name())
                .putLong(UPDATED_AT, System.currentTimeMillis())
                .apply();
        }
    }

    static void setMissing(Context context, String list) {
        synchronized (LOCK) {
            preferences(context).edit().remove(ITEMS + list).putBoolean(MISSING + list, true).apply();
        }
    }

    static boolean isMissing(Context context, String list) {
        return preferences(context).getBoolean(MISSING + list, false);
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

    /**
     * Signing out must not leave another account's titles on the home screen. Which list each
     * widget shows is a home-screen choice and survives.
     */
    static void clear(Context context) {
        synchronized (LOCK) {
            SharedPreferences preferences = preferences(context);
            SharedPreferences.Editor editor = preferences.edit();
            for (Map.Entry<String, ?> entry : preferences.getAll().entrySet()) {
                String key = entry.getKey();
                if (key.startsWith(ITEMS) || key.startsWith(MISSING) || key.equals(UPDATED_AT)) editor.remove(key);
            }
            editor.putString(STATE, State.SIGNED_OUT.name()).apply();
        }
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
