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
import java.util.Collections;
import java.util.List;
import java.util.UUID;

import app.selfhandler.mobile.BuildConfig;

/**
 * Minimal client for the existing Storage API. It reuses the app's mobile session token and the
 * build-time public origin; the backend is unchanged. Writes carry a fresh workspace operation id,
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

    /** Open tasks due today or earlier, sorted by due date, priority and title. */
    List<WidgetTask> dueTasks(String today) throws IOException {
        List<WidgetTask> tasks = new ArrayList<>();
        for (String status : new String[] {"inbox", "active"}) {
            JSONObject body = request("GET", "/api/storage/items?type=task&status=" + status + "&limit=" + PAGE_LIMIT, null);
            JSONArray data = body.optJSONArray("data");
            if (data == null) continue;
            for (int index = 0; index < data.length(); index++) {
                JSONObject item = data.optJSONObject(index);
                if (item == null) continue;
                WidgetTask task = WidgetTask.fromJson(item);
                if (task.dueOn != null && task.dueOn.compareTo(today) <= 0 && !task.title.isEmpty()) tasks.add(task);
            }
        }
        sort(tasks);
        return tasks;
    }

    void complete(long id) throws IOException {
        JSONObject payload = new JSONObject();
        try {
            payload.put("status", "done");
        } catch (JSONException exception) {
            throw new IOException("Invalid task payload.", exception);
        }
        request("PATCH", "/api/storage/items/" + id, payload);
    }

    void create(String title, String dueOn) throws IOException {
        JSONObject payload = new JSONObject();
        try {
            payload.put("type", "task").put("status", "inbox").put("title", title).put("due_on", dueOn);
        } catch (JSONException exception) {
            throw new IOException("Invalid task payload.", exception);
        }
        request("POST", "/api/storage/items", payload);
    }

    static void sort(List<WidgetTask> tasks) {
        Collections.sort(tasks, (left, right) -> {
            int due = left.dueOn.compareTo(right.dueOn);
            if (due != 0) return due;
            int priority = Integer.compare(left.priorityRank(), right.priorityRank());
            if (priority != 0) return priority;
            return left.title.compareToIgnoreCase(right.title);
        });
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
