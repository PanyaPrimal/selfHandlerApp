package app.selfhandler.mobile.widget;

import org.json.JSONException;
import org.json.JSONObject;

/** The few checklist fields the home-screen widget renders. */
final class WidgetTask {
    final long id;
    final String title;
    final String dueOn;
    final String priority;
    /** 0 for a top-level item. */
    final long parentId;
    final int position;
    final boolean done;

    WidgetTask(long id, String title, String dueOn, String priority, long parentId, int position, boolean done) {
        this.id = id;
        this.title = title;
        this.dueOn = dueOn;
        this.priority = priority;
        this.parentId = parentId;
        this.position = position;
        this.done = done;
    }

    static WidgetTask fromJson(JSONObject json) {
        String status = json.optString("status", "inbox");
        return new WidgetTask(
            json.optLong("id"),
            json.optString("title", ""),
            json.isNull("due_on") ? null : json.optString("due_on", null),
            json.isNull("priority") ? null : json.optString("priority", null),
            json.isNull("parent_id") ? 0L : json.optLong("parent_id", 0L),
            json.optInt("position", 0),
            "done".equals(status)
        );
    }

    JSONObject toJson() throws JSONException {
        return new JSONObject()
            .put("id", id)
            .put("title", title)
            .put("due_on", dueOn == null ? JSONObject.NULL : dueOn)
            .put("priority", priority == null ? JSONObject.NULL : priority)
            .put("parent_id", parentId == 0L ? JSONObject.NULL : parentId)
            .put("position", position)
            .put("status", done ? "done" : "active");
    }

    WidgetTask withDone(boolean value) {
        return new WidgetTask(id, title, dueOn, priority, parentId, position, value);
    }

    boolean isHigh() {
        return "high".equals(priority);
    }
}
