package app.selfhandler.mobile.widget;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

import app.selfhandler.mobile.BuildConfig;

/**
 * Minimal client for the Storage checklist API. It reuses the app's mobile session token and the
 * build-time public origin. Writes carry a fresh workspace operation id,
 * so a retried request is replayed by the server instead of being applied twice.
 */
final class TasksApi {
    static final class UnauthorizedException extends IOException {
        UnauthorizedException() {
            super("The mobile session is no longer valid.");
        }
    }

    static final class NotConfiguredException extends IOException {
        NotConfiguredException() {
            super("The API origin is not configured for this build.");
        }
    }

    private static final int CONNECT_TIMEOUT_MS = 8000;
    private static final int READ_TIMEOUT_MS = 12000;
    private static final int PAGE_LIMIT = 500;

    private final String token;

    TasksApi(String token) {
        this.token = token;
    }

    static boolean isConfigured() {
        return BuildConfig.API_ORIGIN.startsWith("https://");
    }

    /** A list the widget can show: the general list or one open Storage project. */
    static final class ListChoice {
        final String key;
        final String name;

        ListChoice(String key, String name) {
            this.key = key;
            this.name = name;
        }
    }

    /** Every open or finished task of one list; dropped items are not part of a checklist. */
    List<WidgetTask> listTasks(String list) throws IOException {
        String project = TaskWidgetStore.GENERAL.equals(list) ? "" : list;
        JSONObject body = request("GET", "/api/storage/items?type=task&limit=" + PAGE_LIMIT + "&project_id=" + project, null);
        List<WidgetTask> tasks = new ArrayList<>();
        JSONArray data = body.optJSONArray("data");
        if (data == null) return tasks;
        for (int index = 0; index < data.length(); index++) {
            JSONObject item = data.optJSONObject(index);
            if (item == null || "dropped".equals(item.optString("status")) || !"task".equals(item.optString("type"))) continue;
            WidgetTask task = WidgetTask.fromJson(item);
            if (!task.title.isEmpty()) tasks.add(task);
        }
        return tasks;
    }

    /** Open Storage projects, which are the named lists. */
    List<ListChoice> lists() throws IOException {
        JSONObject body = request("GET", "/api/storage/projects", null);
        List<ListChoice> lists = new ArrayList<>();
        JSONArray data = body.optJSONArray("data");
        if (data == null) return lists;
        for (int index = 0; index < data.length(); index++) {
            JSONObject project = data.optJSONObject(index);
            if (project == null || project.optBoolean("is_archived", false)) continue;
            lists.add(new ListChoice(String.valueOf(project.optLong("id")), project.optString("name", "")));
        }
        return lists;
    }

    /** A checklist checkbox: the server applies it to the whole group. */
    void toggle(long id, boolean done) throws IOException {
        JSONObject payload = new JSONObject();
        try {
            payload.put("status", done ? "done" : "active").put("cascade", true);
        } catch (JSONException exception) {
            throw new IOException("Invalid task payload.", exception);
        }
        request("PATCH", "/api/storage/items/" + id, payload);
    }

    /** Adds an item at the end of the list. */
    void create(String title, String list) throws IOException {
        JSONObject payload = new JSONObject();
        try {
            payload.put("type", "task").put("status", "active").put("title", title)
                .put("project_id", TaskWidgetStore.GENERAL.equals(list) ? JSONObject.NULL : Long.parseLong(list));
        } catch (JSONException | NumberFormatException exception) {
            throw new IOException("Invalid task payload.", exception);
        }
        request("POST", "/api/storage/items", payload);
    }

    private JSONObject request(String method, String path, JSONObject payload) throws IOException {
        if (!isConfigured()) throw new NotConfiguredException();

        HttpURLConnection connection = (HttpURLConnection) new URL(BuildConfig.API_ORIGIN + path).openConnection();
        try {
            connection.setConnectTimeout(CONNECT_TIMEOUT_MS);
            connection.setReadTimeout(READ_TIMEOUT_MS);
            connection.setUseCaches(false);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("Authorization", "Bearer " + token);
            if ("PATCH".equals(method)) {
                // HttpURLConnection rejects PATCH; Laravel honours the method override for POST.
                connection.setRequestMethod("POST");
                connection.setRequestProperty("X-HTTP-Method-Override", "PATCH");
            } else {
                connection.setRequestMethod(method);
            }
            if (payload != null) {
                connection.setRequestProperty("X-Workspace-Operation", UUID.randomUUID().toString());
                connection.setRequestProperty("Content-Type", "application/json");
                connection.setDoOutput(true);
                byte[] bytes = payload.toString().getBytes(StandardCharsets.UTF_8);
                try (OutputStream output = connection.getOutputStream()) {
                    output.write(bytes);
                }
            }

            int status = connection.getResponseCode();
            if (status == 401 || status == 419) throw new UnauthorizedException();
            if (status < 200 || status >= 300) throw new IOException("Unexpected HTTP status " + status + ".");
            String body = readAll(connection.getInputStream());
            if (body.isEmpty()) return new JSONObject();
            try {
                return new JSONObject(body);
            } catch (JSONException exception) {
                throw new IOException("Invalid JSON response.", exception);
            }
        } finally {
            connection.disconnect();
        }
    }

    private static String readAll(InputStream input) throws IOException {
        try (InputStream stream = input; ByteArrayOutputStream buffer = new ByteArrayOutputStream()) {
            byte[] chunk = new byte[8192];
            int read;
            while ((read = stream.read(chunk)) != -1) buffer.write(chunk, 0, read);
            return buffer.toString("UTF-8");
        }
    }
}
