package app.selfhandler.mobile.widget;

import org.json.JSONException;
import org.json.JSONObject;

/** The few task fields the home-screen widget renders. */
final class WidgetTask {
    final long id;
    final String title;
    final String dueOn;
    final String priority;

    WidgetTask(long id, String title, String dueOn, String priority) {
        this.id = id;
        this.title = title;
        this.dueOn = dueOn;
        this.priority = priority;
    }

    static WidgetTask fromJson(JSONObject json) {
        return new WidgetTask(
            json.optLong("id"),
            json.optString("title", ""),
            json.isNull("due_on") ? null : json.optString("due_on", null),
            json.isNull("priority") ? null : json.optString("priority", null)
        );
    }

    JSONObject toJson() throws JSONException {
        return new JSONObject()
            .put("id", id)
            .put("title", title)
            .put("due_on", dueOn == null ? JSONObject.NULL : dueOn)
            .put("priority", priority == null ? JSONObject.NULL : priority);
    }

    boolean isHigh() {
        return "high".equals(priority);
    }

    int priorityRank() {
        if ("high".equals(priority)) return 0;
        if ("low".equals(priority)) return 2;
        return 1;
    }
}
